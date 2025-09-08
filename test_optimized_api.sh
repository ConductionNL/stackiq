#!/bin/bash

# ArchiMate Optimized API Performance Test
# This script tests both original and optimized methods via API calls

set -e

# Configuration
BASE_URL="http://localhost"
USERNAME="admin"
PASSWORD="admin"
GEMMA_FILE="lib/Settings/GEMMA_release.xml"
OUTPUT_DIR="./performance_test_results"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

# Create output directory
mkdir -p "$OUTPUT_DIR"

echo -e "${CYAN}🚀 ARCHIMATE OPTIMIZED API PERFORMANCE TEST${NC}"
echo -e "${CYAN}============================================${NC}"
echo

# Check if GEMMA file exists in container
if ! docker-compose exec -T nextcloud test -f "/var/www/html/apps-extra/softwarecatalog/$GEMMA_FILE"; then
    echo -e "${RED}❌ ERROR: GEMMA file not found in container${NC}"
    exit 1
fi

# Get file size
FILE_SIZE=$(docker-compose exec -T nextcloud stat -c%s "/var/www/html/apps-extra/softwarecatalog/$GEMMA_FILE")
FILE_SIZE_MB=$(echo "scale=2; $FILE_SIZE / 1024 / 1024" | bc)
echo -e "${BLUE}📁 Test file: $GEMMA_FILE (${FILE_SIZE_MB} MB)${NC}"
echo

