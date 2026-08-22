#!/bin/bash

# ArchiMate API Test Script
# Tests the complete ArchiMate import/export functionality with progress tracking

set -e

# Configuration
DOCKER_COMPOSE_PATH="/home/rubenlinde/nextcloud-docker-dev"
BASE_URL="http://localhost"
AUTH="admin:admin"
GEMMA_FILE="lib/Settings/GEMMA_release.xml"
TEST_OUTPUT_DIR="./test_output"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Logging functions
log_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Create test output directory
mkdir -p "$TEST_OUTPUT_DIR"

log_info "Starting ArchiMate API Testing..."
log_info "Using Docker Compose path: $DOCKER_COMPOSE_PATH"
log_info "Using GEMMA file: $GEMMA_FILE"

# Change to docker compose directory
cd "$DOCKER_COMPOSE_PATH"

# Test 1: Health Check
log_info "Test 1: Health Check"
HEALTH_RESPONSE=$(docker-compose exec -T nextcloud curl -s -w "%{http_code}" -o /tmp/health_response.json -X GET "$BASE_URL/index.php/apps/stackiq/api/health" -H "Content-Type: application/json" -u "$AUTH" || echo "000")

if [ "$HEALTH_RESPONSE" = "200" ]; then
    log_success "Health check passed"
else
    log_warning "Health check returned: $HEALTH_RESPONSE"
fi

# Test 2: Auto-configure AMEF settings
log_info "Test 2: Auto-configuring AMEF settings"
AMEF_AUTO_CONFIG_RESPONSE=$(docker-compose exec -T nextcloud curl -s -w "%{http_code}" -o "$TEST_OUTPUT_DIR/amef_auto_config_response.json" -X POST "$BASE_URL/index.php/apps/stackiq/api/settings/amef/auto-configure" -H "Content-Type: application/json" -u "$AUTH" || echo "000")

if [ "$AMEF_AUTO_CONFIG_RESPONSE" = "200" ]; then
    log_success "AMEF auto-configuration completed"
    cat "$TEST_OUTPUT_DIR/amef_auto_config_response.json" | python3 -m json.tool 2>/dev/null || cat "$TEST_OUTPUT_DIR/amef_auto_config_response.json"
else
    log_error "AMEF auto-configuration failed with status: $AMEF_AUTO_CONFIG_RESPONSE"
    cat "$TEST_OUTPUT_DIR/amef_auto_config_response.json" 2>/dev/null || echo "No response body"
fi

# Test 3: Get AMEF settings to verify configuration
log_info "Test 3: Getting AMEF settings"
AMEF_GET_RESPONSE=$(docker-compose exec -T nextcloud curl -s -w "%{http_code}" -o "$TEST_OUTPUT_DIR/amef_settings.json" -X GET "$BASE_URL/index.php/apps/stackiq/api/settings/amef" -H "Content-Type: application/json" -u "$AUTH" || echo "000")

if [ "$AMEF_GET_RESPONSE" = "200" ]; then
    log_success "AMEF settings retrieved successfully"
    echo "Current AMEF settings:"
    cat "$TEST_OUTPUT_DIR/amef_settings.json" | python3 -m json.tool 2>/dev/null || cat "$TEST_OUTPUT_DIR/amef_settings.json"
else
    log_error "Failed to get AMEF settings with status: $AMEF_GET_RESPONSE"
fi

# Test 4: Import ArchiMate file
log_info "Test 4: Importing GEMMA ArchiMate file"

# Copy the GEMMA file to a temporary location accessible by the container
docker-compose exec -T nextcloud cp "/var/www/html/apps-extra/stackiq/$GEMMA_FILE" "/tmp/test_gemma.xml"

# Import the file using curl with form data
IMPORT_RESPONSE=$(docker-compose exec -T nextcloud curl -s -w "%{http_code}" -o "$TEST_OUTPUT_DIR/import_response.json" -X POST "$BASE_URL/index.php/apps/stackiq/api/archimate/import" -H "Content-Type: multipart/form-data" -u "$AUTH" -F "file=@/tmp/test_gemma.xml" -F "options[update_existing]=true" -F "options[organization_filter]=" || echo "000")

