# Tasks — bound-unbounded-searchobjects-scans

## 1. High-traffic view/dashboard paths

- [ ] 1.1 `lib/Service/ViewService.php:270` — add `_limit` to the "all view
  objects" query (view index load).
- [ ] 1.2 `lib/Service/ViewService.php:666` — add `_limit` to the module
  lookup-index query.
- [ ] 1.3 `lib/Service/ViewService.php:771` — add `_limit` to the gebruik
  enrichment query.
- [ ] 1.4 `lib/Service/ViewService.php:1152` — add `_limit` to the deelnames
  enrichment query.
- [ ] 1.5 `lib/Service/ModuleComplianceService.php:307` and `:588` — add
  `_limit` to the compliance-matrix data queries.

## 2. Service listing/search paths

- [ ] 2.1 `lib/Service/ArchiMateService.php:2022` — add `_limit`.
- [ ] 2.2 `lib/Service/ContactpersoonService.php:801` — add `_limit`.
- [ ] 2.3 `lib/Service/ModuleVersionService.php:157` — add `_limit`.
- [ ] 2.4 `lib/Service/ModerationService.php:96` — add `_limit`.
- [ ] 2.5 `lib/Service/ContractApprovalService.php:411` and
  `lib/Service/ContractStatusService.php:138` — add `_limit`.
- [ ] 2.6 `lib/Service/IntakeService.php:235` — add `_limit`.
- [ ] 2.7 `lib/Service/GebruikSyncService.php:345` — add `_limit` (this call
  filters by a caller-supplied ID list; bound to `count($ids)` or a safe
  ceiling, whichever is smaller).
- [ ] 2.8 `lib/Service/AangebodenGebruikService.php:818`, `:1336`, `:1362` —
  add `_limit` to each.

## 3. Sync/federation background paths

- [ ] 3.1 `lib/Service/Federation/FederationService.php:498` — add `_limit`
  (page the sync if it can exceed one bounded fetch).
- [ ] 3.2 `lib/Service/OrganizationSyncService.php:830`, `:1276`, `:2032`,
  `:2047` — add `_limit` to each; convert to `searchObjectsPaginated()` and
  loop if a sync tick must genuinely cover every object.
- [ ] 3.3 `lib/Service/SettingsService.php:4462` — add `_limit`.
- [ ] 3.4 `lib/Service/ArchiMateImportService.php:779` — add `_limit`.

## 4. Event listener (highest-risk path — fires outside app's own requests)

- [ ] 4.1 `lib/EventListener/UserProfileUpdatedEventListener.php:348` and
  `:388` — replace the broad scan with a query filtered directly on the
  updated user's identifier (this listener is looking for ONE contact
  record, not the whole register); add `_limit` regardless as a backstop.

## 5. Verification

- [ ] 5.1 PHPUnit: for at least one representative call site per service
  (`ViewService`, `OrganizationSyncService`, `UserProfileUpdatedEventListener`),
  assert the query array passed to `searchObjects()` contains `_limit`.
- [ ] 5.2 Manual/log verification: confirm no call site listed in the
  proposal was missed (re-run the `_limit`-proximity grep from the
  proposal and confirm 0 remaining unbounded call sites among the ones
  enumerated).
- [ ] 5.3 Run `composer check:strict` (PHPCS/PHPMD/Psalm/PHPStan) — no new
  violations.
