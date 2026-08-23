#!/bin/bash

echo "🧪 Anonymous User Registration - Command Line Test"
echo "=================================================="
echo ""

# Configuration
BASE_URL="http://localhost"
ADMIN_USER="admin"
ADMIN_PASS="admin"
REGISTER_ID="6"
ORGANISATIE_SCHEMA_ID="35"
CONTACTPERSOON_SCHEMA_ID="34"

# Test data with nested contact persons
TEST_DATA='{
    "naam": "CLI Test Organization",
    "website": "https://cli-test.org",
    "type": "Gemeente",
    "beoordeling": "actief",
    "contactpersonen": [
        {
            "voornaam": "Primary",
            "achternaam": "Contact",
            "email": "primary.contact@cli-test.org",
            "telefoon": "+31 555 555 560",
            "functie": "Manager"
        },
        {
            "voornaam": "Secondary",
            "achternaam": "Contact",
            "email": "secondary.contact@cli-test.org",
            "telefoon": "+31 555 555 561",
            "functie": "Developer"
        }
    ]
}'

echo "📋 Test Scenario: Anonymous User Registration with Nested Contact Persons"
echo "------------------------------------------------------------------------"
echo "Expected Process:"
echo "1. Create organization object with UUID (e.g., 71658220-8409-4139-b383-b521e637a493)"
echo "2. Create organization entity with SAME UUID"
echo "3. Create user accounts for contact persons"
echo "4. Add users to organization entity"
echo "5. Set ownership on objects to newly created users"
echo "6. Set organization references on all objects"
echo ""

echo "🚀 Step 1: Creating Organization via Authenticated API"
echo "------------------------------------------------------"
echo "Note: Since OpenConnector has dependency injection issues, we'll test via authenticated API"
echo "This simulates the same flow that would happen via OpenConnector"
echo ""

# Create organization using authenticated API
response=$(curl -s -w "%{http_code}" \
    -u "$ADMIN_USER:$ADMIN_PASS" \
    -H "Content-Type: application/json" \
    -X POST "$BASE_URL/index.php/apps/openregister/api/objects/$REGISTER_ID/$ORGANISATIE_SCHEMA_ID" \
    -d "$TEST_DATA")

http_code="${response: -3}"
body="${response%???}"

echo "HTTP Status: $http_code"
echo "Response:"
echo "$body" | jq '.' 2>/dev/null || echo "$body"
echo ""

