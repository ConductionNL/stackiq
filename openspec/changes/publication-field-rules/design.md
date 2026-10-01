# Design: publication-field-rules

## D1. Field rules

Each rule is `{"authorization": {"read": ["authenticated"]}}` on the property. OpenRegister's property RBAC strips the field from the rendered object for a reader outside the list, and withholds it from facets. Signed-in users keep reading them, as today.

| Schema | Private fields |
|---|---|
| module | contactPerson, usages, dpiaDocumentRef, verwerkingsregisterRef |
| moduleVersion | usages |
| suite | contactPerson |
| catalogService | contactPerson |
| connection | longDescription, the four date fields, nonMunicipalProvision, realisedWithIntermediaryModule, provider, service, registeredBy, the four serviceDesk fields |
| usage | provider, contactPerson, businessOwner, technicalOwner, participants, the four non-production dates, amefElements, elementRef, plannedReplacement(Date), the TIME fields, installedVersion, the four serviceDesk fields, the value assessment fields |
| organization | contactsUid, xml, pki, registrationStatus, mergedInto |

`publicationDate` stays readable on every schema, because the object read rules match on it.

## D2. usage is public from its publication date

`usage.authorization.read` is the four organisation rules it has today plus `{"group": "public", "match": {"publicationDate": {"$lte": "$now"}}}`. A fragment replaces an `authorization` list (`SettingsService::deepMergeConfig`, `replaceLists` for that key). Lane oc-pub's draft listed only the public rule. That would have removed every organisation's own read access.

## D3. moduleVersion follows its application

A read rule matches the object's own fields only. So the version gets `modulePublicationDate` and `moduleRegisteredBy`, and its rule becomes:

`["authenticated", {public, modulePublicationDate <= now}, {public, moduleRegisteredBy = Supplier}]`

These are the same two conditions as `module`'s public rules.

`ModuleVersionPublicationService` copies the values:

- when a module is saved, onto each of its versions;
- when a version is saved, from its module.

It writes only when a value differs, so its own writes stop after one event. `BackfillModuleVersionPublication` runs it for every module on upgrade.

Until the backfill runs, an existing version is not public. That is the safe direction.

## D4. The highest version wins

`SettingsService::keepHighestSchemaVersions()` runs after each fragment merge. It gives every schema the highest `version` any file declared, so file names no longer decide.
