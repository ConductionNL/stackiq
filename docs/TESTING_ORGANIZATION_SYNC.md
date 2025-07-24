# Organization Synchronization Testing Guide

**Date:** July 24, 2025  
**App:** SoftwareCatalog  
**Feature:** Organization Synchronization with OpenRegister

## 🚨 ESSENTIAL INFORMATION FOR NEW CONVERSATION

### Current Status
- ✅ **Anonymous user registration fix implemented**: Modified `createOrganisationInOpenRegister()` to handle no-user context
- ✅ **Ownership assignment implemented**: Added `handleOwnershipAssignment()` method
- ✅ **Nested contactpersoon testing documented**: Added test scenarios for nested objects
- 🔄 **Testing in progress**: Anonymous user registration via OpenConnector needs verification

### Critical API Endpoints

#### OpenRegister API (Authenticated)
```bash
# Create organization object
curl -u 'admin:admin' -H 'Content-Type: application/json' -X POST \
  'http://localhost/index.php/apps/openregister/api/objects/6/35' \
  -d '{"naam":"Test Org","website":"https://test.org","type":"Leverancier","beoordeling":"actief"}'

# Update organization object
curl -u 'admin:admin' -H 'Content-Type: application/json' -X PUT \
  'http://localhost/index.php/apps/openregister/api/objects/6/35/{UUID}' \
  -d '{"naam":"Updated Org","beoordeling":"inactief"}'

# Get organization object
curl -u 'admin:admin' 'http://localhost/index.php/apps/openregister/api/objects/6/35/{UUID}'

# Get organization entity
curl -u 'admin:admin' 'http://localhost/index.php/apps/openregister/api/organisations/{UUID}'
```

#### OpenConnector API (Anonymous)
```bash
# Anonymous user registration (MAIN TEST SCENARIO)
curl -X POST 'http://nextcloud.local/index.php/apps/openconnector/api/endpoint/register' \
  -H 'Content-Type: application/json' \
  -d '{
    "naam": "Anonymous Test Org",
    "website": "https://anonymous-test.org",
    "type": "Gemeente",
    "beoordeling": "actief",
    "contactpersonen": [
      {
        "voornaam": "Anonymous",
        "achternaam": "Contact1",
        "email": "anonymous.contact1@test.org",
        "telefoon": "+31 555 555 555",
        "functie": "Manager"
      }
    ]
  }'
```

### Essential Commands

#### Docker Container Access
```bash
# Access Nextcloud container
cd /home/rubenlinde/nextcloud-docker-dev
docker-compose exec nextcloud bash

# Run commands as www-data user (required for file operations)
docker-compose exec -u 33 nextcloud bash
```

#### User Management
```bash
# List all users
docker-compose exec -u 33 nextcloud php /var/www/html/occ user:list

# Get user details
docker-compose exec -u 33 nextcloud php /var/www/html/occ user:info {username}

# Check user status (enabled/disabled)
docker-compose exec -u 33 nextcloud php /var/www/html/occ user:info {username} | grep enabled
```

#### Configuration
```bash
# Check SoftwareCatalog configuration
docker-compose exec -u 33 nextcloud php /var/www/html/occ config:app:get softwarecatalog voorzieningen_organisatie_schema
docker-compose exec -u 33 nextcloud php /var/www/html/occ config:app:get softwarecatalog voorzieningen_contactpersoon_schema
docker-compose exec -u 33 nextcloud php /var/www/html/occ config:app:get softwarecatalog voorzieningen_register
```

### Log Reading

#### Real-time Log Monitoring
```bash
# Follow logs in real-time
docker-compose exec nextcloud tail -f /var/www/html/data/nextcloud.log

# Filter for SoftwareCatalog events
docker-compose exec nextcloud tail -f /var/www/html/data/nextcloud.log | grep -i "softwarecatalog"

# Filter for specific organization UUID
docker-compose exec nextcloud tail -f /var/www/html/data/nextcloud.log | grep "{UUID}"

# Filter for user activation/deactivation
docker-compose exec nextcloud tail -f /var/www/html/data/nextcloud.log | grep -E "becameActive|becameInactive|activate|deactivate"
```

