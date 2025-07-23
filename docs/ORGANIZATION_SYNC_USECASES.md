# Organization Synchronization Use Cases and Testing

**Date:** July 23, 2025  
**App:** SoftwareCatalog  
**Feature:** Organization Synchronization with OpenRegister

## Overview

This document outlines the use cases and testing scenarios for the new organization synchronization functionality in the Software Catalog app. The feature enables automatic synchronization between SoftwareCatalog organizations and OpenRegister, along with user status management based on organization status.

## Use Cases

### 1. Organization Creation Synchronization

**Scenario:** A new organization is created in SoftwareCatalog

**Expected Behavior:**
- Organization data is automatically synced to OpenRegister
- Status mapping: `actief` → `active`, `inactief`/`deactief` → `inactive`
- Organization UUID is preserved across both systems
- Contactpersonen are processed and users are created (inactive initially)

**Test Steps:**
1. Create a new organization in SoftwareCatalog with status `actief`
2. Verify organization appears in OpenRegister with status `active`
3. Verify organization UUID is identical in both systems
4. Create contactpersonen for the organization
5. Verify users are created but set to inactive initially

### 2. Organization Status Change - Activation

**Scenario:** Organization status changes from `inactief` to `actief`

**Expected Behavior:**
- Organization is updated in OpenRegister with status `active`
- All users in the organization are activated
- Activation email is sent to the organization
- Contactpersonen processing continues normally

**Test Steps:**
1. Create organization with status `inactief`
2. Add contactpersonen (users will be inactive)
3. Change organization status to `actief`
4. Verify all users become active
5. Verify activation email is sent
6. Verify organization status is `active` in OpenRegister

### 3. Organization Status Change - Deactivation

**Scenario:** Organization status changes from `actief` to `inactief`

**Expected Behavior:**
- Organization is updated in OpenRegister with status `inactive`
- All users in the organization are deactivated
- Users cannot log in but accounts are preserved

**Test Steps:**
1. Create organization with status `actief`
2. Add contactpersonen (users will be active)
3. Change organization status to `inactief`
4. Verify all users become inactive
5. Verify users cannot log in
6. Verify organization status is `inactive` in OpenRegister

### 4. Organization Deletion

**Scenario:** Organization is deleted from SoftwareCatalog

**Expected Behavior:**
- All users in the organization are deactivated
- Organization data is preserved in OpenRegister (no automatic deletion)
- Users cannot log in but accounts are preserved

**Test Steps:**
1. Create organization with contactpersonen
2. Delete the organization
3. Verify all users become inactive
4. Verify users cannot log in
5. Verify organization still exists in OpenRegister

### 5. Contactpersoon Creation and Organization Membership

**Scenario:** A new contactpersoon is created for an organization

**Expected Behavior:**
- User account is created (inactive initially)
- Username is added to organization's `users` array
- User is assigned to appropriate groups based on roles
- Organization membership is properly established

**Test Steps:**
1. Create organization in SoftwareCatalog
2. Create contactpersoon for the organization
3. Verify user account is created
4. Verify username is added to organization's users list
5. Verify user is assigned to appropriate groups
6. Verify user status matches organization status

### 6. Contactpersoon Update and Organization Membership

**Scenario:** An existing contactpersoon is updated

**Expected Behavior:**
- User account is updated if needed
- Username remains in organization's `users` array
- Group assignments are updated based on role changes
- Organization membership is maintained

**Test Steps:**
1. Create organization with contactpersoon
2. Update contactpersoon roles or details
3. Verify user account is updated
4. Verify username remains in organization's users list
5. Verify group assignments are updated
6. Verify organization membership is maintained

### 7. Cross-System UUID Consistency

**Scenario:** Organizations and contactpersonen use UUIDs across systems

**Expected Behavior:**
- Organization UUIDs are identical in SoftwareCatalog and OpenRegister
- Contactpersoon UUIDs are preserved during processing
- UUID-based lookups work correctly in both systems

**Test Steps:**
1. Create organization and note UUID
2. Verify same UUID appears in OpenRegister
3. Create contactpersoon and note UUID
4. Verify UUID is preserved during user creation
5. Test UUID-based lookups in both systems

### 8. SoftwareCatalog-Specific User Status Management

**Scenario:** Organization status changes affect only SoftwareCatalog-specific users (from contactpersoon objects), while admin group users remain protected.

