# Design — mcp-full-action-surface

## 1. Positioning against `stackiq-mcp-adoption`

That change (active, unimplemented, `.openspec.yaml: schema: conduction`,
created 2026-07-13) is the read-only declarative half of this surface. Its
reasoning is kept; its artefacts cannot be applied as written:

| Its assumption | State at HEAD | Consequence |
|---|---|---|
| Slugs `moduleVersie`, `dienst`, `organisatie`, `contactpersoon`, `koppeling`, `gebruik` | Renamed to `moduleVersion`, `service`, `organization`, `contactPerson`, `connection`, `usage` | Its fragment would deep-merge 8 orphan schemas into the register (ADR-037 creates keys it cannot match) — worse than failing loudly |
| Dutch filter names (`naam`, `aanbieder`, `licentietype`, `standaardGemma`, `afnemer`…) | Properties are English (`name`, `provider`, `licentietype` DOES survive on `module`, but e.g. `naam` → `name`, `standaardGemma` → `standardGemma`, `afnemer` → `consumer`) | `McpAnnotationValidator` would reject — every filter list must be re-derived from the HEAD `properties` maps |
| `kwetsbaarheid`/`beoordeeling` excluded as "dead schemas" | `vulnerability` and `assessment` are live: manifest pages `Kwetsbaarheden`/`KwetsbaarheidDetail`, `Reviews`/`ReviewDetail`; `ReviewService`/`ModerationService`; `register.d/catalog-ratings.json` moderation fields | Both belong in the read surface; `vulnerability` is also the safe derived-write candidate |
| Write tools deferred (`DEFERRED_QUESTIONS`) | The concrete need now exists (hermiq grant model + chat commanding) | This change is the deferred `kind: code` follow-up it named |

**Disposition:** this change supersedes it. Archive
`stackiq-mcp-adoption` as superseded-by `mcp-full-action-surface`
when this lands; do not apply its fragment first.

## 2. Architecture

```
hermiq agent (default-deny grants, scope × reach, approval gate, audit)
  -> OpenRegister /api/mcp (JSON-RPC) / chat facade
    -> SchemaDerivedToolProvider           <- register.d/mcp-full-action-surface.json  (layer A+B)
    -> IMcpToolProvider::stackiq   <- lib/Mcp/StackiqToolProvider.php  (layer C)
         dispatcher only; per tool:
           McpArgumentValidator -> per-object gate -> existing workflow service
```

Fleet reference: `decidesk/lib/Mcp/` — `DecideskToolProvider` (dispatcher
with a `TOOL_DESCRIPTORS` constant so unit tests assert the catalogue as a
fixture), `McpArgumentValidator`, `McpMeetingGate` (the single
"load object, prove the caller may touch it" ladder: argument validation →
load → not_found → authorise, auth helpers that return real booleans and
are never wrapped in `catch(\Throwable)`), `McpMeetingScopeResolver`.
Software Catalog ports the shape: `McpContractGate` (wraps the existing
`ContractApprovalService::authorizeSubmit(contractUuid, groupNames,
activeOrgUuid)` — the IDOR guard from `contract-approval-ownership-guard`),
`McpPublicationGate` (wraps `PublicationController::authorizeEntry()`
semantics via `PublicationService::resolveEntry()` + the admin /
`aanbod-beheerder` organisation match), and admin checks via
`IGroupManager::isAdmin()`. **Rule: the MCP layer adds no new authority —
every tool runs exactly the guard its REST twin runs.**

DI alias, mirroring `decidesk/lib/AppInfo/Registrar/DomainServiceRegistrar.php:121`:

```php
$context->registerServiceAlias(
    'OCA\\OpenRegister\\Mcp\\IMcpToolProvider::stackiq',
    StackiqToolProvider::class
);
```

## 3. Layer A — derived read tools (14 schemas × search/get = 28 tools)

