$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
Set-Location $Root
php scripts/runtime-capability-audit.php --strict --json=runtime-artifacts/runtime-capabilities.json
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
composer validate --strict --no-check-publish
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
composer install --no-interaction --prefer-dist --no-progress
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
npm ci --no-audit --no-fund
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
npm run build
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
php artisan optimize:clear
php artisan test --configuration=phpunit.xml
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
if ($env:VSN_AY_RUN_MYSQL -eq '1') { composer test:mysql; if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE } }
php scripts/audit-final-production-acceptance.php
php scripts/audit-production-operations.php
php scripts/audit-performance-security.php
Write-Host '[AY] Runtime acceptance core passed. Run deployed browser/Android evidence and final-production-acceptance next.'
