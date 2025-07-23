# Software Catalog - Organization Synchronization Testing Guide

## Overview
This document provides comprehensive testing scenarios for the organization synchronization functionality between the Software Catalog app and OpenRegister. The synchronization ensures that `organisatie` objects in the Software Catalog are properly synchronized with organization objects in OpenRegister.

## Test Environment Setup

### Prerequisites
- Nextcloud Docker container running (`master-nextcloud-1`)
- Software Catalog app enabled
- OpenRegister app enabled
- Admin credentials: `admin:admin`

### Configuration Verification
Before testing, verify the Software Catalog configuration:
```bash
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' 'http://localhost/index.php/apps/softwarecatalog/api/settings'"
```

Expected configuration:
- `voorzieningen_organisatie_register`: "6"
- `voorzieningen_organisatie_schema`: "35"

## Test Scenarios

### 1. Organization Creation Test

**Objective**: Verify that creating an `organisatie` object in OpenRegister triggers synchronization to create a corresponding organization in OpenRegister.

**Test Steps**:
1. Create a new `organisatie` object in the voorzieningen register:
```bash
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' -H 'Content-Type: application/json' -X POST -d '{\"naam\":\"Test Organization Create\",\"website\":\"https://test-create.org\",\"type\":\"Leverancier\",\"status\":\"actief\"}' 'http://localhost/index.php/apps/openregister/api/objects/6/35'"
```

2. Verify the object was created in the voorzieningen register:
```bash
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' 'http://localhost/index.php/apps/openregister/api/objects/6/35?_limit=800' | grep 'Test Organization Create'"
```

3. Check if the organization was synchronized to OpenRegister organizations:
```bash
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' 'http://localhost/index.php/apps/openregister/api/organisations' | grep 'Test Organization Create'"
```

**Expected Results**:
- Organization object created in voorzieningen register with status "actief"
- Corresponding organization created in OpenRegister with status "active"
- UUID preserved between both systems

### 2. Organization Update Test

**Objective**: Verify that updating an `organisatie` object triggers synchronization to update the corresponding organization in OpenRegister.

**Test Steps**:
1. Update an existing organization:
```bash
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' -H 'Content-Type: application/json' -X PUT -d '{\"naam\":\"Updated Test Organization\",\"website\":\"https://updated-test.org\",\"type\":\"Leverancier\",\"status\":\"inactief\"}' 'http://localhost/index.php/apps/openregister/api/objects/6/35/{ORGANIZATION_ID}'"
```

2. Verify the update in voorzieningen register:
```bash
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' 'http://localhost/index.php/apps/openregister/api/objects/6/35/{ORGANIZATION_ID}'"
```

3. Check if the organization was updated in OpenRegister:
```bash
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' 'http://localhost/index.php/apps/openregister/api/organisations/{ORGANIZATION_ID}'"
```

**Expected Results**:
- Organization updated in voorzieningen register with status "inactief"
- Corresponding organization updated in OpenRegister with status "inactive"
- All other fields properly synchronized

### 3. Organization Deletion Test

**Objective**: Verify that deleting an `organisatie` object triggers synchronization to deactivate the corresponding organization in OpenRegister.

**Test Steps**:
1. Delete an organization:
```bash
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' -X DELETE 'http://localhost/index.php/apps/openregister/api/objects/6/35/{ORGANIZATION_ID}'"
```

2. Verify the deletion in voorzieningen register:
```bash
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' 'http://localhost/index.php/apps/openregister/api/objects/6/35/{ORGANIZATION_ID}'"
```

3. Check if the organization was deactivated in OpenRegister:
```bash
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' 'http://localhost/index.php/apps/openregister/api/organisations/{ORGANIZATION_ID}'"
```

**Expected Results**:
- Organization deleted from voorzieningen register
- Corresponding organization deactivated in OpenRegister
- All users in the organization deactivated

### 4. Status Mapping Test

**Objective**: Verify that status values are properly mapped between Software Catalog and OpenRegister.

**Test Cases**:
- `actief` → `active`
- `inactief` → `inactive`

**Test Steps**:
1. Create organization with status "actief":
```bash
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' -H 'Content-Type: application/json' -X POST -d '{\"naam\":\"Active Test Org\",\"status\":\"actief\"}' 'http://localhost/index.php/apps/openregister/api/objects/6/35'"
```

