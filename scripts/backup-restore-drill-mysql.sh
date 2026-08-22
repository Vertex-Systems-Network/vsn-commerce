#!/usr/bin/env bash
set -euo pipefail

: "${DB_HOST:=127.0.0.1}" "${DB_PORT:=3306}" "${DB_USERNAME:=root}" "${DB_PASSWORD:=}"
: "${SOURCE_DB:=vsn_ecommerce}" "${RESTORE_DB:=vsn_restore}"
: "${VSN_MYSQL_DUMP_BINARY:=mysqldump}" "${VSN_MYSQL_CLIENT_BINARY:=mysql}"

for db in "$SOURCE_DB" "$RESTORE_DB"; do
  [[ "$db" =~ ^[A-Za-z0-9_]+$ ]] || { echo "Unsafe database name: $db" >&2; exit 2; }
done
[[ "$SOURCE_DB" != "$RESTORE_DB" ]] || { echo "RESTORE_DB must differ from SOURCE_DB" >&2; exit 2; }

command -v "$VSN_MYSQL_DUMP_BINARY" >/dev/null 2>&1 || { echo "mysqldump binary not found: $VSN_MYSQL_DUMP_BINARY" >&2; exit 3; }
command -v "$VSN_MYSQL_CLIENT_BINARY" >/dev/null 2>&1 || { echo "mysql client not found: $VSN_MYSQL_CLIENT_BINARY" >&2; exit 3; }

export MYSQL_PWD="$DB_PASSWORD"
work="$(mktemp -d)"
trap 'rm -rf "$work"; unset MYSQL_PWD' EXIT
file="$work/vsn-runtime.sql"

common=(--host="$DB_HOST" --port="$DB_PORT" --user="$DB_USERNAME" --default-character-set=utf8mb4)

echo "[mysql-backup-drill] dumping $SOURCE_DB"
"$VSN_MYSQL_DUMP_BINARY" "${common[@]}" \
  --single-transaction --quick --skip-lock-tables --hex-blob \
  --routines --events --triggers \
  "$SOURCE_DB" > "$file"

sha="$(sha256sum "$file" | awk '{print $1}')"
[[ -n "$sha" && -s "$file" ]] || { echo "Backup artifact is empty" >&2; exit 4; }
echo "[mysql-backup-drill] SHA-256 $sha"

"$VSN_MYSQL_CLIENT_BINARY" "${common[@]}" -e "DROP DATABASE IF EXISTS \`$RESTORE_DB\`; CREATE DATABASE \`$RESTORE_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
"$VSN_MYSQL_CLIENT_BINARY" "${common[@]}" --database="$RESTORE_DB" < "$file"

scalar() {
  local db="$1" sql="$2"
  "$VSN_MYSQL_CLIENT_BINARY" "${common[@]}" --batch --skip-column-names --database="$db" -e "$sql"
}

src_migrations="$(scalar "$SOURCE_DB" 'SELECT COUNT(*) FROM migrations')"
dst_migrations="$(scalar "$RESTORE_DB" 'SELECT COUNT(*) FROM migrations')"
[[ "$src_migrations" == "$dst_migrations" ]] || { echo "Migration count mismatch: source=$src_migrations restore=$dst_migrations" >&2; exit 5; }

for table in users products orders wallet_transactions finance_journals; do
  src="$(scalar "$SOURCE_DB" "SELECT COUNT(*) FROM \`$table\`")"
  dst="$(scalar "$RESTORE_DB" "SELECT COUNT(*) FROM \`$table\`")"
  [[ "$src" == "$dst" ]] || { echo "$table count mismatch: source=$src restore=$dst" >&2; exit 6; }
done

echo "[mysql-backup-drill] restore verified ($dst_migrations migrations)"
