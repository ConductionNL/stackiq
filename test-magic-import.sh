#!/bin/bash

###############################################################################
# Magic Mapper CSV Import Test Script
#
# This script imports CSV data via the OpenRegister API and verifies that
# objects are stored in magic mapper tables (not blob storage).
#
# Usage:
#   cd /home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/stackiq
#   chmod +x test-magic-import.sh
#   ./test-magic-import.sh
###############################################################################

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Configuration
NEXTCLOUD_URL="http://localhost:8080"
ADMIN_USER="admin"
ADMIN_PASSWORD="admin"
DATA_DIR="./data"

echo "╔══════════════════════════════════════════════════════════════════════════════╗"
echo "║ Magic Mapper CSV Import & Verification Test                                  ║"
echo "╚══════════════════════════════════════════════════════════════════════════════╝"
echo ""

# Step 1: Import magic mapper configuration via API
echo -e "${BLUE}1️⃣  Importing magic mapper register configuration via API...${NC}"

# Copy config file to container
docker cp ./lib/Settings/softwarecatalogus_register_magic.json nextcloud:/tmp/config.json

# Import via API
CONFIG_IMPORT_RESPONSE=$(docker exec -u 33 nextcloud curl -s -u "${ADMIN_USER}:${ADMIN_PASSWORD}" \
    -X POST \
    -H "Content-Type: application/json" \
    -d @/tmp/config.json \
    "${NEXTCLOUD_URL}/index.php/apps/openregister/api/configurations?force=true")

# Check if import was successful
if echo "$CONFIG_IMPORT_RESPONSE" | docker exec -i nextcloud jq -e '.registers' > /dev/null 2>&1; then
    REGISTER_COUNT=$(echo "$CONFIG_IMPORT_RESPONSE" | docker exec -i nextcloud jq -r '.registers | length')
    SCHEMA_COUNT=$(echo "$CONFIG_IMPORT_RESPONSE" | docker exec -i nextcloud jq -r '.schemas | length')
    echo -e "${GREEN}✓ Configuration imported: $REGISTER_COUNT registers, $SCHEMA_COUNT schemas${NC}"
else
    echo -e "${YELLOW}⚠️  Configuration import response: $CONFIG_IMPORT_RESPONSE${NC}"
fi

# Clean up temp file
docker exec nextcloud rm -f /tmp/config.json
echo ""

# Step 2: Find the stackiq register ID
echo -e "${BLUE}2️⃣  Finding stackiq register...${NC}"
REGISTER_RESPONSE=$(docker exec -u 33 nextcloud curl -s -u "${ADMIN_USER}:${ADMIN_PASSWORD}" \
    "${NEXTCLOUD_URL}/index.php/apps/openregister/api/registers?slug=stackiq")

REGISTER_ID=$(echo "$REGISTER_RESPONSE" | docker exec -i nextcloud jq -r '.results[0].id // empty')

if [ -z "$REGISTER_ID" ]; then
    echo -e "${RED}❌ Voorzieningen register not found!${NC}"
    echo "Response: $REGISTER_RESPONSE"
    exit 1
fi

echo -e "${GREEN}✓ Register found: ID=$REGISTER_ID${NC}"

# Check magic mapper configuration
MAGIC_CONFIG=$(echo "$REGISTER_RESPONSE" | docker exec -i nextcloud jq -r '.results[0].configuration.schemas // empty')
if [ -n "$MAGIC_CONFIG" ]; then
    echo -e "   Magic mapping configured for:"
    echo "$MAGIC_CONFIG" | docker exec -i nextcloud jq -r 'keys[]' | sed 's/^/     • /'
fi
echo ""

# Step 3: Find schema IDs
echo -e "${BLUE}3️⃣  Finding schema IDs...${NC}"

declare -A SCHEMA_IDS
declare -A SCHEMA_FILES

SCHEMAS=("module" "organisatie" "koppeling")

for SCHEMA_SLUG in "${SCHEMAS[@]}"; do
    SCHEMA_RESPONSE=$(docker exec -u 33 nextcloud curl -s -u "${ADMIN_USER}:${ADMIN_PASSWORD}" \
        "${NEXTCLOUD_URL}/index.php/apps/openregister/api/schemas?slug=${SCHEMA_SLUG}")
    
    SCHEMA_ID=$(echo "$SCHEMA_RESPONSE" | docker exec -i nextcloud jq -r ".results[0].id // empty")
    
    if [ -n "$SCHEMA_ID" ]; then
        SCHEMA_IDS[$SCHEMA_SLUG]=$SCHEMA_ID
        SCHEMA_FILES[$SCHEMA_SLUG]="${DATA_DIR}/${SCHEMA_SLUG}.csv"
        echo -e "${GREEN}✓ ${SCHEMA_SLUG}: ID=$SCHEMA_ID${NC}"
    else
        echo -e "${YELLOW}⚠️  ${SCHEMA_SLUG}: Schema not found${NC}"
    fi
done
echo ""

