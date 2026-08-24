#!/bin/bash

echo "🧪 Testing Anonymous User Registration with Fixes..."

# Test data
TEST_DATA='{
    "naam": "Test Fix Organization",
    "website": "https://test-fix.org",
    "type": "Gemeente",
    "beoordeling": "actief",
    "contactpersonen": [
        {
            "voornaam": "Test",
            "achternaam": "Fix",
            "email": "test.fix@test.org",
            "telefoon": "+31 555 555 557",
            "functie": "Manager"
        }
    ]
}'

# Make the request
echo "Sending anonymous registration request..."
response=$(curl -s -w "%{http_code}" \
    -X POST "http://nextcloud.local/index.php/apps/openconnector/api/endpoint/register" \
    -H "Content-Type: application/json" \
    -d "$TEST_DATA")

http_code="${response: -3}"
body="${response%???}"

echo "HTTP Status: $http_code"
echo "Response:"
echo "$body" | jq '.' 2>/dev/null || echo "$body"

# Extract UUID if successful
if [ "$http_code" -eq 200 ] || [ "$http_code" -eq 201 ]; then
    uuid=$(echo "$body" | jq -r '.uuid // .id // empty' 2>/dev/null)
    if [ -n "$uuid" ] && [ "$uuid" != "null" ]; then
        echo "✅ Organization created with UUID: $uuid"
        
        # Wait a moment for processing
        echo "Waiting for processing..."
        sleep 5
        
        # Check if users were created
        echo "Checking for created users..."
        docker-compose exec -u 33 nextcloud php /var/www/html/occ user:list | grep -E "test.fix|Test Fix"
        
        # Check organization object
        echo "Checking organization object..."
        curl -s -u 'admin:admin' "http://localhost/index.php/apps/openregister/api/objects/6/35/$uuid" | jq '.'
        
        # Check organization entity
        echo "Checking organization entity..."
        curl -s -u 'admin:admin' "http://localhost/index.php/apps/openregister/api/organisations/$uuid" | jq '.'
        
        # Check object ownership
        echo "Checking object ownership..."
        curl -s -u 'admin:admin' "http://localhost/index.php/apps/openregister/api/objects/6/35/$uuid" | jq '.@self.owner'
        
        # Check logs for any errors
        echo "Checking recent logs..."
        docker-compose exec nextcloud tail -n 20 /var/www/html/data/nextcloud.log | grep -E "Stackiq|ownership|UUID"
        
    else
        echo "❌ Could not extract UUID from response"
    fi
else
    echo "❌ Registration failed with HTTP $http_code"
fi 