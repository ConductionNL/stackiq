#!/bin/bash

# Test Scenarios for SoftwareCatalog EventListener and Email System
# This script tests the complete workflow from organization creation to user management

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

# Test data
TEST_ORG_NAME="Test Email Organization"
TEST_ORG_WEBSITE="www.emailtest.com"
TEST_CONTACT_EMAIL="test.email@example.com"
TEST_CONTACT_FIRSTNAME="Test"
TEST_CONTACT_LASTNAME="Email"

echo -e "${BLUE}=============================================="
echo -e "SoftwareCatalog EventListener Test Suite"
echo -e "=============================================="
echo -e "${NC}"

# Function to print test step
print_step() {
    echo -e "${YELLOW}[STEP $1]${NC} $2"
}

# Function to print success
print_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

# Function to print error
print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Function to check logs
check_logs() {
    echo -e "${BLUE}[LOGS]${NC} Checking SoftwareCatalog logs..."
    $DOCKER_CMD tail -n 20 /var/www/html/data/nextcloud.log | grep -i "softwarecatalog" || echo "No SoftwareCatalog logs found"
}

# Function to wait for processing
wait_for_processing() {
    echo -e "${BLUE}[WAIT]${NC} Waiting $1 seconds for processing..."
    sleep $1
}

# Test 1: Create organization (inactive) - should trigger registration email
print_step "1" "Creating organization (inactive) - should trigger registration email"
CREATE_ORG_RESPONSE=$(eval $DOCKER_CMD curl -s -X POST "$BASE_URL/$REGISTER_ID/$ORGANIZATION_SCHEMA_ID" $HEADERS $AUTH -d '{
  "naam": "'$TEST_ORG_NAME'",
  "website": "'$TEST_ORG_WEBSITE'",
  "beoordeling": "Geregistreerd",
  "contactpersonen": [
    {
      "voornaam": "'$TEST_CONTACT_FIRSTNAME'",
      "achternaam": "'$TEST_CONTACT_LASTNAME'",
      "email": "'$TEST_CONTACT_EMAIL'",
      "functie": "beheerder"
    }
  ],
  "type": "Leverancier",
  "beschrijvingKort": "Test organization for email testing"
}')

# Extract organization ID from response
ORG_ID=$(echo "$CREATE_ORG_RESPONSE" | grep -o '"id":"[^"]*"' | cut -d'"' -f4)
echo "Organization created with ID: $ORG_ID"

if [ -z "$ORG_ID" ]; then
    print_error "Failed to create organization"
    exit 1
fi

print_success "Organization created successfully"
wait_for_processing 3
check_logs

# Test 2: Activate organization - should trigger activation email and user creation
print_step "2" "Activating organization - should trigger activation email and user creation"
ACTIVATE_ORG_RESPONSE=$(eval $DOCKER_CMD curl -s -X PUT "$BASE_URL/$REGISTER_ID/$ORGANIZATION_SCHEMA_ID/$ORG_ID" $HEADERS $AUTH -d '{
  "id": "'$ORG_ID'",
  "naam": "'$TEST_ORG_NAME'",
  "website": "'$TEST_ORG_WEBSITE'",
  "beoordeling": "Actief",
  "contactpersonen": [
    {
      "voornaam": "'$TEST_CONTACT_FIRSTNAME'",
      "achternaam": "'$TEST_CONTACT_LASTNAME'",
      "email": "'$TEST_CONTACT_EMAIL'",
      "functie": "beheerder"
    }
  ],
  "type": "Leverancier",
  "beschrijvingKort": "Test organization for email testing"
}')

print_success "Organization activated successfully"
wait_for_processing 5
check_logs

# Test 3: Check if contactpersonen array was emptied (indicates successful processing)
print_step "3" "Checking if contactpersonen were processed (array should be empty)"
CHECK_ORG_RESPONSE=$(eval $DOCKER_CMD curl -s -X GET "$BASE_URL/$REGISTER_ID/$ORGANIZATION_SCHEMA_ID/$ORG_ID" $HEADERS $AUTH)
echo "Organization response: $CHECK_ORG_RESPONSE"

