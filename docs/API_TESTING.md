# API Testing Guide for Stackiq Integration

**Date:** July 24, 2025  
**App:** Stackiq  
**Purpose:** Repeatable local API testing for organization synchronization

## 🚀 Recommended Testing Tools

### Primary: Postman + Newman (CLI)
- **Postman**: GUI for creating and managing API requests
- **Newman**: CLI tool for running Postman collections in CI/CD
- **Benefits**: Repeatable, version-controlled, supports environments, detailed reporting

### Alternative: curl + Shell Scripts
- **curl**: Direct command-line testing
- **Shell scripts**: Automated test sequences
- **Benefits**: Simple, no dependencies, easy to debug

## 📦 Setup Instructions

### 1. Install Postman
```bash
# Download from https://www.postman.com/downloads/
# Or install via package manager
sudo snap install postman
```

### 2. Install Newman (CLI)
```bash
# Install globally
npm install -g newman

# Or install locally in project
npm init -y
npm install newman --save-dev
```

### 3. Install Newman Reporter (Optional)
```bash
npm install -g newman-reporter-htmlextra
npm install -g newman-reporter-cli
```

## 📁 Project Structure

```
stackiq/
├── tests/
│   ├── api/
│   │   ├── postman/
│   │   │   ├── Stackiq_API_Tests.postman_collection.json
│   │   │   ├── Local_Environment.postman_environment.json
│   │   │   └── Docker_Environment.postman_environment.json
│   │   ├── scripts/
│   │   │   ├── run-api-tests.sh
│   │   │   ├── test-anonymous-registration.sh
│   │   │   └── test-organization-sync.sh
│   │   └── results/
│   │       ├── newman-reports/
│   │       └── test-logs/
│   └── unit/
└── docs/
    └── API_TESTING.md
```

## 🎯 Postman Collection Setup

### Collection Structure
```
Stackiq API Tests/
├── Setup/
│   ├── Check Configuration
│   └── Verify Services
├── Organization Management/
│   ├── Create Organization (Authenticated)
│   ├── Update Organization Status
│   ├── Get Organization Object
│   └── Get Organization Entity
├── Anonymous Registration/
│   ├── Register Organization (Anonymous)
│   ├── Verify User Creation
│   └── Verify Ownership Assignment
├── Contact Person Management/
│   ├── Create Nested Contact Persons
│   ├── Create Separate Contact Person
│   └── Update Contact Person
├── User Status Management/
│   ├── Activate Organization
│   ├── Deactivate Organization
│   └── Verify User Status Changes
└── Cleanup/
    ├── List Users
    └── Cleanup Test Data
```

### Environment Variables
```json
{
  "base_url": "http://localhost",
  "nextcloud_url": "http://nextcloud.local",
  "admin_username": "admin",
  "admin_password": "admin",
  "register_id": "6",
  "organisatie_schema_id": "35",
  "contactpersoon_schema_id": "34",
  "test_organization_name": "API Test Organization",
  "test_contact_email": "api.test@example.com"
}
```

## 🔧 Newman CLI Commands

### Basic Test Execution
```bash
# Run all tests
newman run tests/api/postman/Stackiq_API_Tests.postman_collection.json \
  -e tests/api/postman/Local_Environment.postman_environment.json

# Run specific folder
newman run tests/api/postman/Stackiq_API_Tests.postman_collection.json \
  -e tests/api/postman/Local_Environment.postman_environment.json \
  --folder "Anonymous Registration"

# Run with detailed reporting
newman run tests/api/postman/Stackiq_API_Tests.postman_collection.json \
  -e tests/api/postman/Local_Environment.postman_environment.json \
  --reporters cli,htmlextra \
  --reporter-htmlextra-export tests/api/results/newman-reports/
```

### Test with Different Environments
```bash
# Local development
newman run tests/api/postman/Stackiq_API_Tests.postman_collection.json \
  -e tests/api/postman/Local_Environment.postman_environment.json

# Docker environment
newman run tests/api/postman/Stackiq_API_Tests.postman_collection.json \
  -e tests/api/postman/Docker_Environment.postman_environment.json
```

## 📝 Shell Script Testing

