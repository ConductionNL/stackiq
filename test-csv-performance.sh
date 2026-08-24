#!/bin/bash

###############################################################################
# CSV Import Performance Test via API
###############################################################################
# Tests bulk import to magic-mapped tables using real CSV data
###############################################################################

set -e

# Colors
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
CYAN='\033[0;36m'
NC='\033[0m'

echo -e "${BLUE}"
cat << 'EOF'
╔══════════════════════════════════════════════════════════════════════════════╗
║              CSV IMPORT PERFORMANCE TEST - MAGIC MAPPER                      ║
╚══════════════════════════════════════════════════════════════════════════════╝
EOF
echo -e "${NC}"

CONTAINER="nextcloud"
DB_CONTAINER="openregister-postgres"
API_URL="http://localhost"
AUTH="admin:admin"
DATA_DIR="/var/www/html/custom_apps/stackiq/data"

# Import configuration from magic config
CONFIG_FILE="/var/www/html/custom_apps/stackiq/lib/Settings/softwarecatalogus_register_magic.json"

echo -e "${YELLOW}📋 STAP 1: Importeren van configuratie${NC}"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

echo "🔍 Controleren of configuratie al bestaat..."

# Check if stackiq register exists
REGISTER_RESPONSE=$(docker exec -u 33 $CONTAINER curl -s -u "$AUTH" \
    "$API_URL/api/registers?filters[slug]=stackiq")

REGISTER_ID=$(echo "$REGISTER_RESPONSE" | jq -r '.results[0].id // empty')

if [ -z "$REGISTER_ID" ]; then
    echo -e "${YELLOW}⚠️  'stackiq' register niet gevonden, kopiëren en importeren van configuratie...${NC}"
    
    # Copy config file to container
    docker cp /home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/stackiq/lib/Settings/softwarecatalogus_register_magic.json \
        $CONTAINER:/tmp/softwarecatalogus_register_magic.json
    
    IMPORT_START=$(date +%s)
    
    IMPORT_RESPONSE=$(docker exec -u 33 $CONTAINER sh -c "cat /tmp/softwarecatalogus_register_magic.json | curl -s -u '$AUTH' -X POST -H 'Content-Type: application/json' -d @- '$API_URL/api/configurations'")
    
    IMPORT_END=$(date +%s)
    IMPORT_DURATION=$((IMPORT_END - IMPORT_START))
    
    echo -e "${GREEN}✓ Configuratie geïmporteerd in ${IMPORT_DURATION}s${NC}"
    
    # Clean up
    docker exec $CONTAINER rm -f /tmp/softwarecatalogus_register_magic.json
    
    # Re-fetch register
    REGISTER_RESPONSE=$(docker exec -u 33 $CONTAINER curl -s -u "$AUTH" \
        "$API_URL/api/registers?filters[slug]=stackiq")
    
    REGISTER_ID=$(echo "$REGISTER_RESPONSE" | jq -r '.results[0].id // empty')
fi

if [ -z "$REGISTER_ID" ]; then
    echo -e "${RED}❌ 'stackiq' register niet gevonden na import!${NC}"
    exit 1
fi

echo -e "${GREEN}✓ Register gevonden: ID $REGISTER_ID${NC}"

# CSV files to import (in order of dependencies)
declare -A CSV_FILES
CSV_FILES["organisatie"]="organisatie"
CSV_FILES["module"]="module"
CSV_FILES["moduleversie"]="moduleversie"
CSV_FILES["koppeling"]="koppeling"
CSV_FILES["compliancy"]="compliancy"

echo ""
echo -e "${YELLOW}📋 STAP 2: CSV Import Performance Test${NC}"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

TOTAL_IMPORTED=0
TOTAL_FAILED=0
TOTAL_DURATION=0

