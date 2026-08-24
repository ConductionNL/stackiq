# Organization Synchronization Use Cases and Testing

**Date:** July 24, 2025  
**App:** Stackiq  
**Feature:** Organization Synchronization with OpenRegister

## 🚨 QUICK REFERENCE FOR NEW CONVERSATION

### Current Implementation Status
- ✅ **Anonymous user registration**: Fixed "No user logged in" error
- ✅ **Ownership assignment**: Implemented automatic ownership transfer
- ✅ **User status management**: Implemented activation/deactivation based on organization status
- ✅ **Admin user protection**: Admin users remain unaffected by organization status changes
- 🔄 **Testing needed**: Anonymous user registration via OpenConnector

### Key API Endpoints
```bash
# Anonymous registration (MAIN TEST)
POST http://nextcloud.local/index.php/apps/openconnector/api/endpoint/register

# Authenticated organization management
POST http://localhost/index.php/apps/openregister/api/objects/6/35
PUT http://localhost/index.php/apps/openregister/api/objects/6/35/{UUID}
GET http://localhost/index.php/apps/openregister/api/organisations/{UUID}
```

### Essential Commands
```bash
# User management
docker-compose exec -u 33 nextcloud php /var/www/html/occ user:list
docker-compose exec -u 33 nextcloud php /var/www/html/occ user:info {username}

# Log monitoring
docker-compose exec nextcloud tail -f /var/www/html/data/nextcloud.log | grep -i "stackiq"

# Configuration
docker-compose exec -u 33 nextcloud php /var/www/html/occ config:app:get stackiq voorzieningen_organisatie_schema
```

### Architecture Summary
- **Objects**: OpenRegister schema-managed data (organisatie, contactpersoon)
- **Entities**: Nextcloud entities (organisation, user)
- **Flow**: Object creation → Entity creation → User creation → Ownership assignment

### Next Priority Test
1. Test anonymous user registration via OpenConnector
2. Verify user creation and ownership assignment
3. Test organization status changes affect user status

---

## Overview

This document outlines the use cases and testing scenarios for the new organization synchronization functionality in the Software Catalog app. The feature enables automatic synchronization between Stackiq organizations and OpenRegister, along with user status management based on organization status.

## Use Cases

### 1. Organization Creation Synchronization

**Scenario:** A new organization is created in Stackiq

**Expected Behavior:**
- Organization data is automatically synced to OpenRegister
- Status mapping: `actief` → `active: true`, `inactief`/`deactief` → `active: false`
- Organization UUID is preserved across both systems
- Contactpersonen are processed and users are created (inactive initially)

**Test Steps:**
1. Create a new organization in Stackiq with status `actief`
2. Verify organization appears in OpenRegister with `active: true`
3. Verify organization UUID is identical in both systems
4. Create contactpersonen for the organization
5. Verify users are created but set to inactive initially

### 2. Organization Status Change - Activation

**Scenario:** Organization status changes from `inactief` to `actief`

**Expected Behavior:**
- Organization is updated in OpenRegister with `active: true`
- All users in the organization are activated
- Activation email is sent to the organization
- Contactpersonen processing continues normally

**Test Steps:**
1. Create organization with status `inactief`
2. Add contactpersonen (users will be inactive)
3. Change organization status to `actief`
4. Verify all users become active
5. Verify activation email is sent
6. Verify organization has `active: true` in OpenRegister

### 3. Organization Status Change - Deactivation

**Scenario:** Organization status changes from `actief` to `inactief`

**Expected Behavior:**
- Organization is updated in OpenRegister with `active: false`
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

**Scenario:** Organization is deleted from Stackiq

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
1. Create organization in Stackiq
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
- Organization UUIDs are identical in Stackiq and OpenRegister
- Contactpersoon UUIDs are preserved during processing
- UUID-based lookups work correctly in both systems

**Test Steps:**
1. Create organization and note UUID
2. Verify same UUID appears in OpenRegister
3. Create contactpersoon and note UUID
4. Verify UUID is preserved during user creation
5. Test UUID-based lookups in both systems

### 8. Stackiq-Specific User Status Management

