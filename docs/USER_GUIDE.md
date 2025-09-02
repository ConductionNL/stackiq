# User Guide

## Overview

The Software Catalog app automatically manages user accounts, groups, and organizational hierarchy in Nextcloud based on data from OpenRegister. This guide explains how the system works from a user and administrator perspective.

## For End Users

### Automatic User Account Creation

When you are added to a contactgegevens object in OpenRegister, the system automatically:

1. **Creates Your Account**: Generates a Nextcloud user account
2. **Sets Username**: Creates username from your name fields (voornaam.achternaam)
3. **Assigns Groups**: Adds you to appropriate groups based on your roles
4. **Sets Manager**: Assigns your organization's beheerder as your manager

### Understanding Your Groups

You may be automatically assigned to several types of groups:

#### Role-Based Groups
- **beheerder** - Administrator/manager role with elevated permissions
- **inkoper** - Purchaser role with specific access rights
- **Custom roles** - Additional roles based on your organization's needs

#### Organization Groups
- **Organization-specific group** - All members of your organization
- Example: 'gemeente_amsterdam' for Gemeente Amsterdam employees

#### Special Groups
- **ambtenaar** - Available for manual assignment (civil servant role)

### Your Manager

The system automatically assigns managers:
- Your organization's beheerder becomes your manager
- If multiple beheerders exist, the most senior one is your primary manager
- Manager relationships are used for reporting and approval workflows

### Accessing Your Information

To view your groups and manager:
1. Go to Nextcloud Personal Settings
2. Your groups are visible in the account section
3. Manager information may be displayed depending on your organization's setup

## For Administrators

### Initial Setup

#### 1. Configure Schemas
1. Access Nextcloud Admin Settings
2. Navigate to "Software Catalogus" section
3. Select your register type (AMEF, Voorzieningen, or Generic)
4. Configure schema IDs for each object type
5. Save configuration

#### 2. Verify Event Processing
1. Create a test contactgegevens object in OpenRegister
2. Check Nextcloud Users section to verify account creation
3. Check Groups section to verify group creation
4. Monitor logs for any processing errors

### Managing Users and Groups

#### User Management

**Viewing Users:**
1. Go to Nextcloud Admin Settings → Users
2. Users created by Software Catalog will have:
   - Username format: voornaam.achternaam
   - Group memberships based on roles
   - Manager relationships (stored in preferences)

**Manual User Operations:**
- You can manually modify users, but changes may be overwritten
- Role changes should be made in OpenRegister contactgegevens objects
- Group memberships will be automatically updated

#### Group Management

**Automatic Groups:**
The system creates these groups automatically:
- 'beheerder' - Administrator role
- 'inkoper' - Purchaser role
- 'ambtenaar' - Civil servant role (manual assignment)
- Organization-specific groups (e.g., 'gemeente_amsterdam')

**Manual Group Operations:**
- You can create additional groups manually
- Automatic groups will be recreated if deleted
- Manual group assignments may be overwritten by automatic processing

### Organization Management

#### Organization Processing

When organizations are created/updated in OpenRegister:
1. **Group Creation**: Each organization gets its own group
2. **Name Sanitization**: Organization names are converted to safe group names
3. **Group Assignment**: Group ID is stored back to the organization object

#### Managing Organization Hierarchy

**Beheerder Assignment:**
- First user in an organization automatically gets 'beheerder' role
- Additional beheerders can be assigned manually in OpenRegister
- Oldest beheerder becomes primary manager for the organization

**Manager Relationships:**
- All users get the organization's primary beheerder as manager
- Multiple beheerders get the primary beheerder as their manager
- Manager relationships are stored in user preferences

### Monitoring and Troubleshooting

#### Log Monitoring

Monitor Nextcloud logs for:
- User creation events
- Group assignment changes
- Manager relationship updates
- Processing errors

**Log Examples:**
```
SoftwareCatalog: Successfully processed contactgegevens creation
SoftwareCatalog: Added user to role-based group: beheerder
SoftwareCatalog: Set user manager: john.smith → jane.doe
```

