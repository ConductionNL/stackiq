# Software Catalog Architecture

## Overview

The Software Catalog app is a Nextcloud application that provides automated user management, group assignment, and organizational hierarchy management based on OpenRegister object events.

## System Components

### Core Services

#### SoftwareCatalogueService
**Location**: 'lib/Service/SoftwareCatalogueService.php'

The main service handling all user and organization management logic:
- User account creation and management
- Group assignment and management  
- Organization hierarchy handling
- Manager relationship establishment
- Email notifications

**Key Methods:**
- 'processContactgegevens()' - Main entry point for user processing
- 'processOrganization()' - Main entry point for organization processing
- 'updateUserGroups()' - Handles all group assignments
- 'ensureOrganizationBeheerder()' - Manages organizational hierarchy
- 'getUserManager()' - Retrieves user manager information

#### SettingsService
**Location**: 'lib/Service/SettingsService.php'

Manages application configuration and schema mappings:
- Schema ID retrieval for different object types
- Register-specific configuration handling
- Settings persistence and loading

#### EmailService
**Location**: 'lib/Service/EmailService.php'

Handles automated email notifications:
- Welcome emails for new organizations
- User creation notifications
- Role assignment notifications

### Event Handling

#### SoftwareCatalogEventListener
**Location**: 'lib/EventListener/SoftwareCatalogEventListener.php'

Central event listener that processes OpenRegister object events:
- Listens to ObjectCreatedEvent, ObjectUpdatedEvent, ObjectDeletedEvent
- Routes events to appropriate service methods
- Handles different object types (contactgegevens, organization, gebruiker, contact)

**Supported Events:**
- 'ObjectCreatedEvent' - New object creation
- 'ObjectUpdatedEvent' - Object modifications
- 'ObjectDeletedEvent' - Object removal
- 'ObjectLockedEvent' - Object locking
- 'ObjectUnlockedEvent' - Object unlocking
- 'ObjectRevertedEvent' - Object reversion

### Configuration Management

#### Routes
**Location**: 'appinfo/routes.php'

Defines API endpoints:
- Settings management endpoints
- Schema configuration endpoints
- Load configuration endpoints

#### Application Bootstrap
**Location**: 'lib/AppInfo/Application.php'

Handles application initialization:
- Event listener registration
- Service container setup
- Dependency injection configuration

## Data Flow

### User Creation Flow

```
1. Contactgegevens Object Created/Updated
   ↓
2. SoftwareCatalogEventListener receives event
   ↓
3. Event routed to handleObjectCreated/Updated
   ↓
4. SoftwareCatalogueService.processContactgegevens()
   ↓
5. Username generation from name fields
   ↓
6. User account creation in Nextcloud
   ↓
7. Group assignment (role-based, organization, special)
   ↓
8. Organization beheerder check and assignment
   ↓
9. Manager relationship establishment
   ↓
10. Object updated with username
```

### Organization Processing Flow

```
1. Organization Object Created/Updated
   ↓
2. SoftwareCatalogEventListener receives event
   ↓
3. Event routed to handleObjectCreated/Updated
   ↓
4. SoftwareCatalogueService.processOrganization()
   ↓
5. Organization group creation (if needed)
   ↓
6. Group ID stored back to organization object
   ↓
7. Existing users linked to organization group
```

### Group Assignment Flow

```
1. User processing initiated
   ↓
2. updateUserGroups() called
   ↓
3. Role-based group assignment
   ├── Check user roles array
   ├── Create groups if needed
   ├── Add/remove user from groups
   └── Log changes
   ↓
4. Organization group assignment
   ├── Get organization UUID from user data
   ├── Find organization object
   ├── Get organization group ID
   └── Add user to organization group
   ↓
5. Special group assignment (gemeente → ambtenaar)
   ├── Check organization type
   ├── Create ambtenaar group if needed
   └── Add user to ambtenaar group
```

## Database Integration

### Nextcloud Integration

The system integrates with Nextcloud's built-in user and group management:

**User Management:**
- 'IUserManager' - User creation and retrieval
- 'IUser' - User object manipulation
- User preferences for manager storage

**Group Management:**
- 'IGroupManager' - Group creation and management
- 'IGroup' - Group object manipulation
- User-group relationship management

### OpenRegister Integration

The system depends on OpenRegister for object storage and events:

**Object Storage:**
- ObjectService for object persistence
- ObjectEntity for object representation
- Schema-based object validation

**Event System:**
- Event dispatching for object lifecycle
- Event listener registration
- Type-safe event handling

## Configuration Architecture

### Schema Mapping

The system uses schema IDs to identify object types:

```php
// Register-specific schemas
'amef_organization_schema' => '123'
'voorzieningen_gebruiker_schema' => '456'
'voorzieningen_organisatie_schema' => '789'

// Generic schemas (fallback)
'organization_schema' => '123'
'gebruiker_schema' => '456'
'contact_schema' => '789'
```

### Multi-Register Support

The system supports multiple register types:
- **AMEF Register**: Organization schema configuration
- **Voorzieningen Register**: User, organization, and contact schemas
- **Generic Fallback**: Default schema configuration

## Security Considerations

### Permission Management

- Group-based access control
- Manager hierarchy for authorization
- Role-based feature access

### Data Validation

- Input sanitization for group names
- Type safety for schema ID comparisons
- Graceful handling of malformed data

### Error Handling

- Comprehensive exception catching
- Detailed error logging
- Graceful degradation on service failures

## Performance Considerations

### Event Processing

- Parallel tool execution where possible
- Efficient group membership checking
- Cached schema ID lookups

### Database Operations

- Batch user operations where applicable
- Optimized group queries
- Minimal object re-saves

### Logging

- Appropriate log levels to avoid spam
- Contextual information for debugging
- Performance-critical path optimization

## Extension Points

### Adding New Object Types

1. Add event handling in SoftwareCatalogEventListener
2. Create processing method in SoftwareCatalogueService
3. Update schema configuration in SettingsService
4. Add documentation for new workflow

### Custom Group Logic

1. Extend '_defaultGroups' array for new role-based groups
2. Implement custom assignment logic in '_updateRoleBasedGroups'
3. Add special handling in '_updateGemeenteGroups'

### Custom Event Handling

1. Register additional event listeners in Application.php
2. Create custom event handler methods
3. Integrate with existing service methods

## Dependencies

### Required Nextcloud APIs

- User Manager API ('OCP\IUserManager')
- Group Manager API ('OCP\IGroupManager')
- Configuration API ('OCP\IConfig')
- Logger API ('Psr\Log\LoggerInterface')
- Event Dispatcher ('OCP\EventDispatcher\IEventDispatcher')

### Required Apps

- **OpenRegister**: Provides object storage and event system
- **Software Catalog Settings**: UI for schema configuration

### PHP Dependencies

- PHP 8.1+ for typed properties and union types
- Composer autoloader for dependency management
- PSR-4 autoloading for class structure

## Deployment Architecture

### File Structure

```
softwarecatalog/
├── appinfo/
│   ├── routes.php              # API endpoint definitions
│   └── info.xml               # App metadata
├── lib/
│   ├── AppInfo/
│   │   └── Application.php     # App bootstrap
│   ├── Controller/             # API controllers
│   ├── EventListener/          # Event handling
│   ├── Service/               # Business logic
│   └── ...
├── src/                       # Frontend assets
├── docs/                      # Documentation
└── vendor/                    # Dependencies
```

### Configuration Files

- **Schema Configuration**: Stored in Nextcloud app config
- **Register Settings**: JSON files in app directory
- **User Preferences**: Nextcloud user preference system
- **Group Memberships**: Nextcloud group system 