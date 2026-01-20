#!/bin/bash

# =============================================================================
# Test: Organization Status Change -> User Creation
# =============================================================================
#
# This test verifies that when an organization's status is changed to "active",
# users are automatically created for the contactpersonen.
#
# Workflow:
# 1. POST an organization with contactpersonen (status = "Concept")
# 2. PATCH the organization status to "Actief"
# 3. Verify that users are created for the contactpersonen
# 4. Verify contactpersoon has username field set
# 5. Verify organisation entity exists with same UUID
# 6. Verify user is in organisation entity's users array
#
# =============================================================================
# ENVIRONMENT VARIABLES
# =============================================================================
#
# Configure the test by setting these environment variables:
#
#   BASE_URL        - OpenRegister API base URL (default: http://localhost:3000)
#   NEXTCLOUD_URL   - Nextcloud OCS API URL (default: http://localhost:8080)
#                     If not set separately, uses BASE_URL
#   USERNAME        - Admin username (default: admin)
#   PASSWORD        - Admin password (default: admin)
#   AUTH            - Full auth string user:pass (overrides USERNAME/PASSWORD)
#   REGISTER        - Register slug (default: voorzieningen)
#   SCHEMA          - Organisation schema slug (default: organisatie)
#   WAIT_TIME       - Seconds to wait for event processing (default: 3)
#   API_PATH        - API path prefix (default: /api/apps/openregister/api/objects)
#                     External environments may need: /index.php/apps/openregister/api/objects
#
# =============================================================================
# USAGE EXAMPLES
# =============================================================================
#
# Local development (default):
#   ./test-organization-user-creation.sh
#
# External environment with same URL for both APIs:
#   BASE_URL=https://example.com NEXTCLOUD_URL=https://example.com \
#   USERNAME=admin PASSWORD=secret ./test-organization-user-creation.sh
#
# External environment with separate URLs:
#   BASE_URL=https://api.example.com NEXTCLOUD_URL=https://nextcloud.example.com \
#   AUTH=admin:secret123 ./test-organization-user-creation.sh
#
# With custom wait time and register:
#   WAIT_TIME=5 REGISTER=my-register ./test-organization-user-creation.sh
#
# External environment (with index.php API path):
#   BASE_URL=https://softwarecatalogus.performance.commonground.nu \
#   NEXTCLOUD_URL=https://softwarecatalogus.performance.commonground.nu \
#   API_PATH=/index.php/apps/openregister/api/objects \
#   USERNAME=user@example.com PASSWORD=secret ./test-organization-user-creation.sh
#
# =============================================================================

# Disable exit on error to see all test results
# set -e

# Configuration with sensible defaults
USERNAME="${USERNAME:-admin}"
PASSWORD="${PASSWORD:-admin}"
AUTH="${AUTH:-${USERNAME}:${PASSWORD}}"
BASE_URL="${BASE_URL:-http://localhost:3000}"
NEXTCLOUD_URL="${NEXTCLOUD_URL:-http://localhost:8080}"
REGISTER="${REGISTER:-voorzieningen}"
SCHEMA="${SCHEMA:-organisatie}"
WAIT_TIME="${WAIT_TIME:-3}"
API_PATH="${API_PATH:-/api/apps/openregister/api/objects}"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Generate unique test identifiers
UNIQUE_ID=$(date +%s)
CONTACT_EMAIL="test.contact.${UNIQUE_ID}@example.com"
ORG_NAME="Test Organisation ${UNIQUE_ID}"

echo -e "${BLUE}======================================================${NC}"
echo -e "${BLUE}  Organization Status -> User Creation Test${NC}"
echo -e "${BLUE}======================================================${NC}"
echo ""
echo -e "${YELLOW}Configuration:${NC}"
echo -e "  OpenRegister API:  ${BASE_URL}"
echo -e "  Nextcloud OCS API: ${NEXTCLOUD_URL}"
echo -e "  API Path:          ${API_PATH}"
echo -e "  Auth:              ${USERNAME}:****"
echo -e "  Register:          ${REGISTER}"
echo -e "  Schema:            ${SCHEMA}"
echo -e "  Wait Time:         ${WAIT_TIME}s"
echo ""
echo -e "${YELLOW}Test Data:${NC}"
echo -e "  Contact Email:     ${CONTACT_EMAIL}"
echo -e "  Org Name:          ${ORG_NAME}"
echo ""

