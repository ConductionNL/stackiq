---
kind: code
depends_on: []
---

# softwarecatalog — bound unbounded `searchObjects()` full-table scans

## Why

`ObjectService::searchObjects()` builds its result set with
`$queryBuilder->setMaxResults($limit)` where
`$limit = $query['_limit'] ?? null` (`openregister/lib/Db/MagicMapper/MagicSearchHandler.php:195,268-269`).
Doctrine's `setMaxResults(null)` means **no LIMIT clause at all** — every
call site that omits `_limit` fetches and hydrates the *entire* magic table
for that register/schema into PHP memory on every request. This is the
opposite failure mode from the more common "default-limit truncates
results" bug: here, forgetting `_limit` silently removes the safety net.

A repo-wide audit of every non-test `searchObjects()` call site in
`softwarecatalog/lib/` found **25 of 29 call sites never set `_limit`**
(verified by grepping the 20 lines preceding each call for `_limit`; the 4
exceptions are `ArchiMateService.php:378/397/412/435`, which do set an
explicit `_limit`). These are full, unbounded register/schema scans on
every invocation, several on request paths that run on every dashboard
load, every ArchiMate view render, or every contact/organisation sync tick:

- `lib/Service/ViewService.php:270` — "all view objects" for the view index
  (runs on every ArchiMate view list load).
- `lib/Service/ViewService.php:666` — "all modules for a schema" used to
  build a lookup index for product/module enrichment per node.
- `lib/Service/ViewService.php:771` — gebruik (usage) items for enrichment.
- `lib/Service/ViewService.php:1152` — deelnames (participations) items.
- `lib/Service/ArchiMateService.php:2022` — general object filter search.
- `lib/Service/ModuleComplianceService.php:307,588` — compliance matrix
  data, rebuilt per assessment view.
- `lib/Service/ContactpersoonService.php:801` — contact person search.
- `lib/Service/ModuleVersionService.php:157` — existing module versions
  lookup (runs per version-bump check).
- `lib/Service/ModerationService.php:96` — moderation queue listing.
- `lib/Service/ContractApprovalService.php:411`,
  `lib/Service/ContractStatusService.php:138` — contract listings.
- `lib/Service/IntakeService.php:235` — intake match search.
- `lib/Service/GebruikSyncService.php:345` — AMEF element lookup by ID list.
- `lib/Service/AangebodenGebruikService.php:818,1336,1362` — usage/suite/
  module listings.
- `lib/Service/Federation/FederationService.php:498` — federated catalog
  sync search.
- `lib/Service/OrganizationSyncService.php:830,1276,2032,2047` — org sync
  and related-contacts lookups, run on every sync tick.
- `lib/Service/SettingsService.php:4462`, `lib/Service/ArchiMateImportService.php:779`
  — settings/import object lookups.
- `lib/EventListener/UserProfileUpdatedEventListener.php:348,388` — runs on
  **every** Nextcloud user profile update event, unbounded scanning
  contact-person objects each time.

On a catalog instance with a non-trivial AMEF register (hundreds to
thousands of elements/modules/gebruiks — which is the expected steady
state for a municipality-scale software catalog), every one of these code
paths does a full-table fetch-and-hydrate. The `UserProfileUpdatedEventListener`
case is the worst: it fires on an event outside the app's own request
lifecycle (any NC profile edit, by anyone), turning an unrelated action
into a full-register scan.

## What Changes

- Add an explicit `_limit` (with a sane page size, e.g. 200–500 depending
  on call site, or the register's realistic upper bound) to every listed
  call site, OR switch the call site to `searchObjectsPaginated()` where
  the caller already needs page/offset semantics.
- For call sites that structurally need "all matching objects" (e.g. index
  building for O(1) lookup), set an explicit, intentional `_limit` at a
  documented safe ceiling (mirroring the existing `ArchiMateService.php`
  pattern of `_limit` on the AMEF queries) rather than leaving `_limit`
  unset, so the omission cannot silently regress to unbounded again.
- `UserProfileUpdatedEventListener` MUST bound its contact-person lookup
  query (it is looking for a specific user's contact record, not fetching
  the register) — verify it can filter directly on the profile identifier
  instead of a broad scan.
- No behavior change is intended for correctness (all call sites already
  handle "no more results" gracefully) — this is a memory/latency bound,
  not a new feature. NOT BREAKING for API consumers.

## Non-goals

- Not touching `ArchiMateExportService.php:860,906`, which already set an
  explicit `_limit: 10000` — those are bounded (if generously) by design
  for full-catalog export.
- Not re-architecting pagination UX; this only adds a bound where none
  exists today.
