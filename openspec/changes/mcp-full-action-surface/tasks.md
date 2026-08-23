# Tasks — mcp-full-action-surface

## 1. Derived layer (register fragment)

- [ ] 1.1 Add `lib/Settings/register.d/mcp-full-action-surface.json`:
  `configuration.x-openregister-mcp` with `search`/`get` (`scope: read`,
  honest hints, explicit `reach: user`) on the 14 schemas in design.md §3,
  plus `create`/`update` (`reach: instance`) on `vulnerability` only.
  Assert (script or PHPUnit) that every schema key in the fragment exists
  in the HEAD monolith — none of the retired Dutch slugs — and validate
  with `python3 -m json.tool`.
- [ ] 1.2 Re-derive every `search.filters` list from the HEAD `properties`
  maps (design.md table — every name verified against HEAD at
  proposal time; re-verify at apply time); the merged register must pass
  `McpAnnotationValidator` with zero unknown-filter errors.
- [ ] 1.3 Agent-facing English `description` prose per verb per schema
  (what the LLM reads to choose the tool), reusing the superseded
  change's descriptions where the schema survived the rename.
- [ ] 1.4 Import on the dev instance and verify the derived tool listing:
  28 read tools + `vulnerability.create`/`.update`, and no write tool on
  any lifecycle-governed schema.

## 2. Provider skeleton

- [ ] 2.1 Add `lib/Mcp/StackiqToolProvider.php`
  (`OCA\Stackiq\Mcp`, implements
  `OCA\OpenRegister\Mcp\IMcpToolProvider`): descriptor constant
  (id, name, description, inputSchema, scope, reach, hints) + dispatch
  table; no business logic (decidesk `DecideskToolProvider` shape).
- [ ] 2.2 Register the DI alias
  `OCA\OpenRegister\Mcp\IMcpToolProvider::stackiq` in
  `lib/AppInfo/Application.php` (mirror
  `decidesk/lib/AppInfo/Registrar/DomainServiceRegistrar.php:121`).
- [ ] 2.3 Add `lib/Mcp/McpArgumentValidator.php` (port of decidesk's:
  typed required/optional argument checking, validation error before any
  service call).

## 3. Authorisation gates

- [ ] 3.1 Add `lib/Mcp/McpContractGate.php`: load contract →
  `not_found` → `ContractApprovalService::authorizeSubmit(contractUuid,
  groupNames, activeOrgUuid)`; helpers return real booleans, never
  wrapped in `catch(\Throwable)` (decidesk `McpMeetingGate` rules).
- [ ] 3.2 Add `lib/Mcp/McpPublicationGate.php` reusing the
  `PublicationController::authorizeEntry()` semantics via
  `PublicationService::resolveEntry()` (admin OR owning
  `aanbod-beheerder`).
- [ ] 3.3 Admin gates for moderation/merge/EOL/membership tools via
  `IGroupManager::isAdmin()` — identical posture to the REST twins.

## 4. Read tools

- [ ] 4.1 Implement the curated read handlers (design.md §5 read table):
  `getMyContactProfile`, `listOffers`, `listOfferedUsages`,
  `getPortfolioReport` (reject foreign org uuid for non-admins),
  `listPendingModerations`, `getReviewAggregate`,
  `getContractApprovalConfig`, `getSbomImportStatus`
  (`userCanReadModule()` gate), `listViews`, `getView`,
  `previewOrganisationMerge` (dryRun only), `getEolSyncStatus`.

## 5. Write tools

- [ ] 5.1 Contract seam: `submitContractApproval`, `submitContractRenewal`
  → `ContractApprovalService::submitForApproval()` behind
  `McpContractGate`.
- [ ] 5.2 Publication seam: `publishObject`, `depublishObject` →
  `PublicationService::publish()/depublish()` behind
  `McpPublicationGate`; descriptors declare `reach: external`.
- [ ] 5.3 Moderation/review/intake: `approveRegistration`,
  `rejectRegistration` (`ModerationService`), `submitReview`
  (`ReviewService::submit()` — pending forced server-side),
  `registerOrganisation` (`IntakeService::validate()` + `submit()`).
- [ ] 5.4 Offers/usages/membership/merge: `acceptOffer`, `declineOffer`
  (`AanbodService`), `claimUsage`, `declineUsage`
  (`AangebodenGebruikService`), `grantOrganisationMembership`,
  `revokeOrganisationMembership` (extract the
  `OrganisationMembersController` grant/revoke logic into a small
  service, or defer these two tools — record the decision here),
  `mergeOrganisations` (`MergeOrganisatieService::execute()`,
  admin-gated, `destructiveHint: true`).
- [ ] 5.5 Ops: `importSbom` (`SbomImportService::importForModuleVersie()`,
  inline payload), `triggerEolSync` (`EolSyncService::run()`,
  `reach: external`).

## 6. Tests

- [ ] 6.1 PHPUnit descriptor fixture: every entry has `scope` + `reach`
  from the closed vocabularies; reads are `readOnlyHint: true`;
  publication + EOL tools are `reach: external`; write set matches
  design.md exactly.
- [ ] 6.2 PHPUnit per gate: non-owning caller → forbidden AND delegate
  never called AND no `DecisionRequestedEvent` (mirror
  `contract-approval-ownership-guard`'s cases at the MCP seam);
  owning/admin caller passes through.
- [ ] 6.3 PHPUnit validator ladder: missing/badly-typed argument →
  validation error, zero service calls.
- [ ] 6.4 PHPUnit `submitReview` pending-forcing;
  `previewOrganisationMerge` never reaches `execute()`.
- [ ] 6.5 PHPUnit fragment/merge: schema key set unchanged after merge
  (no orphan Dutch slugs); vulnerability the only schema with write verbs.
- [ ] 6.6 `composer check:strict` clean (PHPCS, PHPMD, Psalm, PHPStan) —
  fix pre-existing issues encountered in touched files.

## 7. Supersession + spec/docs

- [ ] 7.1 Archive `stackiq-mcp-adoption` as superseded by this
  change (pointer in its archive note); its
  `register.d/stackiq-mcp-adoption.json` is never created.
- [ ] 7.2 Sync this change's spec delta into
  `openspec/specs/mcp-tool-surface/spec.md` on archive.
- [ ] 7.3 CHANGELOG entry under Unreleased: full MCP action surface
  (28 derived read tools, vulnerability writes, ~29 curated tools) for
  hermiq consumption.
