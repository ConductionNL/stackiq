# AangebodenGebruik API Documentation

## Overview

The AangebodenGebruik API provides endpoints to manage gebruiks (usage) and koppelingen (connections) objects where the active organization is involved either as an afnemer (consumer) or in the deelnemers (participants) list. This API allows organizations to:

1. Retrieve gebruiks objects where they are the afnemer
2. Retrieve gebruiks objects where they are listed in deelnemers
3. Update the '@self' property of a gebruik to claim ownership (only if they are the afnemer)
4. **NEW:** Retrieve koppelingen and gebruiks with extended access control (for ambtenaar users and organization owners)

## Base URL

All endpoints are prefixed with:
- '/api/aangeboden-gebruik' (original endpoints)
- '/api/koppelingen-gebruik/{uuid}' (UUID-specific extended access endpoint)

## Authentication

These endpoints require user authentication and use standard Nextcloud RBAC (Role-Based Access Control) for the afnemer endpoints. The deelnemers endpoint uses RBAC-disabled search to find participation records.

## Endpoints

### 1. Get Gebruiks Where Active Organization is Afnemer

**Endpoint:** 'GET /api/aangeboden-gebruik/afnemer'

**Description:** Returns all gebruiks objects where the active organization is the afnemer (consumer).

**Query Parameters:**
- 'limit' (integer, optional): Maximum number of results to return
- 'offset' (integer, optional): Number of results to skip for pagination  
- 'status' (string, optional): Filter by usage status
- 'product' (string, optional): Filter by product ID
- 'startDate' (string, optional): Filter by start date (ISO 8601 format)
- 'endDate' (string, optional): Filter by end date (ISO 8601 format)

**Response Example:**
```json
{
  'success': true,
  'gebruiks': [
    {
      'id': 'usage-uuid-123',
      'afnemer': 'org-uuid',
      'product': 'product-uuid',
      'status': 'actief',
      '_filter_type': 'afnemer',
      '_schema_id': 'schema-id'
    }
  ],
  'count': 1,
  'filter_type': 'afnemer',
  'organisation': 'org-uuid'
}
```

### 2. Get Gebruiks Where Active Organization is in Deelnemers

**Endpoint:** 'GET /api/aangeboden-gebruik/deelnemers'

**Description:** Returns all gebruiks objects where the active organization appears in the deelnemers (participants) array.

**Query Parameters:**
- 'limit' (integer, optional): Maximum number of results to return
- 'offset' (integer, optional): Number of results to skip for pagination
- 'status' (string, optional): Filter by usage status
- 'product' (string, optional): Filter by product ID
- 'startDate' (string, optional): Filter by start date (ISO 8601 format)
- 'endDate' (string, optional): Filter by end date (ISO 8601 format)

**Response Example:**
```json
{
  'success': true,
  'gebruiks': [
    {
      'id': 'usage-uuid-456',
      'afnemer': 'other-org-uuid',
      'deelnemers': ['org-uuid', 'another-org-uuid'],
      'product': 'product-uuid',
      'status': 'actief',
      '_filter_type': 'deelnemers',
      '_schema_id': 'schema-id'
    }
  ],
  'count': 1,
  'filter_type': 'deelnemers',
  'organisation': 'org-uuid'
}
```

### 3. Set Gebruik @self Property to Active Organization

**Endpoint:** 'PUT /api/aangeboden-gebruik/{gebruikId}/set-self'

**Description:** Sets the '@self.organisation' property of a specific gebruik object to the active organization. This operation is only allowed if the active organization is the afnemer for that gebruik.

**Path Parameters:**
- 'gebruikId' (string, required): The UUID of the gebruik object to update

**Security:** This endpoint verifies that the active organization is the afnemer before allowing the update.

**Response Example (Success):**
```json
{
  'success': true,
  'message': 'Gebruik @self property updated successfully',
  'gebruik': {
    'id': 'usage-uuid-123',
    'afnemer': 'org-uuid',
    '@self': {
      'organisation': 'org-uuid',
      'register': 'register-id',
      'schema': 'schema-id'
    }
  },
  'updated_fields': ['@self.organisation']
}
```

**Response Example (Permission Denied):**
```json
{
  'success': false,
  'error': 'Operation not allowed: active organization is not the afnemer',
  'gebruik': null
}
```

### 4. Deny a Suggestion (Delete)

