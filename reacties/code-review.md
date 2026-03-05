# Code Review: Backend Bug-Fixing Session

Date: 2026-03-01
Reviewer: Claude (automated code review)
Scope: Changes across openregister (docs/rbac-rewrite), softwarecatalog (development), opencatalogi (feature/newopenregister)

---

## 1. PropertyRbacHandler.php - `authenticated` keyword

**File:** `openregister/lib/Service/PropertyRbacHandler.php`
**Methods:** `checkSimpleRule()` (line 354), `checkConditionalRule()` (line 376)

**FINDING: INCONSISTENCY / MISSING FEATURE**

The `authenticated` keyword is NOT supported in `PropertyRbacHandler.checkSimpleRule()`. It only handles:
- `'public'` -> grant access to everyone
- Group name -> `in_array($rule, $userGroups)`

However, `authenticated` IS supported in the **ExportService** (line 786):
```php
if ($rule === 'authenticated' && $isAuthenticated === true) {
    return true;
}
```

And `authenticated` is actively USED in `softwarecatalogus_register.json` as a property-level authorization rule:
```json
"contactpersonen": {
  "authorization": {
    "read": ["authenticated"]
  }
}
```

This means:
- **ExportService** correctly hides `contactpersonen`, `e-mailadres`, `inloggegevens` from anonymous users in CSV/Excel exports
- **PropertyRbacHandler** does NOT filter these properties in API responses -- `"authenticated"` is treated as a literal group name, which no user belongs to, so these properties are **incorrectly hidden from ALL users** (including logged-in ones)

**Also missing from:** `MagicRbacHandler.processSimpleRule()` (line 244) and `MagicRbacHandler.checkPermissionRule()` (line 596) -- same issue at schema level.

**Verdict:** CRITICAL BUG. The `authenticated` keyword needs to be added to `PropertyRbacHandler.checkSimpleRule()`, `PropertyRbacHandler.checkConditionalRule()` (for `$group === 'authenticated'`), `MagicRbacHandler.processSimpleRule()`, and `MagicRbacHandler.checkPermissionRule()`.

**Fix:** Add to each handler:
```php
// 'authenticated' grants access to any logged-in user.
if ($rule === 'authenticated' && $userId !== null) {
    return true;
}
```

---

## 2. QueryHandler.php - Serialized schema arrays

**File:** `openregister/lib/Service/Object/QueryHandler.php`
**Method:** `searchObjectsPaginated()` (line 454)

**FINDING: BUG CONFIRMED**

The code checks:
```php
foreach ($schemas as $schema) {
    if ($schema instanceof \OCA\OpenRegister\Db\Schema
        && $schema->hasPropertyAuthorization() === true
    ) {
```

But `$schemas` comes from `$searchResult['schemas']`, which is populated by `UnifiedObjectMapper` as:
```php
$schemasCache[$schema->getId()] = $schema->jsonSerialize();  // Returns ARRAY, not Schema
```

This happens in BOTH paths:
- `searchObjectsPaginatedMultiSchema()` (line 1227)
- `searchObjectsPaginated()` (line 1563)

So `instanceof Schema` always fails, and property-level authorization is never triggered for paginated search results.

**Verdict:** BUG. The `instanceof` check needs to handle serialized arrays. Either:
1. Store Schema objects in the cache instead of serialized arrays
2. OR check if the serialized array has property authorization (check `$schema['properties']` for `authorization` keys)

**Better pattern:** Option 2 is better since the schemas cache is meant for frontend consumption (serialized). Add a utility method:
```php
private function hasPropertyAuthorizationFromArray(array $schemaData): bool
{
    foreach ($schemaData['properties'] ?? [] as $prop) {
        if (isset($prop['authorization'])) {
            return true;
        }
    }
    return false;
}
```

---

## 3. ExportService.php - visible/RBAC filtering in getHeaders()

**File:** `openregister/lib/Service/ExportService.php`
**Method:** `getHeaders()` (line 404)

**FINDING: OK - NEW FUNCTIONALITY, CORRECTLY PLACED**