2. Create organization with status "inactief":
```bash
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' -H 'Content-Type: application/json' -X POST -d '{\"naam\":\"Inactive Test Org\",\"status\":\"inactief\"}' 'http://localhost/index.php/apps/openregister/api/objects/6/35'"
```

3. Verify status mapping in OpenRegister organizations

### 5. Contact Person Organization Membership Test

**Objective**: Verify that when a `contactpersoon` is created or updated, they are automatically added to their organization's users list.

**Test Steps**:
1. Create a contact person with organization reference:
```bash
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' -H 'Content-Type: application/json' -X POST -d '{\"naam\":\"Test Contact\",\"username\":\"testuser\",\"organisatie\":\"{ORGANIZATION_ID}\"}' 'http://localhost/index.php/apps/openregister/api/objects/6/{CONTACT_SCHEMA_ID}'"
```

2. Verify the contact person was added to organization users list:
```bash
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' 'http://localhost/index.php/apps/openregister/api/objects/6/35/{ORGANIZATION_ID}'"
```

**Expected Results**:
- Contact person created successfully
- Username added to organization's users list
- User account created in Nextcloud

### 6. User Status Management Test

**Objective**: Verify that when an organization status changes, all users in that organization are activated/deactivated accordingly.

**Test Steps**:
1. Create organization with users
2. Change organization status to "inactief"
3. Verify all users are deactivated
4. Change organization status to "actief"
5. Verify all users are activated

**Expected Results**:
- Organization status change triggers user status changes
- All users in organization follow organization status
- User accounts properly activated/deactivated in Nextcloud

### 7. SoftwareCatalog-Specific User Activation/Deactivation Test

**Objective**: Verify that when an organization status changes, only SoftwareCatalog-specific users (from contactpersoon objects) are activated/deactivated, while admin group users remain unaffected.

**Test Steps**:

#### 7.1 Organization Deactivation Test
1. Create an organization with contact persons:
```bash
# Create organization
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' -H 'Content-Type: application/json' -X POST -d '{\"naam\":\"Test Org with Users\",\"website\":\"https://test-users.org\",\"type\":\"Leverancier\",\"beoordeling\":\"actief\"}' 'http://localhost/index.php/apps/openregister/api/objects/6/35'"
```

2. Create contact persons for the organization:
```bash
# Create contact person 1
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' -H 'Content-Type: application/json' -X POST -d '{\"voornaam\":\"John\",\"achternaam\":\"Doe\",\"email\":\"john.doe@test.org\",\"functie\":\"Manager\",\"organisatie\":\"{ORGANIZATION_UUID}\"}' 'http://localhost/index.php/apps/openregister/api/objects/6/38'"

# Create contact person 2
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' -H 'Content-Type: application/json' -X POST -d '{\"voornaam\":\"Jane\",\"achternaam\":\"Smith\",\"email\":\"jane.smith@test.org\",\"functie\":\"Developer\",\"organisatie\":\"{ORGANIZATION_UUID}\"}' 'http://localhost/index.php/apps/openregister/api/objects/6/38'"
```

3. Verify users are created and active:
```bash
# Check organization users
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' 'http://localhost/index.php/apps/openregister/api/organisations' | grep '{ORGANIZATION_UUID}'"

# Check user status
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ user:list | grep -E "john.doe|jane.smith"
```

4. Deactivate the organization:
```bash
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' -H 'Content-Type: application/json' -X PUT -d '{\"naam\":\"Test Org with Users\",\"website\":\"https://test-users.org\",\"type\":\"Leverancier\",\"beoordeling\":\"inactief\"}' 'http://localhost/index.php/apps/openregister/api/objects/6/35/{ORGANIZATION_ID}'"
```

5. Verify SoftwareCatalog users are deactivated but admin users remain active:
```bash
# Check user status after deactivation
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ user:list | grep -E "john.doe|jane.smith|admin"

# Verify admin user still active
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ user:info admin
```

**Expected Results**:
- SoftwareCatalog users (john.doe, jane.smith) are deactivated
- Admin group users remain active and unaffected
- Organization status shows as "inactief"

#### 7.2 Organization Activation Test
1. Reactivate the organization:
```bash
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' -H 'Content-Type: application/json' -X PUT -d '{\"naam\":\"Test Org with Users\",\"website\":\"https://test-users.org\",\"type\":\"Leverancier\",\"beoordeling\":\"actief\"}' 'http://localhost/index.php/apps/openregister/api/objects/6/35/{ORGANIZATION_ID}'"
```

