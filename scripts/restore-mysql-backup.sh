#!/usr/bin/env bash
set -euo pipefail

: "${VSN_RESTORE_CONFIRM:?Set VSN_RESTORE_CONFIRM=YES only after verifying the target database and maintenance window.}"
[[ "$VSN_RESTORE_CONFIRM" == "YES" ]] || { echo "Refusing restore: VSN_RESTORE_CONFIRM must equal YES" >&2; exit 2; }
: "${BACKUP_FILE:?Set BACKUP_FILE to a verified mysqldump SQL file}"
: "${DB_HOST:?}" "${DB_PORT:=3306}" "${DB_DATABASE:?}" "${DB_USERNAME:?}"
[[ -f "$BACKUP_FILE" ]] || { echo "Backup file not found" >&2; exit 2; }

if [[ ! "$DB_DATABASE" =~ ^[A-Za-z0-9_]+$ ]]; then
  echo "Unsafe DB_DATABASE; only letters, digits, and underscore are allowed" >&2
  exit 2
fi

if [[ -n "${BACKUP_SHA256:-}" ]]; then
  actual="$(sha256sum "$BACKUP_FILE" | awk '{print $1}')"
  [[ "$actual" == "$BACKUP_SHA256" ]] || { echo "SHA-256 mismatch" >&2; exit 3; }
fi

mysql_bin="${VSN_MYSQL_CLIENT_BINARY:-mysql}"
command -v "$mysql_bin" >/dev/null 2>&1 || { echo "MySQL client not found: $mysql_bin" >&2; exit 4; }

export MYSQL_PWD="${DB_PASSWORD:-}"
trap 'unset MYSQL_PWD' EXIT

"$mysql_bin" \
  --host="$DB_HOST" \
  --port="$DB_PORT" \
  --user="$DB_USERNAME" \
  --default-character-set="${DB_CHARSET:-utf8mb4}" \
  --database="$DB_DATABASE" \
  < "$BACKUP_FILE"

echo "Restore completed. Run migration/status checks and application smoke tests before leaving maintenance mode."
