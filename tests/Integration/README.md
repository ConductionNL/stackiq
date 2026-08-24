# Integration Tests for Stackiq

## Overview

This directory contains integration tests for the Stackiq Nextcloud app. These tests verify API endpoints by making real HTTP requests to a running Nextcloud instance.

## Test Structure

### KoppelingenGebruikIntegrationTest.php

Tests for the Koppelingen-Gebruik API endpoints that provide extended access to gebruiks (usage) and koppelingen (connections) objects.

**Test Coverage:**
- **Basic Functionality** - Response structure and status codes
- **Pagination** - Limit, offset, and page parameters
- **Access Control** - Organization filtering and UUID-based access
- **Query Parameters** - Sorting, searching, filtering
- **Error Handling** - Invalid UUIDs and missing configuration
- **Data Consistency** - Response format across different query parameters

## Prerequisites

### Environment
- Nextcloud container running (e.g., master-nextcloud-1)
- Stackiq app enabled
- OpenRegister app enabled and configured
- Test user 'admin' with password 'admin'
- Voorzieningen register and schemas configured

### Dependencies

Install test dependencies via Composer:

```bash
composer install --dev
```

This installs:
- PHPUnit 10.5+ for test execution
- Guzzle HTTP Client 7.8+ for API requests

## Running Tests

### From Host Machine (WSL)

```bash
# Navigate to app directory
cd /Ubuntu-20.04/home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/stackiq

# Run all integration tests
vendor/bin/phpunit --testsuite "Integration Tests"

# Run specific test file
vendor/bin/phpunit tests/Integration/KoppelingenGebruikIntegrationTest.php

# Run single test method
vendor/bin/phpunit --filter testGetKoppelingenGebruikReturnsSuccessfulResponse

# Run with verbose output
vendor/bin/phpunit --testsuite "Integration Tests" --verbose --colors=always
```

### From Docker Container

```bash
# Enter the Nextcloud container
docker exec -it master-nextcloud-1 bash

# Navigate to app directory
cd /var/www/html/apps-extra/stackiq

# Run tests as www-data user (UID 33)
sudo -u www-data vendor/bin/phpunit --testsuite "Integration Tests"
```

### Using Composer Scripts

```bash
# Run all tests (unit + integration)
composer test:unit
```

## Test Configuration

### Base URL

Tests connect to `http://localhost` by default. This should resolve to your Nextcloud container when running from within WSL or the container itself.

### Authentication

All tests use Basic Auth with:
- **Username:** admin
- **Password:** admin

These credentials are hard-coded in the test setup. Update `KoppelingenGebruikIntegrationTest::setUp()` if your environment uses different credentials.

### Headers

Tests include:
- `Authorization: Basic <base64-encoded-credentials>`
- `OCS-APIRequest: true` (required for Nextcloud API)
- `Content-Type: application/json`

## Test Data Management

### Setup (`setUp()`)

Each test method runs `setUp()` which:
1. Creates Guzzle HTTP client with auth
2. Loads voorzieningen configuration (register and schema IDs)
3. Prepares test environment

### Teardown (`tearDown()`)

After each test, `tearDown()`:
1. Deletes all created test objects
2. Cleans up resources
3. Prevents test data pollution

### Object Tracking

The `$createdObjectIds` array tracks all objects created during tests for automatic cleanup. Helper methods like `createTestProduct()`, `createTestGebruik()`, and `createTestKoppeling()` automatically register created objects.

## Writing New Tests

### Test Method Template

```php
/**
 * Test description here
 *
 * Detailed explanation of what this test verifies
 *
 * @return void
 */
public function testYourTestName(): void
{
    // Skip if configuration not available
    if (!$this->voorzieningenRegisterId) {
        $this->markTestSkipped('Configuration not available');
    }

    // Make API request
    $response = $this->client->get('/index.php/apps/stackiq/api/your-endpoint');
    
    // Assert response
    $this->assertEquals(200, $response->getStatusCode());
    
    $data = json_decode($response->getBody()->getContents(), true);
    
    // Assert data structure
    $this->assertArrayHasKey('results', $data);
    $this->assertIsArray($data['results']);
}
```

