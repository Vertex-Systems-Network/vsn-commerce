$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
Set-Location $Root
if ($env:APP_ENV -eq 'production' -or ((Test-Path '.env') -and (Select-String -Path '.env' -Pattern '^APP_ENV=production\s*$' -Quiet))) {
    throw 'Refusing dependency resolution in production. Resolve and review composer.lock in development/CI.'
}
if (-not (Get-Command composer -ErrorAction SilentlyContinue)) { throw 'Composer v2 is required.' }
composer update --no-install --no-interaction --prefer-dist --no-progress
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
composer validate --strict --no-check-publish
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
if (-not (Test-Path 'composer.lock')) { throw 'Composer did not generate composer.lock.' }
New-Item -ItemType Directory -Force -Path 'runtime-artifacts' | Out-Null
$sha=(Get-FileHash 'composer.lock' -Algorithm SHA256).Hash.ToLowerInvariant()
$payload=[ordered]@{schema='vsn-composer-lock-resolution-v1';passed=$true;composerLockSha256=$sha;generatedAt=(Get-Date).ToUniversalTime().ToString('o')}
$payload | ConvertTo-Json | Set-Content -Encoding UTF8 'runtime-artifacts/composer-lock-resolution.json'
Write-Host "[lock] composer.lock generated and validated: $sha"
Write-Host '[lock] Review composer.lock diff and commit it before release acceptance.'