**Scenario:** Organization status changes affect only Stackiq-specific users (from contactpersoon objects), while admin group users remain protected.

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
1. Create organization in Stackiq
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
- Only Stackiq-specific users (contactpersonen) are affected by status changes

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

### 12. Nested Contact Person Objects

**Scenario:** Contact persons are created as nested objects within organizations rather than as separate objects.

**Expected Behavior:**
- Organizations can contain nested contact person objects
- Nested contact persons are automatically processed for user creation
- User accounts are created for each nested contact person
- User status matches organization status
- Nested contact persons respond to organization status changes

**Test Steps:**
1. Create organization with nested contact persons in the `contactpersonen` array
2. Verify contact persons are properly linked to the organization
3. Verify user accounts are created for each nested contact person
4. Test organization status changes affect nested contact person users
5. Verify admin users remain unaffected

### 13. Mixed Contact Person Creation Methods

**Scenario:** Organizations can have both nested contact persons and separately created contact persons.

**Expected Behavior:**
- Organizations can contain both nested and separate contact persons
- All contact persons (nested and separate) are properly managed
- User management works consistently regardless of creation method
- Organization status changes affect all contact person users uniformly

**Test Steps:**
1. Create organization with some nested contact persons
2. Add additional contact persons via separate API calls
3. Verify all contact persons are properly linked and managed
4. Test organization status changes affect all users consistently
5. Verify admin users remain protected throughout the process

### 14. Anonymous User Registration via OpenConnector

**Scenario:** Anonymous users register organizations through OpenConnector without having existing user accounts or organization entities.

**Expected Behavior:**
- Anonymous user can create organization via OpenConnector endpoint
- System automatically creates organization entity in OpenRegister
- System creates user account for primary contact person
- Newly created user becomes owner of organization and contact person objects
- Organization entity is properly linked to all objects
- User can log in and access their organization

**Test Steps:**
1. Anonymous user submits organization registration via OpenConnector
2. Verify organization object is created with admin as temporary owner
3. Verify organization entity is created in OpenRegister
4. Verify user account is created for primary contact person
5. Verify ownership is transferred to newly created user
6. Verify organization references are properly set
7. Test user login and access to their organization

### 15. OpenRegister Objects vs Entities Architecture

**Scenario:** Understanding the distinction between OpenRegister objects and entities for proper system integration.

**OpenRegister Objects:**
- Abstract data structures managed by schemas
- Stored in `oc_openregister_objects` table
- Have UUID, owner, organization, and schema-specific fields
- Examples: `organisatie` (schema 35), `contactpersoon` (schema 34)
- Created via REST API endpoints with register/schema parameters
- Support complex relationships and nested structures

**OpenRegister Entities:**
- Classic Nextcloud entities with dedicated tables
- Stored in specific entity tables (e.g., `oc_openregister_organisations`)
- Have ID, UUID, name, status, and entity-specific fields
- Examples: `organisation` entity, `user` entity
- Created via entity-specific API endpoints
- Support standard Nextcloud entity operations

**Conversion Process:**
1. Object creation triggers event listeners
2. Event listeners create corresponding entities
3. Entities are linked back to objects via UUID references
4. Ownership and relationships are established
5. System maintains consistency between objects and entities

**Expected Behavior:**
- Objects and entities maintain UUID consistency
- Changes to objects trigger entity updates
- Entity changes can trigger object updates (bidirectional sync)
- Proper ownership and relationship management
- Consistent data across both systems

## Testing Scenarios

### Test Environment Setup

**Prerequisites:**
- Stackiq app enabled
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
    // 1. Create organization in Stackiq
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
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ stackiq:debug:organization-sync

# Check user status for organization
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ stackiq:debug:user-status --organization=UUID

# Check organization membership
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ stackiq:debug:organization-membership --organization=UUID
```

## Future Enhancements

### Planned Features
- Bidirectional sync (OpenRegister → Stackiq)
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

The organization synchronization feature provides seamless integration between Stackiq and OpenRegister, ensuring data consistency and proper user management. The comprehensive testing framework ensures reliable operation across various scenarios and edge cases.

For questions or issues, refer to the Stackiq documentation or contact the development team. 