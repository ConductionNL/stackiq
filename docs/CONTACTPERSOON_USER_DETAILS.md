# ContactpersoonService with User Details Enhancement

## Overview

The `ContactpersoonService` has been enhanced with a new method that retrieves contact persons for an organization and automatically fetches their corresponding Nextcloud user details, splicing them into the contact person objects.

## New Method: `getContactPersonsWithUserDetailsForOrganization()`

### Purpose

This method takes an organization UUID as input and returns all contact person objects that are linked to that organization, with their corresponding Nextcloud user details automatically fetched and spliced into each contact person object.

### Method Signature

```php
public function getContactPersonsWithUserDetailsForOrganization(string $organizationUuid): array
```

### Parameters

- `$organizationUuid` (string): The UUID of the organization to get contact persons for

### Returns

- `array`: Array of contact person objects with user details spliced in

### Throws

- `\Exception`: If contact person retrieval fails

## How It Works

1. **Retrieves Contact Persons**: Uses the existing `getContactPersonsForOrganization()` method to get all contact persons linked to the specified organization
2. **Fetches User Details**: For each contact person with a username, fetches the corresponding Nextcloud user details using `IUserManager`
3. **Splices User Details**: Adds the user details as a `userDetails` property to each contact person object
4. **Returns Enhanced Objects**: Returns an array of contact person objects with user details included

## User Details Structure

The user details object includes the following properties:

```php
[
    'uid' => string,           // User ID
    'email' => string,         // User email address
    'displayName' => string,   // User display name
    'enabled' => bool,         // Whether user account is enabled
    'lastLogin' => int,        // Last login timestamp
    'backend' => string,       // User backend class name
    'home' => string,          // User home directory
    'avatarImage' => string,   // Base64 encoded avatar image
    'quota' => string,         // User quota
    'freeQuota' => string      // User free quota
]
```

## API Endpoint

A new API endpoint has been added to expose this functionality:

### Endpoint

```
GET /api/contactpersonen/organisation/{organizationUuid}/with-user-details
```

### Parameters

- `organizationUuid` (path parameter): The UUID of the organization

### Response

```json
{
    "success": true,
    "data": [
        {
            "id": "contact_person_id",
            "uuid": "contact_person_uuid",
            "object": {
                "naam": "John Doe",
                "email": "john.doe@example.com",
                "username": "john.doe@example.com",
                "userDetails": {
                    "uid": "john.doe@example.com",
                    "email": "john.doe@example.com",
                    "displayName": "John Doe",
                    "enabled": true,
                    "lastLogin": 1640995200,
                    "backend": "Database",
                    "home": "/var/www/html/data/john.doe@example.com",
                    "avatarImage": "base64_encoded_image_data",
                    "quota": "1GB",
                    "freeQuota": "500MB"
                }
            },
            "register": 6,
            "schema": 38,
            "created": "2024-01-01T00:00:00Z",
            "modified": "2024-01-01T00:00:00Z"
        }
    ],
    "count": 1,
    "organizationUuid": "organization_uuid"
}
```

## Usage Examples

### Service Usage

```php
use OCA\SoftwareCatalog\Service\ContactpersoonService;

// Inject the service
$contactpersoonService = $container->get(ContactpersoonService::class);

// Get contact persons with user details for an organization
$organizationUuid = 'your-organization-uuid';
$contactPersons = $contactpersoonService->getContactPersonsWithUserDetailsForOrganization($organizationUuid);

// Process the results
foreach ($contactPersons as $contactPerson) {
    $contactData = $contactPerson->getObject();
    
    echo "Contact Person: " . $contactData['naam'] . "\n";
    echo "Email: " . $contactData['email'] . "\n";
    
    if ($contactData['userDetails']) {
        echo "User Enabled: " . ($contactData['userDetails']['enabled'] ? 'Yes' : 'No') . "\n";
        echo "Last Login: " . date('Y-m-d H:i:s', $contactData['userDetails']['lastLogin']) . "\n";
    } else {
        echo "No user account found\n";
    }
}
```

### API Usage

```javascript
// Frontend JavaScript example
async function getContactPersonsWithUserDetails(organizationUuid) {
    try {
        const response = await fetch(`/api/contactpersonen/organisation/${organizationUuid}/with-user-details`);
        const data = await response.json();
        
        if (data.success) {
            console.log(`Found ${data.count} contact persons`);
            
            data.data.forEach(contactPerson => {
                const contactData = contactPerson.object;
                console.log(`Contact: ${contactData.naam}`);
                
                if (contactData.userDetails) {
                    console.log(`User enabled: ${contactData.userDetails.enabled}`);
                    console.log(`Last login: ${new Date(contactData.userDetails.lastLogin * 1000)}`);
                }
            });
        }
    } catch (error) {
        console.error('Failed to fetch contact persons:', error);
    }
}
```

## Error Handling

The method includes comprehensive error handling:

- **Missing Configuration**: Logs warning if Voorzieningen configuration is missing
- **User Not Found**: Logs warning if a contact person has a username but no corresponding Nextcloud user
- **Individual Failures**: If processing one contact person fails, it continues with the others
- **Service Unavailable**: Returns empty array if OpenRegister service is not available

## Logging

The method provides detailed logging at different levels:

- **Info**: Method entry, successful completion, and summary statistics
- **Debug**: Individual user detail fetching
- **Warning**: Missing usernames, users not found, missing configuration
- **Error**: Processing failures with full exception details

## Performance Considerations

- **Efficient Querying**: Uses the existing optimized `getContactPersonsForOrganization()` method
- **Batch Processing**: Processes all contact persons in a single operation
- **Error Resilience**: Continues processing even if individual contact persons fail
- **Caching**: Leverages existing OpenRegister object caching mechanisms

## Dependencies

- **OpenRegister Service**: Requires OpenRegister to be installed and enabled
- **Settings Service**: Uses SettingsService to get Voorzieningen configuration
- **User Manager**: Uses Nextcloud's IUserManager to fetch user details
- **Logger**: Uses PSR-3 logger for comprehensive logging

## Related Files

- `softwarecatalog/lib/Service/ContactpersoonService.php` - Main service implementation
- `softwarecatalog/lib/Controller/ContactpersonenController.php` - API controller
- `softwarecatalog/appinfo/routes.php` - API route definition
- `softwarecatalog/lib/Examples/ContactpersoonServiceExample.php` - Usage examples
