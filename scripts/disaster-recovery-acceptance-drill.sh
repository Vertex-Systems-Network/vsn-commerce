#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
RPO_MINUTES="${VSN_DRILL_RPO_MINUTES:-0}"
START="$(date +%s)"

echo '[dr] running isolated backup/restore drill'
bash scripts/backup-restore-drill.sh
END="$(date +%s)"
RTO_MINUTES=$(( (END-START+59)/60 ))

if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
  docker compose exec -T app php artisan vsn:dr-record passed "$RTO_MINUTES" "$RPO_MINUTES"
else
  echo "[dr] restore passed; record evidence manually: php artisan vsn:dr-record passed ${RTO_MINUTES} ${RPO_MINUTES}" >&2
fi
