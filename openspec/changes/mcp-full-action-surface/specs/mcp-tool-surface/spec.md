## ADDED Requirements

### Requirement: Software Catalog MUST register a hand-written MCP tool provider
The app SHALL ship `OCA\Stackiq\Mcp\StackiqToolProvider`
implementing `OCA\OpenRegister\Mcp\IMcpToolProvider`, registered under the
DI alias `OCA\OpenRegister\Mcp\IMcpToolProvider::stackiq`
(mirroring decidesk's registrar at
`decidesk/lib/AppInfo/Registrar/DomainServiceRegistrar.php:121`). The
provider MUST be a dispatcher only: it owns the tool catalogue (a constant
descriptor table unit tests can assert as a fixture, per
`DecideskToolProvider::TOOL_DESCRIPTORS`) and routes tool ids to handler
classes; it MUST NOT contain business logic. Every tool id MUST be
namespaced `stackiq.{toolName}`.

#### Scenario: The provider is discoverable through OpenRegister
- GIVEN this change applied and the app enabled
- WHEN OpenRegister resolves registered `IMcpToolProvider` aliases
- THEN `IMcpToolProvider::stackiq` MUST resolve to
  `StackiqToolProvider`
- AND its listed tools MUST all carry ids starting with `stackiq.`
- @e2e exclude DI-resolution assertion; asserted by PHPUnit bootstrapping
  the container

### Requirement: Every tool descriptor MUST declare scope and reach from hermiq's closed vocabularies
Every descriptor — derived and curated — SHALL declare `scope` (one of
`read`, `create`, `update`, `delete`) and `reach` (one of `self`, `user`,
`instance`, `external`, per
`hermiq/openspec/specs/agent-capability-reach/spec.md`), plus honest
`readOnlyHint`/`destructiveHint`/`idempotentHint` values. Reach MUST be
declared explicitly (hermiq fail-closes an undeclared reach to
`external`). A tool whose invocation issues an outbound HTTP request
(`stackiq.triggerEolSync` → endoflife.date) or alters the
anonymous open-data surface (`publishObject`/`depublishObject`) MUST
declare `reach: external` regardless of its verb.

#### Scenario: No descriptor ships without both axes
- GIVEN the provider's descriptor table and the derived-tool fragment
- WHEN every entry is inspected
- THEN each MUST carry a `scope` and a `reach` from the closed vocabularies
- AND no read tool MUST carry `readOnlyHint: false`
- @e2e exclude Descriptor-shape fixture assertion; PHPUnit over the
  descriptor constant

#### Scenario: Publication tools are classified as external reach
- GIVEN the descriptors for `stackiq.publishObject` and
  `stackiq.depublishObject`
- WHEN their `reach` is read
- THEN it MUST be `external`
- AND their `scope` MUST be `update`
- @e2e exclude Fixture assertion; PHPUnit

### Requirement: Read tools MUST be side-effect free and separated from write tools
Curated read tools (`getMyContactProfile`, `listOffers`,
`listOfferedUsages`, `getPortfolioReport`, `listPendingModerations`,
`getReviewAggregate`, `getContractApprovalConfig`, `getSbomImportStatus`,
`listViews`, `getView`, `previewOrganisationMerge`, `getEolSyncStatus`)
SHALL delegate only to read paths of the existing services and MUST NOT
persist anything. `previewOrganisationMerge` MUST delegate to
`MergeOrganisatieService::dryRun()` and MUST NOT be able to reach
`execute()`.

#### Scenario: Merge preview never mutates
- GIVEN two organisation uuids
- WHEN `stackiq.previewOrganisationMerge` is invoked
- THEN the response MUST contain the dry-run impact summary
- AND no object write MUST occur (asserted via a mocked
  `MergeOrganisatieService` expecting `dryRun()` once and `execute()` never)
- @e2e exclude MCP JSON-RPC path; PHPUnit on the handler

### Requirement: Every write tool MUST delegate to the existing workflow service behind its existing guard
Each write tool SHALL delegate to the named workflow method —
`ContractApprovalService::submitForApproval()`,
`PublicationService::publish()/depublish()`,
`ModerationService::approve()/reject()`, `ReviewService::submit()`,
`AanbodService::acceptAanbod()/denyAanbod()`,
`AangebodenGebruikService::setGebruikSelfToActiveOrg()/deleteGebruikAsAfnemer()`,
organisation-membership grant/revoke, `MergeOrganisatieService::execute()`,
`IntakeService::submit()`, `SbomImportService::importForModuleVersie()`,
`EolSyncService::run()` — and MUST run per-object authorisation before the
delegate, structured as the decidesk ladder (argument validation → load →
not_found → authorise → delegate, per `decidesk/lib/Mcp/McpMeetingGate.php`).
The MCP layer MUST NOT grant authority the REST twin denies: in particular
`submitContractApproval`/`submitContractRenewal` MUST pass
`ContractApprovalService::authorizeSubmit()` and fail closed exactly like
the REST 403 path. Raw object writes on lifecycle-governed schemas
(`contract`, `usage`, `organization`, `moduleVersion`, `connection`) MUST
NOT be exposed as MCP tools.

#### Scenario: A non-owning caller cannot submit a contract via MCP
- GIVEN a contract owned by organisation A
- AND an authenticated caller whose active organisation is B and who is
  not an instance admin