The original `getHeaders()` on `main` had NO filtering at all -- it included ALL schema properties as headers. The new version adds:
1. `hideOnCollection` check (line 434) -- matches frontend behavior
2. `visible !== false` check (line 440) -- matches frontend filter `value.visible !== false`
3. Property-level RBAC via `isPropertyReadableByUser()` (line 448)
4. Companion `_propertyName` columns for UUID-to-name resolution (later in the method)
5. `CacheHandler` for bulk UUID resolution

The `isPropertyReadableByUser()` implementation (line 714) is a standalone implementation that duplicates some logic from `PropertyRbacHandler` but is specialized for the export context (no object data available at header time, so conditional rules check group membership only). This is acceptable since export headers cannot use `PropertyRbacHandler` directly (it requires object data for conditional rules).

**However:** The `authenticated` keyword handling here is CORRECT (see finding #1) while `PropertyRbacHandler` is missing it. This inconsistency should be fixed.

**Verdict:** OK (new, necessary feature). But coordinate with finding #1 to ensure consistency.

---

## 4. FilePropertyHandler.php - `_fileOriginalNames` metadata

**File:** `openregister/lib/Service/Object/SaveObject/FilePropertyHandler.php`

**FINDING: NOT FOUND**

The `_fileOriginalNames` feature mentioned in the review request does NOT exist in the current codebase. No references to `_fileOriginalNames`, `fileOriginalName`, or `originalName` were found in any PHP file in the openregister repository, including git history.

This was either:
- Planned but never implemented
- Removed before the branch was finalized
- Confused with a different feature

**Verdict:** N/A -- nothing to review.

---

## 5. SaveObject.php - `_fileOriginalNames` cleanup

**File:** `openregister/lib/Service/Object/SaveObject.php`

**FINDING: NOT FOUND**

Same as #4 -- no `_fileOriginalNames` references exist anywhere in the codebase.

**Verdict:** N/A -- nothing to review.

---

## 6. softwarecatalogus_register.json - RBAC format

**File:** `softwarecatalog/lib/Settings/softwarecatalogus_register.json`

**FINDING: FORMAT CORRECT, BUT BACKEND SUPPORT INCOMPLETE**

The authorization rules use two formats, both of which are valid OpenRegister RBAC syntax:

**Simple rules (string):**
```json
"authorization": {
  "read": ["public"],
  "create": ["vng-raadpleger", "software-catalog-users"]
}
```

**Conditional rules (object):**
```json
"authorization": {
  "read": [{
    "group": "public",
    "match": { "_organisation": "$organisation" }
  }]
}
```

**Property-level authorization:**
```json
"contactpersonen": {
  "authorization": {
    "read": ["authenticated"]
  }
}
```

The schema-level authorization format is correct and matches what `MagicRbacHandler` and `PermissionHandler` expect. The property-level `"authenticated"` keyword is the issue described in finding #1.

The `rollen` property authorization:
```json
"rollen": {
  "authorization": {
    "update": ["gebruik-beheerder", "admin"]
  }
}
```
This is correctly formatted. Only `gebruik-beheerder` and `admin` group members can update the `rollen` field.

**Verdict:** FORMAT OK, but the `"authenticated"` keyword used in property-level auth rules requires the fix from finding #1.

---

## 7. OrganizationSyncService.php - ObjectEntityMapper::update() pattern

**File:** `softwarecatalog/lib/Service/OrganizationSyncService.php`

**FINDING: OK - CORRECT PATTERN FOR THIS USE CASE**

The change from `ObjectService::saveObject()` to `ObjectEntityMapper::update()` is well-documented (line 2153-2160):

```php
// FIX #434: Use ObjectEntityMapper::update() directly instead of
// ObjectService::saveObject() to persist the username. This avoids:
// 1. Having to strip the organisatie field (saveObject validates it
//    as object type but it is stored as a UUID string)
// 2. Triggering ObjectUpdatedEvent cascades that re-enter
//    processContactpersoon() with missing organisatie field
// 3. Potential data loss from stale variables
```

**Is there a better pattern?** `ObjectService::saveObject()` does NOT expose a `skipValidation` flag. The lower-level `SaveObject::saveObject()` has a `$validation` parameter but it's not exposed through the public API. So using the mapper directly is the correct workaround for admin-context sync operations that need to bypass validation.

**Consistency:** This pattern is used consistently throughout OrganizationSyncService (8 occurrences of `$objectMapper->update()`) and in ContactpersoonService.

**Risk:** Using the mapper directly skips:
- Validation
- Event dispatching (ObjectUpdatedEvent)
- Metadata hydration (_name regeneration)
- RBAC checks
- Audit trail creation

For sync operations, skipping events is actually desirable (prevents recursion). But skipping metadata hydration means `_name` stays stale. This is addressed in finding #10.

**Verdict:** OK for admin sync context. Consider adding a `skipValidation` flag to ObjectService for future use.

---

## 8. ContactpersoonService.php - Same mapper pattern + recursion guard

**File:** `softwarecatalog/lib/Service/ContactpersoonService.php`

**FINDING: OK - NECESSARY FIX**

Two changes:

**a) Recursion guard (static `$processingContacts` array):**
```php
private static array $processingContacts = [];

public function processContactpersoon(...): bool {
    if (isset(self::$processingContacts[$contactId])) {
        return true;
    }
    self::$processingContacts[$contactId] = true;
    // ... finally { unset(self::$processingContacts[$contactId]); }
}
```
This is a correct and common pattern for preventing event recursion. The `finally` block ensures cleanup even on exceptions.

