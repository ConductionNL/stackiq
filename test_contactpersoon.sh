#!/bin/bash

# Test script for contactpersoon functionality
# This script tests the new contactpersoon object creation and user management

echo "🧪 Testing SoftwareCatalog contactpersoon functionality..."

# Configuration
CONTAINER_NAME="master-nextcloud-1"
REGISTER_ID="6"
CONTACTPERSOON_SCHEMA_ID="34"  # This should be updated to the actual contactpersoon schema ID
ADMIN_USER="admin"
ADMIN_PASS="admin"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${YELLOW}Step 1: Checking container status...${NC}"
if ! docker ps | grep -q $CONTAINER_NAME; then
    echo -e "${RED}❌ Container $CONTAINER_NAME is not running${NC}"
    exit 1
fi
echo -e "${GREEN}✅ Container is running${NC}"

echo -e "${YELLOW}Step 2: Testing contactpersoon creation...${NC}"

# Create a test contactpersoon
CONTACTPERSOON_DATA='{
    "voornaam": "Jan",
    "achternaam": "Testpersoon",
    "email": "jan.testpersoon@example.com",
    "telefoon": "06-12345678",
    "functie": "Testmanager",
    "roles": ["beheerder"]
}'

echo "Creating contactpersoon with data: $CONTACTPERSOON_DATA"

# Execute the API call inside the container
CREATE_RESULT=$(docker exec -it -u 33 $CONTAINER_NAME bash -c "
curl -s -u '$ADMIN_USER:$ADMIN_PASS' \
     -H 'OCS-APIREQUEST: true' \
     -H 'Content-Type: application/json' \
     -X POST \
     'http://localhost/index.php/apps/openregister/api/objects/$REGISTER_ID/$CONTACTPERSOON_SCHEMA_ID' \
     -d '$CONTACTPERSOON_DATA'
")

echo "API Response: $CREATE_RESULT"

# Check if creation was successful (look for UUID in response)
if echo "$CREATE_RESULT" | grep -q '"id":"[a-f0-9-]*"'; then
    echo -e "${GREEN}✅ Contactpersoon created successfully${NC}"
    
    # Extract UUID from response
    CONTACTPERSOON_UUID=$(echo "$CREATE_RESULT" | grep -o '"id":"[^"]*"' | cut -d'"' -f4)
    echo "Created contactpersoon with UUID: $CONTACTPERSOON_UUID"
    
    # Test if user was created
    echo -e "${YELLOW}Step 3: Checking if user was created...${NC}"
    
    # Check for username in the created object
    GET_RESULT=$(docker exec -it -u 33 $CONTAINER_NAME bash -c "
    curl -s -u '$ADMIN_USER:$ADMIN_PASS' \
         -H 'OCS-APIREQUEST: true' \
         'http://localhost/index.php/apps/openregister/api/objects/$REGISTER_ID/$CONTACTPERSOON_SCHEMA_ID/$CONTACTPERSOON_UUID'
    ")
    
    echo "Retrieved object: $GET_RESULT"
    
    # Check if username was added
    if echo "$GET_RESULT" | grep -q '"username"'; then
        USERNAME=$(echo "$GET_RESULT" | grep -o '"username":"[^"]*"' | cut -d'"' -f4)
        echo -e "${GREEN}✅ Username created: $USERNAME${NC}"
        
        # Check if user exists in Nextcloud
        echo -e "${YELLOW}Step 4: Checking if user exists in Nextcloud...${NC}"
        
        USER_EXISTS=$(docker exec -it -u 33 $CONTAINER_NAME bash -c "
        php /var/www/html/occ user:info $USERNAME 2>/dev/null
        ")
        
        if [ $? -eq 0 ]; then
            echo -e "${GREEN}✅ User exists in Nextcloud${NC}"
            echo "$USER_EXISTS"
            
            # Check if user is inactive
            if echo "$USER_EXISTS" | grep -q "enabled.*false"; then
                echo -e "${GREEN}✅ User is correctly set to inactive${NC}"
            else
                echo -e "${YELLOW}⚠️  User status unclear - check manually${NC}"
            fi
        else
            echo -e "${RED}❌ User not found in Nextcloud${NC}"
        fi
    else
        echo -e "${RED}❌ No username found in contactpersoon object${NC}"
    fi
else
    echo -e "${RED}❌ Failed to create contactpersoon${NC}"
    echo "Response: $CREATE_RESULT"
fi

echo -e "${YELLOW}Step 5: Checking logs for SoftwareCatalog events...${NC}"

# Check recent logs for SoftwareCatalog events
RECENT_LOGS=$(docker logs $CONTAINER_NAME --since 5m 2>&1 | grep -i "softwarecatalog" | tail -20)

if [ -n "$RECENT_LOGS" ]; then
    echo -e "${GREEN}✅ Found SoftwareCatalog log entries:${NC}"
    echo "$RECENT_LOGS"
else
    echo -e "${YELLOW}⚠️  No recent SoftwareCatalog log entries found${NC}"
fi

echo -e "${YELLOW}Step 6: Testing configuration retrieval...${NC}"

# Test the settings API
SETTINGS_RESULT=$(docker exec -it -u 33 $CONTAINER_NAME bash -c "
curl -s -u '$ADMIN_USER:$ADMIN_PASS' \
     -H 'OCS-APIREQUEST: true' \
     -H 'Content-Type: application/json' \
     'http://localhost/index.php/apps/softwarecatalog/api/settings' 2>/dev/null || echo '{\"error\":\"API not accessible\"}'
")

echo "Settings API Response: $SETTINGS_RESULT"

if echo "$SETTINGS_RESULT" | grep -q 'contactpersoon'; then
    echo -e "${GREEN}✅ Contactpersoon configuration found in settings${NC}"
else
    echo -e "${YELLOW}⚠️  Contactpersoon configuration not found - may be using contactgegevens${NC}"
fi

echo -e "${YELLOW}Testing complete!${NC}" 