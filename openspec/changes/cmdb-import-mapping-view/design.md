# Design: cmdb-import-mapping-view

## Context

The CMDB import (archived change `2026-10-05-cmdb-export-import`) maps a TOPdesk export through `lib/Settings/cmdb-import/topdesk-profile.json` and five migration packs, loaded and validated by `CmdbImportProfile` and executed by OpenRegister's `MappingEngine`. The section `CmdbImport.vue` shows the upload form, the progress and the report; its help text names the two sheets from `PROFILE_DEFAULTS` in `src/utils/cmdbImport.js`. Nothing in stackiq or OpenRegister shows the profile or the packs (verified on OpenRegister `beta` ff4dad5c, 2026-10-09: APIs for mappings and migration packs exist, a screen does not).

## Goals / Non-Goals

**Goals**

- An administrator sees, in the section, exactly the mapping the next import runs.
- The section's sheet names cannot drift from the profile.
- Nothing is written; nothing new is parsed.

**Non-Goals**

- Editing the mapping in a UI (see proposal, Out of Scope).
- Showing a pack from OpenRegister's migration-pack store; the import does not read that store.

## Architecture Overview

```
Admin settings, "CMDB import" section (CmdbImport.vue)
  └─ CmdbImportMapping.vue        collapsible "Mapping (read-only)", one CnDataTable per pack
       │  GET /api/settings/cmdb-import/mapping   (+ requesttoken, via @nextcloud/axios)
       ▼
SettingsController::getCmdbImportMapping()      Nextcloud admins only, CSRF
  └─ CmdbImportProfile::mappingOverview()       load() → PackDefinitionValidator → accessors
       lib/Settings/cmdb-import/topdesk-profile.json + topdesk-{module,manufacturer,municipality,usage,business-owner}.json
```

## Decisions

### D1. The endpoint lives in `SettingsController` and reuses `CmdbImportProfile`

The route sits with the other `/api/settings/*` reads, and `SettingsController` gets `CmdbImportProfile` and `IL10N` as optional, trailing constructor arguments, so the eight existing tests that build the controller positionally keep working and Nextcloud's container injects both. The method loads through `CmdbImportProfile::load()`, the loader the import uses, so the view can never show a profile the import would refuse.

- **Alternative: `CmdbImportController::mapping()`.** Rejected: the brief and the plan put the read with the settings reads; the profile is injected directly, so the import service is not needed.
- **Alternative: read the JSON files in the controller.** Rejected: that would bypass the validator and could show a pack the import refuses.

### D2. Admin-only, CSRF, the import's error envelope

The method carries no auth attribute (Nextcloud's default: admins with a CSRF token), with `@auth admin-only` and its reason, as `CmdbImportController` does. The settings page is admin-only anyway, so a delegated group never sees the section; the endpoint follows the import's posture so an API caller gets the same answer from both. Success is the flat `{profile, packs}` (ADR-050). Failure is the import's envelope `{success: false, error, message, details}`, so `cmdbImport.js`'s `normaliseError()` and `errorText()` handle it unchanged; `details.reason` carries the loader's message, because an administrator who edited a file needs to know which file and why, and that message names files and validator rules only.

### D3. The transform is shown as stored

`fieldMappings[].transform` is passed through as the pack stores it (`type` plus `map`/`default`, `fields`/`separator`, `value`, `sourceFormat`/`targetFormat`). Re-shaping per type would hide a key the engine reads. The page renders the known keys and lists any other scalar key as it is. `required` is normalised to a boolean.

### D4. The section takes the sheet names from the endpoint, with the constant as fallback

`CmdbImportMapping.vue` fetches once on creation and emits `loaded` with the answer; `CmdbImport.vue` computes `sheetNames` from it, or from `PROFILE_DEFAULTS.sheets` until it arrives or when it fails. The constant stays, as the design of cmdb-export-import intended: a fallback, never the source.

### D5. One component, fetched once, collapsed by default

The block is collapsed by default so the import form stays the section's first thing; the fetch happens on creation anyway, because the help text needs the sheet names. The toggle is an `NcButton` with a visible label and `aria-expanded`/`aria-controls`, the pattern of `CollapsibleSection.vue`.

## Declarative-vs-imperative decision (ADR-031)

- **No new rule.** The change reads the declarative mapping (JSON packs in OpenRegister's format, executed by `MappingEngine`) and shows it; it adds no `x-openregister-*` block and no imperative rule.
- **Imperative, because it is an endpoint:** one controller method and one accessor that shape the loaded JSON into the answer. That is presentation of configuration, not object lifecycle, aggregation, notification or relation logic.
- **The mapping itself stays declarative:** a change to a pack changes the view and the import together, without PHP.

## Seed Data

No schema changes. No register fragment, seed object or migration is added or changed; the endpoint reads the six files that ship under `lib/Settings/cmdb-import/`.

## Nextcloud Integration

- Controller: `SettingsController::getCmdbImportMapping()`; route `settings#getCmdbImportMapping`, `GET /api/settings/cmdb-import/mapping`.
- Service: `CmdbImportProfile::mappingOverview()`.
- OCP: `OCP\IL10N`.
- OpenRegister: `Service\MigrationPack\PackDefinitionValidator`, through the existing guard; absent, the endpoint answers 503 `MAPPING_UNAVAILABLE`.

## Security Considerations

- Admin-only with CSRF (D2). The answer is configuration that ships with the app: column names, field names, lookup tables, file names. No object, no person.
- `details.reason` is stackiq's own composed message or the validator's structural messages; `message` is static and translated. An unexpected exception is logged and answered as 500 `IMPORT_FAILED` with a static message, never with `getMessage()`.
- Every value is rendered as text; nothing goes into `v-html`.

## NL Design System

Nextcloud and `@conduction/nextcloud-vue` components only (ADR-012): `NcButton` for the toggle, `NcLoadingIcon`, `NcNoteCard` for the error and `CnDataTable` for the tables, the same table the report uses.

## File Structure

```
appinfo/routes.php                                   (+ settings#getCmdbImportMapping)
lib/Controller/SettingsController.php                (+ getCmdbImportMapping)
lib/Service/Cmdb/CmdbImportProfile.php               (+ mappingOverview)
src/utils/cmdbImport.js                              (+ mappingUrl, loadCmdbMapping, mappingSheetNames, packTargetLabel, transformLabel, mappingRows)
src/views/settings/sections/CmdbImportMapping.vue    (new)
src/views/settings/sections/CmdbImport.vue           (mounts the block; sheetNames)
tests/Unit/Controller/SettingsControllerCmdbImportMappingTest.php
tests/Unit/Service/Cmdb/CmdbImportProfileTest.php    (+ overview)
tests/vitest/cmdbImportMapping.spec.js
tests/e2e/spec-coverage/cmdb-import-mapping.spec.ts
docs/features/cmdb-import.md
l10n/en.json, en.js, nl.json, nl.js
```

## Testing

- PHPUnit: the route and its posture (no attribute, `@auth admin-only`, no exemption annotation); the shipped directory answers five packs that match the profile's accessors; a broken pack answers 503 with the reason; a missing validator answers 503; an unexpected error answers 500 and is logged.
- vitest: the component renders one table per pack from a mocked answer, emits `loaded`, and shows the error and code on a 503.
- Playwright (written, not run here: no Nextcloud reachable): an admin sees the five tables and the usage row; a non-admin gets 403 and no settings page.