**b) Backup org entity creation:**
When `OrganisationMapper::findByUuid()` throws `DoesNotExistException`, the code now attempts to create the entity via `OrganizationSyncService::ensureOrganisationEntityPublic()`. This is defensive programming for data consistency.

**Verdict:** OK. Both changes are necessary and well-implemented.

---

## 9. ContactPersonHandler.php - sanitizeEmailForUsername()

**File:** `softwarecatalog/lib/Service/SoftwareCatalogue/ContactPersonHandler.php`

**FINDING: OK - NEW, NECESSARY UTILITY**

The `sanitizeEmailForUsername()` method:
1. Lowercases the email
2. Strips subaddressing (`+tag` before `@`)
3. Removes characters not allowed in Nextcloud usernames (`[^a-z0-9._@-]`)

**Does Nextcloud have this?** No. Nextcloud's `IUserManager::createUser()` validates username constraints but does NOT sanitize. OpenRegister also has no email sanitization utility.

**Does OpenRegister have this?** No. `FileService`, `FileValidationHandler`, and `UserService` have no email sanitization.

**Complementary validation:** The `validateEmailForUsername()` method properly validates length (3-64 chars), presence of `@`, and absence of invalid characters after sanitization.

**Additional change:** The `exec()` call for filesystem pre-warm (line 369-400) is a hack. It forks a PHP process to run `OC_Util::setupFS()` + `OC_Util::copySkeleton()` asynchronously. While functional, this bypasses Nextcloud's job system. A proper approach would be a background job (`\OCP\BackgroundJob\IJobList::add()`).

**Verdict:** OK for sanitization. WARNING on the `exec()` pre-warm hack -- should be refactored to use Nextcloud's background job system.

---

## 10. UserProfileUpdatedEventListener.php - MetadataHydrationHandler

**File:** `softwarecatalog/lib/EventListener/UserProfileUpdatedEventListener.php`

**FINDING: OK - NECESSARY WORKAROUND**

The change bypasses `ObjectService::saveObject()` by using `ObjectEntityMapper::update()` directly, which means `_name` metadata would not be regenerated. The fix explicitly calls:

```php
$metaHydrationHandler = \OC::$server->get(MetadataHydrationHandler::class);
$metaHydrationHandler->hydrateObjectMetadata(entity: $contactpersoon, schema: $schemaEntity);
```

**Is there a simpler way?** Not really. The alternatives are:
1. Use `saveObject()` -- but this triggers validation that rejects legacy enum values (the exact reason for switching to mapper)
2. Use `saveObject()` with `skipValidation` flag -- but this doesn't exist on the public API
3. Manually update `_name` by running the Twig template -- this is essentially what `hydrateObjectMetadata()` does, so calling it is correct

The `findContactpersoon()` method with email fallback is also a good addition. It tries username first, then falls back to case-insensitive email search. This handles the case where users were created before the username field was populated.