`register.d/mcp-full-action-surface.json`, `configuration.x-openregister-mcp`,
`enabled: true`, verbs `search` + `get`, `scope: "read"`,
`readOnlyHint: true`, `destructiveHint: false`, `idempotentHint: true`,
implicit `reach: user` (hermiq infers `user` for 3-segment `{app}.{schema}.{search|get}`
ids; we declare it anyway — see §5). Filters below are cross-checked
against the HEAD `properties` maps (every name verified present):

| Schema | search filters (all real properties) |
|---|---|
| `module` | `name`, `type`, `provider`, `licentietype`, `hostingJurisdiction` |
| `moduleVersion` | `module`, `status`, `dateEndSupport` |
| `service` | `name`, `provider` |
| `organization` | `name`, `type`, `status`, `registrationStatus` |
| `contactPerson` | `organization`, `role` |
| `connection` | `type`, `status`, `integrationType`, `provider` |
| `compliancy` | `module`, `standardGemma` |
| `usage` | `consumer`, `provider`, `status`, `module`, `timeClassification` |
| `contract` | `status`, `contractType`, `service`, `usage`, `endDate` |
| `suite` | `name` |
| `vulnerability` | `name`, `cveCode`, `cvssScore`, `modules` |
| `assessment` | `status`, `rating`, `modules`, `usage` |
| `bioMeasure` | `code`, `name`, `bbnLevel` |
| `sbomComponent` | `name`, `moduleVersion`, `purl`, `vexCveIds` |

Excluded from derivation, reasoning inherited from the superseded change:
`sector` (2-field taxonomy), `element`/`view`/`model`/
`property-definition`/`relation` (AMEF bulk-import artifacts; `element`
alone has 80+ properties). Note `view` data IS reachable through curated
`listViews`/`getView` provider tools (layer C), which return the enriched
projection the `ViewController` API serves rather than raw AMEF XML.

## 4. Layer B — derived writes: `vulnerability` only

