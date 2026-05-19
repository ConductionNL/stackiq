---
description: Get started with SoftwareCatalog, the IT-asset and licence register for Nextcloud. Inventory, contracts, dependencies, one record per install.
---

# Software Catalog Documentation

Welcome to the Software Catalog app documentation. This app provides comprehensive user and organization management for Nextcloud, with automatic group assignment, organizational hierarchy management, and seamless integration with OpenRegister.

## Quick Start

1. **Install Prerequisites**: Ensure OpenRegister app is installed and enabled
2. **Configure Schemas**: Set up schema mappings in Admin Settings → Software Catalogus
3. **Test Processing**: Create a contactgegevens object in OpenRegister to verify automatic user creation
4. **Monitor Groups**: Check that users are assigned to appropriate groups

## Key Features

### 🚀 Automatic User Management
- **User Creation**: Automatic Nextcloud account creation from contactgegevens objects
- **Username Generation**: Smart username creation from name fields (voornaam.achternaam)
- **Profile Synchronization**: User data kept in sync with OpenRegister

### 👥 Advanced Group Management
- **Role-Based Groups**: Automatic assignment to groups based on user roles (beheerder, inkoper)
- **Organization Groups**: Each organization gets its own group with automatic member assignment
- **Special Groups**: The 'ambtenaar' group is available for manual assignment (no longer automatically assigned)
- **Dynamic Updates**: Group memberships automatically updated when roles change

### 🏢 Organizational Hierarchy
- **Auto-Beheerder Assignment**: First user in organization automatically becomes beheerder
- **Manager Relationships**: Beheerders become managers for their organization's users
- **Hierarchy Management**: Multiple beheerders supported with seniority-based primary manager
- **Organization Groups**: Automatic group creation and management for each organization

### ⚡ Event-Driven Processing
- **Real-Time Updates**: Processes changes immediately via OpenRegister events
- **Multiple Event Types**: Handles creation, updates, deletion, locking, and reversion
- **Error Recovery**: Comprehensive error handling with detailed logging
- **Type Safety**: Robust handling of schema ID mismatches and data validation

## Documentation Overview

### For Users and Administrators
- **[User Guide](USER_GUIDE.md)** - Complete guide for end users and system administrators
- **[Configuration Guide](CONFIGURATION.md)** - Setup instructions and troubleshooting

### For Developers and Integrators  
- **[Architecture Documentation](ARCHITECTURE.md)** - System design and component overview
- **[Group Management Guide](GROUP_MANAGEMENT.md)** - Detailed explanation of group logic
- **[API Reference](API_REFERENCE.md)** - Technical API documentation

## System Architecture

```
OpenRegister Events → SoftwareCatalogEventListener → SoftwareCatalogueService
                                                           ↓
User Creation ← Group Assignment ← Organization Processing ← Manager Assignment
     ↓                ↓                     ↓                      ↓
Nextcloud Users   Nextcloud Groups   Organization Groups   User Preferences
```

## Supported Object Types

### Contactgegevens Objects
- **User Creation**: Generates Nextcloud user accounts
- **Group Assignment**: Role-based and organization-based groups
- **Manager Assignment**: Organizational hierarchy management

### Organization Objects  
- **Group Creation**: Organization-specific groups
- **Hierarchy Setup**: Beheerder identification and assignment

### Additional Support
- **Gebruiker Objects**: User-specific processing
- **Contact Objects**: Contact management integration

## Register Support

The system supports multiple register types with specific configurations:

### AMEF Register
- Organization schema configuration
- Algorithm, Model, Ethics Framework focus

### Voorzieningen Register
- User (gebruiker) schema
- Organization (organisatie) schema  
- Contact details (contactgegevens) schema

### Generic Configuration
- Flexible schema mapping
- Supports custom register types

## Quick Configuration

### Basic Setup
1. **Access Settings**: Admin Settings → Software Catalogus
2. **Select Register**: Choose your register type (AMEF/Voorzieningen/Generic)
3. **Configure Schemas**: Map schema IDs for each object type
4. **Save Settings**: Configuration is immediately active

### Schema Mapping Example
```
Contactgegevens Schema: 34
Organization Schema: 25
Gebruiker Schema: 28
```

## User Workflow Example

