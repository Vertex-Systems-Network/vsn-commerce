#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${VSN_ANDROID_API_BASE_URL:-http://127.0.0.1:8000}"
EMAIL="${VSN_ANDROID_TEST_EMAIL:-}"
PASSWORD="${VSN_ANDROID_TEST_PASSWORD:-}"
DEVICE_ID="${VSN_ANDROID_TEST_DEVICE_ID:-550e8400-e29b-41d4-a716-446655440000}"
WRONG_DEVICE_ID="${VSN_ANDROID_TEST_WRONG_DEVICE_ID:-aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee}"
DEVICE_NAME="${VSN_ANDROID_TEST_DEVICE_NAME:-Android API Smoke Device}"
APP_VERSION="${VSN_ANDROID_TEST_APP_VERSION:-1.0.0}"
OS_VERSION="${VSN_ANDROID_TEST_OS_VERSION:-Android Test}"
FCM_TOKEN="${VSN_ANDROID_TEST_FCM_TOKEN:-}"

headers=(-H 'Accept: application/json' -H 'Content-Type: application/json' -H 'X-VSN-Client: android' -H "X-App-Version: ${APP_VERSION}" -H "X-Device-Id: ${DEVICE_ID}")

echo '[1/8] Mobile compatibility config'
curl --fail --silent --show-error "${headers[@]}" "${BASE_URL}/api/mobile/v1/config" >/dev/null

if [[ -z "$EMAIL" || -z "$PASSWORD" ]]; then
  echo 'VSN_ANDROID_TEST_EMAIL and VSN_ANDROID_TEST_PASSWORD are required for authenticated smoke checks.' >&2
  exit 2
fi

export EMAIL PASSWORD DEVICE_ID DEVICE_NAME APP_VERSION OS_VERSION
LOGIN_BODY=$(php -r 'echo json_encode(["email"=>getenv("EMAIL"),"password"=>getenv("PASSWORD"),"deviceId"=>getenv("DEVICE_ID"),"deviceName"=>getenv("DEVICE_NAME"),"appVersion"=>getenv("APP_VERSION"),"osVersion"=>getenv("OS_VERSION")], JSON_UNESCAPED_SLASHES);')

login() {
  curl --fail --silent --show-error "${headers[@]}" -d "$LOGIN_BODY" "${BASE_URL}/api/mobile/v1/auth/login"
}

