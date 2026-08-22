#!/usr/bin/env bash
set -euo pipefail
base="${BASE_URL:-http://127.0.0.1:8000}"
origin="${FRONTEND_ORIGIN:-http://127.0.0.1:8000}"
jar="$(mktemp)"; body="$(mktemp)"; trap 'rm -f "$jar" "$body"' EXIT
curl -fsS -c "$jar" -b "$jar" -H "Origin: $origin" -H 'Accept: application/json' "$base/sanctum/csrf-cookie" >/dev/null
xsrf="$(awk '$6=="XSRF-TOKEN"{print $7}' "$jar" | tail -1)"
xsrf="$(python3 - <<'PY' "$xsrf"
import sys, urllib.parse
print(urllib.parse.unquote(sys.argv[1]))
PY
)"
[[ -n "$xsrf" ]] || { echo 'Missing XSRF token' >&2; exit 3; }
email="runtime-$(date +%s)-$$@example.test"
payload="$(printf '{\"name\":\"Runtime User\",\"email\":\"%s\",\"password\":\"RuntimePass12345!\",\"password_confirmation\":\"RuntimePass12345!\"}' "$email")"
code="$(curl -sS -o "$body" -w '%{http_code}' -c "$jar" -b "$jar" -X POST "$base/api/v1/auth/register" -H "Origin: $origin" -H 'Accept: application/json' -H 'Content-Type: application/json' -H "X-XSRF-TOKEN: $xsrf" -H 'X-Device-Id: runtime-e2e-device' --data "$payload")"
[[ "$code" == 201 ]] || { echo "Registration failed HTTP $code" >&2; cat "$body" >&2; exit 4; }
curl -fsS -c "$jar" -b "$jar" -H "Origin: $origin" -H 'Accept: application/json' "$base/api/v1/auth/me" | grep -q "$email"
curl -fsS -c "$jar" -b "$jar" -H "Origin: $origin" -H 'Accept: application/json' "$base/api/v1/wallet" >/dev/null
echo 'Stateful auth/API E2E passed.'
