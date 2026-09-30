---
kind: code
depends_on: []
---

# Find and merge duplicate applications and services through OpenRegister

## Summary

The same application arrives twice: once typed by a supplier, once from a federation peer or an import, spelled a little differently. Today a Nextcloud admin can merge two organisations in stackiq, and nobody can merge two applications or two services. OpenRegister already finds duplicate candidates from the rules stackiq declares, and already merges two objects reversibly. This change declares those rules and the merge settings on applications and services, links the catalogue pages to OpenRegister's duplicate candidates page, re-points the catalogue's references when OpenRegister merges, hides merged records, and fixes the organisation merge, which leaves a merged supplier's applications and services behind.

## Why

This change covers two matrix rows.

- `stackiq:ops-reconciliation`, "Deduplicate and reconcile records that arrive from several sources." Stackiq rates itself partial: an admin can merge duplicate organisations with a dry run, and nothing deduplicates applications or reconciles records from several sources. SAP LeanIX rates yes: "import software records from ServiceNow as aggregated software fact sheets" (https://help.sap.com/docs/leanix/ea/aggregation-and-linkage-of-software-records), with custom matching to avoid "duplicate fact sheets" (https://help.sap.com/docs/leanix/ea/matching-rules). BlueDolphin rates yes: "for each record an object will be created or merged with an already existing object" (https://help.bluedolphin.io/en/articles/11967635-four-things-you-need-to-know-before-you-start-importing-sources). GLPI rates yes from its source at 11.0.9: `src/RuleImportAsset.php:46` import and link rules match incoming records to existing assets, `src/RuleDictionnarySoftware.php:44` normalises software names from different sources, and duplicates can be merged afterwards (`src/Software.php:1011`).
- `stackiq:land-duplicate-merge`, "Merge two catalogue entries that turn out to describe the same thing." Stackiq rates itself partial: only organisations can be merged, and only by a Nextcloud admin. GLPI rates yes from its source: `src/Software.php:926` lists same-named software as merge candidates and `src/Software.php:1011` moves versions and licences into the kept entry. This row is below the bar on its own and rides with `stackiq:ops-reconciliation`: its missing half is merging applications and services, which is the merge this change extends.

`ops-reconciliation` is partial and built: this change builds deduplication of applications and reconciliation of records from several sources.

## What stackiq has today

Read at development 49e65cb4, with OpenRegister development 4fee776.

- `lib/Controller/MergeController.php:106` `execute()` and `:82` `dryRun()` merge one organisation into another, admin only (`:142`), through `lib/Service/MergeOrganisatieService.php`. The panel `src/components/organisations/OrganisationMergePanel.vue:46` shows its controls to admins only, mounted on `OrganisatieDetail` as `org-merge` (`src/manifest.json:430`).
- That merge re-points the relations listed in `FIELD_RELATION_TYPES` (`lib/Service/MergeOrganisatieService.php:111`: `usage.consumer`, `usage.participants`, `contactPerson.organization`, `connection.provider`) and `@self.organisation` on `catalogContract` and `compliancy` (`:122`). It misses `module.provider`, `catalogService.provider`, `usage.provider`, `organization.deelnames`, `organization.participants` and `model.organizations`, all of which reference an organisation in the register. A merged supplier's applications and services keep pointing at the tombstone.
- `src/modals/object/MergeObject.vue` is a generic merge modal, mounted only for the modal id `mergeOrganisatie` (`src/modals/Modals.vue:11`), which nothing sets.
- `lib/Service/Federation/FederationMerger.php` reconciles a peer's entries with that peer's own mirrors, by peer id. It never compares a mirror with a local record, so a peer's copy of a local application stays a second record.
- `module` (`lib/Settings/softwarecatalogus_register.json:7346`) and `organization` (`:2585`) declare `x-openregister-dedup` in their `configuration`. OpenRegister reads exactly that key (`lib/Service/Quality/DuplicateDetectionService.php:587` and `:588` in OpenRegister) and serves candidate pairs at `GET /api/objects/duplicates/{register}/{schema}` and on its page `/duplicates` (`src/views/quality/DuplicatesIndex.vue`). So the rules are wired in OpenRegister and dormant in stackiq: no stackiq page links to them. `catalogService` declares none. No schema declares `x-openregister-merge`.
- OpenRegister's merge engine (`lib/Service/Merge/MergeService.php`, routes `/api/objects/merge/preview`, `/execute` and `/{id}/reverse`) snapshots, flips the loser's status, records a `mergeOperation`, allows reversal within a window, and dispatches `ObjectsMergedEvent`. It relinks one reverse reference per schema (`relinkReverseFk()`, `:684`), meant for source records, not every catalogue reference to a module.

## What this change builds

- `x-openregister-dedup` on `catalogService`, next to the existing rules on `module` and `organization`.
- `x-openregister-merge` on `module` and `catalogService`, with a new `recordStatus` (Active, Merged) and `mergedInto` on both.
- A Find duplicates action on the Applications, Services and Organisations pages, for admins and functional administrators, that opens OpenRegister's Duplicate candidates page.
- A listener on `ObjectsMergedEvent` that re-points every catalogue reference to a merged application or service onto the survivor and sets `mergedInto`.
- Merged applications and services left out of the Applications and Services lists and facets, and a Merged into banner on their detail page.
- The organisation merge re-points the six missing references.
- The unused `MergeObject.vue` modal and its `mergeOrganisatie` branch go.

## Out of scope

- Relinking every reference inside OpenRegister's merge unit, so that a reversal restores them too. That is OpenRegister's half (ADR-045: relink and reverse on any schema). Until it lands, stackiq's listener re-points references after the merge (D3).
- Opening OpenRegister's Duplicate candidates page on a given register and schema from a link. The page has no query parameters today; the steward picks the register and schema there. That is OpenRegister's half.
- Moving the organisation merge onto OpenRegister's engine. The stackiq merge also moves Nextcloud group membership (`migrateGroupMembership()`, `:570`), which OpenRegister's engine does not do.
- Matching rules for imports and federation pulls at the moment a record arrives (`x-openregister-dedup` `onCreate`). A record is created and then surfaces as a candidate.
- Bulk merges of more than two records at once.

## Risks

- Until OpenRegister relinks inside the merge unit, a reversal restores the two records but leaves the references stackiq re-pointed on the survivor. The listener writes each move to the audit log, and the docs say so.
- A federation mirror merged away comes back on the next pull, because `FederationMerger` plans updates by peer id. The docs tell the steward to keep the mirror as survivor or dismiss the pair.
- New properties on `module` and `catalogService`: both schema versions and the register version must go up, or the import skips the change.
