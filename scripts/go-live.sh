#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
[[ -f vendor/autoload.php ]] || { echo '[AZ] vendor/autoload.php missing. Run composer install first.' >&2; exit 2; }
php artisan vsn:go-live-gate
WINDOW_OUTPUT="$(php artisan vsn:go-live-open)"
WINDOW_ID="$(printf '%s\n' "$WINDOW_OUTPUT" | head -n1 | tr -d '\r')"
[[ -n "$WINDOW_ID" ]] || { echo '[AZ] unable to resolve go-live window ID.' >&2; exit 3; }
printf '%s\n' "$WINDOW_OUTPUT"
mkdir -p runtime-artifacts
php artisan vsn:go-live-status "$WINDOW_ID" > "runtime-artifacts/go-live-open-${WINDOW_ID}.json"
echo "[AZ] Go-live stabilization window opened: $WINDOW_ID"
echo "[AZ] Scheduler will record observations every five minutes."
echo "[AZ] Evidence: runtime-artifacts/go-live-open-${WINDOW_ID}.json"