### Main Test Runner
```bash
#!/bin/bash
# tests/api/scripts/run-api-tests.sh

set -e

echo "🚀 Starting Stackiq API Tests..."

# Configuration
BASE_URL="http://localhost"
ADMIN_USER="admin"
ADMIN_PASS="admin"
REGISTER_ID="6"
SCHEMA_ID="35"

# Test counters
TESTS_PASSED=0
TESTS_FAILED=0

# Helper functions
log_success() {
    echo "✅ $1"
    ((TESTS_PASSED++))
}

log_error() {
    echo "❌ $1"
    ((TESTS_FAILED++))
}

test_endpoint() {
    local name="$1"
    local method="$2"
    local url="$3"
    local data="$4"
    
    echo "Testing: $name"
    
    if [ -n "$data" ]; then
        response=$(curl -s -w "%{http_code}" -X "$method" "$url" \
            -u "$ADMIN_USER:$ADMIN_PASS" \
            -H "Content-Type: application/json" \
            -d "$data")
    else
        response=$(curl -s -w "%{http_code}" -X "$method" "$url" \
            -u "$ADMIN_USER:$ADMIN_PASS")
    fi
    
    http_code="${response: -3}"
    body="${response%???}"
    
    if [ "$http_code" -ge 200 ] && [ "$http_code" -lt 300 ]; then
        log_success "$name (HTTP $http_code)"
        echo "Response: $body" | jq '.' 2>/dev/null || echo "Response: $body"
    else
        log_error "$name (HTTP $http_code)"
        echo "Error: $body"
    fi
    
    echo "---"
}

# Test 1: Create Organization
test_endpoint "Create Organization" \
    "POST" \
    "$BASE_URL/index.php/apps/openregister/api/objects/$REGISTER_ID/$SCHEMA_ID" \
    '{
        "naam": "API Test Organization",
        "website": "https://api-test.org",
        "type": "Leverancier",
        "beoordeling": "actief"
    }'

# Test 2: Get Organization List
test_endpoint "Get Organizations" \
    "GET" \
    "$BASE_URL/index.php/apps/openregister/api/objects/$REGISTER_ID/$SCHEMA_ID"

# Test 3: Anonymous Registration (if OpenConnector available)
if curl -s "$BASE_URL/index.php/apps/openconnector/api/endpoint/register" >/dev/null 2>&1; then
    test_endpoint "Anonymous Registration" \
        "POST" \
        "$BASE_URL/index.php/apps/openconnector/api/endpoint/register" \
        '{
            "naam": "Anonymous Test Org",
            "website": "https://anonymous-test.org",
            "type": "Gemeente",
            "beoordeling": "actief",
            "contactpersonen": [
                {
                    "voornaam": "Anonymous",
                    "achternaam": "Contact",
                    "email": "anonymous@test.org",
                    "telefoon": "+31 555 555 555",
                    "functie": "Manager"
                }
            ]
        }'
else
    echo "⚠️  OpenConnector not available, skipping anonymous registration test"
fi

# Summary
echo "📊 Test Results:"
echo "   Passed: $TESTS_PASSED"
echo "   Failed: $TESTS_FAILED"
echo "   Total: $((TESTS_PASSED + TESTS_FAILED))"

if [ $TESTS_FAILED -eq 0 ]; then
    echo "🎉 All tests passed!"
    exit 0
else
    echo "💥 Some tests failed!"
    exit 1
fi
```

### Anonymous Registration Test
```bash
#!/bin/bash
# tests/api/scripts/test-anonymous-registration.sh

echo "🧪 Testing Anonymous User Registration..."

# Test data
TEST_DATA='{
    "naam": "Anonymous Test Organization",
    "website": "https://anonymous-test.org",
    "type": "Gemeente",
    "beoordeling": "actief",
    "contactpersonen": [
        {
            "voornaam": "Anonymous",
            "achternaam": "Contact1",
            "email": "anonymous.contact1@test.org",
            "telefoon": "+31 555 555 555",
            "functie": "Manager"
        },
        {
            "voornaam": "Anonymous",
            "achternaam": "Contact2", 
            "email": "anonymous.contact2@test.org",
            "telefoon": "+31 555 555 556",
            "functie": "Developer"
        }
    ]
}'

# Make the request
echo "Sending anonymous registration request..."
response=$(curl -s -w "%{http_code}" \
    -X POST "http://nextcloud.local/index.php/apps/openconnector/api/endpoint/register" \
    -H "Content-Type: application/json" \
    -d "$TEST_DATA")

http_code="${response: -3}"
body="${response%???}"

echo "HTTP Status: $http_code"
echo "Response:"
echo "$body" | jq '.' 2>/dev/null || echo "$body"

# Extract UUID if successful
if [ "$http_code" -eq 200 ] || [ "$http_code" -eq 201 ]; then
    uuid=$(echo "$body" | jq -r '.uuid // .id // empty' 2>/dev/null)
    if [ -n "$uuid" ] && [ "$uuid" != "null" ]; then
        echo "✅ Organization created with UUID: $uuid"
        
        # Wait a moment for processing
        sleep 2
        
        # Check if users were created
        echo "Checking for created users..."
        docker-compose exec -u 33 nextcloud php /var/www/html/occ user:list | grep -E "anonymous|test"
        
        # Check organization entity
        echo "Checking organization entity..."
        curl -s -u 'admin:admin' "http://localhost/index.php/apps/openregister/api/organisations/$uuid" | jq '.'
        
        # Check object ownership
        echo "Checking object ownership..."
        curl -s -u 'admin:admin' "http://localhost/index.php/apps/openregister/api/objects/6/35/$uuid" | jq '.@self.owner'
    else
        echo "❌ Could not extract UUID from response"
    fi
else
    echo "❌ Registration failed with HTTP $http_code"
fi
```