# -----------------------------------------------------------------------------
# Step 1: POST organization with status "Concept"
# -----------------------------------------------------------------------------
echo -e "${YELLOW}Step 1: Creating organization with status 'Concept'...${NC}"

ORG_RESPONSE=$(curl -s -X POST \
  "${BASE_URL}${API_PATH}/${REGISTER}/${SCHEMA}" \
  -u "${AUTH}" \
  -H "Content-Type: application/json" \
  -H "OCS-APIRequest: true" \
  -d "{
    \"naam\": \"${ORG_NAME}\",
    \"website\": \"https://test.example.com\",
    \"type\": \"Leverancier\",
    \"status\": \"Concept\",
    \"contactpersonen\": [
      {
        \"voornaam\": \"Test\",
        \"tussenvoegsel\": \"\",
        \"achternaam\": \"Contact${UNIQUE_ID}\",
        \"telefoonnummer\": \"0612345678\",
        \"e-mailadres\": \"${CONTACT_EMAIL}\",
        \"functie\": \"Tester\"
      }
    ],
    \"e-mailadres\": \"org.${UNIQUE_ID}@example.com\",
    \"beschrijvingKort\": \"Test organization for user creation workflow\"
  }")

# Extract organization ID
ORG_ID=$(echo "${ORG_RESPONSE}" | jq -r '.uuid // .id // empty')

if [ -z "${ORG_ID}" ]; then
  echo -e "${RED}FAILED: Could not create organization${NC}"
  echo "Response: ${ORG_RESPONSE}"
  exit 1
fi

echo -e "${GREEN}SUCCESS: Organization created with ID: ${ORG_ID}${NC}"
echo "Status: $(echo "${ORG_RESPONSE}" | jq -r '.status // "unknown"')"
echo ""

# -----------------------------------------------------------------------------
# Step 2: Verify user does NOT exist yet
# -----------------------------------------------------------------------------
echo -e "${YELLOW}Step 2: Verifying user does not exist yet...${NC}"

USER_CHECK_BEFORE=$(curl -s -X GET \
  "${NEXTCLOUD_URL}/ocs/v1.php/cloud/users/${CONTACT_EMAIL}?format=json" \
  -u "${AUTH}" \
  -H "OCS-APIRequest: true")

USER_EXISTS_BEFORE=$(echo "${USER_CHECK_BEFORE}" | jq -r '.ocs.meta.status // "failure"')

if [ "${USER_EXISTS_BEFORE}" = "ok" ]; then
  echo -e "${YELLOW}WARNING: User already exists (possibly from previous test run)${NC}"
else
  echo -e "${GREEN}CONFIRMED: User does not exist yet${NC}"
fi
echo ""

# -----------------------------------------------------------------------------
# Step 3: PATCH organization status to "Actief"
# -----------------------------------------------------------------------------
echo -e "${YELLOW}Step 3: PATCHing organization status to 'Actief'...${NC}"

PATCH_RESPONSE=$(curl -s -X PATCH \
  "${BASE_URL}${API_PATH}/${REGISTER}/${SCHEMA}/${ORG_ID}" \
  -u "${AUTH}" \
  -H "Content-Type: application/json" \
  -H "OCS-APIRequest: true" \
  -d '{"status": "Actief"}')

PATCHED_STATUS=$(echo "${PATCH_RESPONSE}" | jq -r '.status // "unknown"')

if [ "${PATCHED_STATUS}" = "Actief" ] || [ "${PATCHED_STATUS}" = "actief" ]; then
  echo -e "${GREEN}SUCCESS: Organization status updated to '${PATCHED_STATUS}'${NC}"
else
  echo -e "${YELLOW}WARNING: Status might not have been updated. Got: '${PATCHED_STATUS}'${NC}"
  echo "Response: ${PATCH_RESPONSE}"
fi
echo ""

# -----------------------------------------------------------------------------
# Step 4: Wait for event processing
# -----------------------------------------------------------------------------
echo -e "${YELLOW}Step 4: Waiting for event processing (${WAIT_TIME} seconds)...${NC}"
sleep "${WAIT_TIME}"
echo -e "${GREEN}Done waiting${NC}"
echo ""

# -----------------------------------------------------------------------------
# Step 5: Verify user was created
# -----------------------------------------------------------------------------
echo -e "${YELLOW}Step 5: Verifying user was created...${NC}"

USER_CHECK_AFTER=$(curl -s -X GET \
  "${NEXTCLOUD_URL}/ocs/v1.php/cloud/users/${CONTACT_EMAIL}?format=json" \
  -u "${AUTH}" \
  -H "OCS-APIRequest: true")

