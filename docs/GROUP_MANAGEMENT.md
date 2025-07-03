# Group Management System

The Software Catalog app provides comprehensive group management functionality that automatically assigns users to appropriate groups based on their roles, organization membership, and organizational hierarchy.

## Overview

The group management system handles three main types of group assignments:

1. **Role-Based Groups** - Groups based on user roles ('beheerder', 'inkoper')
2. **Organization Groups** - Groups specific to each organization
3. **Special Groups** - Special groups like 'ambtenaar' for gemeente organizations

## Role-Based Groups

### Default Groups

The system automatically manages the following role-based groups:

- **beheerder** - Administrator/manager role
- **inkoper** - Purchaser/buyer role

These groups are automatically created if they don't exist.

### Assignment Logic

Users are automatically assigned to role-based groups based on the 'roles' property in their contactgegevens object:

```
contactgegevens.roles = ['beheerder'] → User added to 'beheerder' group
contactgegevens.roles = ['inkoper'] → User added to 'inkoper' group  
contactgegevens.roles = ['beheerder', 'inkoper'] → User added to both groups
```

### Dynamic Updates

- **Role Added**: User automatically added to corresponding group
- **Role Removed**: User automatically removed from corresponding group
- **Role Changed**: Groups updated to match new roles

## Organization Groups

### Automatic Group Creation

Every organization automatically gets its own group:

1. When an organization object is created/updated
2. If the organization's 'group' property is empty
3. A group is created with a sanitized version of the organization name
4. The group ID is stored back in the organization's 'group' property

### Group Naming

Organization names are sanitized for group names:
- Converted to lowercase
- Special characters replaced with underscores
- Multiple underscores collapsed to single underscore
- Leading/trailing underscores removed

**Examples:**
- "Gemeente Amsterdam" → 'gemeente_amsterdam'
- "ABC Corp B.V." → 'abc_corp_b_v'
- "Test-Org 123!" → 'test_org_123'

### User Assignment

Users are automatically added to their organization's group based on the 'organisation' property in their contactgegevens object.

## Special Groups

### Gemeente Organizations

Organizations with 'type: "gemeente"' trigger special handling:

- Users automatically get added to the 'ambtenaar' group
- This is in addition to all other group assignments
- The 'ambtenaar' group is created automatically if it doesn't exist

## Automatic Beheerder Assignment

### The Problem

Every organization needs at least one beheerder (administrator) to manage operations and serve as a manager for other users.

### The Solution

The system automatically ensures every organization has a beheerder:

1. **Check Existing Beheerders**: When processing a user, check if organization has any existing beheerders
2. **Auto-Assignment**: If no beheerders exist, automatically assign 'beheerder' role to the current user
3. **Role Persistence**: The role is added to both the contactgegevens object and Nextcloud groups

### Implementation Details

```
Organization: "Gemeente Amsterdam"
First User: jane.doe 
- No existing beheerders found
- jane.doe automatically gets 'beheerder' role
- Role saved to contactgegevens object
- User added to 'beheerder' group

Second User: john.smith
- jane.doe already exists as beheerder
- john.smith gets normal role assignment
- jane.doe becomes john.smith's manager
```

## Manager Hierarchy System

### Manager Assignment Rules

Every user automatically gets a manager assigned:

1. **Beheerder as Manager**: The organization's beheerder becomes the manager for all other users
2. **Multiple Beheerders**: If multiple beheerders exist, the oldest one becomes the primary manager
3. **Beheerder Hierarchy**: Non-primary beheerders get the primary beheerder as their manager

### Manager Storage

Manager relationships are stored in Nextcloud user preferences:
- **App**: 'softwarecatalog'
- **Key**: 'manager'
- **Value**: Manager's username

### Accessing Manager Information

```php
// Get a user's manager
$managerUsername = $softwareCatalogueService->getUserManager('john.smith');

// Returns: 'jane.doe' or null if no manager assigned
```

## Event Processing Flow

### Contactgegevens Object Processing

When a contactgegevens object is created or updated:

1. **Username Generation**: Create/validate username from name fields
2. **Role-Based Groups**: Assign user to groups based on 'roles' property
3. **Organization Groups**: Add user to organization-specific group
4. **Special Groups**: Add to 'ambtenaar' if organization is gemeente
5. **Beheerder Check**: Ensure organization has at least one beheerder
6. **Manager Assignment**: Set up manager relationships

### Organization Object Processing

When an organization object is created or updated:

1. **Group Creation**: Create organization-specific group if needed
2. **Group Assignment**: Store group ID back to organization object

## Configuration

### Schema Configuration

The system requires proper schema configuration in the Software Catalog settings:

- **Contactgegevens Schema**: Must be configured to process user data
- **Organization Schema**: Must be configured to process organization data

### Required Properties

**Contactgegevens Object:**
- 'voornaam' - First name (for username generation)
- 'achternaam' - Last name (for username generation)  
- 'roles' - Array of role names
- 'organisation' - UUID linking to organization object

**Organization Object:**
- 'naam' or 'name' - Organization name
- 'type' or 'soort' - Organization type (optional, used for gemeente detection)
- 'group' - Group ID (automatically filled)

## Error Handling

The system includes comprehensive error handling:

- **Missing Groups**: Automatically created as needed
- **Invalid Data**: Graceful fallbacks and logging
- **Service Failures**: Detailed error logging with context
- **Type Mismatches**: Automatic type casting for schema ID comparisons

## Logging

All operations are logged with appropriate detail levels:

- **Info**: Successful operations and important state changes
- **Warning**: Recoverable issues and fallback scenarios  
- **Error**: Failed operations with full context and stack traces
- **Debug**: Detailed processing information (when enabled)

## Extending the System

### Adding New Role-Based Groups

To add new role-based groups, update the '_defaultGroups' array in SoftwareCatalogueService:

```php
private array $_defaultGroups = [
    'beheerder',
    'inkoper',
    'coordinator',  // New role
    'viewer'       // New role
];
```

The system will automatically:
- Create the groups if they don't exist
- Assign users based on their 'roles' property
- Remove users when roles change

### Custom Group Logic

Extend the '_updateRoleBasedGroups' method to implement custom assignment logic for specific roles or organizations. 