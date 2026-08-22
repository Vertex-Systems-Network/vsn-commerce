#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
run_artisan(){
  if command -v docker >/dev/null 2>&1 && docker compose ps app >/dev/null 2>&1; then docker compose exec -T app php artisan "$@";
  elif [[ -x artisan || -f artisan ]]; then php artisan "$@";
  else echo 'Laravel runtime not available.' >&2; return 2; fi
}

php scripts/runtime-capability-audit.php --strict --json=runtime/runtime-capabilities.json
./scripts/final-static-acceptance.sh
php scripts/final-acceptance-evidence.php \
  --launch="${VSN_LAUNCH_EVIDENCE:-runtime/launch-verification.json}" \
  --release-metadata="${VSN_RELEASE_METADATA:-runtime/release-metadata.json}" \
  --static="${VSN_STATIC_ACCEPTANCE_EVIDENCE:-runtime-artifacts/static-acceptance.json}" \
  --capabilities="${VSN_RUNTIME_CAPABILITY_EVIDENCE:-runtime/runtime-capabilities.json}" \
  --browser="${VSN_BROWSER_E2E_EVIDENCE:-runtime-artifacts/browser-e2e.json}" \
  --android="${VSN_ANDROID_EVIDENCE_PATH:-runtime-artifacts/android-api-smoke.json}" \
  --output="${VSN_FINAL_ACCEPTANCE_EVIDENCE:-runtime/final-acceptance-verification.json}"

run_artisan vsn:production-config-audit
run_artisan vsn:ops-status
run_artisan vsn:providers-probe
run_artisan vsn:providers-reconcile
run_artisan vsn:launch-gate
run_artisan vsn:acceptance
cat <<'EOF'
[acceptance] automated gates passed for the exact deployed artifact.
[acceptance] Complete four independent sign-offs in /admin/acceptance:
  1. Operations
  2. Security / Privacy
  3. Finance
  4. Business Owner
[acceptance] Then seal the approved run: php artisan vsn:rc-seal
[acceptance] Final decision: php artisan vsn:go-live-gate
EOF
