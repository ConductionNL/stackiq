# Software Catalog Architecture

## Overview

The Software Catalog app is a Nextcloud application that provides automated user management, group assignment, and organizational hierarchy management. The system has evolved from an event-driven architecture to a **cron-based synchronization system** with manual trigger capabilities to avoid racing conditions and ensure data consistency.

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

#### OrganizationSyncService
**Location**: 'lib/Service/OrganizationSyncService.php'

**NEW**: The dedicated service for organization synchronization between SoftwareCatalog objects and OpenRegister entities:
- Comprehensive organization and contact person synchronization
- User account management for contact persons
- Organization entity creation and management
- Admin user protection during status changes
- Detailed logging for all synchronization steps

**Key Methods:**
- 'performFullSync()' - Core synchronization logic
- 'performScheduledSync()' - Scheduled (cron) synchronization with logging
- 'performManualSync()' - Manual (API) synchronization with logging
- 'getSyncStatus()' - Synchronization status and statistics
- 'recordSyncTime()' - Track last synchronization time

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

### Background Job System

#### OrganizationContactSyncJob
**Location**: 'lib/BackgroundJob/OrganizationContactSyncJob.php'

**NEW**: Background job that runs every 5 minutes to ensure data consistency:
- Delegates all business logic to OrganizationSyncService
- No direct business logic - pure orchestration
- Handles job scheduling and execution
- Minimal dependencies for reliability

**Key Features:**
- Runs every 5 minutes (300 seconds)
- Calls 'OrganizationSyncService::performScheduledSync()'
- Comprehensive error handling and logging
- No direct database or service dependencies

### Event Handling (Legacy)

#### SoftwareCatalogEventListener
**Location**: 'lib/EventListener/SoftwareCatalogEventListener.php'

**NOTE**: Event listeners are now primarily used for contact person processing, while organization synchronization uses the cron-based system.

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
- **NEW**: Manual synchronization trigger endpoint

#### Application Bootstrap
**Location**: 'lib/AppInfo/Application.php'

Handles application initialization:
- Event listener registration
- Service container setup
- Dependency injection configuration
- **NEW**: Background job registration

## Data Flow

### Organization Synchronization Flow (NEW)

```
1. Cron Job Trigger (every 5 minutes)
   ↓
2. OrganizationContactSyncJob.run()
   ↓
3. OrganizationSyncService.performScheduledSync()
   ↓
4. OrganizationSyncService.performFullSync()
   ├── Get all organisatie objects from OpenRegister
   ├── For each organization:
   │   ├── Ensure organization entity exists
   │   ├── Get all contact persons for organization
   │   ├── Process each contact person (create/update users)
   │   └── Update organization entity with all usernames
   └── Record sync time and log results
   ↓
5. Manual Trigger (API endpoint)
   ↓
6. SettingsController.performSync()
   ↓
7. OrganizationSyncService.performManualSync()
   ↓
8. Same core logic as scheduled sync
```

### User Creation Flow (Updated)

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

### Organization Processing Flow (Updated)

```
1. Organization Object Created/Updated
   ↓
2. SoftwareCatalogEventListener receives event (legacy)
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
   ↓
8. NEW: Organization sync job will handle entity creation
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
5. Group management (manual assignment only)
   ├── Create required groups if needed
   ├── 'ambtenaar' group available for manual assignment
   └── No automatic assignment based on organization type
```

## Synchronization Architecture (NEW)

### Why Cron-Based Instead of Event-Driven?

**Problems with Event-Driven System:**
- Racing conditions between multiple event listeners
- Inconsistent state when events fire out of order
- Difficult to debug and trace execution flow
- No guarantee of completion order

**Benefits of Cron-Based System:**
- Predictable execution every 5 minutes
- Comprehensive logging of all steps
- Manual trigger capability for immediate sync
- No racing conditions - single execution path
- Easy to test and debug

### Synchronization Steps

#### 1. Configuration Validation
- Check register and schema configuration
- Validate required services are available
- Log configuration status

#### 2. Organization Object Retrieval
- Get all organisatie objects from specified register/schema
- Log count of objects found
- Handle retrieval errors gracefully

#### 3. Organization Entity Management
- For each organization object:
  - Check if organization entity exists
  - Create entity if missing
  - Update entity if needed
  - Log creation/update operations

#### 4. Contact Person Processing
- For each organization:
  - Get all contact persons for the organization
  - Process each contact person:
    - Check if user account exists
    - Create user account if missing
    - Update user if needed
    - Log user operations

#### 5. Organization User List Updates
- Update organization entity with all usernames
- Include admin users in the list
- Log user list changes
- Handle update errors

#### 6. Status Tracking
- Record synchronization completion time
- Log comprehensive statistics
- Handle and log any errors

### Manual Synchronization

#### API Endpoint
- **URL**: `POST /apps/softwarecatalog/api/settings/sync`
- **Authentication**: Required (admin or authorized user)
- **Response**: JSON with sync results and statistics

#### Manual Trigger Flow
```
1. API Request to SettingsController.performSync()
   ↓
2. OrganizationSyncService.performManualSync()
   ↓
3. Same core logic as scheduled sync
   ↓
4. API Response with results
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

**Entity System:**
- Organization entities for user management
- Entity-Object relationship management
- UUID consistency across systems

## Configuration Architecture

### Schema Mapping

The system uses schema IDs to identify object types:

```php
// Register-specific schemas
'amef_organization_schema' => '123'
'voorzieningen_gebruiker_schema' => '456'
'voorzieningen_organisatie_schema' => '789'
'voorzieningen_contactpersoon_schema' => '101'

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
- Admin user protection during sync

