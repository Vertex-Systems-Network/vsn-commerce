#!/usr/bin/env bash
set -euo pipefail
DEPLOY_ROOT="${VSN_DEPLOY_ROOT:-/var/www/vsn}"
CURRENT_LINK="$DEPLOY_ROOT/current"
TARGET_RELEASE="${1:-}"
DEPLOYMENT_ID="${2:-}"
[[ -n "$TARGET_RELEASE" ]] || { echo 'Usage: rollback-production.sh <target-release> [deployment-id]' >&2; exit 2; }
[[ "$TARGET_RELEASE" =~ ^[A-Za-z0-9._-]+$ ]] || { echo 'Unsafe release name.' >&2; exit 2; }
TARGET="$DEPLOY_ROOT/releases/$TARGET_RELEASE"
[[ -d "$TARGET" && -f "$TARGET/artisan" ]] || { echo "Release not found: $TARGET" >&2; exit 3; }
[[ -L "$CURRENT_LINK" ]] || { echo 'Current release symlink is missing.' >&2; exit 4; }
CURRENT_TARGET="$(readlink -f "$CURRENT_LINK")"
[[ "$CURRENT_TARGET" != "$TARGET" ]] || { echo 'Target release is already current.'; exit 0; }

(cd "$CURRENT_LINK" && php artisan down --retry=30)
ln -sfn "$TARGET" "$DEPLOY_ROOT/current.rollback"
mv -Tf "$DEPLOY_ROOT/current.rollback" "$CURRENT_LINK"
(cd "$CURRENT_LINK" && php artisan optimize:clear && php artisan optimize)
(cd "$CURRENT_LINK" && php artisan queue:restart)
(cd "$CURRENT_LINK" && php artisan horizon:terminate >/dev/null 2>&1 || true)
(cd "$CURRENT_LINK" && php artisan up)
sleep "${VSN_ROLLBACK_HEARTBEAT_WAIT_SECONDS:-5}"
(cd "$CURRENT_LINK" && php artisan vsn:ops-status)
if [[ -n "$DEPLOYMENT_ID" ]]; then (cd "$CURRENT_LINK" && php artisan vsn:deploy-rollback-record "$DEPLOYMENT_ID" "$TARGET_RELEASE") || true; fi
cat <<'EOF'
[rollback] application release switched successfully.
[rollback] Database migrations were NOT rolled back automatically. AW requires backward-compatible forward migrations; perform any schema reversal only through a reviewed data-safe migration.
EOF