**Endpoint:** 'DELETE /api/aangeboden-gebruik/{gebruikId}/deny'

**Description:** Denies a gebruik suggestion by deleting the object completely. Only allowed if the active organization is the afnemer for the object.

**Parameters:**
- 'gebruikId' (path, required): The UUID of the gebruik object to deny

**Success Response (200):**
```json
{
  'success': true,
  'message': 'Gebruik object deleted successfully',
  'deleted': true,
  'gebruik_id': 'usage-uuid-123',
  'organisation': 'gemeente-uuid'
}
```

**Error Response (403 Forbidden):**
```json
{
  'success': false,
  'error': 'Operation not allowed: active organization is not the afnemer',
  'deleted': false,
  'debug': {
    'afnemer_in_object': 'other-org-uuid',
    'resolved_afnemer_id': 'other-org-uuid',
    'current_org': 'gemeente-uuid'
  }
}
```

### 5. Get API Documentation

**Endpoint:** 'GET /api/aangeboden-gebruik/docs'

**Description:** Returns comprehensive API documentation including all endpoints, parameters, and examples.

---

## Extended Access Endpoint (Koppelingen-Gebruik)

The koppelingen-gebruik endpoint provides UUID-specific extended access to both gebruiks and koppelingen objects with enhanced authorization rules. This endpoint is designed for:

1. **Ambtenaar users**: Can see all objects related to any UUID, optionally filtered by organization
2. **Application/Module/Organisation owners**: Can see all usage (koppelingen and gebruiks) related to entities their organization owns

### 6. Get Koppelingen and Gebruiks for Specific UUID

**Endpoint:** 'GET /api/koppelingen-gebruik/{uuid}'

**Description:** Returns koppelingen and gebruiks objects related to a specific organisation, application, or module UUID. The endpoint intelligently handles three types of UUIDs:

1. **Organisation UUID**: Returns all gebruiks and koppelingen owned by that organisation
2. **Application/Product UUID**: Returns all gebruiks and koppelingen that reference that application (regardless of which organisation created them)
3. **Module UUID**: Returns all gebruiks and koppelingen that reference that module (regardless of which organisation created them)

**Access Rules:**
- Users with 'ambtenaar' or 'admin' group: Can access any UUID
- Users whose organization owns the specified application/module: Can access all related usage
- Other users: Will receive empty results

**Path Parameters:**
- 'uuid' (string, required): The UUID of the application or module

**Query Parameters:**
- 'organisation' (string, optional): Filter by organization UUID (only works for ambtenaar users)
- 'limit' (integer, optional): Maximum number of results to return (default: 20)
- 'offset' (integer, optional): Number of results to skip for pagination (default: 0)

**Response Example:**
```json
{
  'results': [
    {
      'id': 'uuid-123',
      'type': 'gebruik',
      '@self': {
        'organisation': 'org-uuid',
        'register': 'register-id',
        'schema': 'schema-id'
      },
      'afnemer': 'org-uuid',
      'product': 'application-uuid'
    }
  ],
  'total': 1,
  'page': 1,
  'pages': 1,
  'limit': 20,
  'offset': 0
}
```

## Error Handling

The API uses standard HTTP status codes:

- **200 OK**: Request successful
- **400 Bad Request**: Invalid parameters or missing required fields
- **403 Forbidden**: Operation not allowed (e.g., organization is not afnemer for @self update)
- **404 Not Found**: Gebruik object not found
- **500 Internal Server Error**: Server-side error occurred

## Security Model

### Afnemer Filtering
- Uses standard RBAC filtering based on organization association
- Only returns gebruiks where the active organization has proper access rights

### Deelnemers Filtering  
- Uses RBAC-disabled search to find participation records
- Searches across all gebruiks to find those where the active organization appears in the deelnemers array

### @self Update Permission
- Verifies that the active organization is the afnemer before allowing updates
- Prevents unauthorized modification of gebruik ownership

### Extended Access Control (Koppelingen-Gebruik)
The koppelingen-gebruik endpoints implement a multi-level access control system:

**Level 1 - Ambtenaar Access:**
- Users with 'ambtenaar' or 'admin' group membership can access all objects
- Can optionally filter by organization UUID to inspect specific municipalities
- Bypasses RBAC and multitenancy restrictions completely

**Level 2 - Application Owner Access:**
- Organizations that own applications or modules can see ALL usage of those applications
- This includes gebruiks and koppelingen created by other organizations
- Enables vendors/suppliers to monitor adoption of their products across municipalities

