#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
command -v docker >/dev/null || { echo "Docker is required for runtime verification." >&2; exit 2; }
docker compose version >/dev/null
mkdir -p runtime runtime-artifacts
rm -f runtime/launch-verification.json runtime-artifacts/launch-verification.json
composerLock=false; [[ -f composer.lock ]] && composerLock=true
npmLock=false; [[ -f package-lock.json ]] && npmLock=true
cleanup(){ docker compose down -v --remove-orphans >/dev/null 2>&1 || true; }
trap cleanup EXIT

echo '[runtime] build unified application image'
docker compose build app test worker scheduler
dependencies=true
frontendBuild=true

echo '[runtime] start PostgreSQL + Redis'
docker compose up -d postgres redis

echo '[runtime] migrate + seed application database'
docker compose run --rm app php artisan migrate:fresh --force
docker compose run --rm app php artisan db:seed --force
databaseMigrations=true

echo '[runtime] run Laravel feature suite against PostgreSQL'
docker compose run --rm test php vendor/bin/phpunit --configuration=phpunit.postgres.xml
laravelTests=true
echo '[runtime] run live-provider contract tests with faked HTTP'
docker compose run --rm test php vendor/bin/phpunit --filter=ProviderIntegrationTest --configuration=phpunit.postgres.xml
providerContracts=true

echo '[runtime] start unified app, workers and scheduler'
docker compose up -d app worker scheduler
for i in $(seq 1 60); do
  if curl -fsS http://127.0.0.1:${VSN_APP_PORT:-8000}/api/v1/health >/dev/null 2>&1; then break; fi
  sleep 2
  [[ "$i" == 60 ]] && { docker compose logs app; exit 6; }
done

base="http://127.0.0.1:${VSN_APP_PORT:-8000}"
curl -fsS "$base/" >/dev/null
curl -fsS "$base/api/v1/health" >/dev/null
curl -fsS "$base/api/v1/products" >/dev/null
curl -fsS "$base/api/v1/search/suggestions?q=phone" >/dev/null
appSmoke=true

echo '[runtime] stateful Sanctum/auth API E2E on same origin'
BASE_URL="$base" FRONTEND_ORIGIN="$base" bash scripts/runtime-auth-e2e.sh
authenticatedE2E=true

docker compose exec -T app php artisan vsn:operations-heartbeat
sleep 4
health_json="$(curl -fsS "$base/api/v1/health/ready")"
echo "$health_json" | grep -q '"status":"ready"'
queueHeartbeat=true
schedulerHeartbeat=true

echo '[runtime] app backup + independent restore drill'
docker compose exec -T app php artisan vsn:backup-create
docker compose exec -T app bash scripts/backup-restore-drill.sh
backupRestoreDrill=true

echo '[runtime] database index audit'
docker compose exec -T app php artisan vsn:db-index-audit | tee runtime-artifacts/db-index-audit.json

generated="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
release="${VSN_RELEASE:-runtime-verification}"
cat > runtime/launch-verification.json <<JSON
{
  "generatedAt": "$generated",
  "release": "$release",
  "composerLock": $composerLock,
  "npmLock": $npmLock,
  "dependencies": $dependencies,
  "databaseMigrations": $databaseMigrations,
  "laravelTests": $laravelTests,
  "frontendBuild": $frontendBuild,
  "appSmoke": $appSmoke,
  "authenticatedE2E": $authenticatedE2E,
  "queueHeartbeat": $queueHeartbeat,
  "schedulerHeartbeat": $schedulerHeartbeat,
  "backupRestoreDrill": $backupRestoreDrill,
  "providerContracts": $providerContracts
}
JSON
cp runtime/launch-verification.json runtime-artifacts/launch-verification.json

echo '[runtime] run machine launch gate'
docker compose exec -T app php artisan vsn:launch-gate | tee runtime-artifacts/launch-gate.json

echo '[runtime] verification passed'
cat runtime/launch-verification.json