# Anonymous User Registration Use Case

**Date:** July 24, 2025  
**App:** SoftwareCatalog  
**Feature:** Anonymous User Registration with Nested Contact Persons

## Overview

This use case describes the process where an anonymous user (without existing Nextcloud account) registers a new organization through the OpenConnector API, including nested contact persons. The system should automatically create the necessary entities and user accounts.

## Use Case Details

### Actor
- **Primary Actor:** Anonymous user (no existing Nextcloud account)
- **Secondary Actor:** System (SoftwareCatalog + OpenRegister)

### Preconditions
- OpenConnector app is enabled and accessible
- SoftwareCatalog app is enabled and configured
- OpenRegister app is enabled and configured
- Valid organization and contact person schemas are configured

### Main Success Scenario

#### 1. Anonymous User Submits Registration
**Actor Action:** Anonymous user submits organization registration via OpenConnector API

**System Response:** 
- Accepts the registration request
- Creates organization object with UUID (e.g., `71658220-8409-4139-b383-b521e637a493`)
- Returns success response with organization UUID

**Expected Data:**
```json
{
  "naam": "Test Organization",
  "website": "https://test.org",
  "type": "Gemeente",
  "beoordeling": "actief",
  "contactpersonen": [
    {
      "voornaam": "Primary",
      "achternaam": "Contact",
      "email": "primary.contact@test.org",
      "telefoon": "+31 555 555 558",
      "functie": "Manager"
    }
  ]
}
```

#### 2. Organization Object Creation
**System Action:** Creates organization object in OpenRegister

**Expected Result:**
- Organization object created in register 6, schema 35
- UUID preserved from original request
- Object initially owned by admin (temporary)
- Contact persons processed and individual objects created

**Verification:**
```bash
curl -u 'admin:admin' 'http://localhost/index.php/apps/openregister/api/objects/6/35/{UUID}'
```

#### 3. Organization Entity Creation
**System Action:** Creates organization entity in OpenRegister

**Expected Result:**
- Organization entity created with SAME UUID as organization object
- Entity initially has no users (will be populated later)
- Entity is visible in OpenRegister organizations list

**Critical Requirement:** Organization entity UUID MUST match organization object UUID

**Verification:**
```bash
curl -u 'admin:admin' 'http://localhost/index.php/apps/openregister/api/organisations/{UUID}'
```

#### 4. User Account Creation
**System Action:** Creates user accounts for contact persons

**Expected Result:**
- User account created for each contact person
- Username generated from email address
- User initially inactive (matching organization status)
- User added to appropriate groups based on roles

**Verification:**
```bash
docker-compose exec -u 33 nextcloud php /var/www/html/occ user:list | grep "primary.contact"
```

#### 5. User-Organization Entity Linking
**System Action:** Adds users to organization entity

**Expected Result:**
- All contact person users added to organization entity
- Organization entity now contains user list
- Organization becomes visible in user's organization list

**Verification:**
```bash
curl -u 'admin:admin' 'http://localhost/index.php/apps/openregister/api/organisations/{UUID}' | jq '.users'
```

#### 6. Ownership Assignment
**System Action:** Transfers ownership from admin to newly created users

**Expected Result:**
- Primary contact person becomes owner of organization object
- Each contact person becomes owner of their own contact person object
- Admin ownership removed from all objects

**Verification:**
```bash
curl -u 'admin:admin' 'http://localhost/index.php/apps/openregister/api/objects/6/35/{UUID}' | jq '.@self.owner'
```

#### 7. Organization Reference Assignment
**System Action:** Sets organization references on all objects

**Expected Result:**
- Organization object has `@self.organisation` field set to organization entity UUID
- Contact person objects have `@self.organisatie` field set to organization entity UUID
- All objects properly linked to organization entity

**Verification:**
```bash
curl -u 'admin:admin' 'http://localhost/index.php/apps/openregister/api/objects/6/34/{CONTACT_UUID}' | jq '.@self.organisatie'
```

## Alternative Flows

### A. Contact Person Processing Failure
**Trigger:** Contact person processing fails (e.g., invalid email, duplicate user)

