#!/bin/bash

###############################################################################
# CSV Import Test Script for Magic Mapper
###############################################################################
# This script tests bulk CSV import to magic-mapped tables using the API.
# It imports data from stackiq/data/*.csv files.
###############################################################################

set -e  # Exit on error

# Colors for output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

echo -e "${BLUE}"
cat << 'EOF'
╔══════════════════════════════════════════════════════════════════════════════╗
║                    CSV IMPORT TEST - MAGIC MAPPER                            ║
╚══════════════════════════════════════════════════════════════════════════════╝
EOF
echo -e "${NC}"

# Container names
CONTAINER="nextcloud"
DB_CONTAINER="openregister-postgres"
API_URL="http://localhost"
AUTH="admin:admin"

echo -e "${YELLOW}📋 STAP 1: Verificatie van configuratie${NC}"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

# Get registers to find voorzieningen register
echo "🔍 Zoeken naar 'voorzieningen' register..."
REGISTER_RESPONSE=$(docker exec -u 33 $CONTAINER curl -s -u "$AUTH" \
    -H "Content-Type: application/json" \
    "$API_URL/api/registers?filters[slug]=voorzieningen")

REGISTER_ID=$(echo "$REGISTER_RESPONSE" | jq -r '.results[0].id // empty')
REGISTER_CONFIG=$(echo "$REGISTER_RESPONSE" | jq -r '.results[0].configuration // empty')

if [ -z "$REGISTER_ID" ]; then
    echo -e "${RED}❌ 'voorzieningen' register niet gevonden!${NC}"
    echo "   Importeer eerst de configuratie uit softwarecatalogus_register_magic.json"
    exit 1
fi

echo -e "${GREEN}✓ Register gevonden: ID $REGISTER_ID${NC}"
echo "   Configuratie: $(echo "$REGISTER_CONFIG" | jq -c '.schemas | keys')"

# Check if magic mapping is enabled for module schema
MAGIC_ENABLED=$(echo "$REGISTER_CONFIG" | jq -r '.schemas.module.magicMapping // false')
AUTO_CREATE=$(echo "$REGISTER_CONFIG" | jq -r '.schemas.module.autoCreateTable // false')

echo ""
echo "📊 Magic Mapper Status voor 'module' schema:"
echo "   • magicMapping: $MAGIC_ENABLED"
echo "   • autoCreateTable: $AUTO_CREATE"

if [ "$MAGIC_ENABLED" != "true" ]; then
    echo -e "${YELLOW}⚠️  Magic mapping is niet enabled, data gaat naar blob storage!${NC}"
fi

echo ""
echo -e "${YELLOW}📋 STAP 2: CSV Import Testen${NC}"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

# Find schema ID for 'module'
SCHEMA_RESPONSE=$(docker exec -u 33 $CONTAINER curl -s -u "$AUTH" \
    -H "Content-Type: application/json" \
    "$API_URL/api/schemas?filters[slug]=module&filters[register]=$REGISTER_ID")

SCHEMA_ID=$(echo "$SCHEMA_RESPONSE" | jq -r '.results[0].id // empty')

if [ -z "$SCHEMA_ID" ]; then
    echo -e "${RED}❌ 'module' schema niet gevonden!${NC}"
    exit 1
fi

echo -e "${GREEN}✓ Schema gevonden: ID $SCHEMA_ID${NC}"

# Copy CSV to container
echo ""
echo "📦 Kopiëren van module.csv naar container..."
docker cp /home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/stackiq/data/module.csv \
    $CONTAINER:/tmp/module.csv

echo -e "${GREEN}✓ CSV gekopieerd${NC}"

# Import CSV
echo ""
echo "🚀 Starten van CSV import..."
echo ""

IMPORT_START=$(date +%s)

IMPORT_RESPONSE=$(docker exec -u 33 $CONTAINER curl -s -u "$AUTH" \
    -H "OCS-APIRequest: true" \
    -F "csv_file=@/tmp/module.csv" \
    -F "register_id=$REGISTER_ID" \
    -F "schema_id=$SCHEMA_ID" \
    -F "mapping={}" \
    -F "validation=true" \
    "$API_URL/api/registers/$REGISTER_ID/import")

IMPORT_END=$(date +%s)
IMPORT_DURATION=$((IMPORT_END - IMPORT_START))

echo "Import Response:"
echo "$IMPORT_RESPONSE" | jq '.'

# Check results
IMPORTED=$(echo "$IMPORT_RESPONSE" | jq -r '.imported // 0')
FAILED=$(echo "$IMPORT_RESPONSE" | jq -r '.failed // 0')
ERRORS=$(echo "$IMPORT_RESPONSE" | jq -r '.errors // []')

echo ""
echo "📊 Import Resultaat:"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "   • Geïmporteerd: $IMPORTED objecten"
echo "   • Gefaald: $FAILED objecten"
echo "   • Duur: ${IMPORT_DURATION}s"

if [ "$FAILED" -gt 0 ]; then
    echo ""
    echo -e "${RED}❌ Errors tijdens import:${NC}"
    echo "$ERRORS" | jq '.'
fi

# Verify in database
echo ""
echo -e "${YELLOW}📋 STAP 3: Verificatie in Database${NC}"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

# Check if magic table exists
TABLE_NAME="oc_openregister_table_${REGISTER_ID}_${SCHEMA_ID}"
echo "🔍 Checken of magic table bestaat: $TABLE_NAME"

TABLES=$(docker exec -it $DB_CONTAINER psql -U nextcloud -d nextcloud -c "\dt oc_openregister_table_*" -t 2>/dev/null | grep -v "^$" || true)

if echo "$TABLES" | grep -q "$TABLE_NAME"; then
    echo -e "${GREEN}✓ Magic table bestaat!${NC}"
    
    # Count rows
    ROW_COUNT=$(docker exec -it $DB_CONTAINER psql -U nextcloud -d nextcloud -c "SELECT COUNT(*) FROM $TABLE_NAME;" -t 2>/dev/null | xargs || echo "0")
    echo "   • Aantal rijen in magic table: $ROW_COUNT"
    
    # Show sample data
    echo ""
    echo "📋 Sample data uit magic table (eerste 3 rijen):"
    docker exec -it $DB_CONTAINER psql -U nextcloud -d nextcloud -c "SELECT _uuid, _title, _created FROM $TABLE_NAME LIMIT 3;" 2>/dev/null || echo "Geen data of error"
else
    echo -e "${YELLOW}⚠️  Magic table bestaat niet, data is in blob storage${NC}"
    
    # Count in blob storage
    BLOB_COUNT=$(docker exec -it $DB_CONTAINER psql -U nextcloud -d nextcloud -c "SELECT COUNT(*) FROM oc_openregister_objects WHERE register='$REGISTER_ID' AND schema='$SCHEMA_ID';" -t 2>/dev/null | xargs || echo "0")
    echo "   • Aantal rijen in blob storage: $BLOB_COUNT"
fi

echo ""
echo -e "${GREEN}✅ Test Compleet!${NC}"

# Cleanup
docker exec $CONTAINER rm -f /tmp/module.csv