# Function to test import method
test_import_method() {
    local method_name="$1"
    local use_optimized="$2"
    local output_file="$3"
    
    echo -e "${YELLOW}🔄 Testing $method_name method...${NC}"
    
    # Copy file to temp location in container
    docker-compose exec -T nextcloud cp "/var/www/html/apps-extra/softwarecatalog/$GEMMA_FILE" "/tmp/test_gemma.xml"
    
    # Record start time
    local start_time=$(date +%s.%3N)
    
    # Make API call
    local http_code=$(docker-compose exec -T nextcloud curl -s -w "%{http_code}" \
        -o "$output_file" \
        -X POST \
        -u "$USERNAME:$PASSWORD" \
        -F "archiMateFile=@/tmp/test_gemma.xml" \
        -F "useOptimized=$use_optimized" \
        -F "updateExisting=true" \
        -F "preserveIds=true" \
        "$BASE_URL/index.php/apps/softwarecatalog/api/archimate/import" 2>/dev/null || echo "000")
    
    # Record end time
    local end_time=$(date +%s.%3N)
    local duration=$(echo "scale=3; $end_time - $start_time" | bc)
    
    # Clean up temp file
    docker-compose exec -T nextcloud rm -f "/tmp/test_gemma.xml"
    
    if [ "$http_code" = "200" ]; then
        # Parse response
        local success=$(cat "$output_file" | python3 -c "import sys, json; print(json.load(sys.stdin).get('success', False))" 2>/dev/null || echo "false")
        
        if [ "$success" = "True" ] || [ "$success" = "true" ]; then
            echo -e "${GREEN}✅ $method_name method completed successfully${NC}"
            echo -e "${GREEN}   ⏱️  Duration: ${duration}s${NC}"
            
            # Extract object count if available
            local object_count=$(cat "$output_file" | python3 -c "
import sys, json
data = json.load(sys.stdin)
objects = data.get('performance_metrics', {}).get('objects_processed', 0)
if objects == 0:
    objects = data.get('summary', {}).get('total_objects_created', 0)
print(objects)
" 2>/dev/null || echo "0")
            
            if [ "$object_count" -gt 0 ]; then
                local objects_per_sec=$(echo "scale=1; $object_count / $duration" | bc)
                echo -e "${GREEN}   🎯 Objects: $object_count${NC}"
                echo -e "${GREEN}   ⚡ Speed: ${objects_per_sec} objects/sec${NC}"
            fi
            
            # Check if under 60 seconds
            local target_check=$(echo "$duration < 60" | bc)
            if [ "$target_check" = "1" ]; then
                echo -e "${GREEN}   🎉 TARGET ACHIEVED! (< 60s)${NC}"
                return 0
            else
                echo -e "${YELLOW}   ⚠️  Over 60s target${NC}"
                return 1
            fi
        else
            local error=$(cat "$output_file" | python3 -c "import sys, json; print(json.load(sys.stdin).get('error', 'Unknown error'))" 2>/dev/null || echo "Parse error")
            echo -e "${RED}❌ $method_name method failed: $error${NC}"
            return 2
        fi
    else
        echo -e "${RED}❌ $method_name method HTTP error: $http_code${NC}"
        if [ -f "$output_file" ]; then
            echo -e "${RED}Response: $(cat "$output_file")${NC}"
        fi
        return 2
    fi
}

# =============================================================================
# TEST 1: OPTIMIZED METHOD
# =============================================================================
echo -e "${YELLOW}🏎️  TEST 1: OPTIMIZED METHOD${NC}"
echo -e "${YELLOW}$(printf '%*s' 40 '' | tr ' ' '-')${NC}"

optimized_result=0
if test_import_method "OPTIMIZED" "true" "$OUTPUT_DIR/optimized_result.json"; then
    optimized_result=1
    optimized_time=$(cat "$OUTPUT_DIR/optimized_result.json" | python3 -c "
import sys, json
data = json.load(sys.stdin)
print(data.get('performance_metrics', {}).get('total_time_seconds', 0))
" 2>/dev/null || echo "0")
else
    optimized_result=0
fi

echo

# =============================================================================
# TEST 2: ORIGINAL METHOD (for comparison - only if optimized failed)
# =============================================================================
if [ "$optimized_result" = "0" ]; then
    echo -e "${YELLOW}🐌 TEST 2: ORIGINAL METHOD (fallback)${NC}"
    echo -e "${YELLOW}$(printf '%*s' 40 '' | tr ' ' '-')${NC}"
    
    original_result=0
    if test_import_method "ORIGINAL" "false" "$OUTPUT_DIR/original_result.json"; then
        original_result=1
        original_time=$(cat "$OUTPUT_DIR/original_result.json" | python3 -c "
import sys, json
data = json.load(sys.stdin)
print(data.get('processing_times', {}).get('total_time_seconds', 0))
" 2>/dev/null || echo "0")
    fi
    echo
fi

# =============================================================================
# RESULTS SUMMARY
# =============================================================================
echo -e "${CYAN}📊 PERFORMANCE TEST SUMMARY${NC}"
echo -e "${CYAN}$(printf '%*s' 35 '' | tr ' ' '=')${NC}"

if [ "$optimized_result" = "1" ]; then
    echo -e "${GREEN}✅ OPTIMIZED METHOD: SUCCESS${NC}"
    if [ -n "$optimized_time" ] && [ "$optimized_time" != "0" ]; then
        echo -e "${GREEN}   Time: ${optimized_time}s${NC}"
        
        target_check=$(echo "$optimized_time < 60" | bc)
        if [ "$target_check" = "1" ]; then
            echo -e "${GREEN}🎉 PERFORMANCE TARGET ACHIEVED!${NC}"
            echo -e "${GREEN}   Target: 60s, Actual: ${optimized_time}s${NC}"
            target_met=true
        else
            shortfall=$(echo "scale=2; $optimized_time - 60" | bc)
            echo -e "${YELLOW}⚠️  Target missed by ${shortfall}s${NC}"
            target_met=false
        fi
    fi
elif [ "$original_result" = "1" ]; then
    echo -e "${YELLOW}⚠️  OPTIMIZED METHOD FAILED, ORIGINAL WORKS${NC}"
    if [ -n "$original_time" ] && [ "$original_time" != "0" ]; then
        echo -e "${YELLOW}   Original time: ${original_time}s${NC}"
    fi
    target_met=false
else
    echo -e "${RED}❌ BOTH METHODS FAILED${NC}"
    target_met=false
fi

echo

# =============================================================================
# NEXT STEPS
# =============================================================================
echo -e "${CYAN}💡 NEXT STEPS${NC}"
echo -e "${CYAN}$(printf '%*s' 15 '' | tr ' ' '=')${NC}"

if [ "$target_met" = "true" ]; then
    echo -e "${GREEN}✅ Performance optimization successful!${NC}"
    echo -e "${GREEN}• Deploy optimized method as default${NC}"
    echo -e "${GREEN}• Update frontend to use optimized API${NC}"
    echo -e "${GREEN}• Add performance monitoring${NC}"
else
    echo -e "${YELLOW}🔧 Further optimization needed:${NC}"
    echo -e "${YELLOW}• Check logs for bottlenecks${NC}"
    echo -e "${YELLOW}• Implement streaming XML parser${NC}"
    echo -e "${YELLOW}• Add ReactPHP parallel processing${NC}"
    echo -e "${YELLOW}• Optimize database operations${NC}"
fi

echo

# Save results summary
cat > "$OUTPUT_DIR/test_summary.txt" << EOF
ArchiMate Performance Test Results
==================================
Date: $(date)
Test File: $GEMMA_FILE ($FILE_SIZE_MB MB)

Optimized Method: $([ "$optimized_result" = "1" ] && echo "SUCCESS" || echo "FAILED")
$([ -n "$optimized_time" ] && echo "Optimized Time: ${optimized_time}s")

Target (60s): $([ "$target_met" = "true" ] && echo "ACHIEVED" || echo "NOT MET")

Test files saved in: $OUTPUT_DIR/
EOF

echo -e "${BLUE}📄 Results saved to: $OUTPUT_DIR/test_summary.txt${NC}"
echo

# Exit with appropriate code
if [ "$target_met" = "true" ]; then
    echo -e "${GREEN}🏁 TEST COMPLETED SUCCESSFULLY - TARGET ACHIEVED${NC}"
    exit 0
else
    echo -e "${YELLOW}🏁 TEST COMPLETED - FURTHER OPTIMIZATION NEEDED${NC}"
    exit 1
fi