### Organization Sync Test
```bash
#!/bin/bash
# tests/api/scripts/test-organization-sync.sh

echo "🔄 Testing Organization Synchronization..."

# Create organization
echo "1. Creating organization..."
org_response=$(curl -s -u 'admin:admin' \
    -H 'Content-Type: application/json' \
    -X POST 'http://localhost/index.php/apps/openregister/api/objects/6/35' \
    -d '{
        "naam": "Sync Test Organization",
        "website": "https://sync-test.org",
        "type": "Leverancier",
        "beoordeling": "actief"
    }')

org_uuid=$(echo "$org_response" | jq -r '.uuid // .id // empty')
echo "Organization UUID: $org_uuid"

if [ -z "$org_uuid" ] || [ "$org_uuid" = "null" ]; then
    echo "❌ Failed to create organization"
    exit 1
fi

# Wait for sync
echo "2. Waiting for synchronization..."
sleep 3

# Check organization entity
echo "3. Checking organization entity..."
entity_response=$(curl -s -u 'admin:admin' \
    "http://localhost/index.php/apps/openregister/api/organisations/$org_uuid")

echo "Entity response:"
echo "$entity_response" | jq '.'

# Update organization status
echo "4. Updating organization status to inactive..."
update_response=$(curl -s -u 'admin:admin' \
    -H 'Content-Type: application/json' \
    -X PUT "http://localhost/index.php/apps/openregister/api/objects/6/35/$org_uuid" \
    -d '{
        "naam": "Sync Test Organization",
        "beoordeling": "inactief"
    }')

echo "Update response:"
echo "$update_response" | jq '.'

# Wait for sync
echo "5. Waiting for status sync..."
sleep 3

# Check entity status
echo "6. Checking entity status..."
entity_response=$(curl -s -u 'admin:admin' \
    "http://localhost/index.php/apps/openregister/api/organisations/$org_uuid")

echo "Entity status:"
echo "$entity_response" | jq '.status'

echo "✅ Organization sync test completed"
```

## 🎯 Specific Test Scenarios

### 1. Anonymous User Registration Test
```bash
# Run the specific test
./tests/api/scripts/test-anonymous-registration.sh

# Expected results:
# - Organization object created
# - Organization entity created in OpenRegister
# - User accounts created for contact persons
# - Ownership transferred to new users
# - Organization references set on all objects
```

### 2. Organization Status Change Test
```bash
# Run the sync test
./tests/api/scripts/test-organization-sync.sh

# Expected results:
# - Organization created with 'actief' status
# - Entity created with 'active' status
# - Status change to 'inactief' triggers user deactivation
# - Admin users remain unaffected
```

### 3. Nested Contact Person Test
```bash
# Test with nested contact persons
curl -u 'admin:admin' \
    -H 'Content-Type: application/json' \
    -X POST 'http://localhost/index.php/apps/openregister/api/objects/6/35' \
    -d '{
        "naam": "Nested Contact Test Org",
        "website": "https://nested-test.org",
        "type": "Gemeente",
        "beoordeling": "actief",
        "contactpersonen": [
            {
                "voornaam": "Nested",
                "achternaam": "Contact1",
                "email": "nested1@test.org",
                "telefoon": "+31 555 555 557",
                "functie": "Manager"
            },
            {
                "voornaam": "Nested",
                "achternaam": "Contact2",
                "email": "nested2@test.org",
                "telefoon": "+31 555 555 558",
                "functie": "Developer"
            }
        ]
    }'
```

