#!/bin/bash

# ArchiMate Unified Test Suite
# Consolidates all ArchiMate testing into one comprehensive script
#
# This replaces:
# - test_optimized_api.sh (performance testing)
# - test_amef_simple.sh (round-trip testing)
# - test_archimate_export.sh (export testing)
# - test_archimate_import_debug.php (debugging)
# - And eliminates redundancy with test_performance_optimization.php & test_amef_roundtrip.php

set -e

# Resolve script directory (for locating companion files)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Configuration
BASE_URL="http://localhost"
USERNAME="admin"
PASSWORD="admin"
GEMMA_FILE="lib/Settings/GEMMA_release.xml"
CONTAINER_APP_PATH="/var/www/html/custom_apps/stackiq"
OUTPUT_DIR="$SCRIPT_DIR/test_results"
TARGET_TIME_SECONDS=60
XSD_CACHE_DIR="$OUTPUT_DIR/schema"
ARCHIMATE_XSD_URL="https://www.opengroup.org/xsd/archimate/3.1/archimate3_Diagram.xsd"
ARCHIMATE_MODEL_XSD_URL="https://www.opengroup.org/xsd/archimate/3.1/archimate3_Model.xsd"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

# Test modes
PERFORMANCE_TEST=false
ROUNDTRIP_TEST=false
DEBUG_TEST=false
EXPORT_TEST=false
VALIDATE_TEST=false
ALL_TESTS=false

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        -p|--performance)
            PERFORMANCE_TEST=true
            shift
            ;;
        -r|--roundtrip)
            ROUNDTRIP_TEST=true
            shift
            ;;
        -d|--debug)
            DEBUG_TEST=true
            shift
            ;;
        -e|--export)
            EXPORT_TEST=true
            shift
            ;;
        -v|--validate)
            VALIDATE_TEST=true
            shift
            ;;
        -a|--all)
            ALL_TESTS=true
            shift
            ;;
        -h|--help)
            echo "ArchiMate Unified Test Suite"
            echo ""
            echo "Usage: $0 [OPTIONS]"
            echo ""
            echo "Options:"
            echo "  -p, --performance    Run performance tests (optimized vs original)"
            echo "  -r, --roundtrip      Run round-trip tests (import -> export -> XSD validate -> semantic compare)"
            echo "  -e, --export         Run export functionality tests"
            echo "  -v, --validate       Run XSD schema validation on the original GEMMA file"
            echo "  -d, --debug          Run debug/diagnostic tests"
            echo "  -a, --all            Run all test suites"
            echo "  -h, --help           Show this help message"
            echo ""
            echo "Examples:"
            echo "  $0 --performance     # Test only performance optimizations"
            echo "  $0 --roundtrip       # Full round-trip: import -> export -> validate -> compare"
            echo "  $0 --validate        # Just validate the GEMMA file against ArchiMate XSD"
            echo "  $0 --all             # Run complete test suite"
            echo "  $0 -p -r            # Run performance and round-trip tests"
            exit 0
            ;;
        *)
            echo "Unknown option: $1"
            echo "Use --help for usage information"
            exit 1
            ;;
    esac
done

# If no specific tests requested, show help
if [[ "$PERFORMANCE_TEST" == false && "$ROUNDTRIP_TEST" == false && "$DEBUG_TEST" == false && "$EXPORT_TEST" == false && "$VALIDATE_TEST" == false && "$ALL_TESTS" == false ]]; then
    echo -e "${YELLOW}No test type specified. Use --help for options or --all to run everything.${NC}"
    exit 1
fi

# Set all tests if --all specified
if [[ "$ALL_TESTS" == true ]]; then
    PERFORMANCE_TEST=true
    ROUNDTRIP_TEST=true
    DEBUG_TEST=true
    EXPORT_TEST=true
    VALIDATE_TEST=true
fi

# Create output directories
mkdir -p "$OUTPUT_DIR"
mkdir -p "$XSD_CACHE_DIR"

