#!/usr/bin/env bash
set -euo pipefail
SOURCE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEPLOY_ROOT="${VSN_DEPLOY_ROOT:-/var/www/vsn}"
RELEASE="${VSN_RELEASE:-}"
[[ -n "$RELEASE" ]] || { echo 'VSN_RELEASE is required.' >&2; exit 2; }
[[ "$RELEASE" =~ ^[A-Za-z0-9._-]+$ ]] || { echo 'VSN_RELEASE contains unsafe characters.' >&2; exit 2; }
RELEASES_DIR="$DEPLOY_ROOT/releases"
SHARED_DIR="$DEPLOY_ROOT/shared"
CURRENT_LINK="$DEPLOY_ROOT/current"
TARGET="$RELEASES_DIR/$RELEASE"
JOURNAL_DIR="$SHARED_DIR/deployments"
JOURNAL="$JOURNAL_DIR/$RELEASE.jsonl"
DEPLOY_ID=""
PREVIOUS_TARGET=""
MAINTENANCE=0
mkdir -p "$RELEASES_DIR" "$SHARED_DIR/storage" "$SHARED_DIR/runtime" "$JOURNAL_DIR"

log_event(){ printf '{"at":"%s","release":"%s","phase":"%s","message":"%s"}\n' "$(date -u +%FT%TZ)" "$RELEASE" "$1" "${2//\"/\\\"}" >> "$JOURNAL"; }
record_phase(){ log_event "$1" "$2"; if [[ -n "$DEPLOY_ID" ]]; then (cd "$TARGET" && php artisan vsn:deploy-phase "$DEPLOY_ID" "$1" >/dev/null) || true; fi; }
fail_release(){
  local code=$?; trap - ERR; set +e
  local msg="release failed at line ${BASH_LINENO[0]} (exit $code)"; log_event failed "$msg"
  if [[ -n "$DEPLOY_ID" && -d "$TARGET" ]]; then (cd "$TARGET" && php artisan vsn:deploy-fail "$DEPLOY_ID" "$msg" >/dev/null 2>&1) || true; fi
  local current_target=""; [[ -L "$CURRENT_LINK" ]] && current_target="$(readlink -f "$CURRENT_LINK")"
  if [[ "${VSN_DEPLOY_AUTO_ROLLBACK:-1}" == "1" && "$current_target" == "$TARGET" && -n "$PREVIOUS_TARGET" && -d "$PREVIOUS_TARGET" ]]; then
    log_event rollback "automatic application rollback to $(basename "$PREVIOUS_TARGET")"
    ln -sfn "$PREVIOUS_TARGET" "$DEPLOY_ROOT/current.rollback"
    mv -Tf "$DEPLOY_ROOT/current.rollback" "$CURRENT_LINK"
    (cd "$CURRENT_LINK" && php artisan optimize:clear >/dev/null 2>&1 && php artisan optimize >/dev/null 2>&1) || true
    (cd "$CURRENT_LINK" && php artisan queue:restart >/dev/null 2>&1) || true
    (cd "$CURRENT_LINK" && php artisan horizon:terminate >/dev/null 2>&1) || true
    (cd "$CURRENT_LINK" && php artisan up >/dev/null 2>&1) || true
    if [[ -n "$DEPLOY_ID" ]]; then (cd "$TARGET" && php artisan vsn:deploy-rollback-record "$DEPLOY_ID" "$(basename "$PREVIOUS_TARGET")" >/dev/null 2>&1) || true; fi
  elif [[ "$MAINTENANCE" == 1 && -L "$CURRENT_LINK" ]]; then
    (cd "$CURRENT_LINK" && php artisan up >/dev/null 2>&1) || true
  fi
  echo "[release] $msg" >&2; exit "$code"
}
trap fail_release ERR

if [[ -L "$CURRENT_LINK" ]]; then PREVIOUS_TARGET="$(readlink -f "$CURRENT_LINK")"; fi
[[ ! -e "$TARGET" ]] || { echo "Release target already exists: $TARGET" >&2; exit 3; }
[[ -f "$SHARED_DIR/.env" ]] || { echo "Shared env missing: $SHARED_DIR/.env" >&2; exit 4; }

command -v rsync >/dev/null 2>&1 || { echo 'rsync is required.' >&2; exit 4; }
log_event preflight 'staging candidate release'
mkdir -p "$TARGET"
rsync -a --delete --exclude='.env' --exclude='vendor' --exclude='node_modules' --exclude='storage' --exclude='runtime' "$SOURCE_ROOT/" "$TARGET/"
ln -sfn "$SHARED_DIR/.env" "$TARGET/.env"
rm -rf "$TARGET/storage" "$TARGET/runtime"
ln -s "$SHARED_DIR/storage" "$TARGET/storage"
ln -s "$SHARED_DIR/runtime" "$TARGET/runtime"
mkdir -p "$SHARED_DIR/storage/app/public" "$SHARED_DIR/storage/framework/cache/data" "$SHARED_DIR/storage/framework/sessions" "$SHARED_DIR/storage/framework/views" "$SHARED_DIR/storage/logs"
(cd "$TARGET" && ./scripts/production-preflight.sh)
record_phase dependencies 'installing locked production dependencies'
(cd "$TARGET" && composer install --no-dev --prefer-dist --classmap-authoritative --no-interaction --no-progress)
record_phase build 'building frontend assets from package-lock.json'
(cd "$TARGET" && npm ci --no-audit --no-fund && npm run build && rm -rf node_modules)
(cd "$TARGET" && php artisan optimize:clear && php artisan vsn:production-config-audit)