### Helper Methods

Create test objects using helper methods:

```php
// Create test product
$product = $this->createTestProduct([
    'title' => 'Custom Title',
    'description' => 'Custom description'
]);

// Create test gebruik
$gebruik = $this->createTestGebruik($productId, [
    'title' => 'Custom Gebruik'
]);

// Create test koppeling
$koppeling = $this->createTestKoppeling($productId);
```

These methods automatically:
- Register created objects for cleanup
- Extract and return the UUID
- Include default values for required fields

## Troubleshooting

### Connection Refused

**Error:** `Connection refused` or `Failed to connect to localhost`

**Solution:** Ensure Nextcloud container is running:
```bash
docker ps | grep nextcloud
docker-compose up -d  # if container is down
```

### Authentication Failed

**Error:** `401 Unauthorized`

**Solution:** 
1. Verify admin user exists with correct password
2. Check container credentials in config.php
3. Update test credentials in `setUp()` method

### Configuration Not Found

**Error:** Tests skipped with "Configuration not available"

**Solution:**
1. Ensure Stackiq app is enabled: `php occ app:enable stackiq`
2. Configure Voorzieningen settings in admin panel
3. Verify register and schema IDs are set

### Test Data Not Cleaned Up

**Error:** Old test data appears in results

**Solution:**
1. Manually delete test objects via API or database
2. Check `tearDown()` is being called
3. Verify `$createdObjectIds` array is populated

### PHPUnit Not Found

**Error:** `vendor/bin/phpunit: No such file or directory`

**Solution:** Install dependencies:
```bash
composer install --dev
```

## Best Practices

### 1. Test Isolation

Each test should be independent and not rely on other tests. Use `setUp()` and `tearDown()` to ensure clean state.

### 2. Use Helper Methods

Create reusable helper methods for common operations like creating test objects or making authenticated requests.

### 3. Meaningful Assertions

Include descriptive messages in assertions:
```php
$this->assertEquals(200, $response->getStatusCode(), 'Expected successful response');
```

### 4. Skip Unavailable Tests

Use `markTestSkipped()` when prerequisites aren't met instead of failing:
```php
if (!$this->configAvailable) {
    $this->markTestSkipped('Configuration not set up');
}
```

### 5. Clean Up Test Data

Always track created objects and clean them up in `tearDown()` to prevent database pollution.

## CI/CD Integration

### GitHub Actions Example

```yaml
name: Integration Tests

on: [push, pull_request]

jobs:
  integration:
    runs-on: ubuntu-latest
    
    steps:
      - uses: actions/checkout@v3
      
      - name: Start Nextcloud
        run: docker-compose up -d
        
      - name: Wait for Nextcloud
        run: |
          timeout 300 bash -c 'until docker exec nextcloud php occ status; do sleep 5; done'
      
      - name: Enable Apps
        run: |
          docker exec -u 33 nextcloud php occ app:enable openregister
          docker exec -u 33 nextcloud php occ app:enable stackiq
      
      - name: Run Tests
        run: |
          docker exec -u 33 nextcloud bash -c "cd /var/www/html/apps-extra/stackiq && vendor/bin/phpunit --testsuite 'Integration Tests'"
```

## Additional Resources

- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [Guzzle HTTP Client](https://docs.guzzlephp.org/)
- [Nextcloud API Documentation](https://docs.nextcloud.com/server/latest/developer_manual/)
- [OpenRegister Documentation](../../openregister/website/docs/)

## Contributing

When adding new integration tests:

1. Follow existing test structure and naming conventions
2. Add comprehensive docblocks explaining what is being tested
3. Include both positive and negative test cases
4. Update this README if adding new test files
5. Ensure tests pass before committing
6. Update documentation in `website/docs/` as needed

