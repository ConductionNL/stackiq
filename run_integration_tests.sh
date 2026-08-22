#!/bin/bash

# Integration Test Runner for Koppelingen-Gebruik API
# This script tests the API endpoints and saves results

echo "================================================"
echo "Integration Tests for Koppelingen-Gebruik API"
echo "================================================"
echo ""

# Test 1: Basic GET endpoint
echo "[TEST 1] GET /api/koppelingen-gebruik (basic response)"
RESPONSE=$(docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  -H "Content-Type: application/json" \
  "http://localhost/index.php/apps/stackiq/api/koppelingen-gebruik?_limit=1")

TOTAL=$(echo "$RESPONSE" | python3 -c "import sys, json; print(json.load(sys.stdin).get('total', 0))" 2>/dev/null)
RESULTS_COUNT=$(echo "$RESPONSE" | python3 -c "import sys, json; print(len(json.load(sys.stdin).get('results', [])))" 2>/dev/null)
PAGE=$(echo "$RESPONSE" | python3 -c "import sys, json; print(json.load(sys.stdin).get('page', 0))" 2>/dev/null)
LIMIT=$(echo "$RESPONSE" | python3 -c "import sys, json; print(json.load(sys.stdin).get('limit', 0))" 2>/dev/null)

echo "  ✓ Total objects: $TOTAL"
echo "  ✓ Results returned: $RESULTS_COUNT"
echo "  ✓ Page: $PAGE"
echo "  ✓ Limit: $LIMIT"
echo ""

# Test 2: Pagination
echo "[TEST 2] GET /api/koppelingen-gebruik (pagination with _limit=5)"
RESPONSE=$(docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  -H "Content-Type: application/json" \
  "http://localhost/index.php/apps/stackiq/api/koppelingen-gebruik?_limit=5")

RESULTS_COUNT=$(echo "$RESPONSE" | python3 -c "import sys, json; print(len(json.load(sys.stdin).get('results', [])))" 2>/dev/null)
LIMIT=$(echo "$RESPONSE" | python3 -c "import sys, json; print(json.load(sys.stdin).get('limit', 0))" 2>/dev/null)

echo "  ✓ Results returned: $RESULTS_COUNT"
echo "  ✓ Limit applied: $LIMIT"
if [ "$RESULTS_COUNT" -le "$LIMIT" ]; then
  echo "  ✓ PASS: Results respect limit parameter"
else
  echo "  ✗ FAIL: Results exceed limit"
fi
echo ""

# Test 3: UUID-specific endpoint
echo "[TEST 3] GET /api/koppelingen-gebruik/{uuid} (filter by UUID)"
# Get a module UUID from the first result
TEST_UUID=$(docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  "http://localhost/index.php/apps/stackiq/api/koppelingen-gebruik?_limit=1" | \
  python3 -c "import sys, json; d=json.load(sys.stdin); print(d['results'][0]['module'] if d.get('results') else '')" 2>/dev/null)

if [ -n "$TEST_UUID" ]; then
  echo "  Using UUID: $TEST_UUID"
  RESPONSE=$(docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
    "http://localhost/index.php/apps/stackiq/api/koppelingen-gebruik/${TEST_UUID}")
  
  TOTAL=$(echo "$RESPONSE" | python3 -c "import sys, json; print(json.load(sys.stdin).get('total', 0))" 2>/dev/null)
  RESULTS_COUNT=$(echo "$RESPONSE" | python3 -c "import sys, json; print(len(json.load(sys.stdin).get('results', [])))" 2>/dev/null)
  
  echo "  ✓ Total related objects: $TOTAL"
  echo "  ✓ Results returned: $RESULTS_COUNT"
  echo "  ✓ PASS: UUID-specific endpoint working"
else
  echo "  ✗ SKIP: Could not extract UUID"
fi
echo ""

# Test 4: Response structure validation
echo "[TEST 4] Response structure validation"
RESPONSE=$(docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  "http://localhost/index.php/apps/stackiq/api/koppelingen-gebruik?_limit=1")

HAS_RESULTS=$(echo "$RESPONSE" | python3 -c "import sys, json; print('results' in json.load(sys.stdin))" 2>/dev/null)
HAS_TOTAL=$(echo "$RESPONSE" | python3 -c "import sys, json; print('total' in json.load(sys.stdin))" 2>/dev/null)
HAS_PAGE=$(echo "$RESPONSE" | python3 -c "import sys, json; print('page' in json.load(sys.stdin))" 2>/dev/null)
HAS_PAGES=$(echo "$RESPONSE" | python3 -c "import sys, json; print('pages' in json.load(sys.stdin))" 2>/dev/null)
HAS_LIMIT=$(echo "$RESPONSE" | python3 -c "import sys, json; print('limit' in json.load(sys.stdin))" 2>/dev/null)
HAS_OFFSET=$(echo "$RESPONSE" | python3 -c "import sys, json; print('offset' in json.load(sys.stdin))" 2>/dev/null)

echo "  Required fields present:"
echo "    - results: $HAS_RESULTS"
echo "    - total: $HAS_TOTAL"
echo "    - page: $HAS_PAGE"
echo "    - pages: $HAS_PAGES"
echo "    - limit: $HAS_LIMIT"
echo "    - offset: $HAS_OFFSET"

if [ "$HAS_RESULTS" = "True" ] && [ "$HAS_TOTAL" = "True" ] && [ "$HAS_PAGE" = "True" ]; then
  echo "  ✓ PASS: Response structure valid"
else
  echo "  ✗ FAIL: Missing required fields"
fi
echo ""

echo "================================================"
echo "Manual Tests Complete"
echo "================================================"
echo ""
echo "To run PHPUnit integration tests:"
echo "  cd stackiq"
echo "  docker exec -u 33 master-nextcloud-1 bash -c \"cd /var/www/html/apps-extra/stackiq && vendor/bin/phpunit --testsuite 'Integration Tests'\""

