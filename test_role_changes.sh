#!/bin/bash

# Test Script for Role Changes and Group Membership Updates
# This script specifically tests adding/removing roles and the corresponding group changes

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
DOCKER_CMD="docker-compose exec nextcloud"
BASE_URL="http://localhost/index.php/apps/openregister/api/objects"
REGISTER_ID="6"
ORGANIZATION_SCHEMA_ID="35"
CONTACTGEGEVENS_SCHEMA_ID="34"
AUTH="-u admin:admin"
HEADERS="-H 'Content-Type: application/json'"

echo -e "${BLUE}=============================================="
echo -e "Role Changes and Group Membership Test"
echo -e "=============================================="
echo -e "${NC}"

# Check if organization ID is provided
if [ -z "$1" ]; then
    echo -e "${RED}Usage: $0 <ORGANIZATION_ID>${NC}"
    echo -e "${YELLOW}Please provide an organization ID as the first parameter${NC}"
    exit 1
fi

ORG_ID="$1"
echo -e "${BLUE}Using Organization ID: $ORG_ID${NC}"

# Create test user with minimal roles
echo -e "${YELLOW}[STEP 1]${NC} Creating test user with minimal roles"
TEST_USER_RESPONSE=$(eval $DOCKER_CMD curl -s -X POST "$BASE_URL/$REGISTER_ID/$CONTACTGEGEVENS_SCHEMA_ID" $HEADERS $AUTH -d '{
  "voornaam": "Role",
  "achternaam": "Tester",
  "email": "role.tester@example.com",
  "roles": ["Gebruik-raadpleger"],
  "organisation": "'$ORG_ID'",
  "title": "Role Tester"
}')

USER_ID=$(echo "$TEST_USER_RESPONSE" | grep -o '"id":"[^"]*"' | cut -d'"' -f4)
echo -e "${GREEN}Test user created with ID: $USER_ID${NC}"

sleep 3

# Check initial groups
echo -e "${YELLOW}[STEP 2]${NC} Checking initial group memberships"
$DOCKER_CMD occ user:info role.tester 2>/dev/null || echo "User not found yet"

# Add more roles
echo -e "${YELLOW}[STEP 3]${NC} Adding multiple roles"
UPDATE_RESPONSE=$(eval $DOCKER_CMD curl -s -X PUT "$BASE_URL/$REGISTER_ID/$CONTACTGEGEVENS_SCHEMA_ID/$USER_ID" $HEADERS $AUTH -d '{
  "id": "'$USER_ID'",
  "voornaam": "Role",
  "achternaam": "Tester",
  "email": "role.tester@example.com",
  "roles": ["Gebruik-raadpleger", "Aanbod-beheerder", "Functioneel-beheerder", "Gebruik-beheerder"],
  "organisation": "'$ORG_ID'",
  "title": "Role Tester"
}')

echo -e "${GREEN}Roles updated - added multiple roles${NC}"
sleep 3

# Check groups after adding roles
echo -e "${YELLOW}[STEP 4]${NC} Checking group memberships after adding roles"
$DOCKER_CMD occ user:info role.tester 2>/dev/null || echo "User not found"

# Remove some roles
echo -e "${YELLOW}[STEP 5]${NC} Removing some roles"
REMOVE_RESPONSE=$(eval $DOCKER_CMD curl -s -X PUT "$BASE_URL/$REGISTER_ID/$CONTACTGEGEVENS_SCHEMA_ID/$USER_ID" $HEADERS $AUTH -d '{
  "id": "'$USER_ID'",
  "voornaam": "Role",
  "achternaam": "Tester",
  "email": "role.tester@example.com",
  "roles": ["Gebruik-raadpleger"],
  "organisation": "'$ORG_ID'",
  "title": "Role Tester"
}')

echo -e "${GREEN}Roles updated - removed most roles${NC}"
sleep 3

# Check groups after removing roles
echo -e "${YELLOW}[STEP 6]${NC} Checking group memberships after removing roles"
$DOCKER_CMD occ user:info role.tester 2>/dev/null || echo "User not found"

# Test different role combinations
echo -e "${YELLOW}[STEP 7]${NC} Testing different role combinations"

# Admin roles
UPDATE_ADMIN_RESPONSE=$(eval $DOCKER_CMD curl -s -X PUT "$BASE_URL/$REGISTER_ID/$CONTACTGEGEVENS_SCHEMA_ID/$USER_ID" $HEADERS $AUTH -d '{
  "id": "'$USER_ID'",
  "voornaam": "Role",
  "achternaam": "Tester",
  "email": "role.tester@example.com",
  "roles": ["Organisatie-beheerder", "Functioneel-beheerder"],
  "organisation": "'$ORG_ID'",
  "title": "Role Tester"
}')

echo -e "${GREEN}Updated to admin roles${NC}"
sleep 3

# Check admin group memberships
echo -e "${YELLOW}[STEP 8]${NC} Checking admin group memberships"
$DOCKER_CMD occ user:info role.tester 2>/dev/null || echo "User not found"

# Clean up - delete the test user
echo -e "${YELLOW}[STEP 9]${NC} Cleaning up - deleting test user"
DELETE_RESPONSE=$(eval $DOCKER_CMD curl -s -X DELETE "$BASE_URL/$REGISTER_ID/$CONTACTGEGEVENS_SCHEMA_ID/$USER_ID" $HEADERS $AUTH)

echo -e "${GREEN}Test user deleted${NC}"
sleep 3

# Check if user is disabled
echo -e "${YELLOW}[STEP 10]${NC} Checking if user account is disabled"
$DOCKER_CMD occ user:info role.tester 2>/dev/null || echo "User not found or disabled"

# Summary
echo -e "${BLUE}=============================================="
echo -e "Role Changes Test Summary"
echo -e "=============================================="
echo -e "${NC}"
echo -e "Test User ID: ${GREEN}$USER_ID${NC}"
echo -e ""
echo -e "${YELLOW}Expected Results:${NC}"
echo -e "1. ✓ User created with minimal roles"
echo -e "2. ✓ User added to additional role groups"
echo -e "3. ✓ User removed from role groups when roles removed"
echo -e "4. ✓ User assigned to admin groups with admin roles"
echo -e "5. ✓ User account disabled when contactgegevens deleted"
echo -e ""
echo -e "${BLUE}Check the logs above for group membership changes${NC}"
echo -e "${BLUE}=============================================="
echo -e "${NC}" 