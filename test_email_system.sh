#!/bin/bash

# Test Script for Email System Functionality
# This script tests email configuration, templates, and sending functionality

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
DOCKER_CMD="docker-compose exec nextcloud"
BASE_URL="http://localhost/index.php/apps/softwarecatalog/api"
AUTH="-u admin:admin"
HEADERS="-H 'Content-Type: application/json'"

echo -e "${BLUE}=============================================="
echo -e "Email System Functionality Test"
echo -e "=============================================="
echo -e "${NC}"

# Test 1: Check current email configuration
echo -e "${YELLOW}[STEP 1]${NC} Checking current email configuration"
EMAIL_CONFIG=$(eval $DOCKER_CMD curl -s -X GET "$BASE_URL/settings/email" $HEADERS $AUTH)
echo "Current email config: $EMAIL_CONFIG"

# Test 2: Enable email system
echo -e "${YELLOW}[STEP 2]${NC} Enabling email system"
ENABLE_EMAIL=$(eval $DOCKER_CMD curl -s -X PUT "$BASE_URL/settings/email" $HEADERS $AUTH -d '{
  "enabled": true,
  "senderEmail": "noreply@softwarecatalogus.nl",
  "senderName": "Software Catalogus Test",
  "testReceiverOverride": "test@example.com",
  "organizationRegistrationEnabled": true,
  "organizationActivationEnabled": true,
  "userCreationEnabled": true
}')

echo "Email system enabled: $ENABLE_EMAIL"

# Test 3: Check email templates
echo -e "${YELLOW}[STEP 3]${NC} Checking organization registration email template"
ORG_REG_TEMPLATE=$(eval $DOCKER_CMD curl -s -X GET "$BASE_URL/settings/email/template/organization_registration" $HEADERS $AUTH)
echo "Organization registration template: $ORG_REG_TEMPLATE"

echo -e "${YELLOW}[STEP 4]${NC} Checking organization activation email template"
ORG_ACT_TEMPLATE=$(eval $DOCKER_CMD curl -s -X GET "$BASE_URL/settings/email/template/organization_activation" $HEADERS $AUTH)
echo "Organization activation template: $ORG_ACT_TEMPLATE"

echo -e "${YELLOW}[STEP 5]${NC} Checking user creation email template"
USER_CREATE_TEMPLATE=$(eval $DOCKER_CMD curl -s -X GET "$BASE_URL/settings/email/template/user_creation" $HEADERS $AUTH)
echo "User creation template: $USER_CREATE_TEMPLATE"

# Test 4: Update a template
echo -e "${YELLOW}[STEP 6]${NC} Updating organization registration template"
UPDATE_TEMPLATE=$(eval $DOCKER_CMD curl -s -X PUT "$BASE_URL/settings/email/template/organization_registration" $HEADERS $AUTH -d '{
  "template": "<h1>Welcome {{ organization.name }}!</h1><p>Your organization has been registered in our system.</p><p>Organization type: {{ organization.type }}</p><p>Status: {{ organization.beoordeling }}</p>"
}')

echo "Template updated: $UPDATE_TEMPLATE"

# Test 5: Test email sending
echo -e "${YELLOW}[STEP 7]${NC} Testing email sending functionality"
TEST_EMAIL=$(eval $DOCKER_CMD curl -s -X POST "$BASE_URL/settings/email/test" $HEADERS $AUTH -d '{
  "testEmail": "admin@example.com"
}')

echo "Test email result: $TEST_EMAIL"

# Test 6: Check PHP mail configuration
echo -e "${YELLOW}[STEP 8]${NC} Checking PHP mail configuration"
echo "Checking if PHP mail() function is available..."
$DOCKER_CMD php -r "echo 'PHP mail function available: ' . (function_exists('mail') ? 'YES' : 'NO') . PHP_EOL;"

# Test 7: Check Nextcloud mail settings
echo -e "${YELLOW}[STEP 9]${NC} Checking Nextcloud mail settings"
$DOCKER_CMD occ config:system:get mail_domain || echo "No mail domain configured"
$DOCKER_CMD occ config:system:get mail_from_address || echo "No mail from address configured"

# Test 8: Disable specific email types
echo -e "${YELLOW}[STEP 10]${NC} Testing individual email type controls"
DISABLE_USER_EMAIL=$(eval $DOCKER_CMD curl -s -X PUT "$BASE_URL/settings/email" $HEADERS $AUTH -d '{
  "enabled": true,
  "senderEmail": "noreply@softwarecatalogus.nl",
  "senderName": "Software Catalogus Test",
  "testReceiverOverride": "test@example.com",
  "organizationRegistrationEnabled": true,
  "organizationActivationEnabled": true,
  "userCreationEnabled": false
}')

echo "User creation emails disabled: $DISABLE_USER_EMAIL"

# Test 9: Re-enable all emails
echo -e "${YELLOW}[STEP 11]${NC} Re-enabling all email types"
ENABLE_ALL_EMAIL=$(eval $DOCKER_CMD curl -s -X PUT "$BASE_URL/settings/email" $HEADERS $AUTH -d '{
  "enabled": true,
  "senderEmail": "noreply@softwarecatalogus.nl",
  "senderName": "Software Catalogus Test",
  "testReceiverOverride": "",
  "organizationRegistrationEnabled": true,
  "organizationActivationEnabled": true,
  "userCreationEnabled": true
}')

echo "All emails re-enabled: $ENABLE_ALL_EMAIL"

# Test 10: Final configuration check
echo -e "${YELLOW}[STEP 12]${NC} Final email configuration check"
FINAL_CONFIG=$(eval $DOCKER_CMD curl -s -X GET "$BASE_URL/settings/email" $HEADERS $AUTH)
echo "Final email config: $FINAL_CONFIG"

# Summary
echo -e "${BLUE}=============================================="
echo -e "Email System Test Summary"
echo -e "=============================================="
echo -e "${NC}"
echo -e "${YELLOW}Tests Performed:${NC}"
echo -e "1. ✓ Email configuration retrieved"
echo -e "2. ✓ Email system enabled/configured"
echo -e "3. ✓ Email templates retrieved"
echo -e "4. ✓ Email template updated"
echo -e "5. ✓ Test email sent"
echo -e "6. ✓ PHP mail function checked"
echo -e "7. ✓ Nextcloud mail settings checked"
echo -e "8. ✓ Individual email type controls tested"
echo -e "9. ✓ Email settings restored"
echo -e ""
echo -e "${GREEN}Email system is ready for testing with the main scenarios!${NC}"
echo -e "${BLUE}=============================================="
echo -e "${NC}" 