#### Log Analysis
```bash
# Get last 50 log entries
docker-compose exec nextcloud tail -n 50 /var/www/html/data/nextcloud.log

# Search for specific error
docker-compose exec nextcloud grep -i "no user logged in" /var/www/html/data/nextcloud.log

# Search for organization sync events
docker-compose exec nextcloud grep -i "sync.*organization" /var/www/html/data/nextcloud.log
```

### Schema Configuration
- **Register ID**: 6 (Voorzieningen)
- **Organisatie Schema ID**: 35
- **Contactpersoon Schema ID**: 34
- **Gebruiker Schema ID**: 42

### Key Architecture Concepts

#### OpenRegister Objects vs Entities
- **Objects**: Abstract data structures managed by schemas (e.g., `organisatie` object at register 6, schema 35)
- **Entities**: Classic Nextcloud entities (e.g., `organisation` entity, `user` entity)
- **Flow**: Anonymous user creates `organisatie` object → System creates `organisation` entity and `user` entity → New user becomes owner

#### Event Flow
1. Object creation triggers `ObjectCreatedEvent`
2. Event listener calls `handleNewOrganization()`
3. `syncOrganizationWithOpenRegister()` creates organization entity
4. `processOrganization()` creates user accounts
5. `handleOwnershipAssignment()` transfers ownership to new users

### Current Test Scenarios

#### 1. Anonymous User Registration (PRIORITY)
- **Status**: ✅ Code implemented, 🔄 Testing needed
- **Endpoint**: `POST /apps/openconnector/api/endpoint/register`
- **Expected**: Organization created, users created, ownership assigned
- **Previous Error**: "No user logged in" - ✅ FIXED

#### 2. Nested Contactpersoon Objects
- **Status**: ✅ Documented, 🔄 Testing needed
- **Endpoint**: `POST /apps/openregister/api/objects/6/35` with nested contactpersonen
- **Expected**: Contact persons processed, users created

#### 3. User Status Management
- **Status**: ✅ Implemented, 🔄 Testing needed
- **Test**: Change organization `beoordeling` from `actief` to `inactief`
- **Expected**: Only SoftwareCatalog users deactivated, admin users protected

### Known Issues and Solutions

#### 1. "No user logged in" Error
- **Problem**: `OrganisationService->createOrganisation()` requires user context
- **Solution**: ✅ Modified `createOrganisationInOpenRegister()` to detect anonymous context and use mapper directly
- **Status**: ✅ FIXED

#### 2. Ownership Assignment
- **Problem**: Anonymous users need ownership transferred after creation
- **Solution**: ✅ Added `handleOwnershipAssignment()` method
- **Status**: ✅ IMPLEMENTED

#### 3. Organization References
- **Problem**: Objects need proper organization entity references
- **Solution**: ✅ Ownership assignment sets `organisation` field on all objects
- **Status**: ✅ IMPLEMENTED

### Next Steps for New Conversation

1. **Test Anonymous User Registration**: Use OpenConnector endpoint with Postman/curl
2. **Verify User Creation**: Check if contact person users are created
3. **Verify Ownership Assignment**: Check object ownership and organization references
4. **Test User Status Changes**: Activate/deactivate organization and verify user status
5. **Test Nested Contact Persons**: Create organization with nested contactpersonen array

### Debugging Commands

```bash
# Check if users were created
docker-compose exec -u 33 nextcloud php /var/www/html/occ user:list | grep -E "anonymous|test"

# Check organization entity
curl -u 'admin:admin' 'http://localhost/index.php/apps/openregister/api/organisations/{UUID}'

# Check object ownership
curl -u 'admin:admin' 'http://localhost/index.php/apps/openregister/api/objects/6/35/{UUID}' | jq '.@self.owner'

# Monitor logs during test
docker-compose exec nextcloud tail -f /var/www/html/data/nextcloud.log | grep -E "ownership|assignment|anonymous"
```

