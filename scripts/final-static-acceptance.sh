#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
OUT="${VSN_STATIC_ACCEPTANCE_EVIDENCE:-runtime-artifacts/static-acceptance.json}"
mkdir -p "$(dirname "$OUT")"
php scripts/audit-seeders.php >/dev/null
php scripts/audit-cache-readiness.php >/dev/null
php scripts/audit-mysql-migrations.php >/dev/null
php scripts/audit-database-portability.php >/dev/null
php scripts/audit-performance-security.php >/dev/null
php scripts/audit-production-operations.php >/dev/null
php scripts/audit-auth-admin-ui.php >/dev/null
php scripts/audit-test-suite.php >/dev/null
php scripts/audit-final-production-acceptance.php >/dev/null
php scripts/audit-runtime-acceptance.php >/dev/null
COMMIT_SHA="${VSN_COMMIT_SHA:-$(git rev-parse HEAD 2>/dev/null || true)}"
GENERATED="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
cat > "$OUT" <<JSON
{
  "passed": true,
  "generatedAt": "$GENERATED",
  "commitSha": "$COMMIT_SHA",
  "seederAudit": true,
  "cacheReadinessAudit": true,
  "mysqlMigrationAudit": true,
  "databasePortabilityAudit": true,
  "performanceSecurityAudit": true,
  "productionOperationsAudit": true,
  "authAdminAudit": true,
  "testSuiteAudit": true,
  "finalAcceptanceAudit": true,
  "runtimeAcceptanceContractAudit": true
}
JSON
echo "[static-acceptance] evidence written to $OUT"
