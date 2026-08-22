#!/usr/bin/env bash
set -euo pipefail
: "${PGHOST:=postgres}" "${PGPORT:=5432}" "${PGUSER:=vsn}" "${PGPASSWORD:=vsn-local-only}" "${SOURCE_DB:=vsn_ecommerce}" "${RESTORE_DB:=vsn_restore}"
export PGPASSWORD
work="$(mktemp -d)"; trap 'rm -rf "$work"' EXIT
file="$work/vsn-runtime.dump"
echo "[backup-drill] dumping $SOURCE_DB"
pg_dump --host="$PGHOST" --port="$PGPORT" --username="$PGUSER" --format=custom --no-owner --no-privileges --file="$file" "$SOURCE_DB"
sha="$(sha256sum "$file" | awk '{print $1}')"
test -n "$sha"
echo "[backup-drill] SHA-256 $sha"
dropdb --host="$PGHOST" --port="$PGPORT" --username="$PGUSER" --if-exists "$RESTORE_DB"
createdb --host="$PGHOST" --port="$PGPORT" --username="$PGUSER" "$RESTORE_DB"
pg_restore --host="$PGHOST" --port="$PGPORT" --username="$PGUSER" --dbname="$RESTORE_DB" --no-owner --no-privileges "$file"
src_migrations="$(psql --host="$PGHOST" --port="$PGPORT" --username="$PGUSER" --dbname="$SOURCE_DB" -Atqc 'select count(*) from migrations')"
dst_migrations="$(psql --host="$PGHOST" --port="$PGPORT" --username="$PGUSER" --dbname="$RESTORE_DB" -Atqc 'select count(*) from migrations')"
[[ "$src_migrations" == "$dst_migrations" ]] || { echo "Migration count mismatch: source=$src_migrations restore=$dst_migrations" >&2; exit 4; }
for table in users products orders wallet_transactions finance_journals; do
  src="$(psql --host="$PGHOST" --port="$PGPORT" --username="$PGUSER" --dbname="$SOURCE_DB" -Atqc "select count(*) from $table")"
  dst="$(psql --host="$PGHOST" --port="$PGPORT" --username="$PGUSER" --dbname="$RESTORE_DB" -Atqc "select count(*) from $table")"
  [[ "$src" == "$dst" ]] || { echo "$table count mismatch: source=$src restore=$dst" >&2; exit 5; }
done
echo "[backup-drill] restore verified ($dst_migrations migrations)"
