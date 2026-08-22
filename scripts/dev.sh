#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
php artisan serve --host=127.0.0.1 --port=8000 &
laravel_pid=$!
cleanup(){ kill "$laravel_pid" >/dev/null 2>&1 || true; }
trap cleanup EXIT INT TERM
echo 'VSN Ecommerce: http://localhost:8000'
npm run dev:vite
