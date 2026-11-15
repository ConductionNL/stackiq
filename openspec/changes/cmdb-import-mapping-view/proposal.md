---
kind: code
depends_on: []
---

# Proposal: cmdb-import-mapping-view

## Summary

A Nextcloud administrator can view and edit the column mapping of the CMDB import in OpenRegister. The four sheet packs of the import (module, manufacturer, usage and business owner) move into OpenRegister's existing migration-pack store, where OpenRegister's pack editor shows and changes them. The import reads each pack from that store first and runs exactly what the administrator saved. The packs that ship under `lib/Settings/cmdb-import/` become the seed and the fallback: a repair step stores them in OpenRegister as built-in packs, and replaces a stored pack nobody edited when a later release ships a newer one. An edited pack is never overwritten. A stored pack that is invalid, or that breaks a rule of the import, stops the import with 503 `MAPPING_UNAVAILABLE` naming the pack; it never falls back to the files silently. `GET /api/settings/cmdb-import/mapping` says per pack where it comes from, which versions are stored and shipped, whether it was edited, and links to its editor in OpenRegister. Showing that per pack in the "CMDB import" section of stackiq's admin settings is the frontend part (#1289).

## Motivation

The municipality's application manager (Jira WOO-588, sub-task of WOO-586) must be able to see and change which TOPdesk column lands in which stackiq field, and which export values the import recognises, without editing JSON on the server. The first version of this change (2026-10-09) only showed the mapping read-only and left editing in the files, which an app update overwrites. On 2026-10-12 it was decided that mapping lives in OpenRegister: the packs are already OpenRegister migration packs, executed by OpenRegister's `MappingEngine` and validated by its `PackDefinitionValidator`, and OpenRegister already stores and serves such packs (`/api/migration-packs`). Storing the import's packs there makes the edited object exactly the object the import runs, and gives the administrator one place for mappings. Integriq is not changed; its mapping screens are the pattern for OpenRegister's editor.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `cmdb-export-import`: ADDED REQ-CMDB-020 (the mapping endpoint, now with source, versions and the editor link; the section that shows it is #1289), REQ-CMDB-021 (the import takes the sheet packs from OpenRegister's store, with the import's rules and fail-closed answers) and REQ-CMDB-022 (seeding and updating the packs in OpenRegister); MODIFIED REQ-CMDB-005 (where the packs come from, the date columns a pack adds, the pack's own concat separator).

## Affected Projects

- [ ] Project: `stackiq`: the pack resolution and rules in `lib/Service/Cmdb/`, a repair step, the extended endpoint, the slimmed mapping block in the "CMDB import" section (#1289), documentation, translations, tests.
- [ ] Project: `openregister` (separate change and PR): the migration-pack list and editor pages that the `editUrl` opens, the envelope body on `PUT /api/migration-packs/{id}`, and a builtin pack keeping its slug.

## Scope

### In Scope

- Reading the module, manufacturer, usage and business-owner packs from OpenRegister's migration-pack store by slug, with the shipped file as the seed and, while nothing was ever stored, as the fallback with one import warning.
- The import's own rules on every pack, stored or shipped: the required mappings, and the fields a pack may write: every plain property of its target schema, read from the register stackiq ships.
- Failing closed with 503 `MAPPING_UNAVAILABLE` when a stored pack is invalid or breaks a rule, when it was renamed, or when the store is gone after the packs were stored there.
- The repair step `SeedCmdbImportPacks` on install and upgrade: seeds missing packs as built-in, replaces unedited packs with the shipped pack of the release, keeps edited ones and reports them; the row ids are kept in stackiq's app config.
- The `x-stackiq` marker in a stored definition: the shipped version and the hash of the shipped pack it was seeded from.
- Excel date conversion for every column a pack maps with a date transform; the note cleanup on the pack's own concat separator.
- `GET /api/settings/cmdb-import/mapping` extended per pack with `source`, `packId`, `storedVersion`, `shippedVersion`, `edited`, `newerShipped`, `updatePending`, `editable` and `editUrl`, and `openRegisterPacks` at the top level.
- The section's mapping block (#1289): per pack its source, version, a hint when a newer shipped pack was not applied, and a link to its editor in OpenRegister (Task 4); the sheet names in the help text come from the endpoint.
- `docs/features/cmdb-import.md`: viewing and editing the mapping in OpenRegister.

### Out of Scope

- The editor itself: OpenRegister's change for its migration-pack pages.
- The municipality pack: it maps the import options, not a sheet, and stays a shipped file that is not editable.
- The import profile (`topdesk-profile.json`): sheets, key and required columns, limits and write rules stay a shipped file, shown read-only.
- More than one mapping per instance: OpenRegister's pack store has no organisation scope, so an instance has one TOPdesk mapping for every municipality it imports.
- Promoting `MappingEngine`, `PackDefinitionValidator` or `MigrationPackService` to an OpenRegister contract.

## Approach

`CmdbImportProfile::load()` reads each shipped pack, validates it, and for the four sheet targets asks `CmdbImportPackStore` for the stored pack. The store resolves OpenRegister's `MigrationPackService` defensively from the container, finds the pack by slug, seeds it when it is missing, and replaces it when its `x-stackiq` marker shows it was not edited and the shipped pack has changed. `CmdbImportPackRules` checks every pack against the import's rules; `CmdbImportPackMarker` computes the canonical hash. The repair step calls the same store. `SettingsController` adds `editUrl` from OpenRegister's page route through `IURLGenerator`.

## New Dependencies

None.

## Impact

- **Backend**: `lib/Service/Cmdb/CmdbImportProfile.php` (resolution, sources, date columns, concat separator), new `CmdbImportPackStore.php`, `CmdbImportPackMarker.php`, `CmdbImportPackRules.php`, new `lib/Repair/SeedCmdbImportPacks.php` and its registration in `appinfo/info.xml`, `lib/Controller/SettingsController.php` (`editUrl`, details), `lib/Controller/CmdbImportController.php` (the message names the pack), `lib/Service/CmdbExportImportService.php` (fallback warning, separator).
- **Frontend** (#1289): the mapping block in the "CMDB import" section.
- **Data**: four rows in OpenRegister's `openregister_migration_packs`, created by the repair step or the first import; one app config key, `cmdb_import_pack_ids`.

## Cross-Project Dependencies

- **openregister** (consumed): `Service\MigrationPackService` (`findByPackSlug`, `find`, `create`, `update`), `Db\MigrationPack::getDefinitionArray()`, `Service\MigrationPack\PackDefinitionValidator`, `Service\MigrationPack\MappingEngine`, and the page route `dashboard#catchAll` for `/migration-packs/{id}`.
- **openregister** (required before the store is used): OpenRegister's change for WOO-588 (`feat/woo588-migration-pack-editor`): the pack writes require a CSRF token and are admin-only (no `#[NoAdminRequired]`, `#[PublicPage]`, `#[AuthorizedAdminSetting]` or `#[NoCSRFRequired]` on a write method: stackiq refuses a store that has one), a built-in pack's slug is locked (`Exception\BuiltinPackSlugLockedException`), and it adds the migration-pack pages and the envelope body. Released OpenRegister versions write packs without a CSRF check, and the packs drive writes with RBAC off, so stackiq uses the store only when `BuiltinPackSlugLockedException` exists; on an older OpenRegister the import keeps the shipped files with a warning. Do not promote this change to beta before that OpenRegister change is released.

## Risks

### Risk 1: An edit in OpenRegister writes fields the import must not write
**Severity:** High — **Mitigation:** the import writes with OpenRegister's RBAC and multitenancy off, so every pack must follow the import's rules (REQ-CMDB-021): only plain properties of the target schema, never object metadata, match keys, relations, publication dates or admin fields, and the required mappings kept. A pack that breaks a rule stops the import with 503 naming the pack.

### Risk 2: An administrator's edit is lost or silently ignored
**Severity:** High — **Mitigation:** the repair step never overwrites an edited pack; a renamed pack, or a store that is gone after the packs were stored, fails closed instead of falling back to the files.

### Risk 3: An edit widens what the import reads
**Severity:** Medium — **Mitigation:** the read allowlist is every column the profile or a pack names, so an administrator who maps a new column makes the import read it. Only Nextcloud admins can edit packs and run the import; the documentation says that a mapped column is read.

### Risk 4: OpenRegister's internal classes change
**Severity:** Medium — **Mitigation:** resolved defensively and checked for the methods called and for the hardened store's `BuiltinPackSlugLockedException`; a missing or older store before any seed falls back to the files with a warning, afterwards fails closed.

## Rollback Strategy

Revert the PR. The import then reads the shipped files again; the four rows in OpenRegister's pack store stay and are ignored, and can be deleted there. The app config key `cmdb_import_pack_ids` can be deleted with `occ config:app:delete stackiq cmdb_import_pack_ids`.

## Open Questions

- Does any instance import for more than one municipality? The store holds one mapping per instance; per-municipality packs would need a slug per municipality or a pack chosen in the import options.
