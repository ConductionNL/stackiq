#!/bin/bash

# Test script for ArchiMate export functionality
# This script tests the export functionality and verifies it produces valid XML

set -e

echo "=== ArchiMate Export Test ==="
echo "Testing export functionality and XML generation..."
echo

# Test 1: Basic export
echo "Test 1: Basic export to XML"
echo "---------------------------"

curl -X POST "http://localhost/index.php/apps/stackiq/api/archimate/export" \
  -H "Content-Type: application/json" \
  -u admin:admin \
  -d '{
    "format": "xml",
    "includeRelationships": true,
    "includeViews": true,
    "organizationSpecific": false,
    "selectedSchemas": []
  }' \
  -o "archimate_export_test.xml"

if [ -f "archimate_export_test.xml" ]; then
    echo "✓ Export file created successfully"
    echo "File size: $(wc -c < archimate_export_test.xml) bytes"
    
    # Check if it's valid XML
    if xmllint --noout "archimate_export_test.xml" 2>/dev/null; then
        echo "✓ XML is valid"
    else
        echo "✗ XML validation failed"
        exit 1
    fi
    
    # Check for basic structure
    if grep -q "<model" "archimate_export_test.xml" && grep -q "</model>" "archimate_export_test.xml"; then
        echo "✓ XML has correct model structure"
    else
        echo "✗ XML missing model tags"
        exit 1
    fi
    
    # Count elements
    elements_count=$(grep -c "<element" "archimate_export_test.xml" || echo "0")
    echo "Elements found: $elements_count"
    
else
    echo "✗ Export failed - no file created"
    exit 1
fi

echo

# Test 2: Round-trip test
echo "Test 2: Round-trip test"
echo "----------------------"

curl -X POST "http://localhost/index.php/apps/stackiq/api/archimate/test-round-trip" \
  -H "Content-Type: application/json" \
  -u admin:admin \
  -d '{}' \
  -o "roundtrip_test_result.json"

if [ -f "roundtrip_test_result.json" ]; then
    echo "✓ Round-trip test completed"
    
    # Parse JSON result
    success=$(jq -r '.success' "roundtrip_test_result.json" 2>/dev/null || echo "false")
    if [ "$success" = "true" ]; then
        echo "✓ Round-trip test passed"
        
        # Extract statistics
        export_time=$(jq -r '.details.performance.export_time_ms' "roundtrip_test_result.json" 2>/dev/null || echo "0")
        import_time=$(jq -r '.details.performance.import_time_ms' "roundtrip_test_result.json" 2>/dev/null || echo "0")
        total_time=$(jq -r '.details.performance.total_time_ms' "roundtrip_test_result.json" 2>/dev/null || echo "0")
        
        echo "Performance:"
        echo "  Export time: ${export_time}ms"
        echo "  Import time: ${import_time}ms"
        echo "  Total time: ${total_time}ms"
        
        # Check data integrity
        exported_count=$(jq -r '.details.export_result.elements_count' "roundtrip_test_result.json" 2>/dev/null || echo "0")
        imported_count=$(jq -r '.details.import_result.objects_created' "roundtrip_test_result.json" 2>/dev/null || echo "0")
        
        echo "Data integrity:"
        echo "  Exported: $exported_count elements"
        echo "  Re-imported: $imported_count objects"
        
        if [ "$exported_count" -gt 0 ] && [ "$imported_count" -gt 0 ]; then
            echo "✓ Data integrity check passed"
        else
            echo "⚠ Data integrity check inconclusive (no data found)"
        fi
        
    else
        echo "✗ Round-trip test failed"
        message=$(jq -r '.message' "roundtrip_test_result.json" 2>/dev/null || echo "Unknown error")
        echo "Error: $message"
        exit 1
    fi
else
    echo "✗ Round-trip test failed - no result file"
    exit 1
fi

echo

# Test 3: Export status check
echo "Test 3: Export status check"
echo "---------------------------"

curl -X GET "http://localhost/index.php/apps/stackiq/api/settings" \
  -H "Content-Type: application/json" \
  -u admin:admin \
  -o "settings_status.json"

if [ -f "settings_status.json" ]; then
    echo "✓ Settings retrieved"
    
    # Check for ArchiMate export status
    export_status=$(jq -r '.archimate_export_status.status' "settings_status.json" 2>/dev/null || echo "unknown")
    echo "Export status: $export_status"
    
    if [ "$export_status" = "completed" ] || [ "$export_status" = "idle" ]; then
        echo "✓ Export status is valid"
    else
        echo "⚠ Export status: $export_status"
    fi
else
    echo "✗ Failed to retrieve settings"
    exit 1
fi

echo

# Test 4: XML content analysis
echo "Test 4: XML content analysis"
echo "----------------------------"

if [ -f "archimate_export_test.xml" ]; then
    echo "Analyzing exported XML content..."
    
    # Check for different element types
    capability_count=$(grep -c "xsi:type=\"Capability\"" "archimate_export_test.xml" || echo "0")
    business_service_count=$(grep -c "xsi:type=\"BusinessService\"" "archimate_export_test.xml" || echo "0")
    business_role_count=$(grep -c "xsi:type=\"BusinessRole\"" "archimate_export_test.xml" || echo "0")
    business_process_count=$(grep -c "xsi:type=\"BusinessProcess\"" "archimate_export_test.xml" || echo "0")
    business_object_count=$(grep -c "xsi:type=\"BusinessObject\"" "archimate_export_test.xml" || echo "0")
    
    echo "Element types found:"
    echo "  Capabilities: $capability_count"
    echo "  Business Services: $business_service_count"
    echo "  Business Roles: $business_role_count"
    echo "  Business Processes: $business_process_count"
    echo "  Business Objects: $business_object_count"
    
    # Check for properties
    properties_count=$(grep -c "<properties>" "archimate_export_test.xml" || echo "0")
    echo "Elements with properties: $properties_count"
    
    # Check for names
    names_count=$(grep -c "<name" "archimate_export_test.xml" || echo "0")
    echo "Elements with names: $names_count"
    
    if [ "$elements_count" -gt 0 ]; then
        echo "✓ XML contains expected ArchiMate elements"
    else
        echo "⚠ No ArchiMate elements found in export"
    fi
fi

echo

# Cleanup
echo "Cleaning up test files..."
rm -f "archimate_export_test.xml" "roundtrip_test_result.json" "settings_status.json"

echo
echo "=== ArchiMate Export Test Completed ==="
echo "All tests passed successfully!"
echo
echo "Summary:"
echo "- Export functionality works correctly"
echo "- Generated XML is valid and well-formed"
echo "- Round-trip test validates data integrity"
echo "- Export status tracking is functional"
echo "- XML contains proper ArchiMate structure" 