if [ "$IMPORT_RESPONSE" = "200" ]; then
    log_success "ArchiMate import initiated successfully"
    
    # Extract operation ID from response
    OPERATION_ID=$(cat "$TEST_OUTPUT_DIR/import_response.json" | python3 -c "import sys, json; print(json.load(sys.stdin).get('operation_id', ''))" 2>/dev/null || echo "")
    
    if [ -n "$OPERATION_ID" ]; then
        log_info "Operation ID: $OPERATION_ID"
        
        # Test 5: Monitor import progress
        log_info "Test 5: Monitoring import progress"
        
        for i in {1..10}; do
            sleep 2
            PROGRESS_RESPONSE=$(docker-compose exec -T nextcloud curl -s -w "%{http_code}" -o "$TEST_OUTPUT_DIR/progress_$i.json" -X GET "$BASE_URL/index.php/apps/stackiq/api/progress/$OPERATION_ID" -H "Content-Type: application/json" -u "$AUTH" || echo "000")
            
            if [ "$PROGRESS_RESPONSE" = "200" ]; then
                PROGRESS_STATUS=$(cat "$TEST_OUTPUT_DIR/progress_$i.json" | python3 -c "import sys, json; print(json.load(sys.stdin).get('status', ''))" 2>/dev/null || echo "")
                PROGRESS_PHASE=$(cat "$TEST_OUTPUT_DIR/progress_$i.json" | python3 -c "import sys, json; print(json.load(sys.stdin).get('phase', ''))" 2>/dev/null || echo "")
                PROGRESS_PERCENT=$(cat "$TEST_OUTPUT_DIR/progress_$i.json" | python3 -c "import sys, json; print(json.load(sys.stdin).get('percentage', 0))" 2>/dev/null || echo "0")
                
                log_info "Progress check $i: Status=$PROGRESS_STATUS, Phase=$PROGRESS_PHASE, Progress=$PROGRESS_PERCENT%"
                
                if [ "$PROGRESS_STATUS" = "completed" ]; then
                    log_success "Import completed successfully!"
                    cat "$TEST_OUTPUT_DIR/progress_$i.json" | python3 -m json.tool 2>/dev/null || cat "$TEST_OUTPUT_DIR/progress_$i.json"
                    break
                elif [ "$PROGRESS_STATUS" = "failed" ]; then
                    log_error "Import failed!"
                    cat "$TEST_OUTPUT_DIR/progress_$i.json" | python3 -m json.tool 2>/dev/null || cat "$TEST_OUTPUT_DIR/progress_$i.json"
                    break
                fi
            else
                log_warning "Progress check $i failed with status: $PROGRESS_RESPONSE"
            fi
        done
    else
        log_warning "No operation ID found in import response"
        cat "$TEST_OUTPUT_DIR/import_response.json" | python3 -m json.tool 2>/dev/null || cat "$TEST_OUTPUT_DIR/import_response.json"
    fi
else
    log_error "ArchiMate import failed with status: $IMPORT_RESPONSE"
    cat "$TEST_OUTPUT_DIR/import_response.json" 2>/dev/null || echo "No response body"
fi

# Test 6: Export ArchiMate data
log_info "Test 6: Exporting ArchiMate data"
EXPORT_RESPONSE=$(docker-compose exec -T nextcloud curl -s -w "%{http_code}" -o "$TEST_OUTPUT_DIR/export_response.json" -X POST "$BASE_URL/index.php/apps/stackiq/api/archimate/export" -H "Content-Type: application/json" -u "$AUTH" -d '{
    "format": "xml",
    "include_relationships": true,
    "include_views": true,
    "organization_specific": false
}' || echo "000")

