# Design: operations-record-reconciliation

Read at development 49e65cb4, with OpenRegister development 4fee776 and `@conduction/nextcloud-vue` 2.57.1.

## Where it fits

| Part | File and line | What changes |
|---|---|---|
| Fragment | new `lib/Settings/register.d/operations-record-reconciliation.json` (ADR-037) | `recordStatus` and `mergedInto` on `module` (`lib/Settings/softwarecatalogus_register.json:6777`) and `catalogService` (`:1324`); `configuration.x-openregister-merge` on both; `configuration.x-openregister-dedup` on `catalogService`; higher schema versions |
| Register | `lib/Settings/softwarecatalogus_register.json:7` | register version and changelog entry in the monolith, since a fragment cannot set the register's own version text |
| Listener | new `lib/EventListener/CatalogueMergeRelinker.php`, registered in `lib/AppInfo/Application.php:777` `registerEventListeners()` | on OpenRegister's `ObjectsMergedEvent` for `module` or `catalogService`: re-point references, set `mergedInto` |
| Map | new `lib/Service/CatalogueReferenceMap.php` | the references per target schema, one list for the listener and the organisation merge |
| Service | `lib/Service/MergeOrganisatieService.php:111` `FIELD_RELATION_TYPES` | reads the organisation entries from the map, adding the six missing references |
| Service | `lib/Service/FacetService.php:400` `fetchBaseObjects()` | leaves out objects whose `recordStatus` is Merged |
| Pages | `src/views/FacetedCatalogIndexView.vue:68` toolbar, `src/manifest.json:386` `Organisaties` `headerActions` | a Find duplicates action; handler `openDuplicateCandidates` in `src/customComponents.js` |
| Pages | `src/manifest.json:491` `ModuleDetail` | a body widget `MergedRecordBanner` |
| Component | new `src/components/merge/MergedRecordBanner.vue` | shows Merged into with a link to the survivor when `recordStatus` is Merged |
| Cleanup | `src/modals/object/MergeObject.vue`, `src/modals/Modals.vue:11`, `src/store/plugins/stackiqPlugin.js:1408` `mergeObjects` | removed |

## Decisions

### D1. OpenRegister finds and merges; stackiq declares (ADR-045)

ADR-045 gives OpenRegister the duplicate candidates surface and the reversible merge, and lists an app-local merge wizard or dedup scanner as review-blocking. OpenRegister already reads `configuration.x-openregister-dedup` (`DuplicateDetectionService.php:587`), lists candidate pairs, lets a steward dismiss a pair, and merges with preview, execute and reverse (`lib/Service/Merge/MergeService.php`). Both annotations are in `Schema::ANNOTATION_VOCABULARY` (`lib/Db/Schema.php:3154` and `:3163` in OpenRegister), so they survive import. Stackiq declares:

- `catalogService`: `x-openregister-dedup` with `matchRules` name normalized 0.4, name levenshtein 0.2, provider exact 0.3, website normalized 0.1, threshold 0.7, the same shape as `module`'s.
- `module` and `catalogService`: `x-openregister-merge` with `statusField` `recordStatus`, `survivorStatus` Active, `mergedStatus` Merged, `reversalWindowDays` 30.

Rejected: mounting the dead `MergeObject.vue` for applications. It is exactly the app-local merge tool ADR-045 forbids, and it calls OpenRegister's older per-object merge (`stackiqPlugin.js:1418`) without preview or reversal.

### D2. The catalogue links to OpenRegister's page instead of hosting one

The steward opens Find duplicates on `/modules`, `/diensten` or `/organisaties` and lands on OpenRegister's `/duplicates` page (`src/manifest.json:415` in OpenRegister), which lists pairs and runs the merge wizard. The action shows for Nextcloud admins and functional administrators. On the two faceted pages it is an entry in the toolbar's `NcActions` next to Saved views (`src/views/FacetedCatalogIndexView.vue:68`); on `Organisaties` it is a `headerActions` entry with a handler, the pattern the Integrations page uses (`src/manifest.d/connection-registry.json:37` to `:43`), because a manifest `navigate` only pushes a route inside stackiq.

### D3. Stackiq re-points catalogue references after OpenRegister merges

