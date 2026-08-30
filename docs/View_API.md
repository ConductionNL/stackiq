# View API Documentation

## Overview

The SoftwareCatalog View API provides endpoints for querying and enriching ArchiMate views with additional data such as products, usage information (gebruik), and participation data (deelnames gebruik).

**Base URL**: '/api/views'
**API Version**: 1.0.0

## Authentication

All View API endpoints are currently configured as:
- `@NoAdminRequired` - No admin privileges required
- `@NoCSRFRequired` - No CSRF token required
- `@PublicPage` - Publicly accessible

> Note: In production, you may want to adjust these security settings based on your requirements.

## Endpoints

### 1. Get All Views

**Endpoint**: `GET /api/views`

Returns all available ArchiMate views with optional enrichment data.

#### Query Parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `include_products` | boolean | No | false | Include product data in view nodes |
| `include_gebruik` | boolean | No | false | Include usage data in view nodes |
| `include_deelnames_gebruik` | boolean | No | false | Include participation usage data in view nodes |

#### Boolean Parameter Formats

The API accepts various formats for boolean parameters:
- **True values**: `true`, `1`, `yes`, `on`, `True`, `TRUE`
- **False values**: `false`, `0`, `no`, `off`, `False`, `FALSE`

#### Example Requests

```bash
# Get all views without enrichment
GET /api/views

# Get all views with product data
GET /api/views?include_products=true

# Get all views with multiple enrichments
GET /api/views?include_products=1&include_gebruik=yes&include_deelnames_gebruik=true
```

#### Response Format

```json
{
  "success": true,
  "views": [
    {
      "id": "view-lv01",
      "name": "LV01 BGT basisregistratie en SVB view",
      "documentation": "Mocked from GEMMA LV01.",
      "viewNodes": [
        {
          "modelNodeId": "bs-assembleren",
          "viewNodeId": "vn-assembleren-bgt",
          "x": 7,
          "y": 7,
          "width": 341,
          "height": 36,
          "parent": null,
          "name": "Assembleren BGT aanleveringen van bronhouders",
          "type": "businessservice",
          "color": "rgb(255, 255, 204)",
          "borderColor": "rgb(0, 0, 0)",
          "font": {
            "name": "Segoe UI, Arial",
            "size": 12,
            "style": "normal",
            "color": "rgb(0, 0, 0)"
          },
          "description": null,
          "elementRef": "bs-assembleren",
          "products": [], // Only present if include_products=true
          "gebruik": [], // Only present if include_gebruik=true
          "deelnamesGebruik": [] // Only present if include_deelnames_gebruik=true
        }
      ],
      "viewRelationships": [
        {
          "modelRelationshipId": "rel-1",
          "sourceId": "vn-aanleveringen-bgt",
          "targetId": "vn-stuf-imgeo",
          "viewRelationshipId": "rel-1",
          "type": "realization",
          "bendpoints": [
            {
              "x": 92.5,
              "y": 282.5
            }
          ],
          "label": {}
        }
      ]
    }
  ],
  "count": 1,
  "enrichments_applied": ["products", "gebruik"]
}
```

### 2. Get Specific View

**Endpoint**: `GET /api/views/{viewId}`

Returns a specific ArchiMate view by its identifier with optional enrichment data.

#### URL Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `viewId` | string | Yes | The unique identifier of the view to retrieve |

#### Query Parameters

Same as "Get All Views" endpoint.

#### Example Requests

```bash
# Get specific view without enrichment
GET /api/views/view-lv01

# Get specific view with all enrichments
GET /api/views/view-lv01?include_products=true&include_gebruik=true&include_deelnames_gebruik=true
```

#### Response Format

```json
{
  "success": true,
  "view": {
    "id": "view-lv01",
    "name": "LV01 BGT basisregistratie en SVB view",
    "documentation": "Mocked from GEMMA LV01.",
    "viewNodes": [],
    "viewRelationships": []
  },
  "enrichments_applied": []
}
```

#### Error Response (View Not Found)

```json
{
  "success": false,
  "error": "View with ID 'non-existent-view' not found",
  "view": null
}
```

### 3. Get API Documentation

**Endpoint**: `GET /api/views/docs`

Returns comprehensive API documentation in JSON format.

#### Example Request

```bash
GET /api/views/docs
```

#### Response Format

Returns a detailed JSON object containing API version, endpoints, parameters, and examples.