**Verdict:** OK. The MetadataHydrationHandler call is the correct approach when bypassing saveObject().

---

## 11. Vue modals - ObjectModal pattern

**FINDING: NOT IN SCOPE**

No Vue modal changes appear in the softwarecatalog `development` branch diff. The `src/modals/` directory is not modified. This item may have been intended for a different branch or repository.

**Verdict:** N/A -- no changes found to review.

---

## 12. PublicationsController.php - universalOrderFields

**File:** `opencatalogi/lib/Controller/PublicationsController.php`

**FINDING: BUG - WRONG FIELD NAME PREFIX**

The `universalOrderFields` list is:
```php
$universalOrderFields = ['uuid', 'created', 'updated', 'published', 'depublished'];
```

But the MagicSearchHandler's `applySorting()` method (line 976-978) expects metadata fields with underscore prefix:
```php
} else if (in_array($field, ['_created', '_updated', '_name', '_description', '_summary',
    '_uuid', '_register', '_schema', '_owner', '_organisation', '_published', '_depublished',
], true) === true) {
```

After `buildSearchQuery()` processes `ordering=-_updated`, it creates `_order: {'_updated': 'DESC'}`. The filter then checks if `'_updated'` is in `['uuid', 'created', 'updated', ...]` -- it's NOT (missing underscore prefix), so it gets **incorrectly stripped**.

**Additionally missing:** `_name` should be in the universal list. It exists in ALL magic tables as a metadata column and is commonly used for sorting.

**Fix:**
```php
$universalOrderFields = ['_uuid', '_created', '_updated', '_published', '_depublished', '_name'];
```

**Also:** The `findObjectLocation()` method (line 101-140) uses raw SQL with `information_schema.tables` and string concatenation. While `$quotedUuid` uses `$this->db->quote()`, the table names are from `information_schema` and trusted. However, this could be improved by using the QueryBuilder for the initial table lookup.

**Verdict:** BUG. The `universalOrderFields` list needs underscore prefixes and should include `_name`.

---

## Summary of Findings

| # | File | Verdict | Severity |
|---|------|---------|----------|
| 1 | PropertyRbacHandler.php | CRITICAL BUG - `authenticated` keyword not supported | Critical |
| 2 | QueryHandler.php | BUG - `instanceof Schema` always fails for serialized arrays | High |
| 3 | ExportService.php | OK - new, necessary feature | - |
| 4 | FilePropertyHandler.php | N/A - feature not found | - |
| 5 | SaveObject.php | N/A - feature not found | - |
| 6 | register.json | FORMAT OK, depends on fix #1 | Medium |
| 7 | OrganizationSyncService.php | OK - correct pattern | - |
| 8 | ContactpersoonService.php | OK - necessary fixes | - |
| 9 | ContactPersonHandler.php | OK, but WARNING on exec() hack | Low |
| 10 | UserProfileUpdatedEventListener.php | OK - correct approach | - |
| 11 | Vue modals | N/A - no changes found | - |
| 12 | PublicationsController.php | BUG - wrong field name prefix | High |

---

## Recommended Commit Groupings

### openregister (docs/rbac-rewrite)
1. **fix: Add `authenticated` keyword support to RBAC handlers** -- PropertyRbacHandler, MagicRbacHandler, PermissionHandler
2. **fix: Handle serialized schema arrays in QueryHandler property authorization check** -- QueryHandler.php
3. (ExportService changes can stay as-is -- they're part of the broader export rewrite)

### softwarecatalog (development)
1. **fix(#434): Use mapper for sync operations to avoid validation cascades** -- OrganizationSyncService, ContactpersoonService, UserProfileUpdatedEventListener
2. **feat(#392): Add email sanitization for username generation** -- ContactPersonHandler
3. **chore: Update register.json schema config** -- softwarecatalogus_register.json (rollen enum, table defaults, auth rules, version bump)

### opencatalogi (feature/newopenregister)
1. **fix: Correct universalOrderFields prefix and add _name** -- PublicationsController.php
2. **feat: Add catalog-based search with multi-schema/register support** -- PublicationsController.php (the broader refactor)