**Expected Behavior:**
- When organization becomes `inactief`: Only contactpersoon users are deactivated
- When organization becomes `actief`: Only contactpersoon users are activated
- Admin group users are never affected by organization status changes
- Contactpersoon users are identified by their usernames in the organization's contactpersonen array

**Test Steps:**
1. Create organization with status `actief`
2. Create contactpersonen for the organization (users will be active)
3. Add admin users to admin group (they should remain unaffected)
4. Change organization status to `inactief`
5. Verify only contactpersoon users become inactive
6. Verify admin users remain active
7. Change organization status back to `actief`
8. Verify only contactpersoon users become active again

### 9. Contact Person User Creation and Management

**Scenario:** Contact persons are automatically converted to user accounts and managed based on organization status.

**Expected Behavior:**
- Contact person creation triggers user account creation
- Username is generated from contact person data
- User status matches organization status
- User is added to organization's users list
- User can be activated/deactivated with organization status changes

**Test Steps:**
1. Create organization in SoftwareCatalog
2. Create contact person with organization reference
3. Verify user account is created
4. Verify username is added to organization's users list
5. Verify user status matches organization status
6. Test organization status changes affect user status

### 10. Admin Group User Protection

**Scenario:** Admin group users are completely protected from organization status changes.

**Expected Behavior:**
- Admin group users are always added to organizations
- Admin group users are never deactivated when organization becomes inactive
- Admin group users remain active regardless of organization status
- Only SoftwareCatalog-specific users (contactpersonen) are affected by status changes

**Test Steps:**
1. Create organization with admin users present
2. Add additional users to admin group
3. Create contact persons for the organization
4. Deactivate organization
5. Verify admin users remain active
6. Verify contact person users are deactivated
7. Reactivate organization
8. Verify admin users remain active
9. Verify contact person users are reactivated

### 11. Bulk User Management

**Scenario:** Organizations with multiple contact persons can be managed in bulk.

**Expected Behavior:**
- Multiple contact persons can be created for an organization
- All contact person users are managed together
- Bulk activation/deactivation works correctly
- Admin users remain protected during bulk operations

**Test Steps:**
1. Create organization
2. Create multiple contact persons (5-10 users)
3. Verify all users are created and active
4. Deactivate organization
5. Verify all contact person users are deactivated
6. Verify admin users remain active
7. Reactivate organization
8. Verify all contact person users are reactivated

## Testing Scenarios

### Test Environment Setup

**Prerequisites:**
- SoftwareCatalog app enabled
- OpenRegister app enabled
- Proper schema and register configuration
- Test organization and contactpersoon schemas configured

**Configuration Required:**
```php
// SettingsService configuration
'voorzieningen_organisatie_schema' => '37', // Organization schema ID
'voorzieningen_contactpersoon_schema' => '38', // Contactpersoon schema ID
'voorzieningen_register' => '1', // Register ID
```

### Test Data Requirements

**Organization Test Data:**
```json
{
  "naam": "Test Organization",
  "type": "Gemeente",
  "website": "https://test.org",
  "beoordeling": "actief",
  "adres": "Test Street 123",
  "postcode": "1234 AB",
  "plaats": "Test City",
  "telefoon": "+31 123 456 789",
  "email": "info@test.org"
}
```

**Contactpersoon Test Data:**
```json
{
  "voornaam": "John",
  "achternaam": "Doe",
  "email": "john.doe@test.org",
  "telefoon": "+31 123 456 789",
  "functie": "Manager",
  "organisation": "ORGANIZATION_UUID",
  "roles": ["Functioneel-beheerder", "Gebruik-beheerder"]
}
```

### Automated Test Cases

#### Test Case 1: Organization Creation Flow
```php
// Test organization creation and OpenRegister sync
public function testOrganizationCreationSync(): void
{
    // 1. Create organization in SoftwareCatalog
    $organizationData = $this->createTestOrganization();
    $organization = $this->createOrganization($organizationData);
    
    // 2. Verify organization exists in OpenRegister
    $openRegisterOrg = $this->findOrganizationInOpenRegister($organization->getUuid());
    $this->assertNotNull($openRegisterOrg);
    $this->assertEquals('active', $openRegisterOrg->getStatus());
    
    // 3. Verify UUID consistency
    $this->assertEquals($organization->getUuid(), $openRegisterOrg->getUuid());
}
```