OpenRegister's merge relinks one reverse reference per schema (`relinkReverseFk()`, `MergeService.php:684`), meant for source records. A module is referenced from eleven places in the register: `suite.applications`, `catalogService.modules`, `vulnerability.modules`, `usage.module`, `usage.plannedReplacement`, `connection.moduleA`, `connection.moduleB`, `connection.realisedWithIntermediaryModule`, `software-review.modules`, `compliancy.module` and `moduleVersion.module`. A service from five: `usage.diensten`, `catalogContract.service`, `connection.service`, `software-review.diensten` and `module.diensten`. `CatalogueMergeRelinker` listens to `ObjectsMergedEvent` (`getSurvivorUuid()`, `getMergedFromUuids()`, `getMergeOperationId()`), walks `CatalogueReferenceMap` for the merged schema, replaces each loser uuid by the survivor in scalar and array fields (dropping a duplicate that the replacement creates in an array), sets `mergedInto` on the losers, and writes one audit entry per moved reference with the merge operation id.

This is the domain half ADR-045 leaves to the app: which fields point at an application. The generic half, relinking every `$ref` inside the merge unit so a reversal restores it, is OpenRegister's, named in the proposal's Out of scope. When it lands the listener's walk becomes a no-op and can go.

Rejected: declaring `x-openregister-merge` `sourceLink` for one of the references. It would move only that one, and it is meant for source records feeding a golden record, not for catalogue relations.

### D4. One reference map for both merges

`MergeOrganisatieService` keeps its own engine for organisations, because it also moves Nextcloud group membership (`:570`), which OpenRegister's engine does not. Its relation list moves into `CatalogueReferenceMap` next to the module and service lists, and gains `module.provider`, `catalogService.provider`, `usage.provider`, `organization.deelnames`, `organization.participants` and `model.organizations`. `tests/Unit/Service/CatalogueReferenceMapTest.php` reads the merged register and fails when a `$ref` to `module`, `catalogService` or `organization` exists that the map does not list, so a new reference cannot be forgotten again.

### D5. Merged records leave the lists but stay reachable

`recordStatus` defaults to Active; a repair step sets it on existing rows so no row is left empty. `FacetService::fetchBaseObjects()` drops Merged objects, so `/modules`, `/diensten` and their facet counts leave them out; the manifest pages that filter by a value list are not used, because a value filter that matches no stored value hides every row (the lesson in the `Organisaties` page note, `src/manifest.json:396`). A merged record keeps its detail page, and `MergedRecordBanner` says Merged into and links to the survivor.

## Declarative versus imperative

- Duplicate detection and the merge are declarative: `x-openregister-dedup` and `x-openregister-merge` on the schemas (ADR-031, ADR-045).
- The reference walk after a merge is imperative, in one listener, because no OpenRegister rule relinks arbitrary references yet (D3).
- The Merged into banner is a relation shown from a field, which a built-in widget cannot condition on the status, so it is a small body widget.

## Seed data

`module` and `catalogService` gain `recordStatus` and `mergedInto`. The demo descriptor `lib/Settings/stackiq_mock_register.json` sets `recordStatus: Active` on the existing modules and services, and adds one likely duplicate in the `stackiq` register so the candidates page has a pair on a fresh instance:

| `@self.slug` | `name` | `provider` | `website` | `recordStatus` |
|---|---|---|---|---|
| module-voorbeeld-name-1-1 (existing) | Voorbeeld Name 1 | `@ref:organization-voorbeeld-name-2-2` (set here if `insight-supplier-facet` has not) | https://example.invalid/resource/0 | Active |
| module-duplicate-1 | Voorbeeld name 1 | `@ref:organization-voorbeeld-name-2-2` | https://example.invalid/resource/0 | Active |

Name normalized, provider and website match, so the pair scores 1.0 against the 0.7 threshold.

## Risks

- **Reversal leaves references on the survivor** until OpenRegister relinks inside the merge unit. The audit entries list every move with the merge operation id, so a steward can put them back by hand.
- **Federation mirrors.** A merged-away mirror returns on the next pull (`FederationMerger` plans by peer id). The docs tell the steward to keep the mirror as survivor or dismiss the pair.
- **Rights.** OpenRegister's merge checks the caller's rights on both objects; the listener writes with the same rights the organisation merge uses today and only touches references to the two merged objects.