2. Verify SoftwareCatalog users are reactivated:
```bash
# Check user status after reactivation
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ user:list | grep -E "john.doe|jane.smith|admin"

# Verify all users are active
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ user:info john.doe
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ user:info jane.smith
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ user:info admin
```

**Expected Results**:
- SoftwareCatalog users (john.doe, jane.smith) are reactivated
- Admin group users remain active
- Organization status shows as "actief"

### 8. Contact Person User Management Test

**Objective**: Verify that contact persons are properly linked to organizations and their user accounts are managed correctly.

**Test Steps**:
1. Create organization:
```bash
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' -H 'Content-Type: application/json' -X POST -d '{\"naam\":\"Contact Person Test Org\",\"website\":\"https://contact-test.org\",\"type\":\"Gemeente\",\"beoordeling\":\"actief\"}' 'http://localhost/index.php/apps/openregister/api/objects/6/35'"
```

2. Create contact person with organization reference:
```bash
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' -H 'Content-Type: application/json' -X POST -d '{\"voornaam\":\"Contact\",\"achternaam\":\"Person\",\"email\":\"contact.person@test.org\",\"telefoon\":\"+31 123 456 789\",\"functie\":\"Beheerder\",\"organisatie\":\"{ORGANIZATION_UUID}\"}' 'http://localhost/index.php/apps/openregister/api/objects/6/38'"
```

3. Verify contact person is processed and user created:
```bash
# Check contact person object
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' 'http://localhost/index.php/apps/openregister/api/objects/6/38?_limit=800' | grep 'contact.person@test.org'"

# Check user account created
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ user:list | grep contact.person

# Check organization users list
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' 'http://localhost/index.php/apps/openregister/api/organisations' | grep '{ORGANIZATION_UUID}'"
```

**Expected Results**:
- Contact person object created successfully
- User account created for contact person
- Username added to organization's users list
- User is active (matching organization status)

### 9. Admin Group User Protection Test

**Objective**: Verify that admin group users are never affected by organization status changes.

**Test Steps**:
1. Create organization with admin users already present:
```bash
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' -H 'Content-Type: application/json' -X POST -d '{\"naam\":\"Admin Protection Test Org\",\"website\":\"https://admin-protection.org\",\"type\":\"Leverancier\",\"beoordeling\":\"actief\"}' 'http://localhost/index.php/apps/openregister/api/objects/6/35'"
```

2. Add additional admin users to admin group:
```bash
# Create test admin user
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ user:add testadmin --password-from-env
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ group:adduser admin testadmin
```

3. Create contact persons for the organization:
```bash
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' -H 'Content-Type: application/json' -X POST -d '{\"voornaam\":\"Regular\",\"achternaam\":\"User\",\"email\":\"regular.user@test.org\",\"functie\":\"User\",\"organisatie\":\"{ORGANIZATION_UUID}\"}' 'http://localhost/index.php/apps/openregister/api/objects/6/38'"
```

4. Deactivate organization:
```bash
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' -H 'Content-Type: application/json' -X PUT -d '{\"naam\":\"Admin Protection Test Org\",\"website\":\"https://admin-protection.org\",\"type\":\"Leverancier\",\"beoordeling\":\"inactief\"}' 'http://localhost/index.php/apps/openregister/api/objects/6/35/{ORGANIZATION_ID}'"
```

5. Verify admin users remain active:
```bash
# Check admin users status
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ user:info admin
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ user:info testadmin

# Check regular user status
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ user:info regular.user
```

**Expected Results**:
- Admin users (admin, testadmin) remain active
- Regular SoftwareCatalog user (regular.user) is deactivated
- Admin group users are completely protected from organization status changes

### 10. Bulk User Management Test

**Objective**: Verify that bulk operations on organizations with multiple users work correctly.

