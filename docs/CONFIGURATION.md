# Configuration Guide

## Prerequisites

### Required Apps

Before configuring the Software Catalog app, ensure the following apps are installed and enabled:

1. **OpenRegister** - Provides object storage and event system
2. **Software Catalog** - This app

### PHP Requirements

- PHP 8.1 or higher
- Required PHP extensions:
  - PDO
  - JSON
  - cURL
  - mbstring

## Installation

### Step 1: App Installation

1. Install the Software Catalog app through Nextcloud App Store or manually
2. Enable the app in Nextcloud Admin Settings
3. Verify the app appears in the app list

### Step 2: Verify Dependencies

1. Check that OpenRegister app is installed and enabled
2. Verify event system is functioning
3. Test API endpoints are accessible

## Schema Configuration

### Access Configuration Interface

1. Go to Nextcloud Admin Settings
2. Navigate to "Software Catalogus" section
3. You'll see the schema configuration interface

### Register Selection

The system supports multiple register types. Select your register type:

#### AMEF Register
For AMEF (Algoritme, Model, Ethiek Framework) registers:
- **Organization Schema**: Configure organization objects
- Other schemas automatically selected

#### Voorzieningen Register  
For service/provision registers:
- **Gebruiker Schema**: User/contact data
- **Organisatie Schema**: Organization data
- **Contactgegevens Schema**: Contact details

#### Generic Configuration
For other register types:
- **Organization Schema**: Organization objects
- **Contact Schema**: Contact/user objects
- **Gebruiker Schema**: User objects
- **Contactgegevens Schema**: Contact details

### Schema ID Configuration

For each object type, you need to configure the schema ID:

1. **Find Schema IDs**: Go to OpenRegister → Schemas and note the numeric IDs
2. **Configure Mappings**: Enter the schema IDs in the Software Catalog settings
3. **Save Configuration**: Click "Opslaan" to save settings

**Example Configuration:**
```
Organization Schema: 25
Gebruiker Schema: 28  
Contactgegevens Schema: 34
```

### Validation

After configuration:
1. Check "Current Configuration" section shows your settings
2. Verify schema IDs are correctly mapped
3. Test with a sample object creation

## Object Schema Requirements

### Contactgegevens Object

The contactgegevens object must contain the following properties for full functionality:

**Required Properties:**
- 'voornaam' (string) - First name
- 'achternaam' (string) - Last name

**Optional Properties:**  
- 'tussenvoegsel' (string) - Middle name/infix
- 'email' (string) - Email address
- 'roles' (array) - Array of role names ['beheerder', 'inkoper']
- 'organisation' (string) - UUID of linked organization

**Example Structure:**
```json
{
  "voornaam": "Jane",
  "tussenvoegsel": "van",
  "achternaam": "Doe", 
  "email": "jane.doe@example.com",
  "roles": ["beheerder"],
  "organisation": "12345678-1234-1234-1234-123456789abc"
}
```

### Organization Object

The organization object should contain:

**Required Properties:**
- 'naam' or 'name' (string) - Organization name

**Optional Properties:**
- 'type' or 'soort' (string) - Organization type (e.g., 'gemeente')
- 'group' (string) - Group ID (automatically filled by system)

**Example Structure:**
```json
{
  "naam": "Gemeente Amsterdam",
  "type": "gemeente",
  "group": "gemeente_amsterdam"
}
```

## Role Configuration

### Default Roles

The system comes with pre-configured roles:

- **beheerder** - Administrator/manager role
- **inkoper** - Purchaser/buyer role

### Adding Custom Roles

To add new roles:

1. **Update Code**: Modify '_defaultGroups' array in SoftwareCatalogueService.php
2. **Add Role Logic**: Implement custom logic if needed
3. **Test Assignment**: Verify users get assigned to new roles

**Example Code Change:**
```php
private array $_defaultGroups = [
    'beheerder',
    'inkoper',
    'coordinator',    // New role
    'viewer'         // New role
];
```

## Event Configuration

### Event Listener Registration

The system automatically registers event listeners for:

- ObjectCreatedEvent
- ObjectUpdatedEvent  
- ObjectDeletedEvent
- ObjectLockedEvent
- ObjectUnlockedEvent
- ObjectRevertedEvent

### Verifying Event Processing

To verify events are being processed:

1. **Check Logs**: Monitor Nextcloud logs for event processing messages
2. **Test Creation**: Create a contactgegevens object and verify user creation
3. **Monitor Groups**: Check if groups are created and users assigned

## Email Configuration

### Email Service Setup

The system uses Nextcloud's email service for notifications:

1. **Configure SMTP**: Set up SMTP in Nextcloud admin settings
2. **Verify Sending**: Test email functionality
3. **Check Templates**: Email templates are in EmailService.php

### Notification Types