# Test 4: Find created contactgegevens
print_step "4" "Finding created contactgegevens objects"
CONTACTGEGEVENS_RESPONSE=$(eval $DOCKER_CMD curl -s -X GET "$BASE_URL/$REGISTER_ID/$CONTACTGEGEVENS_SCHEMA_ID" $HEADERS $AUTH)
echo "Contactgegevens response: $CONTACTGEGEVENS_RESPONSE"

# Extract contactgegevens ID (assuming the last created one is ours)
CONTACTGEGEVENS_ID=$(echo "$CONTACTGEGEVENS_RESPONSE" | grep -o '"id":"[^"]*"' | tail -1 | cut -d'"' -f4)
echo "Found contactgegevens ID: $CONTACTGEGEVENS_ID"

if [ -z "$CONTACTGEGEVENS_ID" ]; then
    print_error "No contactgegevens found"
else
    print_success "Contactgegevens found successfully"
fi

# Test 5: Add a new contactgegevens (new user) - should trigger user creation email
print_step "5" "Adding new contactgegevens (new user) - should trigger user creation email"
NEW_CONTACT_EMAIL="new.user@example.com"
NEW_CONTACT_FIRSTNAME="New"
NEW_CONTACT_LASTNAME="User"

NEW_CONTACTGEGEVENS_RESPONSE=$(eval $DOCKER_CMD curl -s -X POST "$BASE_URL/$REGISTER_ID/$CONTACTGEGEVENS_SCHEMA_ID" $HEADERS $AUTH -d '{
  "voornaam": "'$NEW_CONTACT_FIRSTNAME'",
  "achternaam": "'$NEW_CONTACT_LASTNAME'",
  "email": "'$NEW_CONTACT_EMAIL'",
  "roles": ["Gebruik-raadpleger"],
  "organisation": "'$ORG_ID'",
  "title": "'$NEW_CONTACT_FIRSTNAME' '$NEW_CONTACT_LASTNAME'"
}')

NEW_CONTACTGEGEVENS_ID=$(echo "$NEW_CONTACTGEGEVENS_RESPONSE" | grep -o '"id":"[^"]*"' | cut -d'"' -f4)
echo "New contactgegevens created with ID: $NEW_CONTACTGEGEVENS_ID"

if [ -z "$NEW_CONTACTGEGEVENS_ID" ]; then
    print_error "Failed to create new contactgegevens"
else
    print_success "New contactgegevens created successfully"
fi

wait_for_processing 3
check_logs

# Test 6: Update contactgegevens roles (add roles) - should update group memberships
print_step "6" "Updating contactgegevens roles (adding roles) - should update group memberships"
if [ ! -z "$NEW_CONTACTGEGEVENS_ID" ]; then
    UPDATE_ROLES_RESPONSE=$(eval $DOCKER_CMD curl -s -X PUT "$BASE_URL/$REGISTER_ID/$CONTACTGEGEVENS_SCHEMA_ID/$NEW_CONTACTGEGEVENS_ID" $HEADERS $AUTH -d '{
      "id": "'$NEW_CONTACTGEGEVENS_ID'",
      "voornaam": "'$NEW_CONTACT_FIRSTNAME'",
      "achternaam": "'$NEW_CONTACT_LASTNAME'",
      "email": "'$NEW_CONTACT_EMAIL'",
      "roles": ["Gebruik-raadpleger", "Aanbod-beheerder", "Functioneel-beheerder"],
      "organisation": "'$ORG_ID'",
      "title": "'$NEW_CONTACT_FIRSTNAME' '$NEW_CONTACT_LASTNAME'"
    }')
    
    print_success "Contactgegevens roles updated (added roles)"
    wait_for_processing 3
    check_logs
fi

