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
#
# Usage: ./test-organization-user-creation.sh
# =============================================================================

set -e

# Configuration
BASE_URL="${BASE_URL:-http://localhost:3000}"
AUTH="${AUTH:-admin:admin}"
REGISTER="${REGISTER:-voorzieningen}"
SCHEMA="${SCHEMA:-organisatie}"

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
echo -e "Base URL: ${BASE_URL}"
echo -e "Auth: ${AUTH}"
echo -e "Contact Email: ${CONTACT_EMAIL}"
echo ""

# -----------------------------------------------------------------------------
# Step 1: POST organization with status "Concept"
# -----------------------------------------------------------------------------
echo -e "${YELLOW}Step 1: Creating organization with status 'Concept'...${NC}"

ORG_RESPONSE=$(curl -s -X POST \
  "${BASE_URL}/index.php/apps/openregister/api/objects/${REGISTER}/${SCHEMA}" \
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
  "${BASE_URL}/ocs/v1.php/cloud/users/${CONTACT_EMAIL}" \
  -u "${AUTH}" \
  -H "OCS-APIRequest: true" \
  -H "Accept: application/json")

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
  "${BASE_URL}/index.php/apps/openregister/api/objects/${REGISTER}/${SCHEMA}/${ORG_ID}" \
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
echo -e "${YELLOW}Step 4: Waiting for event processing (3 seconds)...${NC}"
sleep 3
echo -e "${GREEN}Done waiting${NC}"
echo ""

# -----------------------------------------------------------------------------
# Step 5: Verify user was created
# -----------------------------------------------------------------------------
echo -e "${YELLOW}Step 5: Verifying user was created...${NC}"

USER_CHECK_AFTER=$(curl -s -X GET \
  "${BASE_URL}/ocs/v1.php/cloud/users/${CONTACT_EMAIL}" \
  -u "${AUTH}" \
  -H "OCS-APIRequest: true" \
  -H "Accept: application/json")

USER_EXISTS_AFTER=$(echo "${USER_CHECK_AFTER}" | jq -r '.ocs.meta.status // "failure"')

if [ "${USER_EXISTS_AFTER}" = "ok" ]; then
  echo -e "${GREEN}SUCCESS: User '${CONTACT_EMAIL}' was created!${NC}"

  # Check user groups
  echo ""
  echo -e "${YELLOW}Step 6: Checking user groups...${NC}"

  USER_GROUPS=$(curl -s -X GET \
    "${BASE_URL}/ocs/v1.php/cloud/users/${CONTACT_EMAIL}/groups" \
    -u "${AUTH}" \
    -H "OCS-APIRequest: true" \
    -H "Accept: application/json")

  GROUPS=$(echo "${USER_GROUPS}" | jq -r '.ocs.data.groups[]? // empty' | tr '\n' ', ' | sed 's/,$//')

  if [ -n "${GROUPS}" ]; then
    echo -e "${GREEN}User groups: ${GROUPS}${NC}"

    # Check for expected group based on organization type (Leverancier -> aanbod-beheerder)
    if echo "${GROUPS}" | grep -q "aanbod-beheerder"; then
      echo -e "${GREEN}SUCCESS: User has expected 'aanbod-beheerder' group${NC}"
    else
      echo -e "${YELLOW}WARNING: User does not have 'aanbod-beheerder' group${NC}"
    fi
  else
    echo -e "${YELLOW}WARNING: User has no groups${NC}"
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
  echo "  curl -X DELETE '${BASE_URL}/ocs/v1.php/cloud/users/${CONTACT_EMAIL}' -u '${AUTH}' -H 'OCS-APIRequest: true'"
  echo ""
  echo "  # Delete organization:"
  echo "  curl -X DELETE '${BASE_URL}/index.php/apps/openregister/api/objects/${ORG_ID}' -u '${AUTH}'"

  exit 0
else
  echo -e "${RED}TEST FAILED: User was NOT created${NC}"
  exit 1
fi
