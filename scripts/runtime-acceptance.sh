#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"; cd "$ROOT"
php scripts/runtime-capability-audit.php --strict --json=runtime-artifacts/runtime-capabilities.json
composer validate --strict --no-check-publish
composer install --no-interaction --prefer-dist --no-progress
npm ci --no-audit --no-fund
npm run build
php artisan optimize:clear
php artisan test --configuration=phpunit.xml
if [[ "${VSN_AY_RUN_MYSQL:-0}" == "1" ]]; then composer test:mysql; fi
php scripts/audit-final-production-acceptance.php
php scripts/audit-production-operations.php
php scripts/audit-performance-security.php
printf '[AY] Runtime acceptance core passed. Run deployed browser/Android evidence and final-production-acceptance next.\n'
