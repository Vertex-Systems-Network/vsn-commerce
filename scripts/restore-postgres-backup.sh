#!/usr/bin/env bash
set -euo pipefail
: "${VSN_RESTORE_CONFIRM:?Set VSN_RESTORE_CONFIRM=YES only after verifying the target database and maintenance window.}"
[[ "$VSN_RESTORE_CONFIRM" == "YES" ]] || { echo "Refusing restore: VSN_RESTORE_CONFIRM must equal YES" >&2; exit 2; }
: "${BACKUP_FILE:?Set BACKUP_FILE to a verified pg_dump custom-format file}"
: "${DB_HOST:?}" "${DB_PORT:=5432}" "${DB_DATABASE:?}" "${DB_USERNAME:?}"
[[ -f "$BACKUP_FILE" ]] || { echo "Backup file not found" >&2; exit 2; }
if [[ -n "${BACKUP_SHA256:-}" ]]; then
  actual="$(sha256sum "$BACKUP_FILE" | awk '{print $1}')"
  [[ "$actual" == "$BACKUP_SHA256" ]] || { echo "SHA-256 mismatch" >&2; exit 3; }
fi
export PGPASSWORD="${DB_PASSWORD:-}"
pg_restore --host="$DB_HOST" --port="$DB_PORT" --username="$DB_USERNAME" --dbname="$DB_DATABASE" --clean --if-exists --no-owner --no-privileges "$BACKUP_FILE"
echo "Restore completed. Run migrations/status checks and application smoke tests before leaving maintenance mode."