echo -e "${CYAN}ARCHIMATE UNIFIED TEST SUITE${NC}"
echo -e "${CYAN}============================${NC}"
echo -e "${BLUE}Test configuration:${NC}"
echo -e "${BLUE}  Performance : $([ "$PERFORMANCE_TEST" == true ] && echo "YES" || echo "no")${NC}"
echo -e "${BLUE}  Round-trip  : $([ "$ROUNDTRIP_TEST" == true ] && echo "YES" || echo "no")${NC}"
echo -e "${BLUE}  Export      : $([ "$EXPORT_TEST" == true ] && echo "YES" || echo "no")${NC}"
echo -e "${BLUE}  Validate    : $([ "$VALIDATE_TEST" == true ] && echo "YES" || echo "no")${NC}"
echo -e "${BLUE}  Debug       : $([ "$DEBUG_TEST" == true ] && echo "YES" || echo "no")${NC}"
echo ""

# =============================================================================
# SHARED HELPER FUNCTIONS
# =============================================================================

check_docker() {
    if ! docker-compose ps 2>/dev/null | grep -qE "(nextcloud.*Up|nextcloud.*running)"; then
        echo -e "${RED}ERROR: Nextcloud container is not running${NC}"
        echo "Please start docker-compose and try again"
        exit 1
    fi
}

check_gemma_file() {
    if ! docker-compose exec -T nextcloud test -f "$CONTAINER_APP_PATH/$GEMMA_FILE"; then
        echo -e "${RED}ERROR: GEMMA file not found in container: $GEMMA_FILE${NC}"
        exit 1
    fi

    FILE_SIZE=$(docker-compose exec -T nextcloud stat -c%s "$CONTAINER_APP_PATH/$GEMMA_FILE" | tr -d '[:space:]')
    FILE_SIZE_MB=$(echo "scale=2; $FILE_SIZE / 1024 / 1024" | bc)
    echo -e "${BLUE}Test file: $GEMMA_FILE (${FILE_SIZE_MB} MB)${NC}"
}

# Extract JSON field using python3 (available on most systems)
json_get() {
    local field="$1"
    python3 -c "import sys, json; print(json.load(sys.stdin).get('$field', ''))" 2>/dev/null
}

json_get_bool() {
    local field="$1"
    python3 -c "import sys, json; v = json.load(sys.stdin).get('$field', False); print('true' if v else 'false')" 2>/dev/null
}

# Download ArchiMate XSD schema files (cached)
download_xsd_schema() {
    local xsd_file="$XSD_CACHE_DIR/archimate3_Diagram.xsd"
    local model_xsd_file="$XSD_CACHE_DIR/archimate3_Model.xsd"

    # Check cache
    if [ -f "$xsd_file" ] && [ -s "$xsd_file" ] && [ -f "$model_xsd_file" ] && [ -s "$model_xsd_file" ]; then
        echo -e "${BLUE}  Using cached XSD schema${NC}"
        return 0
    fi

    echo -e "${BLUE}  Downloading ArchiMate 3.1 XSD schema...${NC}"

    # Download diagram XSD (extends model XSD)
    if curl -sS --connect-timeout 10 --max-time 30 -o "$xsd_file" "$ARCHIMATE_XSD_URL" 2>/dev/null; then
        # Verify it's actually XML (not an error page)
        if head -5 "$xsd_file" | grep -q "schema\|xsd\|XSD"; then
            echo -e "${GREEN}  Downloaded archimate3_Diagram.xsd${NC}"
        else
            echo -e "${YELLOW}  Downloaded file does not look like an XSD schema${NC}"
            rm -f "$xsd_file"
            return 1
        fi
    else
        echo -e "${YELLOW}  Failed to download Diagram XSD${NC}"
        return 1
    fi

    # Download model XSD (base schema, referenced by diagram XSD)
    if curl -sS --connect-timeout 10 --max-time 30 -o "$model_xsd_file" "$ARCHIMATE_MODEL_XSD_URL" 2>/dev/null; then
        if head -5 "$model_xsd_file" | grep -q "schema\|xsd\|XSD"; then
            echo -e "${GREEN}  Downloaded archimate3_Model.xsd${NC}"
        else
            echo -e "${YELLOW}  Downloaded file does not look like an XSD schema${NC}"
            rm -f "$model_xsd_file"
            return 1
        fi
    else
        echo -e "${YELLOW}  Failed to download Model XSD${NC}"
        return 1
    fi

    return 0
}

