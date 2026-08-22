#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
TARGET="${1:-}"; WINDOW="${2:-}"; DEPLOYMENT="${3:-}"
[[ -n "$TARGET" && -n "$WINDOW" ]] || { echo 'Usage: go-live-rollback.sh <target-release> <window-id> [deployment-id]' >&2; exit 2; }
./scripts/rollback-production.sh "$TARGET" "$DEPLOYMENT"
php artisan vsn:go-live-rollback-record "$WINDOW" "$TARGET" "Application rollback completed through AZ guarded rollback wrapper."
echo "[AZ] rollback completed and launch-window evidence recorded."
