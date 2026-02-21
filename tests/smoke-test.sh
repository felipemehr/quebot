#!/bin/bash
# QueBot Smoke Test Suite
# Usage: ./tests/smoke-test.sh [URL]
# Example: ./tests/smoke-test.sh https://quebot-production.up.railway.app

URL="${1:-https://quebot-production.up.railway.app}"
PASS=0
FAIL=0
WARN=0

echo "🔥 QueBot Smoke Tests"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "Target: $URL"
echo "Time:   $(date -u '+%Y-%m-%d %H:%M:%S UTC')"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

pass() { PASS=$((PASS+1)); echo "  ✅ $1"; }
fail() { FAIL=$((FAIL+1)); echo "  ❌ $1"; }
warn() { WARN=$((WARN+1)); echo "  ⚠️  $1"; }

# TEST 1: Health Check
echo "📋 Test 1: API health (status check)"
HTTP_CODE=$(curl -s -o /tmp/smoke_health.txt -w "%{http_code}" "$URL/api/chat.php?status=1" 2>/dev/null || echo "000")
if [ "$HTTP_CODE" = "200" ]; then
    pass "API status → HTTP 200"
else
    fail "API status → HTTP $HTTP_CODE"
fi

# TEST 2: Homepage
echo ""
echo "📋 Test 2: Homepage loads"
HTTP_CODE=$(curl -s -o /tmp/smoke_home.txt -w "%{http_code}" "$URL/" 2>/dev/null || echo "000")
if [ "$HTTP_CODE" = "200" ]; then
    pass "GET / → HTTP 200"
    if grep -q "QueBot" /tmp/smoke_home.txt; then
        pass "Contains 'QueBot'"
    else
        fail "Missing 'QueBot' content"
    fi
else
    fail "GET / → HTTP $HTTP_CODE"
fi

# TEST 3: Chat Status
echo ""
echo "📋 Test 3: Chat status check"
HTTP_CODE=$(curl -s -o /tmp/smoke_status.txt -w "%{http_code}" "$URL/api/chat.php?status=1" 2>/dev/null || echo "000")
if [ "$HTTP_CODE" = "200" ]; then
    pass "GET /api/chat.php?status=1 → HTTP 200"
else
    fail "GET /api/chat.php?status=1 → HTTP $HTTP_CODE"
fi

# TEST 4: Chat API
echo ""
echo "📋 Test 4: Chat API (simple message)"
HTTP_CODE=$(curl -s -o /tmp/smoke_chat.txt -w "%{http_code}" -X POST "$URL/api/chat.php" \
    -H "Content-Type: application/json" \
    -H "Origin: $URL" \
    -d '{"message":"hola","history":[]}' \
    --max-time 30 2>/dev/null || echo "000")
if [ "$HTTP_CODE" = "200" ]; then
    pass "POST /api/chat.php → HTTP 200"
    RESP_SIZE=$(wc -c < /tmp/smoke_chat.txt)
    if [ "$RESP_SIZE" -gt 50 ]; then
        pass "Response has content ($RESP_SIZE bytes)"
    else
        warn "Response small ($RESP_SIZE bytes)"
    fi
else
    fail "POST /api/chat.php → HTTP $HTTP_CODE"
fi

# TEST 5: CORS blocks invalid origin
echo ""
echo "📋 Test 5: CORS validation"
CORS_BAD=$(curl -s -D- -o /dev/null "$URL/api/chat.php?status=1" \
    -H "Origin: https://evil.com" 2>/dev/null)
if echo "$CORS_BAD" | grep -qi "access-control-allow-origin: https://evil.com"; then
    fail "CORS allows evil.com"
else
    pass "CORS blocks invalid origin"
fi

# TEST 6: Rate limit (status bypass)
echo ""
echo "📋 Test 6: Rate limit bypass for status"
ALL_OK=true
for i in $(seq 1 15); do
    H=$(curl -s -o /dev/null -w "%{http_code}" "$URL/api/chat.php?status=1" 2>/dev/null)
    if [ "$H" != "200" ]; then ALL_OK=false; break; fi
done
if [ "$ALL_OK" = true ]; then
    pass "15 status requests all HTTP 200"
else
    fail "Status requests rate-limited"
fi

# TEST 7: Legal API
echo ""
echo "📋 Test 7: Legal API"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$URL/api/legal/health.php" 2>/dev/null || echo "000")
if [ "$HTTP_CODE" = "200" ]; then
    pass "Legal API → HTTP 200"
else
    warn "Legal API → HTTP $HTTP_CODE"
fi

# TEST 8: Admin
echo ""
echo "📋 Test 8: Admin dashboard"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$URL/admin.php" 2>/dev/null || echo "000")
if [ "$HTTP_CODE" = "200" ]; then
    pass "Admin dashboard → HTTP 200"
else
    fail "Admin dashboard → HTTP $HTTP_CODE"
fi

# TEST 9: Financial mode separation
echo ""
echo "📋 Test 9: Financial query (mode separation)"
RESP=$(curl -s -X POST "$URL/api/chat.php" \
    -H "Content-Type: application/json" \
    -H "Origin: $URL" \
    -d '{"message":"precio del dólar hoy","history":[]}' \
    --max-time 45 2>/dev/null)
if echo "$RESP" | grep -qi "portalinmobiliario\|yapo\.cl\|toctoc\.com"; then
    fail "Financial query mentions portals"
else
    pass "Financial query: zero portal mentions"
fi

# SUMMARY
echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
TOTAL=$((PASS+FAIL+WARN))
echo "📊 Results: $PASS passed, $FAIL failed, $WARN warnings (of $TOTAL)"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

[ "$FAIL" -gt 0 ] && exit 1 || exit 0