```
1. Contactgegevens Created in OpenRegister
   ├── voornaam: "Jane"
   ├── achternaam: "Doe" 
   ├── roles: ["beheerder"]
   └── organisation: "gemeente-uuid"

2. Software Catalog Processing
   ├── Creates user: jane.doe
   ├── Assigns to 'beheerder' group
   ├── Assigns to 'gemeente_amsterdam' group
   ├── Note: 'ambtenaar' group available for manual assignment
   └── Sets as organization manager (first beheerder)

3. Next User in Same Organization
   ├── Creates user: john.smith
   ├── Assigns to organization group
   └── Sets jane.doe as manager
```

## Key Benefits

### For Organizations
- **Zero-Touch User Management**: Users automatically created and configured
- **Consistent Access Control**: Role-based permissions across the organization
- **Organizational Structure**: Clear hierarchy with automatic manager assignment
- **Scalable Growth**: Handles organizations of any size

### For Administrators  
- **Reduced Manual Work**: Eliminates manual user and group management
- **Audit Trail**: Complete logging of all user and group changes
- **Flexible Configuration**: Supports various organizational structures
- **Error Recovery**: Robust error handling with detailed diagnostics

### For End Users
- **Seamless Access**: Account automatically ready when needed  
- **Clear Permissions**: Intuitive role-based access to resources
- **Organizational Context**: Clear understanding of hierarchy and relationships
- **Consistent Experience**: Uniform access patterns across applications

## Integration Points

### Nextcloud APIs
- **User Manager**: User creation and management
- **Group Manager**: Group creation and membership
- **Config Service**: Settings persistence
- **Event Dispatcher**: Event handling

### OpenRegister Integration
- **Object Service**: Object storage and retrieval
- **Event System**: Real-time change notifications
- **Schema Management**: Type-safe object processing

## Security Features

### Access Control
- **Group-Based Permissions**: Automatic assignment to appropriate groups
- **Manager Hierarchy**: Hierarchical access control
- **Role-Based Security**: Different permissions for different roles

### Data Protection
- **Input Validation**: All data validated before processing
- **Error Isolation**: Failed operations don't affect other users
- **Audit Logging**: Complete trail of all operations

## Performance Characteristics

### Scalability
- **Event-Driven**: Processes changes only when needed
- **Parallel Processing**: Multiple operations processed concurrently
- **Efficient Queries**: Optimized database operations

### Reliability
- **Error Recovery**: Graceful handling of failures
- **Idempotent Operations**: Safe to retry failed operations
- **Comprehensive Logging**: Detailed information for troubleshooting

## Getting Support

### Documentation Resources
Each documentation file provides detailed information for specific audiences:

- **New to the system?** Start with the [User Guide](USER_GUIDE.md)
- **Setting up the system?** Follow the [Configuration Guide](CONFIGURATION.md)  
- **Understanding the architecture?** Read the [Architecture Documentation](ARCHITECTURE.md)
- **Working with groups?** Check the [Group Management Guide](GROUP_MANAGEMENT.md)
- **Integrating with the API?** Use the [API Reference](API_REFERENCE.md)

### Troubleshooting
1. **Check Configuration**: Verify schema mappings are correct
2. **Monitor Logs**: Look for 'SoftwareCatalog' entries in Nextcloud logs
3. **Test Events**: Verify OpenRegister events are being dispatched
4. **Validate Data**: Ensure objects have required properties

### Common Solutions
- **Users not created**: Check contactgegevens schema configuration
- **Groups not assigned**: Verify 'roles' property is an array
- **Managers not set**: Confirm organization has beheerders

## Contributing

### Development Setup
1. **Clone Repository**: Get the latest source code
2. **Install Dependencies**: Set up development environment
3. **Run Tests**: Verify functionality works correctly
4. **Submit Changes**: Follow contribution guidelines

### Documentation Updates
- **Keep Current**: Update documentation with any changes
- **Follow Format**: Use consistent formatting and structure
- **Test Examples**: Verify all code examples work correctly

## Version History

### Current Features
- ✅ Automatic user creation from contactgegevens
- ✅ Role-based group assignment (beheerder, inkoper)
- ✅ Organization-specific groups
- ✅ Manager hierarchy management
- ✅ 'ambtenaar' group available for manual assignment
- ✅ Multi-register support (AMEF, Voorzieningen, Generic)
- ✅ Type-safe event processing
- ✅ Comprehensive error handling

### Planned Enhancements
- 🔄 Webhook support for external integrations
- 🔄 Advanced reporting and analytics
- 🔄 Custom role definitions via UI
- 🔄 Bulk user operations
- 🔄 Advanced permission management

---

**Software Catalog** - Automated User and Organization Management for Nextcloud

For technical support, configuration assistance, or feature requests, please refer to the appropriate documentation section or contact your system administrator. 