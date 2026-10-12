---
kind: code
depends_on: []
---

# Proposal: cmdb-import-mapping-view

## Summary

The "CMDB import" section of stackiq's admin settings gets a collapsible, read-only block "Mapping (read-only)" that shows the column mapping the import really uses: the sheets, the key and required columns, and one table per migration pack with the source column, the target field, whether it is required, and the transformation with its lookup values. A new admin endpoint `GET /api/settings/cmdb-import/mapping` loads the import profile and the five packs through the same loader and validator the import uses, so what the administrator sees is what the next import runs. Editing stays in the JSON files on the server; the documentation says where they are and what an administrator can change.

## Motivation

The municipality's application manager (Jira WOO-588, sub-task of WOO-586) wants to see which TOPdesk column lands in which stackiq field, and which export values the import recognises, without reading JSON on the server. The mapping is declarative (REQ-CMDB-005) and OpenRegister has APIs for migration packs, but no screen for them, and stackiq offered no view of its profile or packs either: the section's help text even carried the sheet names as a constant in the page, which could drift from the profile. Options weighed on 2026-10-09: (A) an existing OpenRegister screen, which does not exist; (B) a read-only view in the section; (C) documentation only; (B+) editing through OpenRegister's packs. B was chosen: the question was "make it visible", the change stays in stackiq, and editing a mapping stays a rare, file-based act.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `cmdb-export-import`: ADDED REQ-CMDB-020 (the mapping overview endpoint and the read-only block); MODIFIED REQ-CMDB-014 (the section shows the mapping, and its help text takes the sheet names from the endpoint).

## Affected Projects

- [ ] Project: `stackiq`: one endpoint in `SettingsController`, one accessor on `CmdbImportProfile`, a `CmdbImportMapping.vue` block in the "CMDB import" section, helpers in `src/utils/cmdbImport.js`, documentation, translations, tests.

## Scope

### In Scope

- `GET /api/settings/cmdb-import/mapping`, for Nextcloud admins only and CSRF-protected, answering the profile and the packs as loaded by `CmdbImportProfile`, or 503 `MAPPING_UNAVAILABLE` with the loader's reason when a pack is broken.
- The collapsible block in `CmdbImport.vue`, with loading and error states, one table per pack and a `data-testid` per table.
- The sheet names in the section's help text come from the endpoint; the shipped names stay as the fallback.
- `docs/features/cmdb-import.md`: "Viewing the mapping", and "Adjusting the mapping" updated.
- PHPUnit, vitest and a Playwright spec.

### Out of Scope

- Editing the mapping in the UI, or through OpenRegister's migration-pack store (B+, rejected for now).
- New transformations in OpenRegister's mapping engine, or promoting `MappingEngine` to an OpenRegister contract.
- A sixth pack (technical owner): the functional administrator is not imported (cmdb-export-import, design D2).

## Approach

`CmdbImportProfile::mappingOverview()` builds the answer from the loaded profile and packs through the existing accessors, after `load()` validated every pack. `SettingsController::getCmdbImportMapping()` returns it as a flat 200, or translates `CmdbImportException` into the import's error envelope with the reason in `details.reason`. The section mounts a `CmdbImportMapping` component that fetches the endpoint once, renders the tables with `CnDataTable`, and emits the loaded mapping so the parent can take the sheet names from it. The transform is shown as the pack stores it: type, map or fields or value, and the formats of a date.

## New Dependencies

None.

## Impact

- **Backend**: `lib/Controller/SettingsController.php` (one method, two optional constructor arguments), `lib/Service/Cmdb/CmdbImportProfile.php` (one method), one route in `appinfo/routes.php`.
- **Frontend**: `src/views/settings/sections/CmdbImportMapping.vue` (new), `src/views/settings/sections/CmdbImport.vue` (mounts it, takes the sheet names from it), `src/utils/cmdbImport.js` (URL, request, row and label helpers).
- **Data**: none. The endpoint reads six files that ship with the app and writes nothing.

## Cross-Project Dependencies

- **openregister** (consumed, not changed): `MigrationPack\PackDefinitionValidator`, through the existing guard in `CmdbImportProfile`.

## Risks

### Risk 1: The view shows a mapping the import does not use
**Severity:** Low — **Mitigation:** the endpoint and the import share one loader (`CmdbImportProfile`), one directory and one validator; the unit test reads the shipped directory through the controller and compares it with the profile's own accessors.

### Risk 2: The loader's reason leaks something it should not
**Severity:** Low — **Mitigation:** the reason is composed by stackiq (file name plus the validator's messages about the pack's structure) or is the fixed text that the validator is missing; it never holds a cell value or a person. The `message` stays static and translated (ADR-050).

## Rollback Strategy

Additive. Revert the PR to remove the route, the method, the component and the strings; the section then shows its constant sheet names again, as before.

## Open Questions

- Should a later change let an administrator override a pack through OpenRegister's migration-pack store (`POST /api/migration-packs/import`), as cmdb-export-import design D2 noted? Not for this change.
