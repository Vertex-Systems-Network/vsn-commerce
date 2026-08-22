#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"; cd "$ROOT"
if [[ "${APP_ENV:-}" == "production" ]] || { [[ -f .env ]] && grep -Eq '^APP_ENV=production\s*$' .env; }; then
  echo '[lock] Refusing dependency resolution in production. Resolve and review composer.lock in development/CI.' >&2; exit 2
fi
command -v composer >/dev/null 2>&1 || { echo '[lock] Composer v2 is required.' >&2; exit 3; }
composer update --no-install --no-interaction --prefer-dist --no-progress
composer validate --strict --no-check-publish
[[ -f composer.lock ]] || { echo '[lock] Composer did not generate composer.lock.' >&2; exit 4; }
mkdir -p runtime-artifacts
SHA="$(sha256sum composer.lock | awk '{print $1}')"
printf '{"schema":"vsn-composer-lock-resolution-v1","passed":true,"composerLockSha256":"%s","generatedAt":"%s"}\n' "$SHA" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > runtime-artifacts/composer-lock-resolution.json
echo "[lock] composer.lock generated and validated: $SHA"
echo '[lock] Review composer.lock diff and commit it before release acceptance.'
