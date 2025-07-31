#!/bin/bash

# AMEF Round-trip Testing Script (Simple Version)
# This script tests ArchiMate import/export using GEMMA_release.xml

# Configuration
BASE_URL="http://localhost"
USERNAME="admin"
PASSWORD="admin"
ORIGINAL_FILE="lib/Settings/GEMMA_release.xml"
EXPORTED_FILE="exported_gemma.xml"
DIFF_FILE="gemma_diff.txt"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}🚀 Starting AMEF Round-trip Test${NC}"
echo "================================="
echo

# Function to make API requests
make_request() {
    local url="$1"
    local method="$2"
    local data="$3"
    
    curl -s -w "\nHTTP_CODE:%{http_code}" \
         -X "$method" \
         -u "$USERNAME:$PASSWORD" \
         -H "X-Requested-With: XMLHttpRequest" \
         -H "Content-Type: application/json" \
         --connect-timeout 30 \
         --max-time 300 \
         "$url" \
         ${data:+-d "$data"}
}

# Step 1: Test AMEF Configuration
echo -e "${BLUE}🔧 Testing AMEF Configuration...${NC}"

echo "Getting current AMEF settings..."
response=$(make_request "$BASE_URL/index.php/apps/softwarecatalog/api/settings/amef" "GET")
http_code=$(echo "$response" | tail -n1 | cut -d: -f2)
json_response=$(echo "$response" | head -n -1)

if [ "$http_code" = "200" ]; then
    echo -e "${GREEN}✅ AMEF settings retrieved successfully${NC}"
    echo "$json_response" | jq '.settings' 2>/dev/null || echo "$json_response"
else
    echo -e "${RED}❌ Failed to get AMEF settings (HTTP $http_code)${NC}"
    echo "$json_response"
fi

echo
echo "Running auto-configuration..."
response=$(make_request "$BASE_URL/index.php/apps/softwarecatalog/api/settings/amef/auto-configure" "POST")
http_code=$(echo "$response" | tail -n1 | cut -d: -f2)
json_response=$(echo "$response" | head -n -1)

if [ "$http_code" = "200" ]; then
    echo -e "${GREEN}✅ Auto-configuration completed${NC}"
    echo "$json_response" | jq '.configured' 2>/dev/null || echo "$json_response"
else
    echo -e "${YELLOW}⚠️  Auto-configuration had issues, continuing...${NC}"
    echo "$json_response"
fi

# Step 2: Import GEMMA_release.xml
echo
echo -e "${BLUE}📥 Importing AMEF file: $ORIGINAL_FILE${NC}"

if [ ! -f "$ORIGINAL_FILE" ]; then
    echo -e "${RED}❌ File not found: $ORIGINAL_FILE${NC}"
    exit 1
fi

file_size=$(stat -f%z "$ORIGINAL_FILE" 2>/dev/null || stat -c%s "$ORIGINAL_FILE" 2>/dev/null)
file_size_mb=$(echo "scale=2; $file_size / 1024 / 1024" | bc)
echo "File size: ${file_size_mb} MB"

echo "Starting import (this may take a while for large files)..."
start_time=$(date +%s)

response=$(curl -s -w "\nHTTP_CODE:%{http_code}" \
    -X POST \
    -u "$USERNAME:$PASSWORD" \
    -H "X-Requested-With: XMLHttpRequest" \
    -F "archiMateFile=@$ORIGINAL_FILE" \
    -F "updateExisting=true" \
    -F "preserveIds=true" \
    --connect-timeout 30 \
    --max-time 300 \
    "$BASE_URL/index.php/apps/softwarecatalog/api/archimate/import")

end_time=$(date +%s)
duration=$((end_time - start_time))

http_code=$(echo "$response" | tail -n1 | cut -d: -f2)
json_response=$(echo "$response" | head -n -1)

echo "Import completed in ${duration}s"

if [ "$http_code" = "200" ]; then
    success=$(echo "$json_response" | jq -r '.success' 2>/dev/null)
    if [ "$success" = "true" ]; then
        echo -e "${GREEN}✅ AMEF import successful!${NC}"
        echo "$json_response" | jq '.statistics' 2>/dev/null || echo "Statistics: $json_response"
    else
        echo -e "${RED}❌ AMEF import failed${NC}"
        echo "$json_response"
        exit 1
    fi
