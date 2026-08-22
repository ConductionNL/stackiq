# API Reference

## Overview

The Software Catalog app provides a REST API for configuration management and programmatic access to user and group management functionality.

## Base URL

All API endpoints are relative to the Nextcloud base URL:
```
https://your-nextcloud-domain/index.php/apps/stackiq/api/
```

## Authentication

All API endpoints require proper authentication:
- **Session Authentication**: For web interface calls
- **App Passwords**: For programmatic access
- **OAuth2**: If configured

## Endpoints

### Settings Management

#### Get Current Settings
```
GET /settings
```

Returns the current schema configuration.

**Response:**
```json
{
  "organization_schema": "25",
  "gebruiker_schema": "28", 
  "contact_schema": "30",
  "contactgegevens_schema": "34",
  "amef_organization_schema": null,
  "voorzieningen_gebruiker_schema": "28",
  "voorzieningen_organisatie_schema": "25",
  "voorzieningen_contactgegevens_schema": "34"
}
```

#### Update Settings
```
POST /settings
```

Updates schema configuration.

**Request Body:**
```json
{
  "organization_schema": "25",
  "contactgegevens_schema": "34"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Settings saved successfully"
}
```

#### Load Configuration from Register
```
POST /load
```

Loads configuration from register-specific JSON files.

**Request Body:**
```json
{
  "register": "voorzieningen"
}
```

**Response:**
```json
{
  "success": true,
  "loaded_settings": {
    "voorzieningen_gebruiker_schema": "28",
    "voorzieningen_organisatie_schema": "25"
  }
}
```

## Service Classes API

### SoftwareCatalogueService

#### processContactgegevens()

Processes a contactgegevens object for user creation and group assignment.

```php
public function processContactgegevens(object $contactgegevensObject): bool
```

**Parameters:**
- '$contactgegevensObject' - ObjectEntity containing contactgegevens data

**Returns:**
- 'bool' - True if processing successful

**Throws:**
- 'Exception' - If processing fails

**Usage:**
```php
$service = \OC::$server->get(SoftwareCatalogueService::class);
$result = $service->processContactgegevens($contactgegevensObject);
```

#### processOrganization()

Processes an organization object for group creation.

```php
public function processOrganization(object $organizationObject): bool
```

**Parameters:**
- '$organizationObject' - ObjectEntity containing organization data

**Returns:**
- 'bool' - True if processing successful

#### updateUserGroups()

Updates user group memberships based on contactgegevens data.

```php
public function updateUserGroups(object $contactgegevensObject, string $username): void
```

**Parameters:**
- '$contactgegevensObject' - ObjectEntity with user data
- '$username' - Username to update groups for

#### ensureOrganizationBeheerder()

Ensures organization has at least one beheerder and sets up manager relationships.

```php
public function ensureOrganizationBeheerder(object $contactgegevensObject, string $username): void
```

**Parameters:**
- '$contactgegevensObject' - ContactGegevens object with organization link
- '$username' - Username being processed

#### getUserManager()

Retrieves a user's manager.

```php
public function getUserManager(string $username): ?string
```

**Parameters:**
- '$username' - Username to get manager for

**Returns:**
- 'string|null' - Manager username or null if not set

**Usage:**
```php
$service = \OC::$server->get(SoftwareCatalogueService::class);
$manager = $service->getUserManager('john.doe');
```

### SettingsService

#### getSchemaIdForObjectType()

Gets the schema ID for a specific object type.

```php
public function getSchemaIdForObjectType(string $objectType): ?string
```

**Parameters:**
- '$objectType' - Object type ('organization', 'gebruiker', 'contactgegevens', etc.)

**Returns:**
- 'string|null' - Schema ID or null if not configured

**Usage:**
```php
$settingsService = \OC::$server->get(SettingsService::class);
$schemaId = $settingsService->getSchemaIdForObjectType('contactgegevens');
```

#### loadSettings()

Loads settings from register-specific configuration files.

```php
public function loadSettings(string $register): array
```

**Parameters:**
- '$register' - Register name ('amef', 'voorzieningen')

**Returns:**
- 'array' - Loaded settings array

## Event System API

### Event Classes

#### ObjectCreatedEvent
Dispatched when a new object is created in OpenRegister.

**Properties:**
- 'getObject()' - Returns the created object

#### ObjectUpdatedEvent  
Dispatched when an object is updated in OpenRegister.

**Properties:**
- 'getNewObject()' - Returns the updated object
- 'getOldObject()' - Returns the previous version

#### ObjectDeletedEvent
Dispatched when an object is deleted in OpenRegister.

**Properties:**
- 'getObject()' - Returns the deleted object

### Event Listener Registration

To register custom event listeners:

```php
// In Application.php
public function register(IRegistrationContext $context): void {
    $context->registerEventListener(
        ObjectCreatedEvent::class, 
        CustomEventListener::class
    );
}
```

### Custom Event Listener

