# Design: cmdb-import-mapping-view

## Context

The CMDB import (archived change `2026-10-05-cmdb-export-import`) maps a TOPdesk export through `lib/Settings/cmdb-import/topdesk-profile.json` and five migration packs, loaded and validated by `CmdbImportProfile` and executed by OpenRegister's `MappingEngine`. The packs are OpenRegister migration-pack documents (`fieldMappings` with `trim`, `date`, `lookup`, `concat` transforms, `defaults`, `idStrategy`). OpenRegister already stores such documents in `openregister_migration_packs` through `MigrationPackService`, with CRUD at `/api/migration-packs` for admins. On 2026-10-12 it was decided that the mapping lives in OpenRegister: the administrator edits the packs there, and the import runs what is stored.

## Goals / Non-Goals

**Goals**

- The import runs the pack the administrator saved in OpenRegister, unchanged.
- An edit is never lost: not overwritten by an update, never silently replaced by the shipped file.
- An edit cannot make the import write what it must not write.
- The mapping endpoint tells where each pack comes from and links to its editor; the settings section that shows it is part of #1289.

**Non-Goals**

- The editor screens (OpenRegister's change).
- Editing the municipality pack or the import profile.
- A mapping per municipality.

## Architecture Overview

```
Install / upgrade                        Import or GET /api/settings/cmdb-import/mapping
  SeedCmdbImportPacks (repair)             CmdbImportProfile::load()
        │                                        │ for module, manufacturer, usage, businessOwner
        ▼                                        ▼
  CmdbImportPackStore::resolve(shipped) ◄────────┘   municipality: shipped file only
        │ findByPackSlug → absent: create(builtin, + x-stackiq)
        │                 unedited + outdated: update(shipped + x-stackiq)
        │                 edited: keep (newerShipped)
        ▼
  OpenRegister MigrationPackService  ──  openregister_migration_packs
        │
  CmdbImportPackRules (required mappings, writable fields)  +  PackDefinitionValidator
        │
  MappingEngine::mapRow()  (import)      SettingsController: + editUrl (IURLGenerator)
```

## Decisions

### D1. The endpoint lives in `SettingsController` and reuses `CmdbImportProfile`

The route sits with the other `/api/settings/*` reads, and `SettingsController` gets `CmdbImportProfile`, `IL10N` and `IURLGenerator` as optional, trailing constructor arguments, so the existing tests that build the controller positionally keep working and Nextcloud's container injects them. The method loads through `CmdbImportProfile::load()`, the loader the import uses, so the view can never show a mapping the import would refuse.

### D2. Admin-only, CSRF, the import's error envelope

The method carries no auth attribute (Nextcloud's default: admins with a CSRF token), with `@auth admin-only` and its reason, as `CmdbImportController` does. Success is the flat `{profile, packs, openRegisterPacks}` (ADR-050). Failure is the import's envelope `{success: false, error, message, details}`; `details.reason` carries the loader's message cut to its first line and 500 characters, and a pack stored in OpenRegister is also named in `details.pack` and `details.packId`. The import's own 503 names the pack in its `message` from the same details; the full reason goes to the Nextcloud log. The reason names the file or the pack, the broken rule and the configuration value it refused, never a workbook cell or a person.

### D3. The transform and the write rules are shown as stored

`fieldMappings[].transform` is passed through as the pack stores it. The write rules the import applies besides the mapping are answered per pack (`defaults`, `createOnlyDefaults`, `createOnlyFields`, `neverWritten`), built from the accessors the import uses. A create-only default that is not a mapped field, such as the module `type`, is answered in `createOnlyDefaults` only: `merge()` never reads it on update, so `createOnlyFields` leaves it out. A mapped field with a create default stays in `createOnlyFields`.

### D4. The section takes the sheet names from the endpoint, with the constant as fallback

Unchanged from the first version: the help text computes the sheet names from the endpoint's answer, or from `PROFILE_DEFAULTS.sheets` until it arrives or when it fails.

### D5. The packs live in OpenRegister's migration-pack store, found by slug

The four sheet packs are stored as OpenRegister migration packs and found by their slug (`stackiq-topdesk-module`, …). That is the format the packs already have and the engine the import already calls, so what the administrator edits is exactly what runs, with OpenRegister's validator on save (422) and on import (503).

- **Alternative: OpenRegister's Twig mapping entity (`/api/mappings`).** Rejected: lookups, `required` and dates would become Twig templates, validation would no longer be structural, and errors would lose their row and column; the import engine would have to be rewritten.
- **Alternative: packs as register objects, like Integriq's mappings.** Rejected: a third mapping store next to the one OpenRegister already has, with the validator duplicated in a listener.

The municipality pack maps the import options (`municipalityName`), not a sheet, so it stays a shipped file and is answered with `editable: false`. The import profile stays a shipped file: its limits guard memory and zip bombs, and its sheets, key column and write rules are stackiq semantics.

OpenRegister's pack store has no organisation column: an instance has one TOPdesk mapping, used for every municipality it imports.

### D6. Seeding, and following the release only while unedited

A stored pack carries `x-stackiq: {shippedVersion, shippedHash}`: the version of the shipped pack it was seeded from and the sha256 of that pack's canonical JSON (keys of every object sorted recursively, list order kept, because the order of `fieldMappings` decides which mapping wins a target). OpenRegister's validator ignores keys it does not know, so the marker travels with the definition. A stored definition that, without the marker, still hashes to `shippedHash` is unedited.

`CmdbImportPackStore::resolve()` is the one place that writes: it creates a missing pack as built-in with the marker (never through OpenRegister's import, which upserts and would overwrite an edit), replaces an unedited pack whose marker hash differs from the current shipped pack, and leaves an edited pack alone, reporting `newerShipped`. A pack OpenRegister reports no last-saved time for is left alone too: the update's guard against an edit saved in between needs that time. `find()`, which the mapping view uses and which writes nothing, answers an unedited outdated pack as the next import will run it: the shipped pack and version with `updatePending: true`. A pack without a marker (created by hand under the same slug) counts as edited with `edited: null`, and is never replaced. The repair step `SeedCmdbImportPacks` runs it on install and upgrade, after `InitializeSettings`; the import's `CmdbImportProfile::load()` runs it too, so a pack deleted in OpenRegister is seeded again on the next import, and an instance whose OpenRegister gained the store after stackiq's upgrade is seeded by its next import. Viewing the mapping uses `loadForView()`, which calls `CmdbImportPackStore::find()`: it only reads, and a pack the store does not hold yet is shown from its shipped file. The update of an unedited pack passes the read pack's `updatedAt`, so OpenRegister refuses it (`PackChangedException`) when an administrator saved the pack in between; the store then keeps that edit. `CmdbImportPackStoreProbe` decides whether the store may be used at all: the slug-lock class exists and no write method of OpenRegister's pack controller carries an attribute or annotation that skips the CSRF check or admits non-admins.

Version numbers alone cannot tell an edit from a release: OpenRegister's editor bumps the version on save, independently of stackiq's releases. The hash can.

- **Alternative: seed only when missing, never update.** Rejected: an unedited pack would never get a fix that a release ships.
- **Alternative: always overwrite on upgrade.** Rejected: it destroys the administrator's edits.

### D7. Fail closed once the packs were stored

The store counts as available only on an OpenRegister whose pack writes require a CSRF token and lock a built-in pack's slug, recognised by the class `OCA\OpenRegister\Exception\BuiltinPackSlugLockedException` that ships with them. Released OpenRegister versions have `MigrationPackService` but write packs without a CSRF check; since a pack drives writes with RBAC off, stackiq does not run packs stored behind those routes and treats such a store as not available.

The store keeps the row id it resolved per slug in stackiq's app config (`cmdb_import_pack_ids`). Without OpenRegister's store and with no recorded ids, the import uses the shipped files and adds one import warning saying so. With recorded ids, a missing store is 503 `MAPPING_UNAVAILABLE` naming the packs: falling back would silently drop an edit. A slug lookup that misses while the recorded row still exists under another slug is a rename, and is 503 naming the row and both slugs, not a second seeded pack the import would read while the administrator edits the renamed one. A recorded row that was deleted is seeded again. Any other failure of the store is 503 naming the pack and the exception class, never its message.

### D8. The import's rules on every pack

OpenRegister's validator checks structure only, and the import writes with OpenRegister's RBAC and multitenancy off. `CmdbImportPackRules` therefore checks every pack, stored or shipped:

- required mappings with `required: true`: module `name` and `externalNumber` (from the profile's key column), manufacturer, municipality and business-owner `name`;
- the writable fields of a target are the properties of its schema in the register stackiq ships (fragments included, ADR-037), read from that file, so a property added to the schema can be mapped without a code change; the business owner may map `name` and `role` only, because it is resolved in Nextcloud Contacts and has no schema;
- refused whatever the schema says: `@self`, `id`, the match keys and the relations the import sets (`externalKey`, `provider`, `consumer`, `module`, `businessOwner`, the organisation `type`), the profile's `neverWritten`, every property whose definition or items carry a `$ref`, an `inversedBy`, a `writeBack`, `format: uuid` or an object configuration that relates or names a schema, every property of `type: file`, `publicationDate` and `depublicationDate` on every target, and the admin fields `registeredBy`, `registrationStatus`, `mergedInto`, `contactsUid`, `pki`, `xml`, the organisation `status` and the module `type`;
- the same rules for `defaults`, and the organisation defaults pinned as shipped: `type` (`Supplier`, `Municipality`), because the import finds organisations by name and type, and `status` `Active` and the manufacturer's `registeredBy` `Supplier`, the only way a pack sets those admin fields.

A broken rule is 503 `MAPPING_UNAVAILABLE` naming the pack, its id and every rule it breaks.

### D9. What a pack controls besides its mappings

Only the profile's date columns used to be converted from Excel serial numbers, so a date transform on a new column would have received `45111`. The date columns are now the profile's plus every column a pack maps with a `date` transform. The cleanup of empty parts in the usage note splits on the separator of the concat mapping that writes `interneAnnotation`, not on a constant. `idColumns`, `emptyValues` and the sheet constants (`Beheer`) stay profile semantics.

### D10. The editor link is built by Nextcloud

`editUrl` is `IURLGenerator::linkToRouteAbsolute('openregister.dashboard.catchAll', ['path' => 'migration-packs/<id>'])`, so an instance that needs `/index.php` gets it. Nextcloud's router answers an unknown route with an empty path; a link that does not end in the pack path is answered as null rather than as the instance's front page.

## Declarative-vs-imperative decision (ADR-031)

- **The mapping stays declarative:** OpenRegister migration packs, executed by `MappingEngine`; an edit changes the import without PHP.
- **Imperative, because they guard the import:** the pack resolution, the seed and update policy and the import's rules. They are configuration management and security checks on writes with RBAC off, not object lifecycle, aggregation, notification or relation logic, and no `x-openregister-*` vocabulary covers them.

## Seed Data

No schema changes and no register fragment. The repair step stores four migration packs in OpenRegister (`stackiq-topdesk-module`, `stackiq-topdesk-manufacturer`, `stackiq-topdesk-usage`, `stackiq-topdesk-business-owner`) from the shipped files, each with the `x-stackiq` marker.

## Nextcloud Integration

- Controller: `SettingsController::getCmdbImportMapping()`; route `settings#getCmdbImportMapping`, `GET /api/settings/cmdb-import/mapping`.
- Services: `CmdbImportProfile`, `CmdbImportPackStore`, `CmdbImportPackMarker`, `CmdbImportPackRules`.
- Repair step: `SeedCmdbImportPacks`, in `<install>` and `<post-migration>`.
- OCP: `OCP\IL10N`, `OCP\IURLGenerator`, `OCP\IAppConfig`, `OCP\Migration\IRepairStep`, `OCP\AppFramework\Db\DoesNotExistException`.
- OpenRegister: `Service\MigrationPackService`, `Service\MigrationPack\PackDefinitionValidator`, `Service\MigrationPack\MappingEngine`, resolved defensively; the page route `dashboard#catchAll`.

## Security Considerations

- Admin-only with CSRF (D2). OpenRegister's pack writes are admin-only with CSRF too; stackiq uses only a store that has those guards (D7).
- The import's rules (D8) let an edited pack write only plain properties of its target schema: never object metadata, match keys, relations, publication dates or admin fields.
- An edit can add a column to what the import reads: the read allowlist is every column the profile or a pack names. Only Nextcloud admins can edit packs and run the import.
- Fail closed (D7): no silent fallback that would drop an administrator's edit.
- Messages name packs, rules and configuration values, never a workbook cell or a person; a store failure is named by its exception class.

## NL Design System

Nextcloud and `@conduction/nextcloud-vue` components only (ADR-012), as in the first version of the block.

## File Structure

```
appinfo/info.xml                                     (+ SeedCmdbImportPacks in install and post-migration)
lib/Repair/SeedCmdbImportPacks.php                   (new)
lib/Service/Cmdb/CmdbImportPackStore.php             (new)
lib/Service/Cmdb/CmdbImportPackMarker.php            (new)
lib/Service/Cmdb/CmdbImportPackRules.php             (new)
lib/Service/Cmdb/CmdbImportProfile.php               (resolution, sources, dateColumns, concatSeparator, overview)
lib/Service/CmdbExportImportService.php              (fallback warning, separator)
lib/Controller/SettingsController.php                (editUrl, details)
lib/Controller/CmdbImportController.php              (MAPPING_UNAVAILABLE names the pack)
src/…                                                (the section's mapping block)
tests/Unit/Service/Cmdb/CmdbImportPackStoreTest.php, CmdbImportPackRulesTest.php, CmdbImportProfileTest.php
tests/Unit/Repair/SeedCmdbImportPacksTest.php
tests/Unit/Support/FakeMigrationPackService.php
tests/Unit/Controller/SettingsControllerCmdbImportMappingTest.php, CmdbImportControllerTest.php
tests/Unit/Service/CmdbExportImportServiceTest.php
tests/e2e/spec-coverage/cmdb-import-packs.spec.ts    (new)
docs/features/cmdb-import.md
l10n/en.json, en.js, nl.json, nl.js
```

## Testing

- PHPUnit against an in-memory `MigrationPackService`: seeding with the marker, an unedited pack following a newer release, an edited pack kept with `newerShipped`, an unedited pack with no `updatedAt` kept, the view answering the shipped pack with `updatePending`, a pack without marker never replaced, a renamed pack and a failing store failing closed, a deleted pack seeded again, a seed that loses a race to another request, an OpenRegister without the hardened store, the canonical hash.
- PHPUnit on the rules: the shipped packs pass, any plain schema field can be mapped, files are refused, relations, fields the register hides or calculates, publication dates and admin fields are refused as mappings and as defaults, a plain field the register turns into a relation is refused.
- PHPUnit on the loader: a stored pack is what runs, an invalid stored pack and thirteen broken rules are 503 naming the pack and id, no store before a seed uses the files, no store after a seed fails closed, a stored date column is converted, the concat separator is the pack's, also with a later plain mapping of the note.
- PHPUnit on the import: an edit in OpenRegister changes the mapped usage, the separator and a new date column; without a store the report carries the fallback warning.
- PHPUnit on the endpoint and the repair step: the extended answer, `editUrl` from the page route and null when it cannot be built, 503 naming the pack with the link to its editor; seeding four packs, updating and keeping, no store, a failing store, the registration order.
- Playwright, through the API (`cmdb-import-packs.spec.ts`): the four packs stored as built-in packs with the marker, the mapping with source, `packId` and `editUrl`, a broken stored pack refused by the mapping and by the import, a module pack without its required name refused, an edit in OpenRegister run by the import, a deleted pack seeded again, the 403 for a non-admin. Each test that edits a pack puts back the definition it read.
- Playwright, on the settings page (Task 7, with the section in Task 4, both in #1289): the section shows the source and the editor link per pack.
- Install, upgrade and a missing or older store are not driven in e2e: they need another OpenRegister or a reinstall. The requirements state them, and the PHPUnit tests above prove them.
