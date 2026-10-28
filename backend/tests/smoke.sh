#!/usr/bin/env sh
set -eu
BASE_URL="${BASE_URL:-http://localhost:8088/api/v1}"
EMAIL="marketing-os-$(date +%s)@example.test"
REGISTER=$(curl -fsS -X POST "$BASE_URL/auth/register" -H 'Content-Type: application/json' -d "{\"name\":\"Smoke Test\",\"email\":\"$EMAIL\",\"password\":\"Local-Test-Password-123\",\"organization_name\":\"Smoke Org\"}")
TOKEN=$(printf '%s' "$REGISTER" | php -r '$d=json_decode(stream_get_contents(STDIN), true); echo $d["data"]["token"]["access_token"] ?? "";')
[ -n "$TOKEN" ] || { echo 'Registration failed: no token'; exit 1; }
curl -fsS "$BASE_URL/me" -H "Authorization: Bearer $TOKEN" >/dev/null
curl -fsS -X POST "$BASE_URL/projects" -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{"name":"Smoke project","objective":"Test API tenant scope"}' >/dev/null
curl -fsS "$BASE_URL/projects" -H "Authorization: Bearer $TOKEN" >/dev/null
curl -fsS -X POST "$BASE_URL/auth/logout" -H "Authorization: Bearer $TOKEN" >/dev/null
STATUS=$(curl -s -o /dev/null -w '%{http_code}' "$BASE_URL/me" -H "Authorization: Bearer $TOKEN")
[ "$STATUS" = "401" ] || { echo "Expected 401 after logout, got $STATUS"; exit 1; }
echo 'Smoke test passed.'
