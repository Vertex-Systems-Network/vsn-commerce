$ErrorActionPreference = 'Stop'
Set-Location (Split-Path -Parent $PSScriptRoot)
php scripts/zero-to-end.php @args
exit $LASTEXITCODE