# Step 4: Import CSV files via API
echo -e "${BLUE}4️⃣  Importing CSV data via API...${NC}"
echo ""

declare -A IMPORT_STATS

for SCHEMA_SLUG in "${!SCHEMA_IDS[@]}"; do
    SCHEMA_ID=${SCHEMA_IDS[$SCHEMA_SLUG]}
    CSV_FILE=${SCHEMA_FILES[$SCHEMA_SLUG]}
    
    if [ ! -f "$CSV_FILE" ]; then
        echo -e "${YELLOW}⚠️  File not found: $CSV_FILE${NC}"
        continue
    fi
    
    echo -e "   📄 Importing ${SCHEMA_SLUG}..."
    
    # Copy CSV to container
    docker cp "$CSV_FILE" nextcloud:/tmp/import.csv
    
    # Import via API
    IMPORT_RESPONSE=$(docker exec -u 33 nextcloud curl -s -u "${ADMIN_USER}:${ADMIN_PASSWORD}" \
        -X POST \
        -F "file=@/tmp/import.csv" \
        -F "type=csv" \
        -F "schema=${SCHEMA_ID}" \
        "${NEXTCLOUD_URL}/index.php/apps/openregister/api/registers/${REGISTER_ID}/import")
    
    # Parse response
    SUCCESS_COUNT=$(echo "$IMPORT_RESPONSE" | docker exec -i nextcloud jq -r '.successCount // 0')
    FAILED_COUNT=$(echo "$IMPORT_RESPONSE" | docker exec -i nextcloud jq -r '.failedCount // 0')
    
    IMPORT_STATS[$SCHEMA_SLUG]="$SUCCESS_COUNT/$FAILED_COUNT"
    
    echo -e "${GREEN}   ✓ Imported: $SUCCESS_COUNT, Failed: $FAILED_COUNT${NC}"
    
    # Clean up temp file
    docker exec nextcloud rm -f /tmp/import.csv
done
echo ""

# Step 5: Verify magic mapper tables
echo -e "${BLUE}5️⃣  Verifying magic mapper tables...${NC}"
echo ""

for SCHEMA_SLUG in "${!SCHEMA_IDS[@]}"; do
    SCHEMA_ID=${SCHEMA_IDS[$SCHEMA_SLUG]}
    TABLE_NAME="oc_openregister_table_${REGISTER_ID}_${SCHEMA_ID}"
    
    echo -e "   📊 Checking table: ${TABLE_NAME}"
    
    # Check table exists and count rows
    TABLE_COUNT=$(docker exec master-database-1 psql -U nextcloud -d nextcloud -t -c \
        "SELECT COUNT(*) FROM ${TABLE_NAME};" 2>/dev/null || echo "0")
    
    if [ "$TABLE_COUNT" -gt 0 ]; then
        echo -e "${GREEN}   ✓ Table exists with $TABLE_COUNT rows${NC}"
        
        # Show sample UUIDs
        SAMPLE_UUIDS=$(docker exec master-database-1 psql -U nextcloud -d nextcloud -t -c \
            "SELECT uuid FROM ${TABLE_NAME} LIMIT 3;" 2>/dev/null)
        
        echo "   Sample UUIDs:"
        echo "$SAMPLE_UUIDS" | sed 's/^/     • /'
    else
        echo -e "${RED}   ❌ Table not found or empty${NC}"
    fi
    echo ""
done

# Step 6: Verify NOT in blob storage
echo -e "${BLUE}6️⃣  Verifying objects are NOT in blob storage...${NC}"
echo ""

for SCHEMA_SLUG in "${!SCHEMA_IDS[@]}"; do
    SCHEMA_ID=${SCHEMA_IDS[$SCHEMA_SLUG]}
    
    BLOB_COUNT=$(docker exec master-database-1 psql -U nextcloud -d nextcloud -t -c \
        "SELECT COUNT(*) FROM oc_openregister_objects WHERE register = '${REGISTER_ID}' AND schema = '${SCHEMA_ID}';" 2>/dev/null || echo "0")
    
    BLOB_COUNT=$(echo "$BLOB_COUNT" | tr -d ' ')
    
    if [ "$BLOB_COUNT" -eq 0 ]; then
        echo -e "${GREEN}✓ ${SCHEMA_SLUG}: 0 objects in blob storage (correct!)${NC}"
    else
        echo -e "${YELLOW}⚠️  ${SCHEMA_SLUG}: ${BLOB_COUNT} objects found in blob storage${NC}"
        echo "   (Should be 0 for magic mapper - objects may have been created before magic mapping was enabled)"
    fi
done
echo ""

# Summary
echo "╔══════════════════════════════════════════════════════════════════════════════╗"
echo "║ ✅ Import and verification complete!                                         ║"
echo "╚══════════════════════════════════════════════════════════════════════════════╝"
echo ""
echo "Import Statistics:"
for SCHEMA_SLUG in "${!IMPORT_STATS[@]}"; do
    echo "  • ${SCHEMA_SLUG}: ${IMPORT_STATS[$SCHEMA_SLUG]}"
done
echo ""