### File Locations
- **Main Service**: `/var/www/html/apps-extra/softwarecatalog/lib/Service/SoftwareCatalogueService.php`
- **Event Listener**: `/var/www/html/apps-extra/softwarecatalog/lib/EventListener/SoftwareCatalogEventListener.php`
- **Logs**: `/var/www/html/data/nextcloud.log`
- **Configuration**: `/var/www/html/config/config.php`

---

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

### 11. Nested Contactpersoon Objects Test

**Objective**: Verify that contact persons can be created as nested objects within organizations and are properly processed for user management.

**Test Steps**:

#### 11.1 Create Organization with Nested Contact Persons
1. Create an organization with nested contact persons:
```bash
# Create organization with nested contact persons
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' -H 'Content-Type: application/json' -X POST -d '{
  \"naam\": \"Nested Contact Test Org\",
  \"website\": \"https://nested-contact-test.org\",
  \"type\": \"Gemeente\",
  \"beoordeling\": \"actief\",
  \"contactpersonen\": [
    {
      \"voornaam\": \"Nested\",
      \"achternaam\": \"Contact1\",
      \"email\": \"nested.contact1@test.org\",
      \"telefoon\": \"+31 111 111 111\",
      \"functie\": \"Manager\"
    },
    {
      \"voornaam\": \"Nested\",
      \"achternaam\": \"Contact2\",
      \"email\": \"nested.contact2@test.org\",
      \"telefoon\": \"+31 222 222 222\",
      \"functie\": \"Developer\"
    }
  ]
}' 'http://localhost/index.php/apps/openregister/api/objects/6/35'"
```

2. Verify the organization was created with nested contact persons:
```bash
# Check organization structure
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' 'http://localhost/index.php/apps/openregister/api/objects/6/35/{ORGANIZATION_ID}'"
```

3. Verify contact persons were processed and users created:
```bash
# Check if users were created
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ user:list | grep -E "nested.contact1|nested.contact2"

# Check user status
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ user:info nested.contact1@test.org
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ user:info nested.contact2@test.org
```

**Expected Results**:
- Organization created successfully with nested contact persons
- Contact persons are properly linked to the organization
- User accounts are created for each contact person
- Users are active (matching organization status)

#### 11.2 Test User Status Changes with Nested Contact Persons
1. Deactivate the organization:
```bash
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' -H 'Content-Type: application/json' -X PUT -d '{
  \"naam\": \"Nested Contact Test Org\",
  \"website\": \"https://nested-contact-test.org\",
  \"type\": \"Gemeente\",
  \"beoordeling\": \"inactief\"
}' 'http://localhost/index.php/apps/openregister/api/objects/6/35/{ORGANIZATION_ID}'"
```

2. Verify nested contact person users are deactivated:
```bash
# Check user status after deactivation
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ user:info nested.contact1@test.org | grep enabled
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ user:info nested.contact2@test.org | grep enabled
```

3. Reactivate the organization:
```bash
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' -H 'Content-Type: application/json' -X PUT -d '{
  \"naam\": \"Nested Contact Test Org\",
  \"website\": \"https://nested-contact-test.org\",
  \"type\": \"Gemeente\",
  \"beoordeling\": \"actief\"
}' 'http://localhost/index.php/apps/openregister/api/objects/6/35/{ORGANIZATION_ID}'"
```

4. Verify nested contact person users are reactivated:
```bash
# Check user status after reactivation
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ user:info nested.contact1@test.org | grep enabled
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ user:info nested.contact2@test.org | grep enabled
```