The system sends emails for:
- New organization creation
- New user account creation
- Role assignments (if configured)

## Group Management Configuration

### Automatic Group Creation

Groups are automatically created for:

1. **Role-Based Groups**: 'beheerder', 'inkoper'
2. **Organization Groups**: Based on organization name
3. **Special Groups**: 'ambtenaar' available for manual assignment (no automatic assignment)

### Group Naming Rules

Organization groups follow naming conventions:
- Lowercase conversion
- Special characters → underscores
- Multiple underscores → single underscore
- Trimmed underscores

**Examples:**
- "Gemeente Amsterdam" → 'gemeente_amsterdam'
- "ABC Corp B.V." → 'abc_corp_b_v'

## Manager Configuration

### Manager Assignment Rules

The system automatically assigns managers:

1. **Organization Beheerder**: Becomes manager for all organization users
2. **Multiple Beheerders**: Oldest becomes primary manager
3. **Storage**: Manager info stored in user preferences

### Accessing Manager Data

Managers are stored in Nextcloud user preferences:
- **App**: 'softwarecatalog'
- **Key**: 'manager'
- **Value**: Manager username

## Troubleshooting

### Common Issues

#### Schema IDs Not Working
- **Check Configuration**: Verify schema IDs are correctly entered
- **Verify Schemas**: Ensure schemas exist in OpenRegister
- **Check Types**: Ensure IDs are numeric (not strings)

#### Users Not Created
- **Check Events**: Verify events are being dispatched
- **Check Schema Mapping**: Ensure contactgegevens schema is configured
- **Check Required Fields**: Verify voornaam/achternaam are present

#### Groups Not Assigned
- **Check Roles Array**: Ensure 'roles' property is an array
- **Verify Group Creation**: Check if groups exist in Nextcloud
- **Check Permissions**: Ensure app has group management permissions

#### Manager Relationships Not Set
- **Check Organization Link**: Verify 'organisation' property exists
- **Check Beheerder Assignment**: Ensure organization has beheerders
- **Check User Creation Order**: First user should get beheerder role

### Debug Mode

Enable debug logging:

1. **Nextcloud Logs**: Set log level to Debug in Nextcloud settings
2. **Check Log Files**: Monitor '/var/log/nextcloud.log' or equivalent
3. **Search Keywords**: Look for 'SoftwareCatalog' in logs

### Log Examples

**Successful Processing:**
```
SoftwareCatalog: Successfully processed contactgegevens creation
SoftwareCatalog: Created new group: gemeente_amsterdam
SoftwareCatalog: Added user to role-based group: beheerder
SoftwareCatalog: Set user manager: john.smith → jane.doe
```

**Error Examples:**
```
SoftwareCatalog: Failed to process contactgegevens: User creation failed
SoftwareCatalog: Schema ID mismatch: expected 34, got 35
SoftwareCatalog: Organization not found for UUID: 12345678-1234-1234-1234-123456789abc
```

## Performance Tuning

### Large Organizations

For organizations with many users:

1. **Batch Processing**: Process users in smaller batches
2. **Cache Groups**: Cache group lookups to avoid repeated queries
3. **Monitor Performance**: Watch for slow event processing

### Event Processing

Optimize event handling:

1. **Parallel Processing**: Events are processed in parallel where possible
2. **Error Isolation**: Failed events don't block others
3. **Retry Logic**: Failed operations are logged for manual retry

## Security Configuration

### Group Access Control

Configure group-based access:

1. **Folder Permissions**: Use groups for folder access control
2. **App Permissions**: Restrict app access by group membership
3. **File Sharing**: Configure sharing permissions by group

### Manager Permissions

Manager relationships can be used for:

1. **Delegation**: Managers can act on behalf of subordinates
2. **Reporting**: Hierarchical reporting structures
3. **Approval Workflows**: Manager-based approval processes

## Backup and Recovery

### Configuration Backup

Backup important configuration:

1. **App Settings**: Export schema configurations
2. **Group Memberships**: Backup Nextcloud group data
3. **User Preferences**: Backup manager relationship data

### Recovery Procedures

In case of data loss:

1. **Restore Configuration**: Re-import schema settings
2. **Recreate Groups**: Groups will be auto-created on next event
3. **Rebuild Relationships**: Manager relationships will be re-established

## Migration

### From Previous Versions

When upgrading:

1. **Backup Data**: Always backup before upgrading
2. **Check Compatibility**: Verify OpenRegister compatibility  
3. **Test Configuration**: Verify schema mappings still work
4. **Monitor Events**: Watch for processing issues after upgrade

### Schema Changes

When schemas change:

1. **Update Mappings**: Reconfigure schema IDs if needed
2. **Test Processing**: Verify objects still process correctly
3. **Update Documentation**: Document any new requirements 