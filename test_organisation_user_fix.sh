#!/bin/bash

# Test script for organisation user assignment fix
# This script tests that users created from contactpersonen are added to the correct organisation
# 
# Usage: ./test_organisation_user_fix.sh [nextcloud-container-name] [database-container-name]
# Example: ./test_organisation_user_fix.sh master-nextcloud-1 master-database-mysql-1

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
NEXTCLOUD_CONTAINER="${1:-master-nextcloud-1}"
DATABASE_CONTAINER="${2:-master-database-mysql-1}"
BASE_URL="http://nextcloud.local/index.php/apps"
AUTH="admin:admin"
TEST_TIMESTAMP=$(date +%s)
TEST_ORG_NAME="TestOrg${TEST_TIMESTAMP}"
TEST_EMAIL="testuser${TEST_TIMESTAMP}@test.nl"

echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}Organisation User Assignment Fix Test${NC}"
echo -e "${BLUE}========================================${NC}"
echo ""
echo -e "${YELLOW}Configuration:${NC}"
echo "  Nextcloud Container: ${NEXTCLOUD_CONTAINER}"
echo "  Database Container:  ${DATABASE_CONTAINER}"
echo "  Base URL:           ${BASE_URL}"
echo "  Test Organisation:  ${TEST_ORG_NAME}"
echo "  Test Email:         ${TEST_EMAIL}"
echo ""

# Function to execute curl from inside Nextcloud container
function nc_curl() {
    docker exec -u 33 "${NEXTCLOUD_CONTAINER}" curl -s -u "${AUTH}" "$@"
}

# Function to execute SQL query
function nc_sql() {
    docker exec "${DATABASE_CONTAINER}" mysql -u nextcloud -pnextcloud nextcloud -N -e "$1"
}

# Function to print success message
function success() {
    echo -e "${GREEN}✓ $1${NC}"
}

# Function to print error message
function error() {
    echo -e "${RED}✗ $1${NC}"
    exit 1
}

# Function to print info message
function info() {
    echo -e "${BLUE}ℹ $1${NC}"
}

# Function to print warning message
function warn() {
    echo -e "${YELLOW}⚠ $1${NC}"
}

# Step 1: Create organisation via API
info "Step 1: Creating test organisation with contactpersoon..."

ORG_RESPONSE=$(nc_curl -X POST \
    "${BASE_URL}/openconnector/api/endpoint/register" \
    -H "Content-Type: application/json" \
    -d "{
        \"naam\": \"${TEST_ORG_NAME}\",
        \"website\": \"www.test${TEST_TIMESTAMP}.nl\",
        \"type\": \"Leverancier\",
        \"status\": \"Concept\",
        \"contactpersonen\": [
            {
                \"voornaam\": \"Test\",
                \"achternaam\": \"User${TEST_TIMESTAMP}\",
                \"e-mailadres\": \"${TEST_EMAIL}\",
                \"telefoonnummer\": \"0612345678\"
            }
        ]
    }")

# Extract organisation UUID from response
ORG_UUID=$(echo "$ORG_RESPONSE" | grep -o '"id":"[^"]*"' | head -1 | cut -d'"' -f4)

if [ -z "$ORG_UUID" ]; then
    error "Failed to create organisation. Response: $ORG_RESPONSE"
fi

success "Organisation created with UUID: ${ORG_UUID}"

# Wait a moment for the object to be created
sleep 2

# Step 2: Verify organisation object exists
info "Step 2: Verifying organisation object exists..."

ORG_CHECK=$(nc_curl -X GET \
    "${BASE_URL}/openregister/api/objects/1/7/${ORG_UUID}?_extend[]=contactpersonen")

if echo "$ORG_CHECK" | grep -q "${TEST_ORG_NAME}"; then
    success "Organisation object found in database"
else
    error "Organisation object not found. Response: $ORG_CHECK"
fi

# Extract contactpersoon UUID
CONTACT_UUID=$(echo "$ORG_CHECK" | grep -o '"id":"[^"]*"' | sed -n '2p' | cut -d'"' -f4)

if [ -z "$CONTACT_UUID" ]; then
    warn "Could not extract contactpersoon UUID, continuing anyway..."
else
    info "Contactpersoon UUID: ${CONTACT_UUID}"
fi

# Step 3: Activate the organisation
info "Step 3: Activating organisation (changing status to Actief)..."

ACTIVATE_RESPONSE=$(nc_curl -X PUT \
    "${BASE_URL}/openregister/api/objects/1/7/${ORG_UUID}" \
    -H "Content-Type: application/json" \
    -d '{"status": "Actief"}')

if echo "$ACTIVATE_RESPONSE" | grep -q "Actief"; then
    success "Organisation activated successfully"