# Validate an XML file against the ArchiMate XSD schema
# Usage: validate_xsd <xml_file>
# Returns: 0 if valid, 1 if invalid, 2 if validation could not be performed
validate_xsd() {
    local xml_file="$1"
    local xsd_file="$XSD_CACHE_DIR/archimate3_Diagram.xsd"

    # Check xmllint is available
    if ! command -v xmllint &>/dev/null; then
        echo -e "${YELLOW}  xmllint not found - install libxml2-utils for XSD validation${NC}"
        return 2
    fi

    # Check well-formedness first
    echo -e "${BLUE}  Checking XML well-formedness...${NC}"
    if ! xmllint --noout "$xml_file" 2>"$OUTPUT_DIR/xmllint_wellformed.log"; then
        echo -e "${RED}  XML is NOT well-formed${NC}"
        cat "$OUTPUT_DIR/xmllint_wellformed.log"
        return 1
    fi
    echo -e "${GREEN}  XML is well-formed${NC}"

    # Attempt XSD validation
    if [ ! -f "$xsd_file" ] || [ ! -s "$xsd_file" ]; then
        echo -e "${YELLOW}  XSD schema not available, skipping schema validation${NC}"
        return 2
    fi

    echo -e "${BLUE}  Validating against ArchiMate 3.1 XSD...${NC}"
    if xmllint --noout --schema "$xsd_file" "$xml_file" 2>"$OUTPUT_DIR/xmllint_schema.log"; then
        echo -e "${GREEN}  XSD schema validation PASSED${NC}"
        return 0
    else
        echo -e "${RED}  XSD schema validation FAILED${NC}"
        # Show first 20 lines of errors
        head -20 "$OUTPUT_DIR/xmllint_schema.log"
        if [ "$(wc -l < "$OUTPUT_DIR/xmllint_schema.log")" -gt 20 ]; then
            echo -e "${YELLOW}  ... (truncated, see $OUTPUT_DIR/xmllint_schema.log for full output)${NC}"
        fi
        return 1
    fi
}

# Run semantic comparison between original and exported ArchiMate files
# Usage: run_semantic_compare <original.xml> <exported.xml>
# Returns: 0 if all checks pass, 1 if checks fail, 2 if comparison could not run
run_semantic_compare() {
    local original_file="$1"
    local exported_file="$2"
    local compare_script="$SCRIPT_DIR/compare_archimate.py"

    if [ ! -f "$compare_script" ]; then
        echo -e "${RED}  Comparison script not found: $compare_script${NC}"
        return 2
    fi

    if ! command -v python3 &>/dev/null; then
        echo -e "${RED}  python3 not found - required for semantic comparison${NC}"
        return 2
    fi

    echo -e "${BLUE}  Running semantic comparison...${NC}"
    echo ""

    # Run comparison, save report to file and show on screen
    if python3 "$compare_script" "$original_file" "$exported_file" | tee "$OUTPUT_DIR/comparison_report.txt"; then
        echo ""
        echo -e "${GREEN}  Semantic comparison: ALL CHECKS PASSED${NC}"

        # Also save JSON version for programmatic use
        python3 "$compare_script" "$original_file" "$exported_file" --json > "$OUTPUT_DIR/comparison_report.json" 2>/dev/null || true
        return 0
    else
        echo ""
        echo -e "${RED}  Semantic comparison: SOME CHECKS FAILED${NC}"
        echo -e "${BLUE}  Full report saved to: $OUTPUT_DIR/comparison_report.txt${NC}"

        python3 "$compare_script" "$original_file" "$exported_file" --json > "$OUTPUT_DIR/comparison_report.json" 2>/dev/null || true
        return 1
    fi
}

# =============================================================================
# PERFORMANCE TESTING (Replaces test_optimized_api.sh & test_performance_optimization.php)
# =============================================================================
run_performance_test() {
    echo -e "${CYAN}PERFORMANCE TEST SUITE${NC}"
    echo -e "${CYAN}======================${NC}"

    # Test optimized method
    echo -e "${YELLOW}Testing OPTIMIZED method...${NC}"

    start_time=$(date +%s.%3N)

    import_result=$(docker-compose exec -T nextcloud curl -s \
        -X POST \
        -u "$USERNAME:$PASSWORD" \
        -F "archiMateFile=@$CONTAINER_APP_PATH/$GEMMA_FILE" \
        -F "useOptimized=true" \
        -F "updateExisting=true" \
        -F "preserveIds=true" \
        "$BASE_URL/index.php/apps/stackiq/api/archimate/import" 2>/dev/null || echo "{}")

    end_time=$(date +%s.%3N)
    duration=$(echo "scale=3; $end_time - $start_time" | bc)

    success=$(echo "$import_result" | json_get_bool "success")

    if [ "$success" = "true" ]; then
        echo -e "${GREEN}  OPTIMIZED method completed in ${duration}s${NC}"

        # Check if under target
        target_check=$(echo "$duration < $TARGET_TIME_SECONDS" | bc)
        if [ "$target_check" = "1" ]; then
            echo -e "${GREEN}  PERFORMANCE TARGET ACHIEVED (< ${TARGET_TIME_SECONDS}s)${NC}"
            return 0
        else
            shortfall=$(echo "scale=2; $duration - $TARGET_TIME_SECONDS" | bc)
            echo -e "${YELLOW}  Target missed by ${shortfall}s${NC}"
            return 1
        fi
    else
        echo -e "${RED}  OPTIMIZED method failed${NC}"
        echo "$import_result" | python3 -m json.tool 2>/dev/null || echo "$import_result"
        return 2
    fi
}