**Level 3 - Regular Users:**
- Users without ambtenaar privileges and whose organization doesn't own any applications receive empty results
- Ensures data privacy for organizations that don't have elevated permissions

## Usage Examples

### Get Gebruiks as Afnemer with Pagination
```bash
curl -X GET 'http://localhost/index.php/apps/softwarecatalog/api/aangeboden-gebruik/afnemer?limit=10&offset=0' \
  -H 'Content-Type: application/json' \
  -u admin:admin
```

### Get Gebruiks as Deelnemers with Status Filter
```bash
curl -X GET 'http://localhost/index.php/apps/softwarecatalog/api/aangeboden-gebruik/deelnemers?status=actief' \
  -H 'Content-Type: application/json' \
  -u admin:admin
```

### Set Gebruik @self Property
```bash
curl -X PUT 'http://localhost/index.php/apps/softwarecatalog/api/aangeboden-gebruik/usage-uuid-123/set-self' \
  -H 'Content-Type: application/json' \
  -u admin:admin
```

### Deny Gebruik Suggestion
```bash
curl -X DELETE 'http://localhost/index.php/apps/softwarecatalog/api/aangeboden-gebruik/usage-uuid-123/deny' \
  -H 'Content-Type: application/json' \
  -u admin:admin
```

### Get Koppelingen and Gebruiks for Specific Organisation
```bash
curl -X GET 'http://localhost/index.php/apps/softwarecatalog/api/koppelingen-gebruik/org-uuid-123' \
  -H 'Content-Type: application/json' \
  -u admin:admin
```

### Get Koppelingen and Gebruiks for Specific Application
```bash
curl -X GET 'http://localhost/index.php/apps/softwarecatalog/api/koppelingen-gebruik/app-uuid-123' \
  -H 'Content-Type: application/json' \
  -u admin:admin
```

## Docker Testing Commands

When testing in the Docker environment, use the docker-compose exec command:

### Test Afnemer Endpoint
```bash
cd /home/rubenlinde/nextcloud-docker-dev
docker-compose exec nextcloud curl -X GET 'http://localhost/index.php/apps/softwarecatalog/api/aangeboden-gebruik/afnemer' \
  -H 'Content-Type: application/json' \
  -u admin:admin
```

### Test Deelnemers Endpoint
```bash
docker-compose exec nextcloud curl -X GET 'http://localhost/index.php/apps/softwarecatalog/api/aangeboden-gebruik/deelnemers' \
  -H 'Content-Type: application/json' \
  -u admin:admin
```

### Test Set @self Property
```bash
docker-compose exec nextcloud curl -X PUT 'http://localhost/index.php/apps/softwarecatalog/api/aangeboden-gebruik/USAGE_UUID/set-self' \
  -H 'Content-Type: application/json' \
  -u admin:admin
```

### Test Koppelingen-Gebruik Endpoint (By Organisation UUID)
```bash
docker-compose exec nextcloud curl -X GET 'http://localhost/index.php/apps/softwarecatalog/api/koppelingen-gebruik/ORG_UUID' \
  -H 'Content-Type: application/json' \
  -u admin:admin
```

### Test Koppelingen-Gebruik Endpoint (By Module UUID)
```bash
docker-compose exec nextcloud curl -X GET 'http://localhost/index.php/apps/softwarecatalog/api/koppelingen-gebruik/MODULE_UUID' \
  -H 'Content-Type: application/json' \
  -u admin:admin
```

### Test Koppelingen-Gebruik Endpoint (By Application UUID)
```bash
docker-compose exec nextcloud curl -X GET 'http://localhost/index.php/apps/softwarecatalog/api/koppelingen-gebruik/APP_UUID' \
  -H 'Content-Type: application/json' \
  -u admin:admin
```

Replace 'USAGE_UUID', 'ORG_UUID', 'MODULE_UUID', and 'APP_UUID' with actual UUIDs from your system.

## Leverancier-Gemeente Workflow

The AangebodenGebruik API supports a specific workflow where leveranciers (suppliers) can register gebruik objects for gemeenten (municipalities), and gemeenten can then claim or deny these suggestions.

### Workflow Overview