### Data Validation

- Input sanitization for group names
- Type safety for schema ID comparisons
- Graceful handling of malformed data
- UUID format validation and conversion

### Error Handling

- Comprehensive exception catching
- Detailed error logging
- Graceful degradation on service failures
- Sync error tracking and reporting

## Performance Considerations

### Synchronization Performance

- Batch processing of organizations
- Efficient user lookup and creation
- Minimal database operations
- Progress tracking and logging

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
- Structured logging for analysis

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

### Synchronization Extensions

1. Add new sync methods to OrganizationSyncService
2. Extend sync statistics and reporting
3. Add custom sync triggers or conditions

## Dependencies

### Required Nextcloud APIs

- User Manager API ('OCP\IUserManager')
- Group Manager API ('OCP\IGroupManager')
- Configuration API ('OCP\IConfig')
- Logger API ('Psr\Log\LoggerInterface')
- Event Dispatcher ('OCP\EventDispatcher\IEventDispatcher')
- **NEW**: Background Job API ('OCP\BackgroundJob\IJobList')
- **NEW**: Time Factory API ('OCP\AppFramework\Utility\ITimeFactory')

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
│   ├── BackgroundJob/          # NEW: Background jobs
│   │   └── OrganizationContactSyncJob.php
│   ├── Controller/             # API controllers
│   ├── EventListener/          # Event handling
│   ├── Service/               # Business logic
│   │   ├── SoftwareCatalogueService.php
│   │   ├── OrganizationSyncService.php  # NEW
│   │   ├── SettingsService.php
│   │   └── EmailService.php
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
- **Sync Configuration**: Stored in Nextcloud app config

## Testing Architecture

### Synchronization Testing

**Manual Testing:**
- Use "Sync Now" button in settings UI
- Monitor logs for detailed execution steps
- Verify organization and user creation
- Check entity-object consistency

**Automated Testing:**
- Background job execution testing
- API endpoint testing
- Service method unit testing
- Integration testing with OpenRegister

### Log Analysis

**Key Log Patterns:**
- `OrganizationSyncService: Starting comprehensive organization synchronization`
- `OrganizationSyncService: Found organisatie objects`
- `OrganizationSyncService: Processing organisatie object`
- `OrganizationSyncService: Creating new organisation entity`
- `OrganizationSyncService: Creating user account for contact person`
- `OrganizationSyncService: Successfully updated organisation entity users`

**Debug Commands:**
```bash
# Monitor sync logs
docker-compose exec nextcloud tail -f /var/www/html/data/nextcloud.log | grep -i "organizationsyncservice"

# Check sync status
curl -u 'admin:admin' 'http://localhost/index.php/apps/softwarecatalog/api/settings/sync-status'

# Manual sync trigger
curl -u 'admin:admin' -X POST 'http://localhost/index.php/apps/softwarecatalog/api/settings/sync'
```

## OpenRegister Abstraction Adoption

SoftwareCatalog is an OpenRegister-backed app (ADR-001: all data in OR).
This section documents how it adopts the fleet-wide OR abstractions.

### Architectural manifest (ADR-024)

`src/manifest.json` declares the app's menu and pages and is loaded via
`useAppManifest('softwarecatalog', bundledManifest)` in `src/main.js`. It
sets `dependencies: ["openregister"]`. Validate it locally with
`npm run check:manifest` (Ajv schema validation with a structural-lint
fallback when the published schema is not resolvable).

### Register / schema resolution

Register and schema IDs are resolved through
`SettingsService::getSchemaIdForObjectType()` and
`getRegisterIdForObjectType()`. These methods consolidate the lookup across
the AMEF and Voorzieningen register configurations and cache results
in-memory, so individual services do not re-derive register/schema IDs from
raw app-config keys. Non-register tunables (sync intervals, feature flags,
email/group settings) intentionally remain on `IAppConfig` directly.

> When OpenRegister ships its shared `RegisterResolverService`
> (`openregister/openspec/changes/register-resolver-service/`), the in-app
> resolver becomes a thin adapter over it. Until that class is merged the
> app keeps its own resolver (ADR-022: consume only shipped OR APIs).

### i18n language negotiation (ADR-025)

All OR object reads issued from the frontend stamp `?_lang={language}` with
the user's Nextcloud language (region tag stripped, e.g. `en_GB` → `en`).
The logic lives in `src/composables/orClient.js`:

- `resolveLanguage()` — derive the bare language code.
- `withLanguageParam(url)` — append `_lang` (without clobbering an existing
  one or an existing query string).
- `buildWriteHeaders(base, { targetLang, organisation })` — layer the
  optional `X-Translation-Target-Language` and `X-OpenRegister-Organisation`
  headers onto a write.

Writes that edit a specific (non-default) language variant pass `targetLang`
through `patchObject(type, id, changes, targetLang)`, which stamps
`X-Translation-Target-Language` so OR writes into the correct language slot.

`src/utils/translationBadge.js` computes a `(translated from {language})`
badge descriptor for index rows where the served language differs from the
object's `sourceLanguage` metadata. The badge label is i18n-keyed
(`(translated from {language})`, present in all l10n files).

### Multi-tenancy readiness

The write-header helper already supports stamping
`X-OpenRegister-Organisation`. Full tenant-switch reactivity (refetch on
switch, navigate-back on detail) trails the nc-vue `useTenantContext()`
release and is tracked in the `softwarecatalog-adopt-or-abstractions`
change (Phase 4).
``` 