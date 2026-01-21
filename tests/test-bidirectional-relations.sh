#!/bin/bash

# Test script for bidirectional relations between organisation and contactpersoon
# Tests both separate creation and embedded creation flows

set -e

BASE_URL="http://localhost:8080/index.php/apps/openregister/api/objects"
AUTH="admin:admin"
REGISTER_ID=2  # voorzieningen
ORGANISATIE_SCHEMA_ID=24
CONTACTPERSOON_SCHEMA_ID=23

# Generate unique IDs for this test run
UNIQUE_ID=$(date +%s)

echo "=============================================="
echo "Testing Bidirectional Relations"
echo "Unique ID: $UNIQUE_ID"
echo "=============================================="

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

success() {
    echo -e "${GREEN}✓ $1${NC}"
}

error() {
    echo -e "${RED}✗ $1${NC}"
}

info() {
    echo -e "${YELLOW}→ $1${NC}"
}

echo ""
echo "=============================================="
echo "TEST 1: Create Organisation and Contactpersoon Separately"
echo "=============================================="

# Step 1: Create Organisation
info "Step 1: Creating organisation..."
ORG_RESPONSE=$(curl -s -X POST \
    -u "$AUTH" \
    -H "Content-Type: application/json" \
    "$BASE_URL/$REGISTER_ID/$ORGANISATIE_SCHEMA_ID" \
    -d "{
        \"naam\": \"Test Organisatie Separate $UNIQUE_ID\",
        \"type\": \"Gemeente\",
        \"website\": \"https://test-separate-$UNIQUE_ID.nl\",
        \"status\": \"Concept\"
    }")

ORG1_UUID=$(echo "$ORG_RESPONSE" | jq -r '.uuid // .["@self"].id // empty')
if [ -z "$ORG1_UUID" ] || [ "$ORG1_UUID" = "null" ]; then
    error "Failed to create organisation"
    echo "Response: $ORG_RESPONSE"
    exit 1
fi
success "Created organisation with UUID: $ORG1_UUID"

# Step 2: Create Contactpersoon with reference to organisation
info "Step 2: Creating contactpersoon with organisatie reference..."
CONTACT_RESPONSE=$(curl -s -X POST \
    -u "$AUTH" \
    -H "Content-Type: application/json" \
    "$BASE_URL/$REGISTER_ID/$CONTACTPERSOON_SCHEMA_ID" \
    -d "{
        \"voornaam\": \"Jan\",
        \"achternaam\": \"Jansen\",
        \"e-mailadres\": \"jan.jansen.separate.$UNIQUE_ID@test.nl\",
        \"naam\": \"Jan Jansen\",
        \"organisatie\": \"$ORG1_UUID\"
    }")

CONTACT1_UUID=$(echo "$CONTACT_RESPONSE" | jq -r '.uuid // .["@self"].id // empty')
if [ -z "$CONTACT1_UUID" ] || [ "$CONTACT1_UUID" = "null" ]; then
    error "Failed to create contactpersoon"
    echo "Response: $CONTACT_RESPONSE"
    exit 1
fi
success "Created contactpersoon with UUID: $CONTACT1_UUID"

# Step 3: Check contactpersoon's relations
info "Step 3: Checking contactpersoon's relations..."
CONTACT_CHECK=$(curl -s -u "$AUTH" "$BASE_URL/$REGISTER_ID/$CONTACTPERSOON_SCHEMA_ID/$CONTACT1_UUID")
CONTACT_RELATIONS=$(echo "$CONTACT_CHECK" | jq -r '.["@self"].relations // []')
CONTACT_ORG_REF=$(echo "$CONTACT_CHECK" | jq -r '.organisatie // empty')

echo "Contactpersoon relations: $CONTACT_RELATIONS"
echo "Contactpersoon organisatie field: $CONTACT_ORG_REF"

if echo "$CONTACT_RELATIONS" | jq -e "any(. == \"$ORG1_UUID\")" > /dev/null 2>&1; then
    success "Contactpersoon has organisation in its relations"
else
    error "Contactpersoon does NOT have organisation in its relations"
fi

# Step 4: Check organisation's relations (should now include contactpersoon)
info "Step 4: Checking organisation's relations..."
ORG_CHECK=$(curl -s -u "$AUTH" "$BASE_URL/$REGISTER_ID/$ORGANISATIE_SCHEMA_ID/$ORG1_UUID")
ORG_RELATIONS=$(echo "$ORG_CHECK" | jq -r '.["@self"].relations // []')

echo "Organisation relations: $ORG_RELATIONS"

if echo "$ORG_RELATIONS" | jq -e "any(. == \"$CONTACT1_UUID\")" > /dev/null 2>&1; then
    success "Organisation has contactpersoon in its relations (inverse relation works!)"
else
    error "Organisation does NOT have contactpersoon in its relations (inverse relation NOT working)"
fi

# Step 5: Test extend on organisation to get contactpersonen
info "Step 5: Testing extend on organisation to get contactpersonen..."
ORG_EXTENDED=$(curl -s -u "$AUTH" "$BASE_URL/$REGISTER_ID/$ORGANISATIE_SCHEMA_ID/$ORG1_UUID?_extend=contactpersonen")

echo "Extended organisation response (contactpersonen field):"
echo "$ORG_EXTENDED" | jq '.contactpersonen // "NOT FOUND"'