USER_EXISTS_AFTER=$(echo "${USER_CHECK_AFTER}" | jq -r '.ocs.meta.status // "failure"')

if [ "${USER_EXISTS_AFTER}" = "ok" ]; then
  echo -e "${GREEN}SUCCESS: User '${CONTACT_EMAIL}' was created!${NC}"

  # Check user groups
  echo ""
  echo -e "${YELLOW}Step 6: Checking user groups...${NC}"

  # Fetch user groups from Nextcloud OCS API
  curl -s -X GET \
    "${NEXTCLOUD_URL}/ocs/v1.php/cloud/users/${CONTACT_EMAIL}/groups?format=json" \
    -u "${AUTH}" \
    -H "OCS-APIRequest: true" > /tmp/user_groups_temp.json 2>/dev/null

  # Parse groups directly using Python as backup (jq has issues in this env)
  USER_GROUP_LIST=$(python3 -c "import json,sys; d=json.load(sys.stdin); print(', '.join(d.get('ocs',{}).get('data',{}).get('groups',[])))" < /tmp/user_groups_temp.json 2>/dev/null || jq -r '.ocs.data.groups | join(", ")' /tmp/user_groups_temp.json 2>/dev/null || echo "")
  rm -f /tmp/user_groups_temp.json

  if [ -n "${USER_GROUP_LIST}" ] && [ "${USER_GROUP_LIST}" != "null" ]; then
    echo -e "${GREEN}User groups: ${USER_GROUP_LIST}${NC}"

    # Check for expected group based on organization type (Leverancier -> aanbod-beheerder)
    if echo "${USER_GROUP_LIST}" | grep -q "aanbod-beheerder"; then
      echo -e "${GREEN}SUCCESS: User has expected 'aanbod-beheerder' group${NC}"
    else
      echo -e "${YELLOW}WARNING: User does not have 'aanbod-beheerder' group${NC}"
    fi
  else
    echo -e "${YELLOW}WARNING: User has no groups or failed to fetch${NC}"
  fi

  # ---------------------------------------------------------------------------
  # Step 7: Verify contactpersoon has username field set
  # ---------------------------------------------------------------------------
  echo ""
  echo -e "${YELLOW}Step 7: Verifying contactpersoon has username field...${NC}"

  # Get org with expanded contactpersonen to find contactpersoon UUID
  ORG_WITH_CONTACTS=$(curl -s -X GET \
    "${BASE_URL}${API_PATH}/${REGISTER}/${SCHEMA}/${ORG_ID}" \
    -u "${AUTH}" \
    -H "Content-Type: application/json")

  CONTACT_UUID=$(echo "${ORG_WITH_CONTACTS}" | jq -r '.contactpersonen[0] // empty')

  if [ -n "${CONTACT_UUID}" ] && [ "${CONTACT_UUID}" != "null" ]; then
    # Fetch the contactpersoon object
    CONTACT_OBJ=$(curl -s -X GET \
      "${BASE_URL}${API_PATH}/${REGISTER}/contactpersoon/${CONTACT_UUID}" \
      -u "${AUTH}" \
      -H "Content-Type: application/json")

    CONTACT_USERNAME=$(echo "${CONTACT_OBJ}" | jq -r '.username // empty')

    if [ "${CONTACT_USERNAME}" = "${CONTACT_EMAIL}" ]; then
      echo -e "${GREEN}SUCCESS: Contactpersoon username='${CONTACT_USERNAME}' matches user email${NC}"
    elif [ -n "${CONTACT_USERNAME}" ]; then
      echo -e "${YELLOW}WARNING: Contactpersoon has username='${CONTACT_USERNAME}' but expected '${CONTACT_EMAIL}'${NC}"
    else
      echo -e "${RED}FAILED: Contactpersoon does not have username field set${NC}"
    fi
  else
    echo -e "${YELLOW}WARNING: Could not find contactpersoon UUID in organization${NC}"
  fi

  # ---------------------------------------------------------------------------
  # Step 8: Verify organisation entity exists with same UUID
  # ---------------------------------------------------------------------------
  echo ""
  echo -e "${YELLOW}Step 8: Verifying organisation entity exists...${NC}"

  # First try direct lookup
  ORG_ENTITY=$(curl -s -X GET \
    "${NEXTCLOUD_URL}/index.php/apps/openregister/api/organisations/${ORG_ID}" \
    -u "${AUTH}" \
    -H "Content-Type: application/json")

  ORG_ENTITY_UUID=$(echo "${ORG_ENTITY}" | jq -r '.uuid // empty')

  # If direct lookup fails, search in full list
  if [ -z "${ORG_ENTITY_UUID}" ] || [ "${ORG_ENTITY_UUID}" = "null" ]; then
    echo "  Direct lookup returned empty, searching in full organisation list..."
    ALL_ORGS=$(curl -s -X GET \
      "${NEXTCLOUD_URL}/index.php/apps/openregister/api/organisations" \
      -u "${AUTH}" \
      -H "Content-Type: application/json")

    ORG_ENTITY=$(echo "${ALL_ORGS}" | jq --arg uuid "${ORG_ID}" '.results[]? | select(.uuid == $uuid)')
    ORG_ENTITY_UUID=$(echo "${ORG_ENTITY}" | jq -r '.uuid // empty')
  fi

  ORG_ENTITY_ACTIVE=$(echo "${ORG_ENTITY}" | jq -r '.active // false')
  ORG_ENTITY_NAME=$(echo "${ORG_ENTITY}" | jq -r '.name // "unknown"')

  if [ "${ORG_ENTITY_UUID}" = "${ORG_ID}" ]; then
    echo -e "${GREEN}SUCCESS: Organisation entity exists with UUID: ${ORG_ENTITY_UUID}${NC}"
    echo "  Name: ${ORG_ENTITY_NAME}"
    echo "  Active: ${ORG_ENTITY_ACTIVE}"
  else
    echo -e "${RED}FAILED: Organisation entity NOT found for UUID: ${ORG_ID}${NC}"
    echo "  This may indicate a bug: organisation entity should be created when organisatie status changes to 'Actief'"
  fi

  # ---------------------------------------------------------------------------
  # Step 9: Verify user is in organisation entity's users array
  # ---------------------------------------------------------------------------
  echo ""
  echo -e "${YELLOW}Step 9: Verifying user is in organisation entity...${NC}"

  if [ -z "${ORG_ENTITY_UUID}" ] || [ "${ORG_ENTITY_UUID}" = "null" ]; then
    echo -e "${RED}SKIPPED: Cannot check user in organisation - organisation entity not found${NC}"
  else
    ORG_ENTITY_USERS=$(echo "${ORG_ENTITY}" | jq -r '.users[]? // empty')

    if echo "${ORG_ENTITY_USERS}" | grep -q "${CONTACT_EMAIL}"; then
      echo -e "${GREEN}SUCCESS: User '${CONTACT_EMAIL}' is in organisation entity's users array${NC}"
    else
      echo -e "${RED}FAILED: User '${CONTACT_EMAIL}' is NOT in organisation entity's users array${NC}"
      echo "  Current users: $(echo "${ORG_ENTITY}" | jq -c '.users // []')"
    fi
  fi