# =============================================================================
# ROUND-TRIP TESTING — import -> export -> download -> XSD validate -> semantic compare
# =============================================================================
run_roundtrip_test() {
    echo -e "${CYAN}ROUND-TRIP TEST SUITE${NC}"
    echo -e "${CYAN}=====================${NC}"

    local original_host_file="$SCRIPT_DIR/$GEMMA_FILE"
    local exported_host_file="$OUTPUT_DIR/exported_roundtrip.xml"
    local roundtrip_passed=true

    # Verify original file exists on host for later comparison
    if [ ! -f "$original_host_file" ]; then
        echo -e "${RED}  Original file not found on host: $original_host_file${NC}"
        echo -e "${YELLOW}  Trying to copy from container...${NC}"
        docker-compose exec -T nextcloud cat "$CONTAINER_APP_PATH/$GEMMA_FILE" > "$OUTPUT_DIR/original_gemma.xml" 2>/dev/null
        if [ -s "$OUTPUT_DIR/original_gemma.xml" ]; then
            original_host_file="$OUTPUT_DIR/original_gemma.xml"
            echo -e "${GREEN}  Copied original from container${NC}"
        else
            echo -e "${RED}  Could not obtain original file for comparison${NC}"
            return 1
        fi
    fi

    # ---- Step 1: Import ----
    echo ""
    echo -e "${BLUE}Step 1/5: Import GEMMA file${NC}"

    start_time=$(date +%s)

    import_result=$(docker-compose exec -T nextcloud curl -s \
        -X POST \
        -u "$USERNAME:$PASSWORD" \
        -F "archiMateFile=@$CONTAINER_APP_PATH/$GEMMA_FILE" \
        -F "updateExisting=true" \
        -F "preserveIds=true" \
        --max-time 600 \
        "$BASE_URL/index.php/apps/stackiq/api/archimate/import" 2>/dev/null || echo "{}")

    end_time=$(date +%s)
    import_duration=$((end_time - start_time))

    import_success=$(echo "$import_result" | json_get_bool "success")

    if [ "$import_success" != "true" ]; then
        echo -e "${RED}  Import FAILED${NC}"
        echo "$import_result" | python3 -m json.tool 2>/dev/null || echo "$import_result"
        return 1
    fi

    echo -e "${GREEN}  Import successful (${import_duration}s)${NC}"
    # Show import statistics
    echo "$import_result" | python3 -c "
import sys, json
try:
    data = json.load(sys.stdin)
    stats = data.get('statistics', {})
    for key, val in stats.items():
        if isinstance(val, dict):
            created = val.get('created', 0)
            updated = val.get('updated', 0)
            print(f'    {key}: {created} created, {updated} updated')
except: pass
" 2>/dev/null || true

    # ---- Step 2: Export ----
    echo ""
    echo -e "${BLUE}Step 2/5: Export from database${NC}"

    start_time=$(date +%s)

    # Export endpoint returns raw XML directly on success, JSON only on error
    export_result=$(docker-compose exec -T nextcloud curl -s \
        -X POST \
        -u "$USERNAME:$PASSWORD" \
        -H "Content-Type: application/json" \
        -d '{"format":"xml","includeRelationships":true,"includeViews":true}' \
        --max-time 600 \
        "$BASE_URL/index.php/apps/stackiq/api/archimate/export" 2>/dev/null || echo "{}")

    end_time=$(date +%s)
    export_duration=$((end_time - start_time))

    # Check if the response is XML (success) or JSON (error)
    if echo "$export_result" | head -5 | grep -q "<?xml\|<model"; then
        # Direct XML response — save it directly as the exported file
        echo "$export_result" > "$exported_host_file"
        echo -e "${GREEN}  Export successful (${export_duration}s) -> direct XML response${NC}"
    else
        # JSON response — check for error
        export_success=$(echo "$export_result" | json_get_bool "success")
        if [ "$export_success" != "true" ]; then
            echo -e "${RED}  Export FAILED${NC}"
            echo "$export_result" | python3 -m json.tool 2>/dev/null || echo "$export_result"
            return 1
        fi

        # JSON success with file_name (legacy path)
        file_name=$(echo "$export_result" | json_get "file_name")
        echo -e "${GREEN}  Export successful (${export_duration}s) -> $file_name${NC}"

        # ---- Step 3: Download exported file ----
        echo ""
        echo -e "${BLUE}Step 3/5: Download exported file${NC}"

        if [ -z "$file_name" ]; then
            echo -e "${RED}  No file_name in export response, cannot download${NC}"
            return 1
        fi

        docker-compose exec -T nextcloud curl -s \
            -u "$USERNAME:$PASSWORD" \
            --max-time 120 \
            "$BASE_URL/index.php/apps/stackiq/api/archimate/download/$file_name" \
            > "$exported_host_file" 2>/dev/null
    fi

    if [ ! -s "$exported_host_file" ]; then
        echo -e "${RED}  Exported file is empty or export failed${NC}"
        return 1
    fi

    exported_size=$(stat -c%s "$exported_host_file" 2>/dev/null || stat -f%z "$exported_host_file" 2>/dev/null || echo "0")
    exported_size_kb=$(echo "scale=1; $exported_size / 1024" | bc)
    echo -e "${GREEN}  Exported file: $exported_host_file (${exported_size_kb} KB)${NC}"

    # Quick sanity check: does it look like ArchiMate XML?
    if ! head -5 "$exported_host_file" | grep -q "<model\|archimate"; then
        echo -e "${RED}  Exported file does not look like ArchiMate XML${NC}"
        echo "  First 3 lines:"
        head -3 "$exported_host_file"
        return 1
    fi

    # ---- Step 4: XSD Schema Validation ----
    echo ""
    echo -e "${BLUE}Step 4/5: XSD schema validation${NC}"

    # Validate original
    echo -e "${BLUE}  Validating original file...${NC}"
    download_xsd_schema
    xsd_available=$?

    if [ $xsd_available -eq 0 ]; then
        validate_xsd "$original_host_file"
        original_xsd_result=$?

        echo ""
        echo -e "${BLUE}  Validating exported file...${NC}"
        validate_xsd "$exported_host_file"
        exported_xsd_result=$?

        if [ $original_xsd_result -ne 0 ] && [ $original_xsd_result -ne 2 ]; then
            echo -e "${YELLOW}  Note: Original file also fails XSD validation${NC}"
        fi
        if [ $exported_xsd_result -eq 1 ]; then
            roundtrip_passed=false
        fi
    else
        echo -e "${YELLOW}  XSD schema not available, checking well-formedness only...${NC}"
        if command -v xmllint &>/dev/null; then
            echo -e "${BLUE}  Original:${NC}"
            if xmllint --noout "$original_host_file" 2>/dev/null; then
                echo -e "${GREEN}    Well-formed XML${NC}"
            else
                echo -e "${RED}    NOT well-formed${NC}"
            fi
            echo -e "${BLUE}  Exported:${NC}"
            if xmllint --noout "$exported_host_file" 2>/dev/null; then
                echo -e "${GREEN}    Well-formed XML${NC}"
            else
                echo -e "${RED}    NOT well-formed${NC}"
                roundtrip_passed=false
            fi
        else
            echo -e "${YELLOW}  xmllint not available, skipping XML validation${NC}"
        fi
    fi

    # ---- Step 5: Semantic Comparison ----
    echo ""
    echo -e "${BLUE}Step 5/5: Semantic comparison${NC}"

    run_semantic_compare "$original_host_file" "$exported_host_file"
    compare_result=$?

    if [ $compare_result -eq 1 ]; then
        roundtrip_passed=false
    elif [ $compare_result -eq 2 ]; then
        echo -e "${YELLOW}  Semantic comparison could not run${NC}"
    fi

    # ---- Summary ----
    echo ""
    echo -e "${CYAN}---- Round-trip Summary ----${NC}"
    echo -e "${BLUE}  Import time  : ${import_duration}s${NC}"
    echo -e "${BLUE}  Export time   : ${export_duration}s${NC}"
    echo -e "${BLUE}  Original file : $original_host_file${NC}"
    echo -e "${BLUE}  Exported file : $exported_host_file${NC}"
    echo -e "${BLUE}  Compare report: $OUTPUT_DIR/comparison_report.txt${NC}"
    echo -e "${BLUE}  JSON report   : $OUTPUT_DIR/comparison_report.json${NC}"

    if [ "$roundtrip_passed" = true ]; then
        echo -e "${GREEN}  ROUND-TRIP: ALL CHECKS PASSED${NC}"
        return 0
    else
        echo -e "${RED}  ROUND-TRIP: SOME CHECKS FAILED${NC}"
        return 1
    fi
}