**System Response:**
- Log error for failed contact person
- Continue processing other contact persons
- Organization and successfully processed contact persons still created

### B. Organization Entity Creation Failure
**Trigger:** Organization entity creation fails

**System Response:**
- Log error and continue with object creation
- Ownership assignment will fail gracefully
- Users still created but not linked to organization entity

### C. User Creation Failure
**Trigger:** User account creation fails

**System Response:**
- Log error for failed user
- Continue with organization and entity creation
- Ownership assignment will skip failed users

## Postconditions

### Success Postconditions
- Organization object exists with correct UUID and ownership
- Organization entity exists with SAME UUID and contains users
- User accounts exist for all contact persons
- All objects have correct ownership and organization references
- Users can log in and access their organization

### Failure Postconditions
- Partial creation may occur (some objects/users created)
- Error logs contain details of failures
- System remains in consistent state

## Data Consistency Requirements

### UUID Consistency
- **CRITICAL:** Organization object UUID = Organization entity UUID
- Contact person object UUIDs preserved during processing
- All references use correct UUIDs

### Ownership Consistency
- Only one user can be owner of an object at a time
- Ownership must be transferred from admin to actual users
- Contact persons own their own objects

### Organization Reference Consistency
- All objects in organization must reference the same organization entity
- Organization entity UUID must match organization object UUID
- References must be set after entity creation

## Error Handling

### Logging Requirements
- All major steps logged with UUIDs for tracking
- Error conditions logged with full context
- UUID mismatches logged as warnings
- Processing failures logged as errors

### Recovery Mechanisms
- Retry mechanism for timing-dependent operations
- Graceful degradation for partial failures
- Cleanup mechanisms for failed operations

## Testing Scenarios

### Happy Path
1. Anonymous user submits valid organization with contact persons
2. All steps complete successfully
3. Verify UUID consistency and ownership assignment

### Edge Cases
1. Organization with single contact person
2. Organization with multiple contact persons
3. Contact person with existing email address
4. Invalid contact person data
5. Network failures during processing

### Error Scenarios
1. Missing required fields
2. Invalid email addresses
3. Duplicate organization names
4. Database connection failures
5. Permission issues

## Monitoring and Debugging

### Key Metrics
- Registration success rate
- UUID consistency rate
- User creation success rate
- Ownership assignment success rate
- Processing time per registration

### Debug Commands
```bash
# Check organization object
curl -u 'admin:admin' 'http://localhost/index.php/apps/openregister/api/objects/6/35/{UUID}'

# Check organization entity
curl -u 'admin:admin' 'http://localhost/index.php/apps/openregister/api/organisations/{UUID}'

# Check user status
docker-compose exec -u 33 nextcloud php /var/www/html/occ user:info {username}

# Check logs
docker-compose exec nextcloud tail -f /var/www/html/data/nextcloud.log | grep -E "Stackiq|ownership|UUID"
```

## Implementation Notes

### Critical Implementation Points
1. **UUID Preservation:** Organization entity MUST use same UUID as organization object
2. **Timing:** User creation must complete before ownership assignment
3. **Error Handling:** Partial failures should not break the entire process
4. **Logging:** Comprehensive logging required for debugging
5. **Consistency:** All objects must have consistent references

### Performance Considerations
- Processing should complete within 10 seconds
- Database operations should be optimized
- Logging should not impact performance significantly

### Security Considerations
- Anonymous users should not have access to other organizations
- User passwords should be securely generated
- Organization data should be properly validated
- Access controls should be properly set

## Success Criteria

The use case is successful when:
1. ✅ Organization object created with correct UUID
2. ✅ Organization entity created with SAME UUID
3. ✅ User accounts created for all contact persons
4. ✅ Users added to organization entity
5. ✅ Ownership transferred to appropriate users
6. ✅ Organization references set on all objects
7. ✅ Users can log in and access their organization
8. ✅ No UUID mismatches in logs
9. ✅ All operations complete within acceptable time
10. ✅ System remains in consistent state 