for SCHEMA_SLUG in "${!CSV_FILES[@]}"; do
    CSV_FILE="${CSV_FILES[$SCHEMA_SLUG]}.csv"
    
    echo ""
    echo -e "${CYAN}━━━ Importing ${CSV_FILE} ━━━${NC}"
    
    # Get schema ID
    SCHEMA_RESPONSE=$(docker exec -u 33 $CONTAINER curl -s -u "$AUTH" \
        "$API_URL/api/schemas?filters[slug]=$SCHEMA_SLUG&filters[register]=$REGISTER_ID")
    
    SCHEMA_ID=$(echo "$SCHEMA_RESPONSE" | jq -r '.results[0].id // empty')
    
    if [ -z "$SCHEMA_ID" ]; then
        echo -e "${RED}❌ Schema '$SCHEMA_SLUG' niet gevonden!${NC}"
        continue
    fi
    
    echo "   Schema ID: $SCHEMA_ID"
    
    # Count lines in CSV
    LINE_COUNT=$(docker exec $CONTAINER wc -l < ${DATA_DIR}/${CSV_FILE} | xargs)
    OBJECT_COUNT=$((LINE_COUNT - 1))  # Minus header
    
    echo "   Objecten: $OBJECT_COUNT"
    
    # Import via API
    echo "   🚀 Starting import..."
    IMPORT_START=$(date +%s.%N)
    
    IMPORT_RESPONSE=$(docker exec -u 33 $CONTAINER curl -s -u "$AUTH" \
        -X POST \
        -F "csv_file=@${DATA_DIR}/${CSV_FILE}" \
        -F "register_id=$REGISTER_ID" \
        -F "schema_id=$SCHEMA_ID" \
        -F "mapping={}" \
        -F "validation=false" \
        "$API_URL/api/registers/$REGISTER_ID/import")
    
    IMPORT_END=$(date +%s.%N)
    IMPORT_DURATION=$(echo "$IMPORT_END - $IMPORT_START" | bc)
    
    # Parse results
    IMPORTED=$(echo "$IMPORT_RESPONSE" | jq -r '.imported // 0')
    FAILED=$(echo "$IMPORT_RESPONSE" | jq -r '.failed // 0')
    
    # Calculate performance
    if [ "$IMPORT_DURATION" != "0" ]; then
        OBJECTS_PER_SEC=$(echo "scale=2; $IMPORTED / $IMPORT_DURATION" | bc)
    else
        OBJECTS_PER_SEC="N/A"
    fi
    
    # Update totals
    TOTAL_IMPORTED=$((TOTAL_IMPORTED + IMPORTED))
    TOTAL_FAILED=$((TOTAL_FAILED + FAILED))
    TOTAL_DURATION=$(echo "$TOTAL_DURATION + $IMPORT_DURATION" | bc)
    
    # Display results
    if [ "$FAILED" -gt 0 ]; then
        echo -e "   ${YELLOW}⚠️  Imported: $IMPORTED, Failed: $FAILED${NC}"
    else
        echo -e "   ${GREEN}✓ Imported: $IMPORTED${NC}"
    fi
    echo "   ⏱️  Duration: ${IMPORT_DURATION}s"
    echo "   📊 Speed: ${OBJECTS_PER_SEC} objects/sec"
    
    # Check if magic table was created
    TABLE_NAME="oc_openregister_table_${REGISTER_ID}_${SCHEMA_ID}"
    TABLE_EXISTS=$(docker exec $DB_CONTAINER psql -U nextcloud -d nextcloud -tAc "SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name = '$TABLE_NAME');" 2>/dev/null || echo "f")
    
    if [ "$TABLE_EXISTS" = "t" ]; then
        ROW_COUNT=$(docker exec $DB_CONTAINER psql -U nextcloud -d nextcloud -tAc "SELECT COUNT(*) FROM $TABLE_NAME;" 2>/dev/null || echo "0")
        echo -e "   ${GREEN}✓ Magic table: $TABLE_NAME ($ROW_COUNT rows)${NC}"
    else
        BLOB_COUNT=$(docker exec $DB_CONTAINER psql -U nextcloud -d nextcloud -tAc "SELECT COUNT(*) FROM oc_openregister_objects WHERE register='$REGISTER_ID' AND schema='$SCHEMA_ID';" 2>/dev/null || echo "0")
        echo -e "   ${YELLOW}⚠️  Blob storage: $BLOB_COUNT rows${NC}"
    fi
done

echo ""
echo -e "${YELLOW}📊 STAP 3: Performance Samenvatting${NC}"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

# Calculate overall performance
if [ "$TOTAL_DURATION" != "0" ]; then
    OVERALL_SPEED=$(echo "scale=2; $TOTAL_IMPORTED / $TOTAL_DURATION" | bc)
else
    OVERALL_SPEED="N/A"
fi

echo ""
echo "   📦 Total Imported: $TOTAL_IMPORTED objecten"
echo "   ❌ Total Failed: $TOTAL_FAILED objecten"
echo "   ⏱️  Total Duration: ${TOTAL_DURATION}s"
echo "   🚀 Overall Speed: ${OVERALL_SPEED} objects/sec"

# Database statistics
echo ""
echo -e "${YELLOW}📋 STAP 4: Database Statistieken${NC}"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

echo ""
echo "Magic Tables:"
docker exec $DB_CONTAINER psql -U nextcloud -d nextcloud -c "
SELECT 
    tablename,
    pg_size_pretty(pg_total_relation_size(schemaname||'.'||tablename)) AS size
FROM pg_tables 
WHERE tablename LIKE 'oc_openregister_table_%'
ORDER BY tablename;" 2>/dev/null || echo "Geen magic tables gevonden"

echo ""
echo -e "${GREEN}✅ Import Test Compleet!${NC}"
echo ""