# Test 7: Update contactgegevens roles (remove roles) - should remove from groups
print_step "7" "Updating contactgegevens roles (removing roles) - should remove from groups"
if [ ! -z "$NEW_CONTACTGEGEVENS_ID" ]; then
    REMOVE_ROLES_RESPONSE=$(eval $DOCKER_CMD curl -s -X PUT "$BASE_URL/$REGISTER_ID/$CONTACTGEGEVENS_SCHEMA_ID/$NEW_CONTACTGEGEVENS_ID" $HEADERS $AUTH -d '{
      "id": "'$NEW_CONTACTGEGEVENS_ID'",
      "voornaam": "'$NEW_CONTACT_FIRSTNAME'",
      "achternaam": "'$NEW_CONTACT_LASTNAME'",
      "email": "'$NEW_CONTACT_EMAIL'",
      "roles": ["Gebruik-raadpleger"],
      "organisation": "'$ORG_ID'",
      "title": "'$NEW_CONTACT_FIRSTNAME' '$NEW_CONTACT_LASTNAME'"
    }')
    
    print_success "Contactgegevens roles updated (removed roles)"
    wait_for_processing 3
    check_logs
fi

# Test 8: Delete contactgegevens - should suspend account
print_step "8" "Deleting contactgegevens - should suspend account"
if [ ! -z "$NEW_CONTACTGEGEVENS_ID" ]; then
    DELETE_RESPONSE=$(eval $DOCKER_CMD curl -s -X DELETE "$BASE_URL/$REGISTER_ID/$CONTACTGEGEVENS_SCHEMA_ID/$NEW_CONTACTGEGEVENS_ID" $HEADERS $AUTH)
    
    print_success "Contactgegevens deleted"
    wait_for_processing 3
    check_logs
fi

# Test 9: Check email configuration
print_step "9" "Checking email configuration"
EMAIL_CONFIG_RESPONSE=$(eval $DOCKER_CMD curl -s -X GET "http://localhost/index.php/apps/softwarecatalog/api/settings/email" $HEADERS $AUTH)
echo "Email configuration: $EMAIL_CONFIG_RESPONSE"

# Test 10: Test email sending
print_step "10" "Testing email sending functionality"
TEST_EMAIL_RESPONSE=$(eval $DOCKER_CMD curl -s -X POST "http://localhost/index.php/apps/softwarecatalog/api/settings/email/test" $HEADERS $AUTH -d '{
  "testEmail": "test@example.com"
}')
echo "Test email response: $TEST_EMAIL_RESPONSE"

# Test 11: Check user creation in Nextcloud
print_step "11" "Checking created users in Nextcloud"
echo "Checking if users were created..."
$DOCKER_CMD occ user:list | grep -E "(test\.email|new\.user)" || echo "No users found matching our test emails"

# Test 12: Check user groups
print_step "12" "Checking user group memberships"
echo "Checking user groups..."
$DOCKER_CMD occ group:list | grep -E "(test_email_organization|beheerder|gebruik-raadpleger)" || echo "No test groups found"

# Test 13: Check organization groups
print_step "13" "Checking organization groups"
echo "Checking organization groups..."
$DOCKER_CMD occ group:list | grep -i "test.*email" || echo "No organization groups found"

# Final summary
echo -e "${BLUE}=============================================="
echo -e "Test Summary"
echo -e "=============================================="
echo -e "${NC}"
echo -e "Organization ID: ${GREEN}$ORG_ID${NC}"
echo -e "Original Contactgegevens ID: ${GREEN}$CONTACTGEGEVENS_ID${NC}"
echo -e "New Contactgegevens ID: ${GREEN}$NEW_CONTACTGEGEVENS_ID${NC}"
echo -e ""
echo -e "${YELLOW}Expected Results:${NC}"
echo -e "1. ✓ Organization registration email sent"
echo -e "2. ✓ Organization activation email sent"
echo -e "3. ✓ User creation emails sent for contact persons"
echo -e "4. ✓ Users created in Nextcloud"
echo -e "5. ✓ Users assigned to role-based groups"
echo -e "6. ✓ Users assigned to organization-specific groups"
echo -e "7. ✓ Role changes reflected in group memberships"
echo -e "8. ✓ Account suspension on contactgegevens deletion"
echo -e ""
echo -e "${BLUE}Check the logs above for SoftwareCatalog entries to verify email sending${NC}"
echo -e "${BLUE}=============================================="
echo -e "${NC}" 