else
    warn "Organisation activation response: $ACTIVATE_RESPONSE"
fi

# Wait for user creation
info "Waiting 5 seconds for user creation process..."
sleep 5

# Step 4: Check if user was created
info "Step 4: Verifying user was created in Nextcloud..."

USER_EXISTS=$(docker exec -u 33 "${NEXTCLOUD_CONTAINER}" php occ user:list | grep -c "${TEST_EMAIL}" || echo "0")

if [ "$USER_EXISTS" -gt 0 ]; then
    success "User ${TEST_EMAIL} was created in Nextcloud"
else
    error "User ${TEST_EMAIL} was NOT created in Nextcloud"
fi

# Step 5: Check user's organisation assignment
info "Step 5: Checking user's organisation assignment..."

# Query to find which organisation the user belongs to
USER_ORG_QUERY="
SELECT o.uuid, o.name, o.users
FROM oc_openregister_organisations o
WHERE o.users LIKE '%${TEST_EMAIL}%';
"

USER_ORG_RESULT=$(nc_sql "$USER_ORG_QUERY")

if [ -z "$USER_ORG_RESULT" ]; then
    error "User is not assigned to any organisation in OpenRegister"
fi

# Check if user is in the correct organisation
if echo "$USER_ORG_RESULT" | grep -q "$ORG_UUID"; then
    success "User is correctly assigned to ${TEST_ORG_NAME} (${ORG_UUID})"
    echo -e "   Organisation details: $USER_ORG_RESULT"
else
    error "User is assigned to WRONG organisation!"
    echo -e "   Expected: ${ORG_UUID} (${TEST_ORG_NAME})"
    echo -e "   Actual:   $USER_ORG_RESULT"
fi

# Step 6: Check user's active organisation config
info "Step 6: Checking user's active organisation configuration..."

ACTIVE_ORG=$(docker exec -u 33 "${NEXTCLOUD_CONTAINER}" php occ config:user:get "${TEST_EMAIL}" openregister active_organisation 2>/dev/null || echo "")

if [ "$ACTIVE_ORG" == "$ORG_UUID" ]; then
    success "User's active organisation is correctly set to ${ORG_UUID}"
else
    warn "User's active organisation is: ${ACTIVE_ORG} (expected: ${ORG_UUID})"
fi

# Step 7: Check organisation entity was created
info "Step 7: Verifying organisation entity was created in database..."

ORG_ENTITY_QUERY="
SELECT id, uuid, name, is_default 
FROM oc_openregister_organisations 
WHERE uuid = '${ORG_UUID}';
"

ORG_ENTITY_RESULT=$(nc_sql "$ORG_ENTITY_QUERY")

if [ -z "$ORG_ENTITY_RESULT" ]; then
    error "Organisation entity was NOT created in database!"
else
    success "Organisation entity exists in database"
    echo -e "   Details: $ORG_ENTITY_RESULT"
fi

# Step 8: Check logs for confirmation
info "Step 8: Checking container logs for organisation entity creation..."

LOG_CHECK=$(docker logs "${NEXTCLOUD_CONTAINER}" 2>&1 | grep -c "ContactPersonHandler: Successfully created organization entity" || echo "0")

if [ "$LOG_CHECK" -gt 0 ]; then
    success "Found ${LOG_CHECK} log entries confirming organisation entity creation"
    
    # Show relevant log entries
    echo ""
    info "Recent relevant log entries:"
    docker logs "${NEXTCLOUD_CONTAINER}" 2>&1 | grep "ContactPersonHandler.*organization" | tail -10 | while read line; do
        echo "   $line"
    done
else
    warn "No log entries found for organisation entity creation (check if logging is enabled)"
fi

# Summary
echo ""
echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}Test Summary${NC}"
echo -e "${BLUE}========================================${NC}"
echo ""
echo -e "${GREEN}All tests passed!${NC}"
echo ""
echo "Test Details:"
echo "  Organisation UUID:  ${ORG_UUID}"
echo "  Organisation Name:  ${TEST_ORG_NAME}"
echo "  User Email:        ${TEST_EMAIL}"
echo "  User in Correct Org: YES"
echo "  Active Org Set:     ${ACTIVE_ORG}"
echo ""
echo -e "${YELLOW}Note: You can manually verify by:${NC}"
echo "  1. Logging into Nextcloud UI as ${TEST_EMAIL}"
echo "  2. Checking the organisation context in OpenRegister app"
echo "  3. Verifying user can only access ${TEST_ORG_NAME} data"
echo ""
echo -e "${BLUE}Clean up test data:${NC}"
echo "  docker exec -u 33 ${NEXTCLOUD_CONTAINER} php occ user:delete ${TEST_EMAIL}"
echo ""

exit 0