else
  echo -e "${RED}FAILED: User '${CONTACT_EMAIL}' was NOT created${NC}"
  echo "Response: ${USER_CHECK_AFTER}"
fi
echo ""

# -----------------------------------------------------------------------------
# Summary
# -----------------------------------------------------------------------------
echo -e "${BLUE}======================================================${NC}"
echo -e "${BLUE}  Test Summary${NC}"
echo -e "${BLUE}======================================================${NC}"
echo ""
echo "Organization ID: ${ORG_ID}"
echo "Contact Email: ${CONTACT_EMAIL}"
echo "User Created: $([ "${USER_EXISTS_AFTER}" = "ok" ] && echo "YES" || echo "NO")"
echo ""

if [ "${USER_EXISTS_AFTER}" = "ok" ]; then
  echo -e "${GREEN}TEST PASSED: User was created when organization status changed to 'Actief'${NC}"

  # Cleanup prompt
  echo ""
  echo -e "${YELLOW}Cleanup commands (run manually if needed):${NC}"
  echo "  # Delete user:"
  echo "  curl -X DELETE '${NEXTCLOUD_URL}/ocs/v1.php/cloud/users/${CONTACT_EMAIL}' -u '${AUTH}' -H 'OCS-APIRequest: true'"
  echo ""
  echo "  # Delete organization:"
  echo "  curl -X DELETE '${BASE_URL}${API_PATH}/${ORG_ID}' -u '${AUTH}'"

  exit 0
else
  echo -e "${RED}TEST FAILED: User was NOT created${NC}"
  exit 1
fi