**Test Steps**:
1. Create organization with multiple contact persons:
```bash
# Create organization
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' -H 'Content-Type: application/json' -X POST -d '{\"naam\":\"Bulk User Test Org\",\"website\":\"https://bulk-users.org\",\"type\":\"Gemeente\",\"beoordeling\":\"actief\"}' 'http://localhost/index.php/apps/openregister/api/objects/6/35'"

# Create multiple contact persons
for i in {1..5}; do
  docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' -H 'Content-Type: application/json' -X POST -d '{\"voornaam\":\"User$i\",\"achternaam\":\"Test\",\"email\":\"user$i@test.org\",\"functie\":\"User$i\",\"organisatie\":\"{ORGANIZATION_UUID}\"}' 'http://localhost/index.php/apps/openregister/api/objects/6/38'"
done
```

2. Verify all users are created and active:
```bash
# Check all users are active
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ user:list | grep -E "user[1-5]"
```

3. Deactivate organization and verify bulk deactivation:
```bash
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' -H 'Content-Type: application/json' -X PUT -d '{\"naam\":\"Bulk User Test Org\",\"website\":\"https://bulk-users.org\",\"type\":\"Gemeente\",\"beoordeling\":\"inactief\"}' 'http://localhost/index.php/apps/openregister/api/objects/6/35/{ORGANIZATION_ID}'"

# Check all users are deactivated
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ user:list | grep -E "user[1-5]"
```

4. Reactivate organization and verify bulk activation:
```bash
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' -H 'Content-Type: application/json' -X PUT -d '{\"naam\":\"Bulk User Test Org\",\"website\":\"https://bulk-users.org\",\"type\":\"Gemeente\",\"beoordeling\":\"actief\"}' 'http://localhost/index.php/apps/openregister/api/objects/6/35/{ORGANIZATION_ID}'"

# Check all users are reactivated
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ user:list | grep -E "user[1-5]"
```

**Expected Results**:
- All 5 users created successfully
- All users deactivated when organization deactivated
- All users reactivated when organization reactivated
- Admin users remain unaffected throughout the process

## Debugging and Troubleshooting

### Check Event Logs
```bash
docker logs master-nextcloud-1 --since 10m | grep -E "\[SoftwareCatalog\]|\[ObjectCreatedEvent\]|\[ObjectUpdatedEvent\]|\[ObjectDeletedEvent\]"
```

### Check App Status
```bash
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ app:list | grep softwarecatalog
```

### Enable App if Needed
```bash
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ app:enable softwarecatalog
```

### Check Nextcloud Logs
```bash
docker exec -u 33 master-nextcloud-1 tail -f /var/www/html/data/nextcloud.log
```

## Test Data Management

### Cleanup Test Data
After testing, clean up test organizations:
```bash
# Find test organizations
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' 'http://localhost/index.php/apps/openregister/api/objects/6/35?_limit=800' | grep -o '\"id\":\"[^\"]*\"' | grep -E 'Test|test'"

# Delete test organizations (replace {ID} with actual IDs)
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' -X DELETE 'http://localhost/index.php/apps/openregister/api/objects/6/35/{ID}'"
```

## Performance Testing

### Bulk Organization Creation
Test creating multiple organizations to verify performance:
```bash
for i in {1..10}; do
  docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' -H 'Content-Type: application/json' -X POST -d '{\"naam\":\"Bulk Test Org $i\",\"status\":\"actief\"}' 'http://localhost/index.php/apps/openregister/api/objects/6/35'"
done
```

## Security Testing

### Authentication Tests
- Test API calls without authentication
- Test with invalid credentials
- Test with different user roles

### Authorization Tests
- Test organization access permissions
- Test user management permissions
- Test synchronization permissions

## Integration Testing

### End-to-End Workflow
1. Create organization in Software Catalog
2. Add contact persons to organization
3. Verify synchronization to OpenRegister
4. Update organization status
5. Verify user status changes
6. Delete organization
7. Verify cleanup

## Monitoring and Metrics

### Key Metrics to Monitor
- Synchronization success rate
- Processing time for organization operations
- Error rates and types
- User activation/deactivation success rate

### Health Checks
```bash
# Check Software Catalog health
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' 'http://localhost/index.php/apps/softwarecatalog/api/settings'"

# Check OpenRegister health
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' 'http://localhost/index.php/apps/openregister/api/registers'"
```

## Conclusion

This testing guide provides a comprehensive framework for validating the organization synchronization functionality. Regular testing ensures that the integration between Software Catalog and OpenRegister remains reliable and performs as expected.

For additional testing scenarios or troubleshooting, refer to the main documentation in `docs/ORGANIZATION_SYNC_USECASES.md`. 