#### Common Issues

**Users Not Created:**
1. Check schema configuration matches OpenRegister
2. Verify contactgegevens objects have required fields (voornaam, achternaam)
3. Check event processing logs for errors

**Groups Not Assigned:**
1. Verify 'roles' property is properly formatted array
2. Check if groups exist and are accessible
3. Verify user has permission to be added to groups

**Manager Relationships Missing:**
1. Check organization has beheerders assigned
2. Verify 'organisation' property links to correct organization UUID
3. Check beheerder users exist and are active

### Best Practices

#### Data Management

**Consistent Data Entry:**
- Ensure consistent naming in contactgegevens objects
- Use proper role names ('beheerder', 'inkoper')
- Maintain accurate organization linkages

**Role Management:**
- Assign beheerder role thoughtfully - they become managers
- Use role-based groups for permission management
- Document custom roles and their purposes

#### Performance Optimization

**Large Organizations:**
- Monitor processing time for large user batches
- Consider staging large imports
- Watch for resource usage during bulk operations

**Event Processing:**
- Events are processed automatically and immediately
- Failed events are logged for manual review
- System handles concurrent events safely

### Customization

#### Adding Custom Roles

To add new role-based groups:

1. **Code Modification**: Update SoftwareCatalogueService
2. **Documentation**: Document new role meanings
3. **Testing**: Verify assignment works correctly

#### Custom Processing Logic

For organization-specific needs:
1. **Event Listeners**: Add custom event processing
2. **Service Extensions**: Extend existing service methods
3. **Custom Groups**: Implement special group logic

### Reporting and Analytics

#### User Statistics

Monitor user creation and assignment:
- Track new users created per month
- Monitor group membership changes
- Analyze manager relationship patterns

#### Organization Metrics

Track organizational data:
- Organization group sizes
- Beheerder distribution
- Manager hierarchy depth

### Security Considerations

#### Access Control

**Group-Based Permissions:**
- Use automatic groups for folder access
- Implement role-based app permissions
- Configure file sharing by group membership

**Manager-Based Security:**
- Manager relationships for approval workflows
- Hierarchical access to subordinate data
- Delegation of administrative tasks

#### Data Privacy

**User Information:**
- Manager relationships stored in user preferences
- Group memberships visible to group administrators
- Contact data synchronized from OpenRegister

**Audit Trail:**
- All user creation logged
- Group assignment changes tracked
- Manager relationship changes recorded

### Integration with Other Apps

#### File Management

**Folder Structure:**
- Use organization groups for shared folders
- Role-based access to specific directories
- Manager access to subordinate folders

#### Workflow Apps

**Approval Processes:**
- Manager relationships for approval chains
- Role-based workflow assignments
- Organization-specific process routing

### Backup and Recovery

#### Regular Backups

**User Data:**
- Group memberships backed up with Nextcloud
- Manager relationships in user preferences
- Configuration stored in app settings

**Recovery Procedures:**
- Groups automatically recreated on next event
- Manager relationships re-established during processing
- Configuration can be restored from backups

### Migration and Updates

#### Version Updates

When updating the Software Catalog app:
1. **Backup First**: Always backup before updating
2. **Test Configuration**: Verify settings after update
3. **Monitor Processing**: Watch for changes in behavior

#### Data Migration

When changing systems:
1. **Export Configuration**: Save schema mappings
2. **Document Customizations**: Record any custom logic
3. **Test Thoroughly**: Verify all functionality works

### Support and Documentation

#### Getting Help

**Log Analysis:**
- Enable debug logging for detailed information
- Search logs for 'SoftwareCatalog' entries
- Include relevant log excerpts when reporting issues

**Documentation Resources:**
- Architecture documentation for technical details
- Configuration guide for setup instructions
- API reference for integration development

#### Community Support

**Best Practices Sharing:**
- Document successful configurations
- Share custom role implementations
- Contribute improvements back to the project 