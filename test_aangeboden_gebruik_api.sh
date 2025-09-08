#!/bin/bash

# Test script for AangebodenGebruik API endpoints
# This script tests the new custom objects endpoint for managing gebruiks objects

echo "==================================="
echo "Testing AangebodenGebruik API"
echo "==================================="

BASE_URL="http://localhost/index.php/apps/softwarecatalog/api/aangeboden-gebruik"
AUTH="admin:admin"

echo ""
echo "1. Testing API Documentation endpoint..."
echo "GET ${BASE_URL}/docs"
docker-compose exec nextcloud curl -s -X GET "${BASE_URL}/docs" \
  -H "Content-Type: application/json" \
  -u ${AUTH} | jq .

echo ""
echo "2. Testing Afnemer Gebruiks endpoint..."
echo "GET ${BASE_URL}/afnemer"
docker-compose exec nextcloud curl -s -X GET "${BASE_URL}/afnemer" \
  -H "Content-Type: application/json" \
  -u ${AUTH} | jq .

echo ""
echo "3. Testing Afnemer Gebruiks endpoint with limit..."
echo "GET ${BASE_URL}/afnemer?limit=5"
docker-compose exec nextcloud curl -s -X GET "${BASE_URL}/afnemer?limit=5" \
  -H "Content-Type: application/json" \
  -u ${AUTH} | jq .

echo ""
echo "4. Testing Deelnemers Gebruiks endpoint..."
echo "GET ${BASE_URL}/deelnemers"
docker-compose exec nextcloud curl -s -X GET "${BASE_URL}/deelnemers" \
  -H "Content-Type: application/json" \
  -u ${AUTH} | jq .

echo ""
echo "5. Testing Deelnemers Gebruiks endpoint with status filter..."
echo "GET ${BASE_URL}/deelnemers?status=actief"
docker-compose exec nextcloud curl -s -X GET "${BASE_URL}/deelnemers?status=actief" \
  -H "Content-Type: application/json" \
  -u ${AUTH} | jq .

echo ""
echo "6. Testing Set @self Property endpoint (will need a valid UUID)..."
echo "Note: Replace USAGE_UUID with an actual usage UUID from the previous responses"
echo "PUT ${BASE_URL}/USAGE_UUID/set-self"
echo "Example command (uncomment and replace UUID):"
echo "# docker-compose exec nextcloud curl -s -X PUT \"${BASE_URL}/USAGE_UUID/set-self\" \\"
echo "#   -H \"Content-Type: application/json\" \\"
echo "#   -u ${AUTH} | jq ."

echo ""
echo "==================================="
echo "Testing Complete"
echo "==================================="
echo ""
echo "To test the @self property update:"
echo "1. Look for a 'gebruikId' or 'id' in the afnemer response above"
echo "2. Replace USAGE_UUID in the curl command with that ID"
echo "3. Run the command manually"
echo ""
echo "Expected behaviors:"
echo "- Afnemer endpoint: Returns gebruiks where active org is the consumer"
echo "- Deelnemers endpoint: Returns gebruiks where active org is a participant"  
echo "- Set @self endpoint: Only works if active org is the afnemer"