1. **Leverancier Creates Suggestion**: A leverancier creates a gebruik object with the afnemer set to a gemeente (different from their own organisation)
2. **Cross-Organisation Access**: The gemeente can access this suggestion even though they didn't create it
3. **Claim or Deny**: The gemeente can either claim the suggestion (set @self.organisation to themselves) or deny it (delete the object)

### Step-by-Step Process

#### Step 1: Leverancier Creates Gebruik Suggestion

The leverancier creates a gebruik object using the standard OpenRegister API, setting the afnemer to the target gemeente:

```bash
# Switch to leverancier organisation
curl -X POST 'http://localhost/index.php/apps/openregister/api/organisations/LEVERANCIER_UUID/set-active' \
  -u admin:admin

# Create gebruik suggestion for gemeente
curl -X POST 'http://localhost/index.php/apps/openregister/api/objects/1/8' \
  -H 'Content-Type: application/json' \
  -u admin:admin \
  -d '{
    "afnemer": "GEMEENTE_UUID",
    "product": "PRODUCT_UUID", 
    "status": "voorgesteld",
    "beschrijving": "Leverancier suggests this product usage for the gemeente"
  }'
```

#### Step 2: Gemeente Discovers Suggestions

The gemeente switches to their organisation context and uses the AangebodenGebruik API to find suggestions:

```bash
# Switch to gemeente organisation  
curl -X POST 'http://localhost/index.php/apps/openregister/api/organisations/GEMEENTE_UUID/set-active' \
  -u admin:admin

# Find gebruik suggestions where gemeente is afnemer
curl -X GET 'http://localhost/index.php/apps/softwarecatalog/api/aangeboden-gebruik/afnemer' \
  -u admin:admin
```

#### Step 3: Gemeente Claims Suggestion

If the gemeente wants to accept the suggestion, they claim it by setting the @self.organisation property:

```bash
curl -X PUT 'http://localhost/index.php/apps/softwarecatalog/api/aangeboden-gebruik/GEBRUIK_UUID/set-self' \
  -u admin:admin
```

**Response:**
```json
{
  "success": true,
  "message": "Gebruik @self property updated successfully",
  "gebruik": {
    "id": "GEBRUIK_UUID",
    "afnemer": "GEMEENTE_UUID",
    "product": "PRODUCT_UUID",
    "status": "voorgesteld"
  },
  "updated_fields": ["@self.organisation"]
}
```

#### Step 4: Gemeente Denies Suggestion (Alternative)

If the gemeente wants to reject the suggestion, they can delete it after claiming:

```bash
# First claim the object (required for deletion permissions)
curl -X PUT 'http://localhost/index.php/apps/softwarecatalog/api/aangeboden-gebruik/GEBRUIK_UUID/set-self' \
  -u admin:admin

# Then delete to deny the suggestion
curl -X DELETE 'http://localhost/index.php/apps/openregister/api/objects/1/8/GEBRUIK_UUID' \
  -u admin:admin
```

### Technical Implementation Details

#### Multitenancy and RBAC Handling

The AangebodenGebruik API uses OpenRegister's built-in RBAC and multitenancy controls:

- **Finding Objects**: Uses `objectService->find()` with `rbac=false, multi=false` to access cross-organisation objects
- **Saving Objects**: Uses `objectService->saveObject()` with `rbac=false, multi=false` to allow cross-organisation updates
- **Permission Verification**: Validates that the active organisation is the afnemer before allowing claim operations

#### Security Model

1. **Creation**: Leveranciers can create gebruik objects with any afnemer (no restriction)
2. **Discovery**: Gemeenten can only see gebruik objects where they are the afnemer
3. **Claiming**: Only the afnemer organisation can claim a gebruik suggestion
4. **Deletion**: Only after claiming can the gemeente delete the object (to deny the suggestion)

### Example Complete Workflow