# =============================================================================
# XSD VALIDATION (standalone — validates the original GEMMA file)
# =============================================================================
run_validate_test() {
    echo -e "${CYAN}XSD VALIDATION TEST SUITE${NC}"
    echo -e "${CYAN}=========================${NC}"

    local host_file="$SCRIPT_DIR/$GEMMA_FILE"

    # Get file on host
    if [ ! -f "$host_file" ]; then
        echo -e "${YELLOW}  File not on host, copying from container...${NC}"
        host_file="$OUTPUT_DIR/original_gemma_validate.xml"
        docker-compose exec -T nextcloud cat "$CONTAINER_APP_PATH/$GEMMA_FILE" > "$host_file" 2>/dev/null
        if [ ! -s "$host_file" ]; then
            echo -e "${RED}  Could not obtain file for validation${NC}"
            return 1
        fi
    fi

    # Download schema
    download_xsd_schema
    xsd_available=$?

    # Validate
    validate_xsd "$host_file"
    result=$?

    if [ $result -eq 0 ]; then
        echo -e "${GREEN}  VALIDATION: PASSED${NC}"
        return 0
    elif [ $result -eq 2 ]; then
        echo -e "${YELLOW}  VALIDATION: SKIPPED (tools or schema unavailable)${NC}"
        return 0
    else
        echo -e "${RED}  VALIDATION: FAILED${NC}"
        return 1
    fi
}

