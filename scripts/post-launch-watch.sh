#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
WINDOW="${1:-}"
MINUTES="${2:-60}"
[[ -n "$WINDOW" ]] || { echo 'Usage: post-launch-watch.sh <window-id> [minutes]' >&2; exit 2; }
[[ "$MINUTES" =~ ^[0-9]+$ ]] || { echo 'minutes must be an integer' >&2; exit 2; }
INTERVAL="${VSN_AZ_WATCH_INTERVAL_SECONDS:-300}"
DEADLINE=$((SECONDS + MINUTES*60))
mkdir -p runtime-artifacts
OUT="runtime-artifacts/go-live-watch-${WINDOW}.jsonl"
while (( SECONDS < DEADLINE )); do
  TMP="$(mktemp)"
  set +e
  php artisan vsn:go-live-observe "$WINDOW" > "$TMP" 2>&1
  RC=$?
  set -e
  python3 - "$TMP" "$OUT" <<'PY'
import json,sys,datetime,pathlib
raw=pathlib.Path(sys.argv[1]).read_text(errors='ignore')
with open(sys.argv[2],'a',encoding='utf-8') as f:
    f.write(json.dumps({'at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'output':raw},ensure_ascii=False)+'\n')
PY
  cat "$TMP"; rm -f "$TMP"
  if [[ "$RC" -eq 2 ]]; then echo '[AZ] blocking stabilization observation detected; stop watch and follow incident/rollback runbook.' >&2; exit 2; fi
  sleep "$INTERVAL"
done
php artisan vsn:go-live-status "$WINDOW"
echo "[AZ] finite watch completed. Evidence: $OUT"