```bash
# === LEVERANCIER SIDE ===
# Create leverancier organisation
curl -X POST 'http://localhost/index.php/apps/openregister/api/organisations' \
  -H 'Content-Type: application/json' \
  -u admin:admin \
  -d '{"name": "Leverancier BV", "description": "Test supplier organisation"}'

# Create gemeente organisation  
curl -X POST 'http://localhost/index.php/apps/openregister/api/organisations' \
  -H 'Content-Type: application/json' \
  -u admin:admin \
  -d '{"name": "Gemeente Amsterdam", "description": "Test municipality organisation"}'

# Switch to leverancier
curl -X POST 'http://localhost/index.php/apps/openregister/api/organisations/LEVERANCIER_UUID/set-active' \
  -u admin:admin

# Create gebruik suggestion
curl -X POST 'http://localhost/index.php/apps/openregister/api/objects/1/8' \
  -H 'Content-Type: application/json' \
  -u admin:admin \
  -d '{
    "afnemer": "GEMEENTE_UUID",
    "product": "EXISTING_PRODUCT_UUID",
    "status": "voorgesteld", 
    "beschrijving": "We suggest this software solution for your municipality"
  }'

# === GEMEENTE SIDE ===
# Switch to gemeente
curl -X POST 'http://localhost/index.php/apps/openregister/api/organisations/GEMEENTE_UUID/set-active' \
  -u admin:admin

# Check active organisation
curl -X GET 'http://localhost/index.php/apps/openregister/api/organisations/active' \
  -u admin:admin

# Find suggestions
curl -X GET 'http://localhost/index.php/apps/softwarecatalog/api/aangeboden-gebruik/afnemer' \
  -u admin:admin

# Claim suggestion
curl -X PUT 'http://localhost/index.php/apps/softwarecatalog/api/aangeboden-gebruik/GEBRUIK_UUID/set-self' \
  -u admin:admin

# OR deny suggestion (delete after claiming)
curl -X DELETE 'http://localhost/index.php/apps/openregister/api/objects/1/8/GEBRUIK_UUID' \
  -u admin:admin
```

## Configuration Requirements

The AangebodenGebruik API relies on the following configuration:

1. **OpenRegister App**: Must be installed and available
2. **Voorzieningen Configuration**: Must be configured with proper register_id, gebruik_schema, and koppeling_schema
3. **User Organization**: Active user must have an organization associated with their account
4. **Multi-Organisation Setup**: Both leverancier and gemeente organisations must exist and users must be able to switch between them
5. **User Groups**: For extended access endpoints, users must be in 'ambtenaar' or 'admin' group, or their organization must own applications/modules

### Configuration Setup

The Voorzieningen configuration in the settings should include:
- 'register': The register ID (e.g., 'voorzieningen')
- 'gebruik_schema': The schema ID for gebruik objects
- 'koppeling_schema': The schema ID for koppeling objects
- 'suite_schema': The schema ID for suite/application objects (for ownership checks)
- 'module_schema': The schema ID for module objects (for ownership checks)

## Technical Implementation

### Service Layer
- **AangebodenGebruikService**: Handles business logic for filtering gebruiks and updating @self properties
- **SettingsService**: Provides configuration data for gebruiks schemas and register IDs

### Controller Layer  
- **AangebodenGebruikController**: Handles HTTP requests and responses, parameter validation, and error handling

### Data Flow
1. Controller receives HTTP request and parses parameters
2. Service layer retrieves configuration and current organization
3. Service queries OpenRegister with appropriate filters (RBAC enabled/disabled)
4. Results are processed and returned via controller

## Testing

### Integration Tests

The SoftwareCatalog app includes comprehensive integration tests for the Koppelingen-Gebruik API endpoints. These tests verify:

- API endpoint responses and status codes
- Pagination and query parameters
- Access control for ambtenaar users and organization owners
- Filtering by organization
- Search and sorting functionality
- Response format consistency

#### Running Integration Tests

**Prerequisites:**
1. Nextcloud container must be running
2. SoftwareCatalog app must be enabled
3. OpenRegister app must be enabled and configured
4. Test user 'admin' with password 'admin' must exist
5. Voorzieningen register and schemas must be configured

**Install Test Dependencies:**

```bash
cd apps-extra/softwarecatalog
composer install --dev
```

This will install Guzzle HTTP client (required for API testing) along with PHPUnit.

**Run All Tests:**

```bash
# Run all tests (unit + integration)
composer test:unit

# Or use phpunit directly
vendor/bin/phpunit
```

**Run Only Integration Tests:**

```bash
vendor/bin/phpunit --testsuite "Integration Tests"
```

**Run Specific Test:**

```bash
vendor/bin/phpunit tests/Integration/KoppelingenGebruikIntegrationTest.php
```

**Run with Verbose Output:**

```bash
vendor/bin/phpunit --testsuite "Integration Tests" --verbose
```

#### Test Coverage

The integration tests cover the following scenarios with **3 test organisations** (A, B, C):