## Data Structures

### ViewNode Structure

Each view node contains the following properties:

| Property | Type | Description |
|----------|------|-------------|
| `modelNodeId` | string | Reference to the ArchiMate element |
| `viewNodeId` | string | Unique identifier within the view |
| `x`, `y` | integer | Position coordinates |
| `width`, `height` | integer | Dimensions |
| `parent` | string\|null | Parent node for nested structures |
| `name` | string | Display name |
| `type` | string | Element type (e.g., "businessservice") |
| `color` | string | Background color in RGB format |
| `borderColor` | string | Border color in RGB format |
| `font` | object | Font styling information |
| `description` | string\|null | Element description |
| `elementRef` | string | Reference to the source element |

### ViewRelationship Structure

Each view relationship contains:

| Property | Type | Description |
|----------|------|-------------|
| `modelRelationshipId` | string | Reference to the ArchiMate relationship |
| `sourceId` | string | Source node viewNodeId |
| `targetId` | string | Target node viewNodeId |
| `viewRelationshipId` | string | Unique identifier within the view |
| `type` | string | Relationship type (e.g., "realization") |
| `bendpoints` | array | Array of bend point coordinates |
| `label` | object | Label information and styling |

## Enrichment Options

### Products (`include_products=true`)

When enabled, adds a `products` array to each viewNode containing product information related to the referenced element.

**Implementation Status**: Placeholder - requires implementation of product data retrieval logic.

### Gebruik (`include_gebruik=true`)

When enabled, adds a `gebruik` array to each viewNode containing usage statistics and data.

**Implementation Status**: Placeholder - requires implementation of usage data retrieval logic.

### Deelnames Gebruik (`include_deelnames_gebruik=true`)

When enabled, adds a `deelnamesGebruik` array to each viewNode containing participation-based usage data.

**Implementation Status**: Placeholder - requires implementation of participation data retrieval logic.

## Error Handling

### HTTP Status Codes

- `200 OK` - Request successful
- `400 Bad Request` - Invalid parameters (e.g., empty viewId)
- `404 Not Found` - View not found
- `500 Internal Server Error` - Server error

### Error Response Format

```json
{
  "success": false,
  "error": "Error description",
  "view": null // or "views": [] for getAllViews
}
```

## Implementation Notes

### Performance Considerations

- The API uses the ViewService for business logic separation
- Enrichment data is loaded upfront when any enrichment option is enabled
- View nodes are processed in batches for optimal performance
- Database queries are optimized using OpenRegister's ObjectService

### Future Enhancements

The API is designed to support future enhancements:

1. **Authentication & Authorization**: Add proper security controls
2. **Pagination**: Support for large result sets
3. **Filtering**: Add query parameters for filtering views
4. **Caching**: Implement response caching for improved performance
5. **Real Enrichment Logic**: Replace placeholder enrichment methods with actual implementations

### Dependencies

- **OpenRegister**: Required for data storage and retrieval
- **AMEF Configuration**: Must be properly configured for view schema access
- **SettingsService**: Used for configuration management

## Testing

### Using curl

```bash
# Test basic functionality
curl -X GET "http://localhost/index.php/apps/stackiq/api/views" \\
  -H "Content-Type: application/json"

# Test with enrichment
curl -X GET "http://localhost/index.php/apps/stackiq/api/views?include_products=true" \\
  -H "Content-Type: application/json"

# Test specific view
curl -X GET "http://localhost/index.php/apps/stackiq/api/views/view-lv01" \\
  -H "Content-Type: application/json"
```

### Expected Data Flow

1. **Client Request** → ViewController
2. **ViewController** → ViewService (business logic)
3. **ViewService** → OpenRegister ObjectService (data retrieval)
4. **ViewService** → Enrichment methods (if enabled)
5. **ViewService** → Response formatting
6. **ViewController** → JSON response to client

## Integration Examples

### JavaScript/Frontend Integration

```javascript
// Get all views with products
const response = await fetch('/api/views?include_products=true');
const data = await response.json();

if (data.success) {
  console.log('Found views:', data.count);
  data.views.forEach(view => {
    console.log('View:', view.name);
    view.viewNodes.forEach(node => {
      if (node.products && node.products.length > 0) {
        console.log('  Node products:', node.products);
      }
    });
  });
}
```

This completes the View API implementation with full documentation for querying ArchiMate views with enrichment capabilities.