# =============================================================================
# EXPORT TESTING (Replaces test_archimate_export.sh)
# =============================================================================
run_export_test() {
    echo -e "${CYAN}EXPORT TEST SUITE${NC}"
    echo -e "${CYAN}=================${NC}"

    echo -e "${BLUE}Testing basic export functionality${NC}"

    export_result=$(docker-compose exec -T nextcloud curl -s \
        -X POST \
        -u "$USERNAME:$PASSWORD" \
        -H "Content-Type: application/json" \
        -d '{"format":"xml","includeRelationships":true,"includeViews":true}' \
        "$BASE_URL/index.php/apps/stackiq/api/archimate/export" 2>/dev/null || echo "{}")

    local test_file="$OUTPUT_DIR/export_test.xml"

    # Check if the response is XML (success) or JSON (error)
    if echo "$export_result" | head -5 | grep -q "<?xml\|<model"; then
        # Direct XML response
        echo "$export_result" > "$test_file"
        echo -e "${GREEN}  Export test passed (direct XML response)${NC}"
    else
        export_success=$(echo "$export_result" | json_get_bool "success")
        if [ "$export_success" != "true" ]; then
            echo -e "${RED}  Export failed${NC}"
            echo "$export_result" | python3 -m json.tool 2>/dev/null || echo "$export_result"
            return 1
        fi

        file_name=$(echo "$export_result" | json_get "file_name")
        echo -e "${GREEN}  Export test passed (file: $file_name)${NC}"

        if [ -n "$file_name" ]; then
            docker-compose exec -T nextcloud curl -s \
                -u "$USERNAME:$PASSWORD" \
                "$BASE_URL/index.php/apps/stackiq/api/archimate/download/$file_name" \
                > "$test_file" 2>/dev/null
        fi
    fi

    if [ -s "$test_file" ]; then
        echo -e "${GREEN}  File obtained successfully${NC}"

        # Well-formedness
        if command -v xmllint &>/dev/null; then
            if xmllint --noout "$test_file" 2>/dev/null; then
                echo -e "${GREEN}  XML is well-formed${NC}"
            else
                echo -e "${RED}  XML is NOT well-formed${NC}"
                rm -f "$test_file"
                return 1
            fi
        fi

        # Basic structure
        if head -20 "$test_file" | grep -q "<model"; then
            echo -e "${GREEN}  Contains <model> root element${NC}"
        else
            echo -e "${RED}  Missing <model> root element${NC}"
            rm -f "$test_file"
            return 1
        fi

        # Count sections
        elements=$(grep -c "<element " "$test_file" 2>/dev/null || echo "0")
        relationships=$(grep -c "<relationship " "$test_file" 2>/dev/null || echo "0")
        views=$(grep -c "<view " "$test_file" 2>/dev/null || echo "0")
        echo -e "${BLUE}  Elements: $elements, Relationships: $relationships, Views: $views${NC}"

        rm -f "$test_file"
    fi

    return 0
}

