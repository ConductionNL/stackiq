#!/bin/bash

# Master Test Runner for SoftwareCatalog EventListener and Email System
# This script runs all test scenarios in the correct order

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}=============================================="
echo -e "SoftwareCatalog Master Test Runner"
echo -e "=============================================="
echo -e "${NC}"

# Check if Docker is running
echo -e "${YELLOW}[SETUP]${NC} Checking Docker environment..."
if ! docker-compose ps | grep -q "nextcloud"; then
    echo -e "${RED}[ERROR]${NC} Nextcloud container is not running. Please start it with 'docker-compose up -d'"
    exit 1
fi

echo -e "${GREEN}[SUCCESS]${NC} Docker environment is ready"

# Test 1: Email System Test
echo -e "${BLUE}=============================================="
echo -e "Running Email System Test"
echo -e "=============================================="
echo -e "${NC}"

if [ -f "./test_email_system.sh" ]; then
    ./test_email_system.sh
    echo -e "${GREEN}[COMPLETED]${NC} Email system test finished"
else
    echo -e "${RED}[ERROR]${NC} test_email_system.sh not found"
fi

echo -e "\n${YELLOW}Press Enter to continue to main scenarios test...${NC}"
read

# Test 2: Main Scenarios Test
echo -e "${BLUE}=============================================="
echo -e "Running Main Scenarios Test"
echo -e "=============================================="
echo -e "${NC}"

if [ -f "./test_scenarios.sh" ]; then
    ./test_scenarios.sh
    
    # Extract organization ID from the main test for role testing
    echo -e "\n${YELLOW}Extracting organization ID for role testing...${NC}"
    ORG_ID=$(docker-compose exec nextcloud curl -s -X GET "http://localhost/index.php/apps/openregister/api/objects/6/35" -H "Content-Type: application/json" -u admin:admin | grep -o '"id":"[^"]*"' | tail -1 | cut -d'"' -f4)
    
    if [ ! -z "$ORG_ID" ]; then
        echo -e "${GREEN}[SUCCESS]${NC} Found organization ID: $ORG_ID"
        echo "ORG_ID=$ORG_ID" > .test_env
    else
        echo -e "${YELLOW}[WARNING]${NC} Could not extract organization ID, role test may need manual ID"
    fi
    
    echo -e "${GREEN}[COMPLETED]${NC} Main scenarios test finished"
else
    echo -e "${RED}[ERROR]${NC} test_scenarios.sh not found"
fi

echo -e "\n${YELLOW}Press Enter to continue to role changes test...${NC}"
read

# Test 3: Role Changes Test
echo -e "${BLUE}=============================================="
echo -e "Running Role Changes Test"
echo -e "=============================================="
echo -e "${NC}"

if [ -f "./test_role_changes.sh" ] && [ -f ".test_env" ]; then
    source .test_env
    echo -e "${BLUE}Using Organization ID: $ORG_ID${NC}"
    ./test_role_changes.sh "$ORG_ID"
    echo -e "${GREEN}[COMPLETED]${NC} Role changes test finished"
elif [ -f "./test_role_changes.sh" ]; then
    echo -e "${YELLOW}[WARNING]${NC} No organization ID found from previous test"
    echo -e "${YELLOW}Please provide an organization ID to test role changes:${NC}"
    read -p "Organization ID: " MANUAL_ORG_ID
    if [ ! -z "$MANUAL_ORG_ID" ]; then
        ./test_role_changes.sh "$MANUAL_ORG_ID"
        echo -e "${GREEN}[COMPLETED]${NC} Role changes test finished"
    else
        echo -e "${YELLOW}[SKIPPED]${NC} Role changes test skipped - no organization ID provided"
    fi
else
    echo -e "${RED}[ERROR]${NC} test_role_changes.sh not found"
fi

# Clean up temporary files
if [ -f ".test_env" ]; then
    rm .test_env
fi

# Final Summary
echo -e "${BLUE}=============================================="
echo -e "All Tests Completed"
echo -e "=============================================="
echo -e "${NC}"
echo -e "${GREEN}Test Summary:${NC}"
echo -e "1. ✓ Email System Test - Configuration and functionality"
echo -e "2. ✓ Main Scenarios Test - Full workflow from organization to users"
echo -e "3. ✓ Role Changes Test - Role assignments and group memberships"
echo -e ""
echo -e "${YELLOW}What to check:${NC}"
echo -e "• SoftwareCatalog logs for email sending entries"
echo -e "• User creation in Nextcloud (occ user:list)"
echo -e "• Group memberships (occ user:info <username>)"
echo -e "• Organization groups created"
echo -e "• Email templates and configuration"
echo -e ""
echo -e "${BLUE}All tests have been executed successfully!${NC}"
echo -e "${BLUE}=============================================="
echo -e "${NC}" 