**Expected Results**:
- Nested contact person users are deactivated when organization becomes inactive
- Nested contact person users are reactivated when organization becomes active
- Admin users remain unaffected throughout the process

#### 11.3 Test Mixed Contact Person Creation Methods
1. Create organization with some nested contact persons:
```bash
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' -H 'Content-Type: application/json' -X POST -d '{
  \"naam\": \"Mixed Contact Test Org\",
  \"website\": \"https://mixed-contact-test.org\",
  \"type\": \"Leverancier\",
  \"beoordeling\": \"actief\",
  \"contactpersonen\": [
    {
      \"voornaam\": \"Mixed\",
      \"achternaam\": \"Contact1\",
      \"email\": \"mixed.contact1@test.org\",
      \"telefoon\": \"+31 333 333 333\",
      \"functie\": \"Manager\"
    }
  ]
}' 'http://localhost/index.php/apps/openregister/api/objects/6/35'"
```

2. Add additional contact person via separate API call:
```bash
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' -H 'Content-Type: application/json' -X POST -d '{
  \"voornaam\": \"Mixed\",
  \"achternaam\": \"Contact2\",
  \"email\": \"mixed.contact2@test.org\",
  \"telefoon\": \"+31 444 444 444\",
  \"functie\": \"Developer\",
  \"organisatie\": \"{ORGANIZATION_UUID}\"
}' 'http://localhost/index.php/apps/openregister/api/objects/6/34'"
```

3. Verify all contact persons are properly managed:
```bash
# Check all users are created
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ user:list | grep -E "mixed.contact1|mixed.contact2"

# Test organization status changes affect all users
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' -H 'Content-Type: application/json' -X PUT -d '{
  \"beoordeling\": \"inactief\"
}' 'http://localhost/index.php/apps/openregister/api/objects/6/35/{ORGANIZATION_ID}'"

# Verify all users are deactivated
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ user:info mixed.contact1@test.org | grep enabled
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ user:info mixed.contact2@test.org | grep enabled
```

**Expected Results**:
- Both nested and separately created contact persons are properly managed
- All contact person users respond to organization status changes
- User management works consistently regardless of creation method

### 12. Anonymous User Registration via OpenConnector

**Objective**: Test organization registration by anonymous users through OpenConnector, ensuring proper ownership assignment and entity creation.

**Background - OpenRegister Objects vs Entities**:
- **OpenRegister Objects**: Abstract data structures managed by schemas (e.g., `organisatie` object at register 6, schema 35)
- **OpenRegister Entities**: Classic Nextcloud entities (e.g., `organisation` entity, `user` entity)
- **Flow**: Anonymous user creates `organisatie` object → System creates `organisation` entity and `user` entity → New user becomes owner of both objects

**Test Steps**:

#### 12.1 Anonymous User Registration via OpenConnector
1. Register organization as anonymous user through OpenConnector:

**Postman Configuration:**
- **Method**: POST
- **URL**: `http://nextcloud.local/index.php/apps/openconnector/api/endpoint/register`
- **Headers**: 
  - `Content-Type: application/json`
- **Body** (raw JSON):
```json
{
  "naam": "Anonymous Test Org",
  "website": "https://anonymous-test.org",
  "type": "Gemeente",
  "beoordeling": "actief",
  "contactpersonen": [
    {
      "voornaam": "Anonymous",
      "achternaam": "Contact1",
      "email": "anonymous.contact1@test.org",
      "telefoon": "+31 555 555 555",
      "functie": "Manager"
    },
    {
      "voornaam": "Anonymous",
      "achternaam": "Contact2",
      "email": "anonymous.contact2@test.org",
      "telefoon": "+31 666 666 666",
      "functie": "Developer"
    }
  ]
}
```