- WHEN `stackiq.submitContractApproval` is invoked for that contract
- THEN the tool MUST return a forbidden error
- AND `ContractApprovalService::submitForApproval()` MUST NOT be invoked
- AND no `DecisionRequestedEvent` MUST be dispatched
- @e2e exclude Mirrors the REST 403 cases of
  `contract-approval-ownership-guard`; PHPUnit with mocked dispatcher

#### Scenario: Review submission cannot bypass moderation
- GIVEN any caller
- WHEN `stackiq.submitReview` is invoked with a payload declaring
  `status: approved`
- THEN the persisted assessment MUST have `status: pending` (forced
  server-side by `ReviewService::submit()`)
- AND the response MUST reflect the pending state
- @e2e exclude Server-side forcing assertion; PHPUnit on the handler +
  service

#### Scenario: Argument validation precedes authorisation and business logic
- GIVEN an invocation of any curated tool with a missing required argument
- WHEN the provider dispatches it
- THEN the tool MUST return a validation error naming the argument
- AND no service method MUST have been called
- @e2e exclude Validator-ladder assertion; PHPUnit

### Requirement: Derived read tools MUST cover the 14 live catalogue schemas under their current English slugs
`lib/Settings/register.d/mcp-full-action-surface.json` SHALL declare
`configuration.x-openregister-mcp` with `search` + `get` (`scope: read`,
`readOnlyHint: true`) on exactly: `module`, `moduleVersion`, `service`,
`organization`, `contactPerson`, `connection`, `compliancy`, `usage`,
`contract`, `suite`, `vulnerability`, `assessment`, `bioMeasure`,
`sbomComponent`. Every schema name and every `search.filters` entry MUST
exist in the HEAD `softwarecatalogus_register.json` (`McpAnnotationValidator`
must report zero unknown-filter errors, and the fragment MUST NOT
introduce any schema key absent from the monolith). The AMEF schemas
(`element`, `view`, `model`, `property-definition`, `relation`) and
`sector` MUST NOT be annotated. `lib/Settings/softwarecatalogus_register.json`
MUST NOT be modified.

#### Scenario: Contracts are searchable by end date from chat
- GIVEN the fragment imported and contracts with `endDate` values in Q4
- WHEN an agent invokes `stackiq.contract.search` with an
  `endDate` range filter for the quarter
- THEN the result MUST contain exactly the contracts whose `endDate`
  falls in the range the caller may read under OR RBAC
- @e2e exclude MCP JSON-RPC query; covered by OpenRegister's derived-tool
  suite plus an app-side import assertion

#### Scenario: No orphan schema is merged into the register
- GIVEN the fragment applied
- WHEN the merged register is diffed against the monolith's schema key set
- THEN the set of schema keys MUST be unchanged (annotations only, no new
  schemas — in particular none of the retired Dutch slugs `moduleVersie`,
  `dienst`, `organisatie`, `contactpersoon`, `koppeling`, `gebruik`,
  `kwetsbaarheid`, `beoordeeling`)
- @e2e exclude Config-merge assertion; PHPUnit on
  `SettingsService::loadSettings()`

### Requirement: Derived write verbs MUST exist on vulnerability and nowhere else
The fragment SHALL additionally declare `create` and `update` (with
matching `scope`, `reach: instance`, `readOnlyHint: false`) on the
`vulnerability` schema only — the one live schema with no lifecycle state
machine, no projection fields, and no workflow service. No other schema in
the fragment MUST carry a `create`, `update`, or `delete` verb, and
`vulnerability` MUST NOT carry `delete`.

#### Scenario: An agent logs a vulnerability against an application
- GIVEN an agent granted `stackiq.vulnerability.create` (a write —
  hermiq default-denies it until granted, and the invocation passes the
  human approval gate)
- WHEN it invokes the tool with `name`, `cveCode`, `cvssScore`, and
  `modules` referencing an existing module id
- THEN a `vulnerability` object MUST be created with those values under
  the caller's OR RBAC authority
- AND the invocation MUST appear in hermiq's audit trail
- @e2e exclude Cross-app hermiq grant flow; covered by hermiq's
  agent-tool-governance suite; app-side PHPUnit asserts the fragment shape

#### Scenario: Writes on lifecycle-governed schemas stay impossible
- GIVEN the imported merged register
- WHEN the derived tool list for `stackiq` is enumerated
- THEN no `contract.*`, `usage.*`, `organization.*`, `moduleVersion.*`,
  or `connection.*` tool with scope `create`, `update`, or `delete` MUST
  exist
- @e2e exclude Tool-listing assertion; import check in CI

### Requirement: This change supersedes stackiq-mcp-adoption
The change SHALL be applied instead of, never after or alongside, the
`stackiq-mcp-adoption` fragment: that change's
`register.d/stackiq-mcp-adoption.json` (Dutch slugs) MUST NOT be
created, and on landing this change the `stackiq-mcp-adoption`
change MUST be archived as superseded with a pointer to
`mcp-full-action-surface`.

#### Scenario: The stale fragment never lands
- GIVEN this change applied
- WHEN `lib/Settings/register.d/` is listed
- THEN it MUST contain `mcp-full-action-surface.json`
- AND it MUST NOT contain `stackiq-mcp-adoption.json`
- @e2e exclude File-presence assertion; checked in review/CI