`vulnerability.create` (`scope: create`) and `vulnerability.update`
(`scope: update`), both `reach: instance`, `destructiveHint: false`.
Justification: it is the only live schema with (a) no
`x-openregister-lifecycle` state machine, (b) no decidesk projection
fields, (c) no dedicated workflow service — the app's own UI authors it
via generic OR object CRUD, so a derived MCP write matches the app's
existing authority model exactly (OR RBAC at invoke time). `delete` is
withheld (destructive; no current UI story). Every OTHER schema keeps the
superseded change's "no raw writes" rule: `contract.status = Actief` is a
decidesk projection ("stackiq NEVER sets `status = Actief` on its
own authority" — `register.d/contracts-to-decidesk.json`), and
`moduleVersion`/`connection`/`organization`/`usage`/`contract` carry
lifecycle state machines a raw `update` would bypass.

## 5. Layer C — curated provider tools (grant-matrix table)

Reach follows `hermiq/openspec/specs/agent-capability-reach/spec.md`:
`self` < `user` < `instance` < `external`; reach = widest principal set an
invocation can AFFECT or DISCLOSE TO; a read that leaves the instance is
`external`; undeclared reach fail-closes to `external`, so every
descriptor declares one explicitly.

### Read tools (scope: read)

| Tool id | Delegates to | Reach | Notes |
|---|---|---|---|
| `stackiq.getMyContactProfile` | `ContactpersonenController::getMe` path (`/api/me` resolution) | user | The caller's own contactPerson + organisation context |
| `stackiq.listOffers` | `AanbodService::getAanbod()` | user | Offers pending for the caller's active organisation |
| `stackiq.listOfferedUsages` | `AangebodenGebruikService::getGebruiksWhereAfnemer()` / `getGebruiksWhereDeelnemers()` | user | Usage records offered to / shared with the caller's organisation |
| `stackiq.getPortfolioReport` | `PortfolioReportService::buildReport(organisationUuid)` | user | Caller's organisation only; gate rejects foreign org uuids for non-admins |
| `stackiq.listPendingModerations` | `ModerationService::listPending()` | user | Admin-gated (same as `moderation#pending`) |
| `stackiq.getReviewAggregate` | `ReviewAggregateService` (`review#aggregate`) | user | Public aggregate numbers |
| `stackiq.getContractApprovalConfig` | `ContractApprovalService::isDelegationConfigured()` (`contractApproval#config`) | user | Lets an agent know whether submit tools can work |
| `stackiq.getSbomImportStatus` | `SbomImportService::getStatus(moduleVersionUuid)` | user | Behind `SbomImportService::userCanReadModule()` |
| `stackiq.listViews` / `stackiq.getView` | `ViewService` (`view#getAllViews` / `#getView`) | user | Enriched ArchiMate view projection, incl. enrichment params |
| `stackiq.previewOrganisationMerge` | `MergeOrganisatieService::dryRun(source, target)` | user | Admin-gated; read-only preview of `mergeOrganisations` |
| `stackiq.getEolSyncStatus` | `EolSyncService::getStatus()` | user | Read of last-run metadata only |

### Write tools (scope as listed; hermiq default-deny, human approval gate)

| Tool id | Delegates to | Scope | Reach | Why that reach |
|---|---|---|---|---|
| `stackiq.submitContractApproval` | `ContractApprovalService::submitForApproval(uuid, false)` behind `authorizeSubmit()` | update | instance | Raises a decidesk Decision other users see; flips `approvalState` |
| `stackiq.submitContractRenewal` | `submitForApproval(uuid, true)` | update | instance | Same seam, renewal flavour |
| `stackiq.publishObject` | `PublicationService::publish(objectType, uuid)` | update | external | Sets `publicationDate` → anonymous open-data readers see the record; effect leaves the authenticated instance surface |
| `stackiq.depublishObject` | `PublicationService::depublish(objectType, uuid)` | update | external | Withdraws from the public surface — same boundary |
| `stackiq.approveRegistration` | `ModerationService::approve(uuid, type)` | update | instance | Admits an organisation/review; visible to all users |
| `stackiq.rejectRegistration` | `ModerationService::reject(uuid, type)` | update | instance | |
| `stackiq.submitReview` | `ReviewService::submit(payload, subjectType, subjectId)` | create | instance | Forced to `status: pending` server-side; moderators observe it |
| `stackiq.acceptOffer` | `AanbodService::acceptAanbod(aanbodId)` | update | instance | |
| `stackiq.declineOffer` | `AanbodService::denyAanbod(aanbodId)` | delete | instance | REST twin is a DELETE verb |
| `stackiq.claimUsage` | `AangebodenGebruikService::setGebruikSelfToActiveOrg(gebruikId)` | update | instance | |
| `stackiq.declineUsage` | `AangebodenGebruikService::deleteGebruikAsAfnemer(gebruikId)` | delete | instance | |
| `stackiq.grantOrganisationMembership` | `OrganisationMembersController::grant(uuid, userId)` logic (extract to service if needed) | update | instance | Changes another user's permission set |
| `stackiq.revokeOrganisationMembership` | `::revoke(uuid, userId)` logic | update | instance | |
| `stackiq.mergeOrganisations` | `MergeOrganisatieService::execute(source, target, actorUid)` | update | instance | Admin-gated; tombstones the source (`mergedInto`) |
| `stackiq.registerOrganisation` | `IntakeService::submit(payload)` (+ `validate()`) | create | instance | Creates a `pending` registration for moderators |
| `stackiq.importSbom` | `SbomImportService::importForModuleVersie(...)` | create | instance | Content passed inline (SBOM JSON/XML string), not a file upload |
| `stackiq.triggerEolSync` | `EolSyncService::run()` | update | external | Outbound HTTP to endoflife.date — per hermiq's rule, anything issuing external requests is `external` regardless of verb |

Descriptor hints: every write tool sets `readOnlyHint: false`;
`destructiveHint: true` only on `declineOffer`, `declineUsage`,
`revokeOrganisationMembership`, and `mergeOrganisations` (tombstoning);
`idempotentHint` per delegate semantics (e.g. `publish` idempotent,
`submitReview` not).

### Named exclusions (auditable "full coverage" boundary)

| Surface | Why not a tool |
|---|---|
| `settings#*` config get/set (~50 endpoints: general/sync/AMEF/voorzieningen/email/cronjob/user-group config, auto-configure, force-update, clear-cache, debug, heartbeat) | App configuration, not catalogue operation. An agent misconfiguring register bindings can brick the app for everyone; nothing in the PO intent ("command the app from chat") needs it. Deferred, not denied forever. |
| `contactpersonen#convertToUser`, `changePassword`, `disableUser`, `enableUser`, `updateUserGroups` | Identity/credential administration. Password and account-state changes are outside any sane agent grant in v1. |
| `settings#importArchiMate` / `exportArchiMate` / `downloadArchiMate` + progress streaming | File-upload/-download shaped with an async progress protocol; MCP tool-call ergonomics don't fit yet. `importSbom` is included instead because its payload is inline text. |
| `federation#addPeer/removePeer/pull` | Instance-topology administration touching remote instances; needs its own security review before any agent reach. |
| `dashboard#*`, `preferences#*`, `facet#getFacets`, `settings#getObjectsCounts/Statistics` | UI plumbing; derived `search` covers the data need. |

## 6. Chat scenarios the surface must support (grounded end-to-end)

1. **"Which contracts expire this quarter?"** →
   `stackiq.contract.search` with a `endDate` range filter
   (real property: `contract.endDate`, "De einddatum van het contract");
   scope read / reach user — grantable without approval friction.
2. **"Log a vulnerability against application X."** →
   `stackiq.module.search {name: X}` then
   `stackiq.vulnerability.create {name, cveCode, cvssScore,
   modules: [moduleId]}` (all real `vulnerability` properties); write →
   default-deny, first use prompts a grant, invocation passes the human
   approval gate and lands in the audit trail.
3. **"Submit contract 2025-0042 for renewal approval."** →
   `contract.search {contractNumber}` then
   `stackiq.submitContractRenewal {contractUuid}`; the gate runs
   `ContractApprovalService::authorizeSubmit()` — a caller whose active
   organisation doesn't own the contract gets the same 403-equivalent
   `forbidden` error the REST path returns, agent or not.

## 7. Risks / trade-offs

- [Risk] The superseded change is applied first with Dutch slugs →
  orphan schemas polluting the register. Mitigation: proposal recommends
  archiving it as superseded; task 1.1 asserts the fragment only names
  slugs present in the HEAD monolith (fails the build otherwise).
- [Risk] MCP write tool drifts from its REST twin's guard (an MCP-only
  IDOR). Mitigation: the delegation rule is a spec requirement with
  per-gate unit tests mirroring `contract-approval-ownership-guard`'s
  403 cases; the gates REUSE the service-level guards rather than
  reimplementing them.
- [Trade-off] `OrganisationMembersController::grant/revoke` logic lives
  in the controller today; the provider either extracts it into a small
  service (preferred, one-time refactor) or is deferred for those two
  tools — decided at apply time, recorded in tasks 5.4.
- [Trade-off] No `delete` tools for catalogue records at all (beyond the
  decline/revoke workflow verbs). Deliberate: destructive deletes have no
  workflow service and no agent story; bias to fewer.

## 8. Deferred

- Curated tools over the excluded admin surfaces (config, ArchiMate,
  federation) once hermiq has an "operator agent" grant tier.
- `x-openregister-mcp` on a trimmed AMEF projection schema (inherited
  deferral).
- `assessment` derived writes — review submission must stay behind
  `stackiq.submitReview` so the server-side `pending` forcing and
  `auteur` stamping are never bypassed.