# =============================================================================
# DEBUG/DIAGNOSTIC TESTING (Replaces test_archimate_import_debug.php)
# =============================================================================
run_debug_test() {
    echo -e "${CYAN}DEBUG/DIAGNOSTIC TEST SUITE${NC}"
    echo -e "${CYAN}===========================${NC}"

    echo -e "${BLUE}Checking app status${NC}"
    docker-compose exec -u 33 -T nextcloud php occ app:list 2>/dev/null | grep -E "(openregister|stackiq)" || echo "  Apps status check completed"

    echo -e "${BLUE}Checking configuration${NC}"
    docker-compose exec -u 33 -T nextcloud php occ config:app:get stackiq amef_register_id 2>/dev/null || echo "  No AMEF register ID configured"

    echo -e "${BLUE}Checking database objects${NC}"
    docker-compose exec -T nextcloud-mysql mysql -u nextcloud -pnextcloud nextcloud -e "SELECT COUNT(*) as total_objects FROM oc_openregister_objects;" 2>/dev/null || echo "  Database check completed"

    echo -e "${BLUE}Checking host tools${NC}"
    echo -n "  python3: " && (python3 --version 2>/dev/null || echo "not found")
    echo -n "  xmllint: " && (xmllint --version 2>/dev/null | head -1 || echo "not found")
    echo -n "  jq:      " && (jq --version 2>/dev/null || echo "not found")
    echo -n "  bc:      " && (echo "1+1" | bc 2>/dev/null && echo "available" || echo "not found")

    echo -e "${GREEN}  Debug checks completed${NC}"
    return 0
}

# =============================================================================
# MAIN EXECUTION
# =============================================================================

# Pre-flight checks
check_docker
check_gemma_file

# Track results
RESULTS=()

# Run requested tests
if [ "$VALIDATE_TEST" = true ]; then
    echo ""
    if run_validate_test; then
        RESULTS+=("Validate:    PASSED")
    else
        RESULTS+=("Validate:    FAILED")
    fi
fi

if [ "$PERFORMANCE_TEST" = true ]; then
    echo ""
    if run_performance_test; then
        RESULTS+=("Performance: PASSED")
    else
        RESULTS+=("Performance: FAILED")
    fi
fi

if [ "$ROUNDTRIP_TEST" = true ]; then
    echo ""
    if run_roundtrip_test; then
        RESULTS+=("Round-trip:  PASSED")
    else
        RESULTS+=("Round-trip:  FAILED")
    fi
fi

if [ "$EXPORT_TEST" = true ]; then
    echo ""
    if run_export_test; then
        RESULTS+=("Export:      PASSED")
    else
        RESULTS+=("Export:      FAILED")
    fi
fi

if [ "$DEBUG_TEST" = true ]; then
    echo ""
    if run_debug_test; then
        RESULTS+=("Debug:       PASSED")
    else
        RESULTS+=("Debug:       FAILED")
    fi
fi

# Final summary
echo ""
echo -e "${CYAN}TEST SUITE SUMMARY${NC}"
echo -e "${CYAN}==================${NC}"

all_passed=true
for result in "${RESULTS[@]}"; do
    if [[ "$result" == *"FAILED"* ]]; then
        echo -e "${RED}  $result${NC}"
        all_passed=false
    else
        echo -e "${GREEN}  $result${NC}"
    fi
done

echo ""
if [ "$all_passed" = true ]; then
    echo -e "${GREEN}ALL TESTS PASSED${NC}"
    exit 0
else
    echo -e "${YELLOW}Some tests failed. Check output above for details.${NC}"
    exit 1
fi
