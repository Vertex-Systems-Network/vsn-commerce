$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root
$laravel = Start-Process -FilePath 'php' -ArgumentList @('artisan','serve','--host=127.0.0.1','--port=8000') -PassThru -NoNewWindow
try {
    Write-Host 'VSN Ecommerce: http://localhost:8000'
    Write-Host 'Press Ctrl+C to stop Laravel + Vite.'
    & npm.cmd run dev:vite
}
finally {
    if ($laravel -and -not $laravel.HasExited) { Stop-Process -Id $laravel.Id -Force -ErrorAction SilentlyContinue }
}
