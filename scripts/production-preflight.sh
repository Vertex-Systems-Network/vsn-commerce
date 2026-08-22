#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
fail(){ echo "[preflight] ERROR: $*" >&2; exit 1; }
pass(){ echo "[preflight] PASS: $*"; }

[[ -f .env ]] || fail '.env is missing from the candidate release.'
[[ -f composer.json ]] || fail 'composer.json is missing.'
[[ -f composer.lock ]] || fail 'composer.lock is required for production.'
[[ -f package.json && -f package-lock.json ]] || fail 'package.json/package-lock.json are required.'
command -v php >/dev/null 2>&1 || fail 'php is not installed.'
command -v composer >/dev/null 2>&1 || fail 'composer is not installed.'
command -v npm >/dev/null 2>&1 || fail 'npm is not installed.'
command -v sha256sum >/dev/null 2>&1 || fail 'sha256sum is required for release evidence.'
[[ -w storage && -w bootstrap/cache ]] || fail 'storage and bootstrap/cache must be writable.'
pass 'release files and required binaries are present'

php scripts/audit-mysql-migrations.php >/dev/null
pass 'MySQL migration portability audit'
php scripts/audit-performance-security.php >/dev/null
pass 'performance/security source audit'

if [[ -d vendor ]]; then
  php artisan optimize:clear >/dev/null
  php artisan vsn:production-config-audit
  pass 'Laravel production configuration audit'
else
  echo '[preflight] WARN: vendor/ is not installed yet; Laravel runtime configuration audit deferred until dependency install.'
fi