CONTACTPERSONEN_ARRAY=$(echo "$ORG_EXTENDED" | jq '.contactpersonen // []')
if [ "$CONTACTPERSONEN_ARRAY" != "[]" ] && [ "$CONTACTPERSONEN_ARRAY" != "null" ]; then
    # Check if our contact is in the array
    if echo "$CONTACTPERSONEN_ARRAY" | jq -e "any(.uuid == \"$CONTACT1_UUID\" or .[\"@self\"].id == \"$CONTACT1_UUID\")" > /dev/null 2>&1; then
        success "Contactpersoon found in extended organisation's contactpersonen!"
    else
        error "Contactpersoon NOT found in extended organisation's contactpersonen"
        echo "Contactpersonen content: $CONTACTPERSONEN_ARRAY"
    fi
else
    error "contactpersonen field is empty or not found in extended response"
fi

echo ""
echo "=============================================="
echo "TEST 2: Create Organisation with Embedded Contactpersoon"
echo "=============================================="

# Step 6: Create organisation with embedded contactpersoon
info "Step 6: Creating organisation with embedded contactpersoon..."
ORG2_RESPONSE=$(curl -s -X POST \
    -u "$AUTH" \
    -H "Content-Type: application/json" \
    "$BASE_URL/$REGISTER_ID/$ORGANISATIE_SCHEMA_ID" \
    -d "{
        \"naam\": \"Test Organisatie Embedded $UNIQUE_ID\",
        \"type\": \"Leverancier\",
        \"website\": \"https://test-embedded-$UNIQUE_ID.nl\",
        \"status\": \"Concept\",
        \"contactpersonen\": [
            {
                \"voornaam\": \"Piet\",
                \"achternaam\": \"Pietersen\",
                \"e-mailadres\": \"piet.pietersen.embedded.$UNIQUE_ID@test.nl\",
                \"naam\": \"Piet Pietersen\"
            }
        ]
    }")

ORG2_UUID=$(echo "$ORG2_RESPONSE" | jq -r '.uuid // .["@self"].id // empty')
if [ -z "$ORG2_UUID" ] || [ "$ORG2_UUID" = "null" ]; then
    error "Failed to create organisation with embedded contactpersoon"
    echo "Response: $ORG2_RESPONSE"
else
    success "Created organisation with UUID: $ORG2_UUID"

    # Step 7: Check if embedded contactpersoon was created
    info "Step 7: Checking embedded contactpersoon creation..."

    # Get the organisation with extend
    ORG2_EXTENDED=$(curl -s -u "$AUTH" "$BASE_URL/$REGISTER_ID/$ORGANISATIE_SCHEMA_ID/$ORG2_UUID?_extend=contactpersonen")

    echo "Organisation with embedded contactpersonen response:"
    echo "$ORG2_EXTENDED" | jq '{naam: .naam, contactpersonen: .contactpersonen, relations: .["@self"].relations}'

    EMBEDDED_CONTACTS=$(echo "$ORG2_EXTENDED" | jq '.contactpersonen // []')
    if [ "$EMBEDDED_CONTACTS" != "[]" ] && [ "$EMBEDDED_CONTACTS" != "null" ]; then
        EMBEDDED_COUNT=$(echo "$EMBEDDED_CONTACTS" | jq 'length')
        success "Found $EMBEDDED_COUNT embedded contactpersoon(en)"

        # Check if embedded contact has reference back to organisation
        FIRST_EMBEDDED=$(echo "$EMBEDDED_CONTACTS" | jq '.[0]')
        EMBEDDED_ORG_REF=$(echo "$FIRST_EMBEDDED" | jq -r '.organisatie // empty')
        echo "Embedded contactpersoon's organisatie field: $EMBEDDED_ORG_REF"

        if [ "$EMBEDDED_ORG_REF" = "$ORG2_UUID" ]; then
            success "Embedded contactpersoon has correct organisation reference"
        else
            info "Embedded contactpersoon organisatie field: $EMBEDDED_ORG_REF (expected: $ORG2_UUID)"
        fi
    else
        error "No embedded contactpersonen found"
        echo "Full response: $ORG2_EXTENDED"
    fi
fi

echo ""
echo "=============================================="
echo "TEST 3: Verify Extend Works Both Ways"
echo "=============================================="

# Step 8: Get contactpersoon and extend organisatie
info "Step 8: Testing extend on contactpersoon to get organisatie..."
CONTACT_EXTENDED=$(curl -s -u "$AUTH" "$BASE_URL/$REGISTER_ID/$CONTACTPERSOON_SCHEMA_ID/$CONTACT1_UUID?_extend=organisatie")

echo "Contactpersoon with extended organisatie:"
echo "$CONTACT_EXTENDED" | jq '{voornaam: .voornaam, achternaam: .achternaam, organisatie: .organisatie}'

ORG_IN_CONTACT=$(echo "$CONTACT_EXTENDED" | jq '.organisatie')
if [ "$ORG_IN_CONTACT" != "null" ] && [ "$ORG_IN_CONTACT" != "\"$ORG1_UUID\"" ]; then
    # Check if it's an object (extended) or just a UUID
    ORG_TYPE=$(echo "$ORG_IN_CONTACT" | jq -r 'type')
    if [ "$ORG_TYPE" = "object" ]; then
        ORG_NAME=$(echo "$ORG_IN_CONTACT" | jq -r '.naam // empty')
        success "Organisation extended in contactpersoon: $ORG_NAME"
    else
        info "Organisation field is: $ORG_IN_CONTACT"
    fi
else
    error "Organisation not properly extended in contactpersoon"
fi

echo ""
echo "=============================================="
echo "SUMMARY"
echo "=============================================="
echo "Test 1 (Separate creation):"
echo "  - Organisation UUID: $ORG1_UUID"
echo "  - Contactpersoon UUID: $CONTACT1_UUID"
echo ""
echo "Test 2 (Embedded creation):"
echo "  - Organisation UUID: $ORG2_UUID"
echo ""
echo "Check the logs above for pass/fail status of each step."
echo "=============================================="