**cURL Command:**
```bash
curl -X POST "http://nextcloud.local/index.php/apps/openconnector/api/endpoint/register" \
  -H "Content-Type: application/json" \
  -d '{
    "naam": "Anonymous Test Org",
    "website": "https://anonymous-test.org",
    "type": "Gemeente",
    "beoordeling": "actief",
    "contactpersonen": [
      {
        "voornaam": "Anonymous",
        "achternaam": "Contact1",
        "email": "anonymous.contact1@test.org",
        "telefoon": "+31 555 555 555",
        "functie": "Manager"
      },
      {
        "voornaam": "Anonymous",
        "achternaam": "Contact2",
        "email": "anonymous.contact2@test.org",
        "telefoon": "+31 666 666 666",
        "functie": "Developer"
      }
    ]
  }'
```

2. Verify the organization object was created:
```bash
# Check organization object (replace with actual UUID from response)
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' 'http://localhost/index.php/apps/openregister/api/objects/6/35/{ORGANIZATION_UUID}'"
```

3. Verify the organization entity was created:
```bash
# Check organization entity
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' 'http://localhost/index.php/apps/openregister/api/organisations/{ORGANIZATION_UUID}'"
```

4. Verify user accounts were created:
```bash
# Check if users were created
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ user:list | grep -E "anonymous.contact1|anonymous.contact2"

# Check user status
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ user:info anonymous.contact1@test.org
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ user:info anonymous.contact2@test.org
```

**Expected Results**:
- Organization object created successfully via OpenConnector
- Organization entity created in OpenRegister
- User accounts created for contact persons
- Primary contact person user becomes owner of organization object
- Organization entity is set as organization on both objects

**Note**: This test should now work correctly. The system has been updated to handle anonymous user creation by:
1. Creating the organization entity directly via mapper (bypassing user context requirements)
2. Creating user accounts for contact persons
3. Assigning ownership of objects to the newly created users
4. Setting proper organization references on all objects

#### 12.2 Verify Ownership Assignment
1. Check organization object ownership:
```bash
# Verify the newly created user is the owner
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' 'http://localhost/index.php/apps/openregister/api/objects/6/35/{ORGANIZATION_UUID}' | jq '.@self.owner'"
```

2. Check contact person object ownership:
```bash
# Verify contact person objects have correct ownership and organization
docker exec -it -u 33 master-nextcloud-1 bash -c "curl -u 'admin:admin' 'http://localhost/index.php/apps/openregister/api/objects/6/34/{CONTACT_UUID}' | jq '.@self.owner, .organisatie'"
```

**Expected Results**:
- Organization object owner is the newly created user (not admin)
- Contact person objects owner is the newly created user
- Contact person objects have organization field set to organization entity UUID

#### 12.3 Test User Login and Access
1. Test user login with newly created credentials:
```bash
# Test login (this would be done via web interface)
# The user should be able to log in and access their organization
```

2. Verify user can access their organization:
```bash
# Check user permissions and access
docker exec -u 33 master-nextcloud-1 php /var/www/html/occ user:info anonymous.contact1@test.org
```

**Expected Results**:
- User can log in successfully
- User has appropriate permissions for their organization
- User can access and modify their organization data

### 13. OpenRegister Objects vs Entities - Technical Details

**OpenRegister Objects**:
- Managed by schemas and registers
- Stored in `oc_openregister_objects` table
- Have UUID, owner, organization fields
- Examples: `organisatie` (schema 35), `contactpersoon` (schema 34)
- Created via `/apps/openregister/api/objects/{register}/{schema}`

**OpenRegister Entities**:
- Classic Nextcloud entities
- Stored in dedicated tables (e.g., `oc_openregister_organisations`)
- Have ID, UUID, name, status fields
- Examples: `organisation` entity, `user` entity
- Created via `/apps/openregister/api/organisations`

**Conversion Flow**:
1. Anonymous user creates `organisatie` object via OpenConnector
2. Event listener triggers organization sync
3. System creates `organisation` entity
4. System creates `user` entity for primary contact
5. System updates object ownership and organization references
6. User becomes owner of their objects

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