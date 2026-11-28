# Tasks — mcp-full-action-surface

## 1. Derived layer (register fragment)

- [x] 1.1 Add `lib/Settings/register.d/mcp-full-action-surface.json`:
  `configuration.x-openregister-mcp` with `search`/`get` (`scope: read`,
  honest hints, explicit `reach: user`) on the 14 schemas in design.md §3,
  plus `create`/`update` (`reach: instance`) on `vulnerability` only.
  Assert (script or PHPUnit) that every schema key in the fragment exists
  in the HEAD monolith — none of the retired Dutch slugs — and validate
  with `python3 -m json.tool`.
- [x] 1.2 Re-derive every `search.filters` list from the HEAD `properties`
  maps (design.md table — every name verified against HEAD at
  proposal time; re-verify at apply time); the merged register must pass
  `McpAnnotationValidator` with zero unknown-filter errors.
- [x] 1.3 Agent-facing English `description` prose per verb per schema
  (what the LLM reads to choose the tool), reusing the superseded
  change's descriptions where the schema survived the rename.
- [x] 1.4 Import on the dev instance and verify the derived tool listing:
  28 read tools + `vulnerability.create`/`.update`, and no write tool on
  any lifecycle-governed schema.
  > Live 25 Nov 2026 (sandbox Nextcloud 34, PostgreSQL 16, OpenRegister main, register imported by the repair step): MCP `tools/list` as admin lists 66 tools; the derived stackiq ones are `<schema>_search` and `<schema>_get` on exactly the 14 schemas (compliancy, module, usage, software-review, sbomComponent, bioMeasure, contactPerson, suite, vulnerability, moduleVersion, catalogService, organization, connection, catalogContract), 28 read tools, plus `vulnerability_create` and `vulnerability_update`; no other create, update or delete tool on any stackiq schema. The 29 curated tools of `StackiqToolProvider` are listed beside them. (OpenRegister's MCP sessions need `memcache.distributed`; the sandbox used APCu.)

## 2. Provider skeleton

- [x] 2.1 Add `lib/Mcp/StackiqToolProvider.php`
  (`OCA\Stackiq\Mcp`, implements
  `OCA\OpenRegister\Mcp\IMcpToolProvider`): descriptor constant
  (id, name, description, inputSchema, scope, reach, hints) + dispatch
  table; no business logic (decidesk `DecideskToolProvider` shape).
- [x] 2.2 Register the DI alias
  `OCA\OpenRegister\Mcp\IMcpToolProvider::stackiq` in
  `lib/AppInfo/Application.php` (mirror
  `decidesk/lib/AppInfo/Registrar/DomainServiceRegistrar.php:121`).
- [x] 2.3 Add `lib/Mcp/McpArgumentValidator.php` (port of decidesk's:
  typed required/optional argument checking, validation error before any
  service call).

## 3. Authorisation gates

- [x] 3.1 Add `lib/Mcp/McpContractGate.php`: load contract →
  `not_found` → `ContractApprovalService::authorizeSubmit(contractUuid,
  groupNames, activeOrgUuid)`; helpers return real booleans, never
  wrapped in `catch(\Throwable)` (decidesk `McpMeetingGate` rules).
- [x] 3.2 Add `lib/Mcp/McpPublicationGate.php` reusing the
  `PublicationController::authorizeEntry()` semantics via
  `PublicationService::resolveEntry()` (admin OR owning
  `aanbod-beheerder`).
- [x] 3.3 Admin gates for moderation/merge/EOL/membership tools via
  `IGroupManager::isAdmin()` — identical posture to the REST twins.

## 4. Read tools

- [x] 4.1 Implement the curated read handlers (design.md §5 read table):
  `getMyContactProfile`, `listOffers`, `listOfferedUsages`,
  `getPortfolioReport` (reject foreign org uuid for non-admins),
  `listPendingModerations`, `getReviewAggregate`,
  `getContractApprovalConfig`, `getSbomImportStatus`
  (`userCanReadModule()` gate), `listViews`, `getView`,
  `previewOrganisationMerge` (dryRun only), `getEolSyncStatus`.

## 5. Write tools

- [x] 5.1 Contract seam: `submitContractApproval`, `submitContractRenewal`
  → `ContractApprovalService::submitForApproval()` behind
  `McpContractGate`.
- [x] 5.2 Publication seam: `publishObject`, `depublishObject` →
  `PublicationService::publish()/depublish()` behind
  `McpPublicationGate`; descriptors declare `reach: external`.
- [x] 5.3 Moderation/review/intake: `approveRegistration`,
  `rejectRegistration` (`ModerationService`), `submitReview`
  (`ReviewService::submit()` — pending forced server-side),
  `registerOrganisation` (`IntakeService::validate()` + `submit()`).
- [x] 5.4 Offers/usages/membership/merge: `acceptOffer`, `declineOffer`
  (`AanbodService`), `claimUsage`, `declineUsage`
  (`AangebodenGebruikService`), `grantOrganisationMembership`,
  `revokeOrganisationMembership` (extract the
  `OrganisationMembersController` grant/revoke logic into a small
  service, or defer these two tools — record the decision here),
  `mergeOrganisations` (`MergeOrganisatieService::execute()`,
  admin-gated, `destructiveHint: true`).
- [x] 5.5 Ops: `importSbom` (`SbomImportService::importForModuleVersie()`,
  inline payload), `triggerEolSync` (`EolSyncService::run()`,
  `reach: external`).

## 6. Tests

- [x] 6.1 PHPUnit descriptor fixture: every entry has `scope` + `reach`
  from the closed vocabularies; reads are `readOnlyHint: true`;
  publication + EOL tools are `reach: external`; write set matches
  design.md exactly.
- [x] 6.2 PHPUnit per gate: non-owning caller → forbidden AND delegate
  never called AND no `DecisionRequestedEvent` (mirror
  `contract-approval-ownership-guard`'s cases at the MCP seam);
  owning/admin caller passes through.
- [x] 6.3 PHPUnit validator ladder: missing/badly-typed argument →
  validation error, zero service calls.
- [x] 6.4 PHPUnit `submitReview` pending-forcing;
  `previewOrganisationMerge` never reaches `execute()`.
- [x] 6.5 PHPUnit fragment/merge: schema key set unchanged after merge
  (no orphan Dutch slugs); vulnerability the only schema with write verbs.
- [x] 6.6 `composer check:strict` clean (PHPCS, PHPMD, Psalm, PHPStan) —
  fix pre-existing issues encountered in touched files.

## Build notes

Built in a first slice: 2.1 to 2.3, 3.1, 3.3 and 5.1, plus `stackiq.getContractApprovalConfig` from 4.1.
- `StackiqToolProvider` holds the descriptor constant and the dispatch table.
- The alias in `Application::register()` is a pair of literals, so nothing autoloads at boot.
- `McpContractGate` feeds `ContractApprovalService::authorizeSubmit()` the caller's groups and active organisation exactly as `ContractApprovalController` does. A contract that cannot be read is refused as forbidden, as the REST route answers 403, rather than reported as not_found.
- `McpAdminGate` denies on any failing admin check.
- `tests/Unit/Mcp/McpContractToolsTest.php` covers the non-owning, owning, admin, unauthenticated, bad-argument and unconfigured cases.

Second slice (continuation), the derived fragment:
- The slugs moved again after the proposal: `service`, `contract` and `assessment` are `catalogService`, `catalogContract` and `software-review` at HEAD, so the derived ids are `stackiq.catalogService.*`, `stackiq.catalogContract.*` and `stackiq.software-review.*`. Every filter in the design table was re-verified against those properties and all exist.
- The annotation dialect (`McpVerbAnnotation`) refuses any key besides description, scope, filters and the three hints, so the fragment cannot write `reach`. OpenRegister's `ToolReachResolver` infers `user` for a derived `search`/`get` id and `instance` for `create`/`update`, which is the reach design.md §3 and §4 ask for.
- `tests/Unit/Mcp/McpFragmentTest.php` checks the fragment against the merged register: same schema key set with and without it, no retired slug, every filter a real property, the closed verb/scope vocabulary, write verbs on `vulnerability` only, the AMEF schemas and `sector` unannotated.
- 1.4 (import on a dev instance) is a live task and stays open.

Third slice (continuation), the curated tools:
- `McpPublicationGate` (3.2) runs `PublicationController::authorizeEntry()`'s steps in its order: signed in, publishable type, entry resolves, not peer-sourced, then an administrator or an `aanbod-beheerder` whose active organisation owns the entry.
- `McpSbomGate` holds `SbomController`'s two guards: reading the status needs an administrator or read access to the parent application (refused as not_found), importing needs an administrator or a manage group member who can read the parent application.
- `McpReadTools` (4.1) and `McpWriteTools` (5.2, 5.3, 5.5 and the offer, usage and merge tools of 5.4) follow the ladder argument validation, caller, guard, service. The offer and usage services check the active organisation themselves, as they do for the REST routes. `getEolSyncStatus` and `triggerEolSync` are administrator-only, as their REST routes are (no access attribute). `listPendingModerations` and the moderation writes need an administrator. `getMyContactProfile` returns what `ContactpersonenController::getMe()` answers, so `/api/me` and the tool cannot drift.
- 5.4 decision: `grantOrganisationMembership` and `revokeOrganisationMembership` are deferred. Their guard (`authorizeMaintainer()` with the caller-organisation lookup) and the join/leave calls live in private methods of `OrganisationMembersController`; extracting them is a refactor of a live access check that belongs in its own change. The provider answers `unknown_tool` for both.
- `registerOrganisation` needs a signed-in caller, narrower than the anonymous intake route.
- Tests: `McpToolCatalogueTest` (6.1, the grant matrix and dispatch), `McpGatesTest` (6.2), `McpCuratedToolsTest` (6.2 to 6.4: the validator ladder with zero service calls, anonymous and non-admin refusals, review pending, the merge preview never reaching `execute()`).
- 6.6: lint, PHPCS, PHPMD (with the baseline), Psalm and PHPStan are clean on `lib/Mcp`.

Left open: 1.4 (import on a dev instance and check the derived listing, a live task) and 7.2 (spec sync, done on archive).

## 7. Supersession + spec/docs

- [x] 7.1 Archive `stackiq-mcp-adoption` as superseded by this
  change (pointer in its archive note); its
  `register.d/stackiq-mcp-adoption.json` is never created.
  Ticked (decision 399): archived early as `openspec/changes/archive/2026-11-12-stackiq-mcp-adoption` with the superseded note at the top of its proposal; no `register.d/stackiq-mcp-adoption.json` exists.
- [x] 7.2 Sync this change's spec delta into
  `openspec/specs/mcp-tool-surface/spec.md` on archive.
  > Synced 25 Nov 2026: seven requirements, titles unchanged so the `@spec` anchors keep resolving.
- [x] 7.3 CHANGELOG entry under Unreleased: full MCP action surface
  (28 derived read tools, vulnerability writes, ~29 curated tools) for
  hermiq consumption.
