# Migration: cmdb-export-import

## Current State

The `module` schema in register `stackiq` is at version `0.3.4` after merging `softwarecatalogus_register.json` (0.3.3) with `register.d/maintenance-and-roadmap.json` (0.3.4, `roadmapStatement`). It has no property that holds an identifier from an external source system. No Nextcloud database table is involved (ADR-001). OpenRegister stores modules in its magic table for the `stackiq` register and `module` schema.

## Target State

The `module` schema is at version `0.3.5` with five extra optional properties, all `visible`. None is `required`, so every existing module stays valid unchanged.

| Property | Type | Notes |
|---|---|---|
| `externalId` | string, maxLength 100 | TOPdesk Applicatie Code (the Middel-ID), shown as "Source id"; reference only |
| `externalNumber` | string, maxLength 50 | TOPdesk APPID ("ICT Applicatienummer") |
| `externalKey` | string, maxLength 200, `table.default: false` | `topdesk:<municipality uuid>:<APPID>`, the import's match key |
| `externalCreatedAt` | string, format date | creation date in the source system |
| `externalModifiedAt` | string, format date | last change in the source system |

Three seed modules (design.md, Seed Data) are added through the fragment's `components.objects`.

## Migration Class

No Nextcloud migration class. The schema change deploys through stackiq's existing register import in the repair step (`SettingsService` loads `softwarecatalogus_register.json`, deep-merges `register.d/*.json` in filename order, and imports the result through OpenRegister's `ConfigurationService`). OpenRegister adds the new columns to the magic table when the schema version increases.

```
Version: n/a (register version bump, no lib/Migration class)
File: lib/Settings/register.d/topdesk-cmdb-import.json
Key operations:
- components.schemas.module.version = "0.3.5"
- components.schemas.module.properties += externalId, externalNumber, externalKey, externalCreatedAt, externalModifiedAt
- components.objects += 3 seed modules (no publicationDate, no externalKey)
```

## Migration Steps

1. Add `lib/Settings/register.d/topdesk-cmdb-import.json`. The filename must sort after `maintenance-and-roadmap.json`, so its `module.version` wins the scalar overwrite in the merge.
2. Run the repair step (app upgrade or `occ maintenance:repair`). OpenRegister sees `module` 0.3.5 > deployed 0.3.4, and updates the schema and its magic table.
3. Seed modules are created when absent (matched on slug), as with the other seeds.

## Data Impact

Existing modules get five new empty columns. There is no data loss and no transformation. Safe on live data: the change is additive, and the columns are nullable.

## Rollback Procedure

Remove the fragment and revert the PR. OpenRegister does not drop columns on a lower version, so the five columns stay, empty, and nothing reads them. To remove imported data, delete the modules with a non-empty `externalKey` and their usages. To remove the seed modules, delete the slugs `voorbeeld-zaaksysteem`, `voorbeeld-afsprakenplanner` and `voorbeeld-belastingapplicatie`.

## Validation

- Unit test `tests/Unit/Settings/TopdeskCmdbFragmentTest.php` merges the register exactly as `SettingsService` does, and asserts `module.version === "0.3.5"` with the five properties present and none required.
- On the rig after the repair step: `GET /index.php/apps/openregister/api/schemas/<module schema id>` shows version `0.3.5` and the five properties.
- `GET /index.php/apps/openregister/api/objects/stackiq/module?externalId=APP-00001` returns the seed module `voorbeeld-zaaksysteem`.
