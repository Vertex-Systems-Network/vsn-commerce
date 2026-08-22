#!/usr/bin/env bash
set -euo pipefail
base="${BASE_URL:-http://127.0.0.1:8000}"
for path in /api/v1/health /api/v1/products '/api/v1/search/suggestions?q=phone'; do
  code="$(curl -sS -o /tmp/vsn-smoke-body -w '%{http_code}' "$base$path")"
  [[ "$code" == 200 ]] || { echo "$path returned HTTP $code" >&2; cat /tmp/vsn-smoke-body >&2; exit 2; }
done
echo 'API smoke checks passed.'
