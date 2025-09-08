# AangebodenGebruik API Documentation

## Overview

The AangebodenGebruik API provides endpoints to manage gebruiks (usage) objects where the active organization is involved either as an afnemer (consumer) or in the deelnemers (participants) list. This API allows organizations to:

1. Retrieve gebruiks objects where they are the afnemer
2. Retrieve gebruiks objects where they are listed in deelnemers
3. Update the '@self' property of a gebruik to claim ownership (only if they are the afnemer)

## Base URL

All endpoints are prefixed with '/api/aangeboden-gebruik'

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

### 4. Get API Documentation

**Endpoint:** 'GET /api/aangeboden-gebruik/docs'

**Description:** Returns comprehensive API documentation including all endpoints, parameters, and examples.

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

Replace 'USAGE_UUID' with an actual usage object UUID from your system.

## Configuration Requirements

The AangebodenGebruik API relies on the following configuration:

1. **OpenRegister App**: Must be installed and available
2. **AMEF Configuration**: Must be configured with proper register_id and gebruik_schemas
3. **User Organization**: Active user must have an organization associated with their account

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

## Troubleshooting

### Common Issues

**No results returned**: 
- Verify that the active user has an organization configured
- Check that gebruiks objects exist with proper afnemer or deelnemers relationships
- Ensure AMEF configuration includes valid gebruik_schemas

**Permission denied on @self update**:
- Verify that the active organization is the afnemer for the specific gebruik
- Check that the gebruik object exists and is accessible

**Configuration errors**:
- Ensure OpenRegister app is installed and enabled  
- Verify AMEF configuration in SoftwareCatalog settings
- Check that register_id and schema IDs are correctly configured