## 📊 Test Reporting

### Newman HTML Report
```bash
# Generate detailed HTML report
newman run tests/api/postman/Stackiq_API_Tests.postman_collection.json \
    -e tests/api/postman/Local_Environment.postman_environment.json \
    --reporters htmlextra \
    --reporter-htmlextra-export tests/api/results/newman-reports/ \
    --reporter-htmlextra-title "Stackiq API Test Report"
```

### Shell Script Logging
```bash
# Run with logging
./tests/api/scripts/run-api-tests.sh 2>&1 | tee tests/api/results/test-logs/$(date +%Y%m%d_%H%M%S)_api_test.log
```

## 🔄 CI/CD Integration

### GitHub Actions Example
```yaml
name: API Tests
on: [push, pull_request]

jobs:
  api-tests:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      
      - name: Setup Node.js
        uses: actions/setup-node@v2
        with:
          node-version: '16'
          
      - name: Install Newman
        run: npm install -g newman newman-reporter-htmlextra
        
      - name: Start Docker Environment
        run: |
          cd /path/to/nextcloud-docker-dev
          docker-compose up -d
          sleep 30  # Wait for services to start
          
      - name: Run API Tests
        run: |
          newman run tests/api/postman/Stackiq_API_Tests.postman_collection.json \
            -e tests/api/postman/Docker_Environment.postman_environment.json \
            --reporters cli,htmlextra \
            --reporter-htmlextra-export tests/api/results/newman-reports/
            
      - name: Upload Test Results
        uses: actions/upload-artifact@v2
        with:
          name: api-test-results
          path: tests/api/results/
```

## 🛠️ Troubleshooting

### Common Issues

#### 1. Connection Refused
```bash
# Check if Nextcloud is running
docker-compose ps

# Check container logs
docker-compose logs nextcloud
```

#### 2. Authentication Issues
```bash
# Verify admin credentials
curl -u 'admin:admin' 'http://localhost/index.php/apps/openregister/api/objects/6/35'
```

#### 3. Schema Configuration Issues
```bash
# Check configuration
docker-compose exec -u 33 nextcloud php /var/www/html/occ config:app:get stackiq voorzieningen_organisatie_schema
```

### Debug Mode
```bash
# Run with verbose output
newman run tests/api/postman/Stackiq_API_Tests.postman_collection.json \
    -e tests/api/postman/Local_Environment.postman_environment.json \
    --verbose

# Run with debug logging
curl -v -u 'admin:admin' 'http://localhost/index.php/apps/openregister/api/objects/6/35'
```

## 📈 Performance Testing

### Load Testing with Artillery
```bash
# Install Artillery
npm install -g artillery

# Run load test
artillery run tests/api/artillery/load-test.yml
```

### Load Test Configuration
```yaml
# tests/api/artillery/load-test.yml
config:
  target: 'http://localhost'
  phases:
    - duration: 60
      arrivalRate: 5
  defaults:
    headers:
      Authorization: 'Basic YWRtaW46YWRtaW4='

scenarios:
  - name: "Organization Creation"
    requests:
      - post:
          url: "/index.php/apps/openregister/api/objects/6/35"
          headers:
            Content-Type: "application/json"
          json:
            naam: "Load Test Org {{ $randomString() }}"
            website: "https://load-test.org"
            type: "Leverancier"
            beoordeling: "actief"
```

## 🎯 Best Practices

1. **Environment Management**: Use separate environments for local, staging, and production
2. **Data Cleanup**: Always clean up test data after tests
3. **Idempotent Tests**: Tests should be repeatable without side effects
4. **Error Handling**: Test both success and failure scenarios
5. **Performance Monitoring**: Monitor response times and resource usage
6. **Documentation**: Keep test documentation up to date
7. **Version Control**: Store Postman collections and scripts in version control

## 📚 Additional Resources

- [Postman Documentation](https://learning.postman.com/)
- [Newman CLI Documentation](https://learning.postman.com/docs/running-collections/using-newman-cli/)
- [curl Documentation](https://curl.se/docs/)
- [jq Documentation](https://stedolan.github.io/jq/manual/)

This comprehensive API testing setup provides repeatable, automated testing for all Stackiq integration scenarios, from basic CRUD operations to complex anonymous user registration flows. 