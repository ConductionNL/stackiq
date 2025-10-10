#!/bin/bash

# Test script for AangebodenGebruik API endpoints
# Tests authentication and authorization for the ambtenaar endpoints

echo "===== Testing AangebodenGebruik API Endpoints ====="
echo ""

# Get the Nextcloud container name
CONTAINER=$(docker ps --format '{{.Names}}' | grep nextcloud | head -n 1)
echo "Using Nextcloud container: $CONTAINER"
echo ""

echo "1. Testing /api/aangeboden-gebruik/ambtenaar (all gebruiks)"
echo "   Expected: Should return results if user is in admin or ambtenaar group"
echo ""
docker exec -u 33 "$CONTAINER" curl -s -u 'admin:admin' \
  -H 'Content-Type: application/json' \
  'http://localhost/index.php/apps/softwarecatalog/api/aangeboden-gebruik/ambtenaar' | jq .

echo ""
echo "2. Testing /api/aangeboden-gebruik/ambtenaar with pagination"
echo "   Expected: Should respect limit and offset parameters"
echo ""
docker exec -u 33 "$CONTAINER" curl -s -u 'admin:admin' \
  -H 'Content-Type: application/json' \
  'http://localhost/index.php/apps/softwarecatalog/api/aangeboden-gebruik/ambtenaar?limit=5&offset=0' | jq .

echo ""
echo "3. Testing /api/aangeboden-gebruik/afnemer (where org is afnemer)"
echo "   Expected: Should return gebruiks where active org is the afnemer"
echo ""
docker exec -u 33 "$CONTAINER" curl -s -u 'admin:admin' \
  -H 'Content-Type: application/json' \
  'http://localhost/index.php/apps/softwarecatalog/api/aangeboden-gebruik/afnemer' | jq .

echo ""
echo "===== Test Complete ====="
