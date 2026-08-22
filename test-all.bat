@echo off
setlocal
where php >nul 2>nul || (echo PHP is required. & exit /b 2)
where composer >nul 2>nul || (echo Composer is required. & exit /b 2)
where npm >nul 2>nul || (echo npm is required. & exit /b 2)
if not exist composer.lock (
  echo composer.lock is missing. Generate and commit it before production.
  exit /b 3
)
composer validate --strict || exit /b 1
composer install --no-interaction --prefer-dist --no-progress || exit /b 1
npm ci --no-audit --no-fund || exit /b 1
php artisan test || exit /b 1
npm run build || exit /b 1
echo All local tests passed.
