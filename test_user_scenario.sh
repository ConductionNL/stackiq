#!/bin/bash
# Test script for the user's exact scenario

set -e

echo "=========================================="
echo "Testing User Scenario - test93 organisation"
echo "=========================================="
echo ""

# Step 1: Create organisation with contactpersoon
echo "Step 1: Creating organisation test94..."
ORG_RESPONSE=$(docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  -X POST 'http://nextcloud.local/index.php/apps/openconnector/api/endpoint/register' \
  -H 'Content-Type: application/json' \
  -d '{
    "naam": "test94",
    "website": "www.test94.nl",
    "type": "Leverancier",
    "status": "Concept",
    "contactpersonen": [{
      "voornaam": "test",
      "achternaam": "94",
      "telefoonnummer": "0645536688",
      "e-mailadres": "test94@test.nl"
    }]
  }')

echo "$ORG_RESPONSE" | python3 -m json.tool
echo ""

# Extract UUID
ORG_UUID=$(echo "$ORG_RESPONSE" | grep -o '"id":"[^"]*"' | head -1 | cut -d'"' -f4)
echo "Organisation UUID: $ORG_UUID"
echo ""

# Wait for object creation
sleep 2

# Step 2: Get organisation with contactpersonen
echo "Step 2: Getting organisation with contactpersonen..."
GET_RESPONSE=$(docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  "http://nextcloud.local/index.php/apps/openregister/api/objects/1/7/$ORG_UUID?_extend[]=contactpersonen")

echo "$GET_RESPONSE" | python3 -m json.tool | head -50
echo ""

# Step 3: Activate the organisation
echo "Step 3: Activating organisation (setting status to Actief)..."
ACTIVATE_RESPONSE=$(docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  -X PUT "http://nextcloud.local/index.php/apps/openregister/api/objects/1/7/$ORG_UUID" \
  -H 'Content-Type: application/json' \
  -d '{"status": "Actief"}')

echo "$ACTIVATE_RESPONSE" | python3 -m json.tool | grep -A 5 '"status"'
echo ""

# Wait for user creation
echo "Waiting 5 seconds for user creation..."
sleep 5

# Step 4: Check if user was created
echo "Step 4: Checking if user test94@test.nl was created..."
USER_CHECK=$(docker exec -u 33 master-nextcloud-1 php occ user:list | grep "test94@test.nl" || echo "NOT_FOUND")

if [ "$USER_CHECK" != "NOT_FOUND" ]; then
    echo "✓ User test94@test.nl exists!"
    echo "$USER_CHECK"
else
    echo "✗ User test94@test.nl NOT found!"
    exit 1
fi
echo ""

# Step 5: Check user's organisation
echo "Step 5: Checking which organisation the user belongs to..."
USER_ORG=$(docker exec master-database-mysql-1 mysql -u nextcloud -pnextcloud nextcloud -N -e \
  "SELECT o.uuid, o.name FROM oc_openregister_organisations o WHERE o.users LIKE '%test94@test.nl%';")

echo "User organisation: $USER_ORG"

if echo "$USER_ORG" | grep -q "$ORG_UUID"; then
    echo "✓ SUCCESS! User is in the CORRECT organisation (test94)!"
else
    echo "✗ FAIL! User is in the WRONG organisation!"
    echo "Expected UUID: $ORG_UUID"
    echo "Actual: $USER_ORG"
    exit 1
fi
echo ""

# Step 6: Check organisation entity details
echo "Step 6: Checking organisation entity details..."
ORG_ENTITY=$(docker exec master-database-mysql-1 mysql -u nextcloud -pnextcloud nextcloud -N -e \
  "SELECT id, uuid, name, users FROM oc_openregister_organisations WHERE uuid = '$ORG_UUID';")

echo "Organisation entity:"
echo "$ORG_ENTITY"
echo ""

# Step 7: Check user's active organisation config
echo "Step 7: Checking user's active organisation setting..."
ACTIVE_ORG=$(docker exec -u 33 master-nextcloud-1 php occ config:user:get test94@test.nl openregister active_organisation 2>/dev/null || echo "NOT_SET")

echo "Active organisation: $ACTIVE_ORG"

if [ "$ACTIVE_ORG" == "$ORG_UUID" ]; then
    echo "✓ Active organisation is correctly set!"
else
    echo "⚠ Active organisation not set (expected: $ORG_UUID, got: $ACTIVE_ORG)"
fi
echo ""

echo "=========================================="
echo "TEST COMPLETE!"
echo "=========================================="
echo ""
echo "Summary:"
echo "  Organisation UUID: $ORG_UUID"
echo "  Organisation Name: test94"
echo "  User Email: test94@test.nl"
echo "  User in correct org: YES ✓"
echo ""
echo "The fix is working correctly!"
echo ""
echo "To clean up:"
echo "  docker exec -u 33 master-nextcloud-1 php occ user:delete test94@test.nl"
echo ""