ARTIFACT_SHA="${VSN_ARTIFACT_SHA256:-}"
if [[ -z "$ARTIFACT_SHA" ]]; then ARTIFACT_SHA="$(cd "$TARGET" && find . -type f ! -path './vendor/*' ! -path './storage/*' ! -path './runtime/*' -print0 | sort -z | xargs -0 sha256sum | sha256sum | awk '{print $1}')"; fi
COMMIT_SHA="${VSN_COMMIT_SHA:-}"
COMPOSER_LOCK_SHA="$(sha256sum "$TARGET/composer.lock" | awk '{print $1}')"
NPM_LOCK_SHA="$(sha256sum "$TARGET/package-lock.json" | awk '{print $1}')"
PREVIOUS_RELEASE=""
if [[ -n "$PREVIOUS_TARGET" ]]; then PREVIOUS_RELEASE="$(basename "$PREVIOUS_TARGET")"; fi

record_phase backup 'creating checksum-verified pre-migration database backup'
BACKUP_OUTPUT="$(cd "$TARGET" && php artisan vsn:backup-create)"
BACKUP_ID="$(printf '%s\n' "$BACKUP_OUTPUT" | awk '/^Backup /{print $2; exit}')"
[[ -n "$BACKUP_ID" ]] || { echo 'Unable to resolve verified backup ID.' >&2; exit 5; }

if [[ -L "$CURRENT_LINK" ]]; then (cd "$CURRENT_LINK" && php artisan down --retry=30); MAINTENANCE=1; fi
record_phase migrate 'running forward-only production migrations'
(cd "$TARGET" && php artisan migrate --force)

DEPLOY_ID="$(cd "$TARGET" && php artisan vsn:deploy-begin --release="$RELEASE" ${PREVIOUS_RELEASE:+--previous="$PREVIOUS_RELEASE"} ${COMMIT_SHA:+--commit="$COMMIT_SHA"} --artifact="$ARTIFACT_SHA" --composer-lock="$COMPOSER_LOCK_SHA" --npm-lock="$NPM_LOCK_SHA" --backup="$BACKUP_ID" --maintenance | tail -n1)"
cat > "$SHARED_DIR/runtime/release-metadata.json.tmp" <<JSON
{
  "schema": "vsn-release-metadata-v1",
  "release": "$RELEASE",
  "deploymentId": "$DEPLOY_ID",
  "commitSha": "$COMMIT_SHA",
  "artifactSha256": "$ARTIFACT_SHA",
  "composerLockSha256": "$COMPOSER_LOCK_SHA",
  "npmLockSha256": "$NPM_LOCK_SHA",
  "generatedAt": "$(date -u +%FT%TZ)"
}
JSON
mv -f "$SHARED_DIR/runtime/release-metadata.json.tmp" "$SHARED_DIR/runtime/release-metadata.json"
record_phase migrate 'deployment database evidence initialized after schema migration'
(cd "$TARGET" && php artisan optimize && php artisan storage:link >/dev/null 2>&1 || true)
record_phase switch 'atomically switching current symlink'
ln -sfn "$TARGET" "$DEPLOY_ROOT/current.new"
mv -Tf "$DEPLOY_ROOT/current.new" "$CURRENT_LINK"
record_phase restart 'restarting queue/Horizon workers'
(cd "$CURRENT_LINK" && php artisan queue:restart)
(cd "$CURRENT_LINK" && php artisan horizon:terminate >/dev/null 2>&1 || true)
(cd "$CURRENT_LINK" && php artisan schedule:interrupt >/dev/null 2>&1 || true)
if [[ -n "${VSN_DEPLOY_SERVICE_RESTART_COMMAND:-}" ]]; then bash -lc "$VSN_DEPLOY_SERVICE_RESTART_COMMAND"; fi
(cd "$CURRENT_LINK" && php artisan up)
MAINTENANCE=0
record_phase readiness 'waiting for real scheduler/worker heartbeats and readiness'
READINESS_TIMEOUT="${VSN_DEPLOY_READINESS_TIMEOUT_SECONDS:-120}"
READINESS_INTERVAL="${VSN_DEPLOY_READINESS_POLL_SECONDS:-5}"
READINESS_DEADLINE=$((SECONDS + READINESS_TIMEOUT))
while ! (cd "$CURRENT_LINK" && php artisan vsn:ops-status >/dev/null 2>&1); do
  if (( SECONDS >= READINESS_DEADLINE )); then echo "Readiness did not become healthy within ${READINESS_TIMEOUT}s." >&2; exit 6; fi
  sleep "$READINESS_INTERVAL"
done
(cd "$CURRENT_LINK" && php artisan vsn:launch-gate)
(cd "$CURRENT_LINK" && php artisan vsn:deploy-complete "$DEPLOY_ID")
log_event complete 'release completed and launch gate passed'
trap - ERR
printf '[release] completed %s (deployment %s, backup %s, artifact %s)\n' "$RELEASE" "$DEPLOY_ID" "$BACKUP_ID" "$ARTIFACT_SHA"
