param([Parameter(Mandatory=$true)][string]$Window,[int]$Minutes=60,[int]$IntervalSeconds=300)
$ErrorActionPreference = 'Stop'
Set-Location (Resolve-Path "$PSScriptRoot\..")
New-Item -ItemType Directory -Force runtime-artifacts | Out-Null
$outFile = "runtime-artifacts\go-live-watch-$Window.jsonl"
$deadline = (Get-Date).AddMinutes($Minutes)
while ((Get-Date) -lt $deadline) {
    $output = php artisan vsn:go-live-observe $Window 2>&1 | Out-String
    $code = $LASTEXITCODE
    $entry = @{ at=(Get-Date).ToUniversalTime().ToString('o'); output=$output } | ConvertTo-Json -Compress
    Add-Content -Path $outFile -Value $entry
    Write-Host $output
    if ($code -eq 2) { throw 'Blocking stabilization observation detected. Follow incident/rollback runbook.' }
    Start-Sleep -Seconds $IntervalSeconds
}
php artisan vsn:go-live-status $Window
Write-Host "[AZ] finite watch completed. Evidence: $outFile"