echo '[2/8] Password login + device-bound bearer token'
LOGIN_JSON=$(login)
ACCESS_TOKEN=$(printf '%s' "$LOGIN_JSON" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["data"]["auth"]["accessToken"]??"";')
REFRESH_TOKEN=$(printf '%s' "$LOGIN_JSON" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["data"]["auth"]["refreshToken"]??"";')
[[ -n "$ACCESS_TOKEN" && -n "$REFRESH_TOKEN" ]] || { echo 'Login did not return tokens.' >&2; exit 1; }

echo '[3/8] Bootstrap + same-origin customer API'
curl --fail --silent --show-error "${headers[@]}" -H "Authorization: Bearer ${ACCESS_TOKEN}" "${BASE_URL}/api/mobile/v1/bootstrap" >/dev/null
curl --fail --silent --show-error "${headers[@]}" -H "Authorization: Bearer ${ACCESS_TOKEN}" "${BASE_URL}/api/v1/orders" >/dev/null

echo '[4/8] Stolen-token device mismatch is rejected'
STATUS=$(curl --silent --output /tmp/vsn-mobile-device-mismatch.json --write-out '%{http_code}' \
  -H 'Accept: application/json' -H 'X-VSN-Client: android' -H "X-App-Version: ${APP_VERSION}" -H "X-Device-Id: ${WRONG_DEVICE_ID}" \
  -H "Authorization: Bearer ${ACCESS_TOKEN}" "${BASE_URL}/api/v1/orders")
[[ "$STATUS" == '401' ]] || { echo "Expected 401 for wrong device, got ${STATUS}." >&2; cat /tmp/vsn-mobile-device-mismatch.json >&2; exit 1; }

if [[ -n "$FCM_TOKEN" ]]; then
  echo '[5/8] FCM register/remove lifecycle'
  export FCM_TOKEN
  PUSH_BODY=$(php -r 'echo json_encode(["provider"=>"fcm","token"=>getenv("FCM_TOKEN")], JSON_UNESCAPED_SLASHES);')
  curl --fail --silent --show-error "${headers[@]}" -H "Authorization: Bearer ${ACCESS_TOKEN}" -X PUT -d "$PUSH_BODY" "${BASE_URL}/api/mobile/v1/device/push-token" >/dev/null
  curl --fail --silent --show-error "${headers[@]}" -H "Authorization: Bearer ${ACCESS_TOKEN}" -X DELETE "${BASE_URL}/api/mobile/v1/device/push-token" >/dev/null
else
  echo '[5/8] FCM lifecycle skipped (VSN_ANDROID_TEST_FCM_TOKEN not set)'
fi

echo '[6/8] Refresh rotation'
export REFRESH_TOKEN
REFRESH_BODY=$(php -r 'echo json_encode(["refreshToken"=>getenv("REFRESH_TOKEN"),"deviceId"=>getenv("DEVICE_ID"),"deviceName"=>getenv("DEVICE_NAME"),"appVersion"=>getenv("APP_VERSION"),"osVersion"=>getenv("OS_VERSION")], JSON_UNESCAPED_SLASHES);')
REFRESH_JSON=$(curl --fail --silent --show-error "${headers[@]}" -d "$REFRESH_BODY" "${BASE_URL}/api/mobile/v1/auth/refresh")
NEW_ACCESS=$(printf '%s' "$REFRESH_JSON" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["data"]["auth"]["accessToken"]??"";')
[[ -n "$NEW_ACCESS" && "$NEW_ACCESS" != "$ACCESS_TOKEN" ]] || { echo 'Refresh did not rotate the access token.' >&2; exit 1; }

echo '[7/8] Rotated refresh replay is detected and session revoked'
REPLAY_STATUS=$(curl --silent --output /tmp/vsn-mobile-refresh-replay.json --write-out '%{http_code}' "${headers[@]}" -d "$REFRESH_BODY" "${BASE_URL}/api/mobile/v1/auth/refresh")
[[ "$REPLAY_STATUS" == '422' ]] || { echo "Expected 422 for refresh replay, got ${REPLAY_STATUS}." >&2; cat /tmp/vsn-mobile-refresh-replay.json >&2; exit 1; }
POST_REPLAY_STATUS=$(curl --silent --output /dev/null --write-out '%{http_code}' "${headers[@]}" -H "Authorization: Bearer ${NEW_ACCESS}" "${BASE_URL}/api/v1/orders")
[[ "$POST_REPLAY_STATUS" == '401' ]] || { echo "Expected revoked bearer token after replay, got ${POST_REPLAY_STATUS}." >&2; exit 1; }

echo '[8/8] Fresh login + explicit logout'
FINAL_LOGIN=$(login)
FINAL_ACCESS=$(printf '%s' "$FINAL_LOGIN" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["data"]["auth"]["accessToken"]??"";')
[[ -n "$FINAL_ACCESS" ]] || { echo 'Final login did not return access token.' >&2; exit 1; }
curl --fail --silent --show-error "${headers[@]}" -H "Authorization: Bearer ${FINAL_ACCESS}" -X POST "${BASE_URL}/api/mobile/v1/auth/logout" >/dev/null

EVIDENCE_PATH="${VSN_ANDROID_EVIDENCE_PATH:-runtime-artifacts/android-api-smoke.json}"
mkdir -p "$(dirname "$EVIDENCE_PATH")"
COMMIT_SHA="${VSN_COMMIT_SHA:-$(git rev-parse HEAD 2>/dev/null || true)}"
cat > "$EVIDENCE_PATH" <<JSON
{
  "passed": true,
  "generatedAt": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
  "commitSha": "$COMMIT_SHA",
  "baseUrl": "${BASE_URL%%\?*}",
  "deviceBinding": true,
  "refreshReplay": true,
  "authenticatedCommerce": true
}
JSON
echo "Android API AU smoke checks passed. Evidence: $EVIDENCE_PATH"
