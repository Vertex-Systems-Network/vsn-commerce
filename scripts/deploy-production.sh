#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
echo '[deploy] AW atomic release workflow is active; delegating to scripts/release-production.sh.'
exec "$ROOT/scripts/release-production.sh" "$@"
