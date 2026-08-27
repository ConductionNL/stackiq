# Bug Fix: Users Created from Contactpersonen Added to Wrong Organisation

## Problem Description

When a new organisation was created through the API and subsequently activated, users created from contactpersonen associated with that organisation were being added to the 'Default Organisation' instead of the organisation specified in the contactpersoon data.

### Root Cause

The issue had two main causes:

1. **Missing Organisation Entity**: When an organisation object was created in the voorzieningen register (via OpenRegister objects), a corresponding organisation **entity** was not automatically created in OpenRegister's `oc_openregister_organisations` database table.

2. **Active Organisation Context**: When a user was created from a contactpersoon, the system would try to add the user to an organisation entity, but if that entity didn't exist, the user would end up in the logged-in admin's organisation (usually the Default Organisation).

### Example Scenario

1. Admin creates a new organisation 'test93' via POST to `/api/apps/openconnector/api/endpoint/register`
2. Organisation object is created in voorzieningen register with UUID `a7604b7e-4b8c-46d5-870b-0018ddd5890a`
3. Organisation includes a contactpersoon with email `test92@test.nl`
4. Admin activates the organisation by changing status to 'Actief'
5. User is created from contactpersoon
6. **BUG**: User ends up in 'Default Organisation' instead of 'test93'

## Solution

The fix adds logic to ensure that:

1. When adding a user to an organisation entity, the system first checks if the organisation entity exists
2. If the entity doesn't exist, it is created from the organisation object data
3. The user is then added to the correct organisation entity
4. The user's active organisation is set to the contactpersoon's organisation

### Code Changes

**File**: `stackiq/lib/Service/Stackique/ContactPersonHandler.php`

#### 1. Updated `addUserToOrganizationEntity()` Method

- Added check for organisation entity existence
- If entity doesn't exist, calls new `ensureOrganizationEntity()` method
- Improved error handling and logging

#### 2. New `ensureOrganizationEntity()` Method

This new private method:
- Fetches the organisation object from OpenRegister by UUID
- Extracts organisation name and description
- Creates the organisation entity using `OrganisationService->createOrganisationWithUuid()`
- Ensures the UUID matches between object and entity
- Returns the created organisation entity

#### 3. Updated `storeUserOrganizationUuid()` Method

- Now also sets the user's active organisation in OpenRegister config
- This ensures users are automatically logged into the correct organisation
- Stores both in 'core' namespace (for OpenConnector) and 'openregister' namespace (for active org)

## Testing

### Prerequisites

1. Access to a development environment with:
   - Nextcloud with OpenRegister and Stackiq apps installed
   - Docker containers running (nextcloud and database)
   - Admin access to Nextcloud

### Test Steps

#### Step 1: Create a New Organisation via API

```bash
# Create a new organisation with a contactpersoon
curl -X POST \
  'http://nextcloud.local/index.php/apps/openconnector/api/endpoint/register' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Basic YWRtaW46YWRtaW4=' \
  -d '{
    "naam": "Test Organisation XYZ",
    "website": "www.testxyz.nl",
    "type": "Leverancier",
    "status": "Concept",
    "contactpersonen": [
      {
        "voornaam": "Test",
        "achternaam": "User",
        "e-mailadres": "testuser@testxyz.nl",
        "telefoonnummer": "0612345678"
      }
    ]
  }'
```

Save the organisation UUID from the response (in `@self.id` or `id` field).

#### Step 2: Verify Organisation Object Created

```bash
# List organisations in concept status
curl -X GET \
  'http://nextcloud.local/index.php/apps/openregister/api/objects/1/7?status=Concept&_extend[]=@self.schema&_extend[]=contactpersonen' \
  -H 'Authorization: Basic YWRtaW46YWRtaW4='
```

Verify the organisation appears with its contactpersoon.

#### Step 3: Activate the Organisation

```bash
# Update organisation status to Actief
curl -X PUT \
  'http://nextcloud.local/index.php/apps/openregister/api/objects/1/7/<ORGANISATION_UUID>' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Basic YWRtaW46YWRtaW4=' \
  -d '{
    "status": "Actief"
  }'
```

#### Step 4: Verify User Created in Correct Organisation

##### Check Nextcloud User Settings

1. Login to Nextcloud as admin
2. Go to Users settings
3. Find the newly created user (testuser@testxyz.nl)
4. Verify the user exists

##### Check User's Organisation in Database

```bash
# Check user's organisation in database
docker exec -it <database-container> mysql -u nextcloud -pnextcloud nextcloud -e \
  "SELECT u.uid, o.uuid, o.name 
   FROM oc_openregister_organisations o
   JOIN JSON_TABLE(o.users, '$[*]' COLUMNS (user_id VARCHAR(255) PATH '$')) ut
   ON FIND_IN_SET(ut.user_id, REPLACE(REPLACE(o.users, '[', ''), ']', ''))
   WHERE ut.user_id = 'testuser@testxyz.nl';"
```

**Expected Result**: User should be in 'Test Organisation XYZ', not 'Default Organisation'

##### Check User's Active Organisation Config

```bash
# Check user config
docker exec -u 33 <nextcloud-container> php occ config:user:get testuser@testxyz.nl openregister active_organisation
```

**Expected Result**: Should return the UUID of 'Test Organisation XYZ'

##### Check User Can Access Correct Organisation

1. Login as the newly created user
2. Go to OpenRegister app
3. Check which organisation is active (should be 'Test Organisation XYZ')
4. Verify user can only see data from their organisation

#### Step 5: Check Logs for Confirmation

```bash
# Check container logs for organisation entity creation
docker logs <nextcloud-container> | grep "ContactPersonHandler: Creating organization entity"

# Should see logs like:
# ContactPersonHandler: Organization entity not found, creating it
# ContactPersonHandler: Creating organization entity from object data
# ContactPersonHandler: Successfully created organization entity
# ContactPersonHandler: Successfully added user to organization entity
```

### Expected Behavior After Fix

1. **Organisation Entity Created**: When user is created, if organisation entity doesn't exist, it is automatically created
2. **Correct Organisation Assignment**: User is added to the contactpersoon's organisation, not admin's organisation
3. **Active Organisation Set**: User's active organisation is set to the correct organisation
4. **User Login**: When user logs in, they are automatically in the correct organisation context

### Rollback

If issues occur, the changes can be reverted by restoring the previous version of:
- `stackiq/lib/Service/Stackique/ContactPersonHandler.php`

## Additional Notes

### Impact

- **Positive**: Users are now correctly assigned to their organisations
- **Performance**: Minimal impact; organisation entity creation only happens once per organisation
- **Backward Compatibility**: Existing users are not affected; only new user creation is changed

### Related Files

- `stackiq/lib/Service/Stackique/ContactPersonHandler.php` - Main fix
- `openregister/lib/Service/OrganisationService.php` - Used for entity creation
- `openregister/lib/Db/OrganisationMapper.php` - Database operations

### Future Improvements

1. Consider automatically creating organisation entities when organisation objects are created
2. Add migration script to create missing organisation entities for existing organisations
3. Add validation to prevent organisations without entities

## Author

- Date: 2025-10-14
- Version: 1.0.0

## References

- Issue: Users created from contactpersonen added to wrong organisation
- Related: OpenRegister multi-tenancy system
- Related: Stackiq organisation management