#### Test Case 2: User Status Management
```php
// Test user activation/deactivation based on organization status
public function testUserStatusManagement(): void
{
    // 1. Create organization with inactive status
    $organization = $this->createOrganization(['beoordeling' => 'inactief']);
    
    // 2. Add contactpersoon
    $contactpersoon = $this->createContactpersoon($organization->getUuid());
    
    // 3. Verify user is inactive
    $user = $this->userManager->get($contactpersoon->getUsername());
    $this->assertFalse($user->isEnabled());
    
    // 4. Activate organization
    $this->updateOrganization($organization->getUuid(), ['beoordeling' => 'actief']);
    
    // 5. Verify user is now active
    $user = $this->userManager->get($contactpersoon->getUsername());
    $this->assertTrue($user->isEnabled());
}
```

#### Test Case 3: Organization Membership
```php
// Test contactpersoon organization membership
public function testOrganizationMembership(): void
{
    // 1. Create organization
    $organization = $this->createOrganization();
    
    // 2. Create contactpersoon
    $contactpersoon = $this->createContactpersoon($organization->getUuid());
    
    // 3. Verify username is in organization's users list
    $orgData = $organization->getObject();
    $this->assertContains($contactpersoon->getUsername(), $orgData['users']);
}
```

### Manual Testing Checklist

#### Organization Management
- [ ] Create organization with `actief` status
- [ ] Verify organization appears in OpenRegister with `active` status
- [ ] Create organization with `inactief` status
- [ ] Verify organization appears in OpenRegister with `inactive` status
- [ ] Update organization status from `inactief` to `actief`
- [ ] Verify all users become active
- [ ] Update organization status from `actief` to `inactief`
- [ ] Verify all users become inactive
- [ ] Delete organization
- [ ] Verify all users become inactive

#### Contactpersoon Management
- [ ] Create contactpersoon for active organization
- [ ] Verify user is created and active
- [ ] Verify username is added to organization's users list
- [ ] Create contactpersoon for inactive organization
- [ ] Verify user is created but inactive
- [ ] Update contactpersoon roles
- [ ] Verify group assignments are updated
- [ ] Verify organization membership is maintained

#### Error Handling
- [ ] Test with missing OpenRegister configuration
- [ ] Test with invalid organization UUID
- [ ] Test with missing contactpersoon data
- [ ] Test with network connectivity issues
- [ ] Verify graceful error handling and logging

## Performance Considerations

### Expected Performance
- Organization sync: < 2 seconds
- User status updates: < 1 second per user
- Contactpersoon processing: < 3 seconds
- Organization membership updates: < 1 second

### Monitoring Points
- Event listener execution time
- OpenRegister API response times
- User management operation times
- Database query performance
- Memory usage during bulk operations

## Security Considerations

### Data Protection
- Organization UUIDs are preserved across systems
- User credentials are not shared between systems
- Organization data is mapped securely
- Access controls are maintained per system

### Audit Trail
- All organization sync operations are logged
- User status changes are tracked
- Organization membership updates are recorded
- Error conditions are logged with context

## Troubleshooting

### Common Issues

**Organization not syncing to OpenRegister:**
- Check OpenRegister app is enabled
- Verify schema and register configuration
- Check network connectivity
- Review error logs for specific issues

**Users not activating/deactivating:**
- Verify organization status changes are detected
- Check user manager permissions
- Review contactpersoon organization assignments
- Check for conflicting group assignments

**UUID mismatches:**
- Verify organization creation order
- Check for duplicate organization creation
- Review UUID generation and assignment
- Verify cross-system UUID consistency

### Debug Commands
```bash
# Check organization sync status
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ softwarecatalog:debug:organization-sync

# Check user status for organization
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ softwarecatalog:debug:user-status --organization=UUID

# Check organization membership
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ softwarecatalog:debug:organization-membership --organization=UUID
```

## Future Enhancements

### Planned Features
- Bidirectional sync (OpenRegister → SoftwareCatalog)
- Bulk organization operations
- Advanced status mapping
- Custom field synchronization
- Real-time sync notifications

### Integration Points
- Email notification system
- Group management system
- User hierarchy management
- Audit trail system
- Reporting and analytics

## Conclusion

The organization synchronization feature provides seamless integration between SoftwareCatalog and OpenRegister, ensuring data consistency and proper user management. The comprehensive testing framework ensures reliable operation across various scenarios and edge cases.

For questions or issues, refer to the SoftwareCatalog documentation or contact the development team. 