**Test Organisations:**
- **Org A**: Product Owner (owns products/modules, can see all usage including cross-org)
- **Org B**: Product Consumer (uses products but doesn't own them)
- **Org C**: Isolated (not involved with any products - control group)

**Total Test Count:** 9 comprehensive integration tests

1. **Basic Functionality:**
   - GET /api/koppelingen-gebruik/{uuid} returns 200 OK
   - Response contains required pagination fields
   - Both gebruiks and koppelingen are returned
   - Works with organisation, module, and product UUIDs

2. **Pagination:**
   - '_limit' parameter limits results correctly
   - '_offset' parameter skips results correctly
   - '_page' parameter works for page-based navigation

3. **Access Control (3-Organisation Matrix):**
   - Organisation UUID filtering (A, B, C isolation)
   - Product/Module owner cross-organisation access
   - Ambtenaar sees all organisations
   - Organisation filter works for ambtenaar users
   - Non-owners cannot access product usage
   - Isolated organisation (C) remains separate

4. **Query Parameters:**
   - Sorting by different fields ('_sort', '_order')
   - Full-text search ('_search')
   - Filtering by organization (ambtenaar only)

5. **Error Handling:**
   - Invalid UUID returns empty results
   - Missing configuration is handled gracefully

**Detailed Documentation:**
- `tests/Integration/TEST_MATRIX.md` - Complete test matrix
- `tests/Integration/THREE_ORG_TEST_SUMMARY.md` - Implementation details

#### Testing in Docker Container

If running tests from within the Nextcloud container:

```bash
# Enter the container
docker exec -it master-nextcloud-1 bash

# Navigate to app directory
cd /var/www/html/apps-extra/softwarecatalog

# Run tests as www-data user
sudo -u www-data vendor/bin/phpunit --testsuite "Integration Tests"
```

#### Manual API Testing

You can also test the API endpoints manually using curl from within the Nextcloud container:

```bash
# Test with organisation UUID
docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  -H "Content-Type: application/json" \
  "http://localhost/index.php/apps/softwarecatalog/api/koppelingen-gebruik/{org-uuid}"

# Test with product/application UUID
docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  -H "Content-Type: application/json" \
  "http://localhost/index.php/apps/softwarecatalog/api/koppelingen-gebruik/{product-uuid}"

# Test with module UUID
docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  -H "Content-Type: application/json" \
  "http://localhost/index.php/apps/softwarecatalog/api/koppelingen-gebruik/{module-uuid}"

# Test with pagination
docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  -H "Content-Type: application/json" \
  "http://localhost/index.php/apps/softwarecatalog/api/koppelingen-gebruik/{uuid}?_limit=10&_page=1"

# Test with organization filter (ambtenaar only)
docker exec -u 33 master-nextcloud-1 curl -s -u 'admin:admin' \
  -H "Content-Type: application/json" \
  "http://localhost/index.php/apps/softwarecatalog/api/koppelingen-gebruik/{uuid}?organisation={org-uuid}"
```

#### Newman/Postman Tests

For automated API testing with Newman:

1. Export Postman collection for the endpoints
2. Run Newman tests against the Docker container:

```bash
newman run collection.json \
  --environment environment.json \
  --reporters cli,json
```

See the main project documentation for more details on Newman testing workflows.

## Troubleshooting

### Common Issues

**No results returned**: 
- Verify that the active user has an organization configured
- Check that gebruiks/koppelingen objects exist with proper afnemer or deelnemers relationships
- Ensure Voorzieningen configuration includes valid gebruik_schema and koppeling_schema
- For koppelingen-gebruik endpoints: Check user group membership or application ownership

**Permission denied on @self update**:
- Verify that the active organization is the afnemer for the specific gebruik
- Check that the gebruik object exists and is accessible

**Empty results from koppelingen-gebruik endpoints**:
- Verify user is in 'ambtenaar' or 'admin' group if expecting full access
- Check if user's organization owns any applications/modules in the system
- Ensure koppeling_schema, suite_schema, and module_schema are configured in Voorzieningen settings
- Verify that applications/modules have proper @self.organisation set to the user's organization

**Configuration errors**:
- Ensure OpenRegister app is installed and enabled  
- Verify Voorzieningen configuration in SoftwareCatalog settings
- Check that register_id and all required schema IDs are correctly configured
- Use the '/api/aangeboden-gebruik/docs' endpoint to verify configuration is loaded correctly