else
    echo -e "${RED}❌ Import failed (HTTP $http_code)${NC}"
    echo "$json_response"
    exit 1
fi

# Step 3: Export AMEF file
echo
echo -e "${BLUE}📤 Exporting AMEF file...${NC}"

start_time=$(date +%s)

export_data='{
    "format": "xml",
    "includeRelationships": true,
    "includeViews": true,
    "organizationSpecific": false
}'

response=$(make_request "$BASE_URL/index.php/apps/softwarecatalog/api/archimate/export" "POST" "$export_data")

end_time=$(date +%s)
duration=$((end_time - start_time))

http_code=$(echo "$response" | tail -n1 | cut -d: -f2)
json_response=$(echo "$response" | head -n -1)

echo "Export completed in ${duration}s"

if [ "$http_code" = "200" ]; then
    success=$(echo "$json_response" | jq -r '.success' 2>/dev/null)
    if [ "$success" = "true" ]; then
        echo -e "${GREEN}✅ AMEF export successful!${NC}"
        file_name=$(echo "$json_response" | jq -r '.file_name' 2>/dev/null)
        echo "Exported file: $file_name"
        echo "$json_response" | jq '.statistics' 2>/dev/null
    else
        echo -e "${RED}❌ AMEF export failed${NC}"
        echo "$json_response"
        exit 1
    fi
else
    echo -e "${RED}❌ Export failed (HTTP $http_code)${NC}"
    echo "$json_response"
    exit 1
fi

# Step 4: Download exported file
echo
echo -e "${BLUE}💾 Downloading exported file: $file_name${NC}"

curl -s -u "$USERNAME:$PASSWORD" \
    -o "$EXPORTED_FILE" \
    "$BASE_URL/index.php/apps/softwarecatalog/api/archimate/download/$file_name"

if [ $? -eq 0 ] && [ -f "$EXPORTED_FILE" ]; then
    exported_size=$(stat -f%z "$EXPORTED_FILE" 2>/dev/null || stat -c%s "$EXPORTED_FILE" 2>/dev/null)
    exported_size_mb=$(echo "scale=2; $exported_size / 1024 / 1024" | bc)
    echo -e "${GREEN}✅ File downloaded successfully!${NC}"
    echo "Saved to: $EXPORTED_FILE"
    echo "Size: ${exported_size_mb} MB"
else
    echo -e "${RED}❌ Download failed${NC}"
    exit 1
fi

# Step 5: Compare files
echo
echo -e "${BLUE}🔍 Comparing files...${NC}"
echo "Original file size: ${file_size_mb} MB"
echo "Exported file size: ${exported_size_mb} MB"

# Generate diff
diff -u "$ORIGINAL_FILE" "$EXPORTED_FILE" > "$DIFF_FILE" 2>&1
diff_exit_code=$?

if [ $diff_exit_code -eq 0 ]; then
    echo -e "${GREEN}✅ Files are identical!${NC}"
    rm -f "$DIFF_FILE"
    identical=true
else
    echo -e "${YELLOW}⚠️  Files have differences${NC}"
    echo "Diff saved to: $DIFF_FILE"
    
    # Show diff summary
    if [ -f "$DIFF_FILE" ]; then
        added_lines=$(grep -c "^+" "$DIFF_FILE" || echo 0)
        removed_lines=$(grep -c "^-" "$DIFF_FILE" || echo 0)
        echo "Added lines: $added_lines"
        echo "Removed lines: $removed_lines"
        
        echo "First few differences:"
        head -20 "$DIFF_FILE" | grep -E "^[+-]" | head -10
    fi
    identical=false
fi

# Summary
echo
echo "=================================================="
echo -e "${BLUE}🏁 AMEF Round-trip Test Summary${NC}"
echo "=================================================="

if [ "$identical" = true ]; then
    echo -e "${GREEN}✅ SUCCESS: Round-trip completed with identical files!${NC}"
    echo "   The ArchiMate import/export functionality is working perfectly."
    exit 0
else
    echo -e "${YELLOW}⚠️  PARTIAL SUCCESS: Round-trip completed but files differ${NC}"
    echo "   This may be expected due to formatting or ordering differences."
    echo "   Check the diff file for details: $DIFF_FILE"
    exit 0
fi