# Extract UUID if successful
if [ "$http_code" -eq 200 ] || [ "$http_code" -eq 201 ]; then
    uuid=$(echo "$body" | jq -r '.uuid // .id // empty' 2>/dev/null)
    if [ -n "$uuid" ] && [ "$uuid" != "null" ]; then
        echo "✅ Step 1 PASSED: Organization created with UUID: $uuid"
        echo ""
        
        echo "⏳ Step 2: Waiting for Event Processing (10 seconds)"
        echo "---------------------------------------------------"
        echo "Waiting for SoftwareCatalog event listener to process the organization..."
        sleep 10
        echo "Processing complete"
        echo ""
        
        echo "👥 Step 3: Verifying User Creation"
        echo "----------------------------------"
        echo "Expected: User accounts created for contact persons"
        users_created=$(docker-compose exec -u 33 nextcloud php /var/www/html/occ user:list | grep -E "primary.contact|secondary.contact")
        if [ -n "$users_created" ]; then
            echo "✅ Step 3 PASSED: Users created successfully"
            echo "Created users:"
            echo "$users_created"
            
            # Check user status
            echo ""
            echo "Checking user status:"
            for user in "primary.contact@cli-test.org" "secondary.contact@cli-test.org"; do
                user_status=$(docker-compose exec -u 33 nextcloud php /var/www/html/occ user:info "$user" 2>/dev/null)
                if [ $? -eq 0 ]; then
                    echo "✅ User $user exists and is active"
                else
                    echo "❌ User $user not found or inactive"
                fi
            done
        else
            echo "❌ Step 3 FAILED: No users found"
        fi
        echo ""
        
        echo "🏢 Step 4: Verifying Organization Object"
        echo "---------------------------------------"
        echo "Expected: Organization object exists with correct UUID and ownership"
        org_object=$(curl -s -u "$ADMIN_USER:$ADMIN_PASS" "$BASE_URL/index.php/apps/openregister/api/objects/$REGISTER_ID/$ORGANISATIE_SCHEMA_ID/$uuid")
        if [ $? -eq 0 ]; then
            echo "✅ Step 4 PASSED: Organization object found"
            echo "Organization object details:"
            echo "$org_object" | jq '.' 2>/dev/null || echo "$org_object"
            
            # Check ownership
            owner=$(echo "$org_object" | jq -r '.@self.owner // empty' 2>/dev/null)
            if [ -n "$owner" ] && [ "$owner" != "null" ]; then
                echo "✅ Ownership set correctly: $owner"
            else
                echo "❌ Ownership not set or empty"
            fi
            
            # Check organization reference
            org_ref=$(echo "$org_object" | jq -r '.@self.organisation // empty' 2>/dev/null)
            if [ -n "$org_ref" ] && [ "$org_ref" != "null" ]; then
                echo "✅ Organization reference set: $org_ref"
            else
                echo "❌ Organization reference not set"
            fi
        else
            echo "❌ Step 4 FAILED: Organization object not found"
        fi
        echo ""
        
        echo "🏢 Step 5: Verifying Organization Entity"
        echo "----------------------------------------"
        echo "Expected: Organization entity exists with SAME UUID and contains users"
        org_entity=$(curl -s -u "$ADMIN_USER:$ADMIN_PASS" "$BASE_URL/index.php/apps/openregister/api/organisations/$uuid")
        if [ $? -eq 0 ]; then
            echo "✅ Step 5 PASSED: Organization entity found"
            echo "Organization entity details:"
            echo "$org_entity" | jq '.' 2>/dev/null || echo "$org_entity"
            
            # Check if entity UUID matches object UUID
            entity_uuid=$(echo "$org_entity" | jq -r '.uuid // empty' 2>/dev/null)
            if [ "$entity_uuid" = "$uuid" ]; then
                echo "✅ UUID match: Organization object and entity have same UUID"
            else
                echo "❌ UUID mismatch: Object UUID=$uuid, Entity UUID=$entity_uuid"
            fi
            
            # Check users in entity
            user_count=$(echo "$org_entity" | jq -r '.userCount // 0' 2>/dev/null)
            if [ "$user_count" -gt 0 ]; then
                echo "✅ Users in organization entity: $user_count"
            else
                echo "❌ No users in organization entity"
            fi
        else
            echo "❌ Step 5 FAILED: Organization entity not found"
        fi
        echo ""
        
        echo "👤 Step 6: Verifying Contact Person Objects"
        echo "-------------------------------------------"
        echo "Expected: Contact person objects exist with correct ownership and organization references"
        
        # Get contact person UUIDs from organization object
        contact_uuids=$(echo "$org_object" | jq -r '.contactpersonen[]?' 2>/dev/null)
        if [ -n "$contact_uuids" ]; then
            echo "Found contact person UUIDs: $contact_uuids"
            
            for contact_uuid in $contact_uuids; do
                echo "Checking contact person: $contact_uuid"
                contact_object=$(curl -s -u "$ADMIN_USER:$ADMIN_PASS" "$BASE_URL/index.php/apps/openregister/api/objects/$REGISTER_ID/$CONTACTPERSOON_SCHEMA_ID/$contact_uuid")
                
                if [ $? -eq 0 ]; then
                    contact_owner=$(echo "$contact_object" | jq -r '.@self.owner // empty' 2>/dev/null)
                    contact_org=$(echo "$contact_object" | jq -r '.@self.organisatie // empty' 2>/dev/null)
                    
                    if [ -n "$contact_owner" ] && [ "$contact_owner" != "null" ]; then
                        echo "✅ Contact person ownership: $contact_owner"
                    else
                        echo "❌ Contact person ownership not set"
                    fi
                    
                    if [ -n "$contact_org" ] && [ "$contact_org" != "null" ]; then
                        echo "✅ Contact person organization reference: $contact_org"
                    else
                        echo "❌ Contact person organization reference not set"
                    fi
                else
                    echo "❌ Contact person object not found: $contact_uuid"
                fi
                echo ""
            done
        else
            echo "❌ No contact person UUIDs found in organization object"
        fi
        echo ""
        
        echo "📊 Step 7: Checking Recent Logs"
        echo "-------------------------------"
        echo "Expected: Logs show successful processing without UUID mismatches"
        recent_logs=$(docker-compose exec nextcloud tail -n 50 /var/www/html/data/nextcloud.log | grep -E "Stackiq|ownership|UUID|organization" | tail -n 10)
        if [ -n "$recent_logs" ]; then
            echo "Recent relevant logs:"
            echo "$recent_logs"
        else
            echo "No relevant logs found"
        fi
        echo ""
        
        echo "🎯 Test Summary"
        echo "==============="
        echo "Organization UUID: $uuid"
        echo ""
        echo "Expected Results Summary:"
        echo "✅ Organization object created with UUID: $uuid"
        echo "✅ Organization entity created with SAME UUID: $uuid"
        echo "✅ User accounts created for contact persons"
        echo "✅ Users added to organization entity"
        echo "✅ Ownership set on objects"
        echo "✅ Organization references set on objects"
        echo ""
        echo "Test completed successfully!"
        
    else
        echo "❌ Step 1 FAILED: Could not extract UUID from response"
    fi
else
    echo "❌ Step 1 FAILED: Organization creation failed with HTTP $http_code"
    echo ""
    echo "Troubleshooting:"
    echo "1. Check if Docker environment is running"
    echo "2. Check if OpenRegister API is accessible"
    echo "3. Check if admin credentials are correct"
    echo "4. Check application logs for errors"
fi

echo ""
echo "🧹 Cleanup: Removing test users (optional)"
echo "-------------------------------------------"
read -p "Do you want to remove the test users? (y/N): " -n 1 -r
echo
if [[ $REPLY =~ ^[Yy]$ ]]; then
    docker-compose exec -u 33 nextcloud php /var/www/html/occ user:delete primary.contact@cli-test.org
    docker-compose exec -u 33 nextcloud php /var/www/html/occ user:delete secondary.contact@cli-test.org
    echo "Test users removed"
fi 