```php
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

class CustomEventListener implements IEventListener {
    public function handle(Event $event): void {
        if ($event instanceof ObjectCreatedEvent) {
            // Handle object creation
            $object = $event->getObject();
            // Your custom logic here
        }
    }
}
```

## Error Handling

### HTTP Status Codes

- **200 OK** - Request successful
- **400 Bad Request** - Invalid request data
- **401 Unauthorized** - Authentication required
- **403 Forbidden** - Access denied
- **404 Not Found** - Resource not found
- **500 Internal Server Error** - Server error

### Error Response Format

```json
{
  "success": false,
  "error": "Error message",
  "details": {
    "field": "validation error"
  }
}
```

### Exception Types

#### ProcessingException
Thrown when object processing fails.

```php
try {
    $service->processContactgegevens($object);
} catch (ProcessingException $e) {
    // Handle processing error
    $logger->error('Processing failed: ' . $e->getMessage());
}
```

#### ConfigurationException
Thrown when configuration is invalid or missing.

```php
try {
    $schemaId = $settingsService->getSchemaIdForObjectType('contactgegevens');
} catch (ConfigurationException $e) {
    // Handle configuration error
    $logger->error('Configuration error: ' . $e->getMessage());
}
```

## Integration Examples

### Programmatic User Creation

```php
use OCA\SoftwareCatalog\Service\SoftwareCatalogueService;

// Get the service
$service = \OC::$server->get(SoftwareCatalogueService::class);

// Create contactgegevens object data
$contactData = [
    'voornaam' => 'John',
    'achternaam' => 'Doe',
    'email' => 'john.doe@example.com',
    'roles' => ['beheerder'],
    'organisation' => '12345678-1234-1234-1234-123456789abc'
];

// Create object entity (pseudo-code)
$contactObject = new ObjectEntity();
$contactObject->setObject($contactData);

// Process the object
try {
    $result = $service->processContactgegevens($contactObject);
    if ($result) {
        echo "User created successfully";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
```

### Check User Manager

```php
use OCA\SoftwareCatalog\Service\SoftwareCatalogueService;

$service = \OC::$server->get(SoftwareCatalogueService::class);

$username = 'john.doe';
$manager = $service->getUserManager($username);

if ($manager) {
    echo "Manager for $username is: $manager";
} else {
    echo "No manager assigned to $username";
}
```

### Monitor Organization Groups

```php
use OCP\IGroupManager;

$groupManager = \OC::$server->get(IGroupManager::class);

// Get all groups
$groups = $groupManager->search('');

// Filter organization groups
$orgGroups = array_filter($groups, function($group) {
    $name = $group->getGID();
    return !in_array($name, ['beheerder', 'inkoper', 'ambtenaar']);
});

foreach ($orgGroups as $group) {
    $userCount = count($group->getUsers());
    echo "Organization group: {$group->getGID()} ({$userCount} users)\n";
}
```

## Webhooks (Future)

The API will support webhooks for external system integration:

### Webhook Events
- User created
- User groups updated
- Organization created
- Manager assigned

### Webhook Configuration
```json
{
  "url": "https://external-system.com/webhook",
  "events": ["user.created", "groups.updated"],
  "secret": "webhook-secret"
}
```

## Rate Limiting

API calls are subject to Nextcloud's built-in rate limiting:
- **Default**: 100 requests per 10 minutes per user
- **Configurable**: Admin can adjust limits
- **Headers**: Rate limit info in response headers

## Caching

The system uses caching for performance:
- **Schema IDs**: Cached for 1 hour
- **Group Memberships**: Cached for 30 minutes
- **Manager Relationships**: Cached for 1 hour

### Cache Invalidation

Cache is automatically invalidated on:
- Settings changes
- Group membership changes
- Manager relationship updates

## Security Considerations

### API Security

- **CSRF Protection**: All POST requests require CSRF tokens
- **Input Validation**: All input is validated and sanitized
- **Permission Checks**: API calls check user permissions
- **Audit Logging**: All API calls are logged

### Data Access

- **Group Membership**: Only group administrators can modify groups
- **Manager Access**: Only managers can access subordinate data
- **Admin Access**: Full access requires admin privileges

## Development Guidelines

### Adding New Endpoints

1. **Define Route**: Add route in 'appinfo/routes.php'
2. **Create Controller**: Implement controller method
3. **Add Validation**: Validate input parameters
4. **Update Documentation**: Document new endpoint

### Testing API Endpoints

```php
// Unit test example
public function testProcessContactgegevens() {
    $service = new SoftwareCatalogueService(/* dependencies */);
    
    $contactObject = $this->createMockContactObject();
    $result = $service->processContactgegevens($contactObject);
    
    $this->assertTrue($result);
}
```

### Error Logging

```php
// Service method example
public function processContactgegevens(object $contactObject): bool {
    try {
        // Processing logic
        return true;
    } catch (Exception $e) {
        $this->logger->error('Processing failed', [
            'exception' => $e->getMessage(),
            'objectId' => $contactObject->getId()
        ]);
        throw $e;
    }
}
``` 