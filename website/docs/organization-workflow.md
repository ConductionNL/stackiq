# Organization Update Workflow

This document describes the organization update workflow in the SoftwareCatalog application.

## Overview

The organization update process is triggered when an organization object is created or updated in the system. The workflow ensures that organizations are properly processed only when they become active, and that contact persons are converted to users with appropriate group assignments.

## Workflow Steps

### 1. Organization Status Check

Organizations are only processed when their `beoordeling` field is set to "actief" (or "Actief"). Organizations with status "Concept" are ignored.

### 2. Organization Group Creation

When an organization becomes active:
- The system checks if the organization has a unique group
- If no group exists, a new group is created based on the organization name
- Group names are sanitized and made unique by appending a counter if needed

### 3. Contact Person Processing

If the organization has `contactpersonen` defined, the system:
- Converts each contact person to a `contactgegevens` object
- Links the contact person to the organization via the `organisation` field
- Maps the contact person's `functie` (job function) to appropriate roles

### 4. User Creation

For each contact person:
- A user account is created with the contact person's email as username
- The user is assigned to groups based on their roles
- The user is also added to the organization's group
- All users are added to the default `software-catalog-users` group

## Data Flow

```
Organization Object (beoordeling: "actief")
├── Check/Create Organization Group
├── Process contactpersonen array
│   ├── Create contactgegevens objects
│   ├── Link to organization UUID
│   └── Map functie to roles
└── Create User Accounts
    ├── Assign role-based groups (direct mapping)
    ├── Assign organization group (specific to org)
    ├── Assign "Organisaties-beheerder" group (all org contacts)
    ├── Note: "ambtenaar" group available for manual assignment (no automatic assignment)
    └── Assign default group (software-catalog-users)
```

## Role Mappings

The system maps job functions (`functie`) to roles as follows:

| Job Function | Assigned Roles |
|-------------|----------------|
| CEO | Functioneel-beheerder, Aanbod-beheerder |
| Manager | Functioneel-beheerder, Gebruik-beheerder |
| Beheerder | Gebruik-beheerder |
| Administrator | Functioneel-beheerder |
| Inkoper | Gebruik-beheerder |
| Procurement | Gebruik-beheerder |
| Raadpleger | Gebruik-raadpleger |
| Viewer | Gebruik-raadpleger |
| VNG | VNG-raadpleger |
| *Unknown* | Gebruik-raadpleger (default) |

## Available Roles

The system supports the following roles:
- **Aanbod-beheerder** - Supply management
- **Gebruik-beheerder** - Usage management
- **Gebruik-raadpleger** - Usage viewing
- **Functioneel-beheerder** - Functional administration
- **VNG-raadpleger** - VNG consultation
- **Organisatie-beheerder** - Organization management
- **Ambtenaar** - Civil servant (available for manual assignment, no longer automatically assigned)

## Group Management

### Role-Based Groups

Each role maps to a corresponding group:
- `aanbod-beheerder`
- `gebruik-beheerder`
- `gebruik-raadpleger`
- `functioneel-beheerder`
- `vng-raadpleger`
- `organisatie-beheerder`
- `ambtenaar`

### Organization-Specific Groups

- **Organization Groups**: Each organization gets its own group named after the organization
- **Organisaties-beheerder**: All users created from organization contacts are added to this group
- **Ambtenaar**: Available for manual assignment to users (no longer automatically assigned based on organization type)

### Default Groups

All users are added to:
- `software-catalog-users` - Default group for all catalog users

## Role-to-Group Assignment Logic

The system uses a flexible role-to-group assignment approach:

1. **Direct Role Mapping**: For each role a user has, if there's a corresponding group in the allowed groups list, the user is added to that group
2. **Organization Contact Group**: All users created from organization contacts are automatically added to the `organisaties-beheerder` group
3. **Organization Type Groups**: The `ambtenaar` group is available for manual assignment (automatic assignment based on organization type has been removed)
4. **Organization Group**: Users are added to their organization's specific group
5. **Default Group**: All users are added to the `software-catalog-users` group

### Role Changes

When a contact person's roles change:
- **Role Removal**: Users are removed from groups corresponding to roles they no longer have
- **Role Addition**: Users are added to groups for new roles they receive
- **Organization Groups**: Users remain in organization-specific groups regardless of role changes
- **Organization Type Groups**: The `ambtenaar` group can be manually assigned and managed like other role-based groups

## Example Organization Object

```json
{
  "beoordeling": "actief",
  "naam": "test83",
  "website": "www.example.com",
  "contactpersonen": [
    {
      "voornaam": "testing",
      "tussenvoegsel": "",
      "achternaam": "joe",
      "telefoon": "06 12345678",
      "email": "test86@gmail.com",
      "functie": "ceo"
    }
  ],
  "type": "Leverancier"
}
```

## Example: Gemeente Organization

For organizations of type "Gemeente", users can get the following group assignments:

```json
{
  "beoordeling": "actief",
  "naam": "Gemeente Amsterdam",
  "website": "www.amsterdam.nl",
  "contactpersonen": [
    {
      "voornaam": "Jan",
      "tussenvoegsel": "de",
      "achternaam": "Vries",
      "telefoon": "020 1234567",
      "email": "j.devries@amsterdam.nl",
      "functie": "manager"
    }
  ],
  "type": "Gemeente"
}
```

**Result**: The user "j.devries@amsterdam.nl" will be assigned to these groups:
- `functioneel-beheerder` (from "manager" role)
- `gebruik-beheerder` (from "manager" role)
- `gemeente-amsterdam` (organization-specific group)
- `organisaties-beheerder` (all organization contacts)
- `ambtenaar` (if manually assigned)
- `software-catalog-users` (default group)

## Event Handling

The workflow is triggered by:
- **ObjectCreatedEvent** - When a new organization is created
- **ObjectUpdatedEvent** - When an organization is updated (checks for beoordeling changes)

## Error Handling

The system includes comprehensive error handling:
- Invalid contact data is logged but doesn't stop processing
- Failed user creation is logged with detailed error information
- Group creation failures are handled gracefully
- Processing continues even if individual steps fail

## Configuration

The default roles and groups can be configured through the admin interface at:
**Settings > SoftwareCatalog > Generic User Groups**

## Technical Implementation

The workflow is implemented across several handler classes:
- **OrganizationHandler** - Manages organization processing and group creation
- **ContactPersonHandler** - Handles contact person to user conversion
- **GroupHandler** - Manages generic user groups and role mappings
- **SoftwareCatalogueService** - Coordinates the overall workflow

## Logging

All workflow steps are logged with appropriate detail levels:
- **INFO** - Successful operations and workflow progress
- **WARNING** - Non-critical issues (e.g., missing contact data)
- **ERROR** - Failed operations with full exception details
- **DEBUG** - Detailed processing information 