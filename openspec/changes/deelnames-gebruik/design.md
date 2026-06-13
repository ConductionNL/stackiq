# Design: deelnames-gebruik

status: pr-created

## Architecture Overview

See specs/deelnames-gebruik/spec.md for detailed requirements and scenarios.

## Implementation Summary

This change adds deelnames (participations) visibility to GEMMA ArchiMate view enrichment by implementing a two-phase query pattern in ViewService.

### Two-Phase Retrieval

1. **Owned gebruik** — fetched via `ObjectService::searchObjects()` with standard RBAC enabled, filtered by `@self.organisation = currentOrg`. Uses `getVoorzieningenConfig()` for the correct voorzieningen register and gebruik schema identifiers.

2. **Deelnames gebruik** — fetched separately via `ObjectService::searchObjects($query, _rbac: false, _multitenancy: false)`, filtering on `deelnemers = currentOrg`. Both RBAC and multitenancy are disabled so objects owned by other organisations are visible.

### Deduplication

After fetching both datasets, `array_diff_key($deelnamesData, $gebruikData)` removes any elementRef from deelnames that already appears in owned gebruik. The owned version always takes precedence.

### Source Organisation Metadata

For each deelnames item, `processGebruikItems()` extracts `_sourceOrganizationId` and `_sourceOrganization` from the `afnemer` field (with fallback to `@self.organisation`). This enables the UI to attribute shared applications to their owning organisation.

### Frontend Toggle

A new Pinia store (`src/store/modules/view.js`) manages `includeGebruik` and `includeDeelnamesGebruik` state independently. Both default to `false`. `GemmaViewIndex.vue` provides two `NcCheckboxRadioSwitch` toggles; enabling either triggers a re-fetch with the corresponding API parameter.

## Reuse Analysis

- **ObjectService** — existing `searchObjects()` with `_rbac` and `_multitenancy` named parameters (as used by `AangebodenGebruikService`)
- **SettingsService.getVoorzieningenConfig()** — existing configuration accessor (as used by `GebruikService`)
- **OrganisationService.getActiveOrganisation()** — existing OpenRegister service (as used by `AangebodenGebruikService`)
- **NcCheckboxRadioSwitch** — existing Nextcloud Vue component for the frontend toggle

No new services were introduced; all logic lives in the existing ViewService.

## Declarative-vs-imperative decision

No schema-declarative extension fits this requirement (no lifecycle, aggregation, calculation, notification, or relation pattern applies). The two-phase query with RBAC bypass is imperative domain logic that cannot be expressed as `x-openregister-*` metadata.

## Seed Data

Three gebruiksobjecten added to `lib/Settings/softwarecatalogus_register.json`:

| Slug | Afnemer | Deelnemers |
|---|---|---|
| `gebruik-topdesk-gem-leiden-deelnemers` | Servicecenter Rijnland (SSC) | Gemeente Leiden, Gemeente Leiderdorp |
| `gebruik-key2-gem-leiden-deelnemer` | GBLT | Gemeente Leiden |
| `gebruik-suite4-gem-delft-eigenaar` | Gemeente Delft | (none — tests zero-deelnemers case) |

These cover: multi-deelnemer object, single-deelnemer object, and zero-deelnemer object (for completeness).
