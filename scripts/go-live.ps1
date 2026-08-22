$ErrorActionPreference = 'Stop'
Set-Location (Resolve-Path "$PSScriptRoot\..")
if (-not (Test-Path 'vendor\autoload.php')) { throw 'vendor/autoload.php missing. Run composer install first.' }
php artisan vsn:go-live-gate
$out = php artisan vsn:go-live-open
if ($LASTEXITCODE -ne 0) { throw 'Unable to open go-live stabilization window.' }
$out | Write-Host
$window = ($out | Select-Object -First 1).Trim()
if ([string]::IsNullOrWhiteSpace($window)) { throw 'Unable to resolve go-live window ID.' }
New-Item -ItemType Directory -Force runtime-artifacts | Out-Null
php artisan vsn:go-live-status $window | Out-File -Encoding utf8 "runtime-artifacts\go-live-open-$window.json"
Write-Host "[AZ] Go-live window opened: $window"
Write-Host '[AZ] Scheduler records observations every five minutes.'