if [ "$EXPORT_RESPONSE" = "200" ]; then
    log_success "ArchiMate export initiated successfully"
    
    # Extract operation ID and file name from response
    EXPORT_OPERATION_ID=$(cat "$TEST_OUTPUT_DIR/export_response.json" | python3 -c "import sys, json; print(json.load(sys.stdin).get('operation_id', ''))" 2>/dev/null || echo "")
    EXPORT_FILE_NAME=$(cat "$TEST_OUTPUT_DIR/export_response.json" | python3 -c "import sys, json; print(json.load(sys.stdin).get('file_name', ''))" 2>/dev/null || echo "")
    
    if [ -n "$EXPORT_OPERATION_ID" ]; then
        log_info "Export Operation ID: $EXPORT_OPERATION_ID"
        
        # Monitor export progress
        for i in {1..10}; do
            sleep 2
            EXPORT_PROGRESS_RESPONSE=$(docker-compose exec -T nextcloud curl -s -w "%{http_code}" -o "$TEST_OUTPUT_DIR/export_progress_$i.json" -X GET "$BASE_URL/index.php/apps/stackiq/api/progress/$EXPORT_OPERATION_ID" -H "Content-Type: application/json" -u "$AUTH" || echo "000")
            
            if [ "$EXPORT_PROGRESS_RESPONSE" = "200" ]; then
                EXPORT_PROGRESS_STATUS=$(cat "$TEST_OUTPUT_DIR/export_progress_$i.json" | python3 -c "import sys, json; print(json.load(sys.stdin).get('status', ''))" 2>/dev/null || echo "")
                EXPORT_PROGRESS_PHASE=$(cat "$TEST_OUTPUT_DIR/export_progress_$i.json" | python3 -c "import sys, json; print(json.load(sys.stdin).get('phase', ''))" 2>/dev/null || echo "")
                EXPORT_PROGRESS_PERCENT=$(cat "$TEST_OUTPUT_DIR/export_progress_$i.json" | python3 -c "import sys, json; print(json.load(sys.stdin).get('percentage', 0))" 2>/dev/null || echo "0")
                
                log_info "Export progress check $i: Status=$EXPORT_PROGRESS_STATUS, Phase=$EXPORT_PROGRESS_PHASE, Progress=$EXPORT_PROGRESS_PERCENT%"
                
                if [ "$EXPORT_PROGRESS_STATUS" = "completed" ]; then
                    log_success "Export completed successfully!"
                    cat "$TEST_OUTPUT_DIR/export_progress_$i.json" | python3 -m json.tool 2>/dev/null || cat "$TEST_OUTPUT_DIR/export_progress_$i.json"
                    break
                elif [ "$EXPORT_PROGRESS_STATUS" = "failed" ]; then
                    log_error "Export failed!"
                    cat "$TEST_OUTPUT_DIR/export_progress_$i.json" | python3 -m json.tool 2>/dev/null || cat "$TEST_OUTPUT_DIR/export_progress_$i.json"
                    break
                fi
            else
                log_warning "Export progress check $i failed with status: $EXPORT_PROGRESS_RESPONSE"
            fi
        done
        
        # Test 7: Download exported file
        if [ -n "$EXPORT_FILE_NAME" ]; then
            log_info "Test 7: Downloading exported file: $EXPORT_FILE_NAME"
            DOWNLOAD_RESPONSE=$(docker-compose exec -T nextcloud curl -s -w "%{http_code}" -o "$TEST_OUTPUT_DIR/exported_archimate.xml" -X GET "$BASE_URL/index.php/apps/stackiq/api/archimate/download/$EXPORT_FILE_NAME" -u "$AUTH" || echo "000")
            
            if [ "$DOWNLOAD_RESPONSE" = "200" ]; then
                log_success "Downloaded exported ArchiMate file successfully"
                
                # Basic file size check
                EXPORTED_SIZE=$(wc -c < "$TEST_OUTPUT_DIR/exported_archimate.xml" 2>/dev/null || echo "0")
                log_info "Exported file size: $EXPORTED_SIZE bytes"
                
                if [ "$EXPORTED_SIZE" -gt 100 ]; then
                    log_success "Exported file appears to have content"
                    
                    # Show first few lines of exported file
                    echo "First 10 lines of exported file:"
                    head -n 10 "$TEST_OUTPUT_DIR/exported_archimate.xml" 2>/dev/null || echo "Could not read exported file"
                else
                    log_warning "Exported file seems very small or empty"
                fi
            else
                log_error "Failed to download exported file with status: $DOWNLOAD_RESPONSE"
            fi
        else
            log_warning "No export file name found in response"
        fi
    else
        log_warning "No export operation ID found in response"
        cat "$TEST_OUTPUT_DIR/export_response.json" | python3 -m json.tool 2>/dev/null || cat "$TEST_OUTPUT_DIR/export_response.json"
    fi
else
    log_error "ArchiMate export failed with status: $EXPORT_RESPONSE"
    cat "$TEST_OUTPUT_DIR/export_response.json" 2>/dev/null || echo "No response body"
fi

# Test 8: File comparison (basic check)
log_info "Test 8: Basic file comparison"
if [ -f "$TEST_OUTPUT_DIR/exported_archimate.xml" ] && [ -s "$TEST_OUTPUT_DIR/exported_archimate.xml" ]; then
    ORIGINAL_SIZE=$(docker-compose exec -T nextcloud wc -c < "/var/www/html/apps-extra/stackiq/$GEMMA_FILE" 2>/dev/null || echo "0")
    EXPORTED_SIZE=$(wc -c < "$TEST_OUTPUT_DIR/exported_archimate.xml" 2>/dev/null || echo "0")
    
    log_info "Original file size: $ORIGINAL_SIZE bytes"
    log_info "Exported file size: $EXPORTED_SIZE bytes"
    
    if [ "$EXPORTED_SIZE" -gt 0 ]; then
        SIZE_RATIO=$(python3 -c "print(round($EXPORTED_SIZE / max($ORIGINAL_SIZE, 1) * 100, 2))" 2>/dev/null || echo "unknown")
        log_info "Size ratio: $SIZE_RATIO% of original"
        
        if [ "$EXPORTED_SIZE" -gt $(($ORIGINAL_SIZE / 4)) ]; then
            log_success "Exported file has reasonable size compared to original"
        else
            log_warning "Exported file is significantly smaller than original"
        fi
    else
        log_error "Exported file is empty"
    fi
else
    log_error "No exported file found to compare"
fi

# Cleanup
log_info "Cleaning up temporary files"
docker-compose exec -T nextcloud rm -f "/tmp/test_gemma.xml" 2>/dev/null || true

# Summary
log_info "Test Summary:"
log_info "- Test output files saved in: $TEST_OUTPUT_DIR"
log_info "- Original GEMMA file: $GEMMA_FILE"
log_info "- Check the log above for detailed results"

log_success "ArchiMate API testing completed!"