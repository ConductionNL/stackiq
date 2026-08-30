---
kind: code
depends_on: []
---

# stackiq — full MCP action surface for hermiq (chat-drivable catalogue)

## Why

**Product intent:** every Conduction app should expose MCP tooling for ALL
of its user actions, so any action can in principle be automated by an AI
agent — with the user granting rights per agent, granularly, on hermiq's
two-axis grant model (`scope` × `reach`, default-deny for writes, human
approval gates, audit trail — `hermiq/openspec/specs/agent-tool-governance/`
and `agent-capability-reach/spec.md`). Even without automation, a user
should be able to command the app from chat: "which contracts expire this
quarter?" answered, "submit contract 2025-0042 for renewal" queued behind
an approval gate.

**Current state (verified at HEAD):** Software Catalog has zero MCP
surface. `grep -rn "IMcpToolProvider\|McpTool\|x-openregister-mcp" lib/
src/ appinfo/` returns nothing outside openspec prose. The mechanism is
proven elsewhere: decidesk ships the fleet reference implementation
(`decidesk/lib/Mcp/DecideskToolProvider.php` — dispatcher +
`TOOL_DESCRIPTORS` catalogue, `McpArgumentValidator`, `McpMeetingGate`
per-object authorisation, `McpMeetingScopeResolver`), registered via the
DI alias `OCA\OpenRegister\Mcp\IMcpToolProvider::decidesk`
(`decidesk/lib/AppInfo/Registrar/DomainServiceRegistrar.php:121`), and
OpenRegister derives CRUD tools from `x-openregister-mcp` schema blocks
(`openregister/lib/Mcp/`).

**Relationship to `stackiq-mcp-adoption` (active change,
2026-07-13, `schema: conduction`):** that change specifies the read-only
half — derived `search`/`get` tools on 9 curated schemas via a
`register.d` fragment — and explicitly defers every write/action tool
("A future `kind: code` change could promote … a `#[McpTool]` once
there's a concrete agent workflow need", its `DEFERRED_QUESTIONS`). This
change is that deferred follow-up, and it also has to correct the ground
under it: **the register was since migrated to English slugs** and
`stackiq-mcp-adoption`'s fragment is written against schema names
that no longer exist. Verified against
`lib/Settings/softwarecatalogus_register.json` at HEAD: the register
contains `module`, `moduleVersion`, `service`, `organization`,
`contactPerson`, `connection`, `compliancy`, `usage`, `contract`,
`suite`, `sector`, `vulnerability`, `assessment`, `bioMeasure`,
`sbomComponent` + the 5 AMEF schemas — there is no `moduleVersie`,
`dienst`, `organisatie`, `contactpersoon`, `koppeling`, `gebruik`,
`kwetsbaarheid`, or `beoordeeling`. Applying that change's JSON as-is
would deep-merge eight ORPHAN schemas into the register (ADR-037 creates
what it cannot match) instead of annotating the real ones. Two of its
exclusions are also stale: `vulnerability` and `assessment` are live
surfaces now (`Kwetsbaarheden`/`KwetsbaarheidDetail` and
`Reviews`/`ReviewDetail` manifest pages; `ReviewService`,
`ModerationService`, the `catalog-ratings` fragment), despite the
monolith's leftover "niet daadwerkelijk gebruikt" description.

**This change therefore supersedes `stackiq-mcp-adoption`**: it
retains that change's curation reasoning (read-only derived tools, honest
hints, filters cross-checked against real properties, AMEF exclusion, no
raw writes on lifecycle-governed schemas) and re-grounds it on the English
slugs, then adds the full action layer on top. Recommend archiving
`stackiq-mcp-adoption` as superseded when this change lands.

## What Changes

1. **Derived read layer (config)** — new
   `lib/Settings/register.d/mcp-full-action-surface.json` fragment
   declaring `configuration.x-openregister-mcp` (`search` + `get`,
   `scope: read`, `readOnlyHint: true`) on 14 schemas: the 9 from the
   superseded change under their current slugs (`module`, `moduleVersion`,
   `service`, `organization`, `contactPerson`, `connection`, `compliancy`,
   `usage`, `contract`) plus `suite`, `vulnerability`, `assessment`,
   `bioMeasure`, `sbomComponent` (all now live surfaces). AMEF schemas
   (`element`, `view`, `model`, `property-definition`, `relation`) and
   `sector` stay excluded — reasoning inherited, see design.md.
2. **Derived write verbs on `vulnerability` only** — `create`/`update`
   (`scope` accordingly, `reach: instance`): the one live schema with no
   lifecycle state machine, no projection fields, and no workflow
   service; the app's own UI writes it through generic OR object CRUD.
   Every other schema's writes stay workflow-only (below).
3. **Hand-written provider (code)** —
   `lib/Mcp/StackiqToolProvider.php`
   (`OCA\Stackiq\Mcp`, implements
   `OCA\OpenRegister\Mcp\IMcpToolProvider`), registered under the DI
   alias `OCA\OpenRegister\Mcp\IMcpToolProvider::stackiq`, tool
   ids `stackiq.{toolName}`. Dispatcher-only, decidesk-style:
   argument validation (`McpArgumentValidator` port) → per-object
   authorisation gate → delegation to the EXISTING workflow service.
   12 curated read tools and 17 write tools covering every real
   user-facing workflow action found in `lib/Controller/` +
   `lib/Service/` — contract approval/renewal
   (`ContractApprovalService::submitForApproval()` behind
   `authorizeSubmit()`), publish/depublish (`PublicationService`),
   moderation (`ModerationService::listPending/approve/reject`), reviews
   (`ReviewService::submit`), offers (`AanbodService::getAanbod/
   acceptAanbod/denyAanbod`), offered-usage claim/decline
   (`AangebodenGebruikService`), organisation membership
   (`OrganisationMembersController` logic), organisation merge
   (`MergeOrganisatieService::dryRun/execute`), intake
   (`IntakeService::submit`), SBOM import (`SbomImportService`), EOL sync
   (`EolSyncService::run`), portfolio report
   (`PortfolioReportService::buildReport`). Full catalogue table with
   per-tool `scope` and `reach` in design.md.
4. **Grant-matrix annotations** — every descriptor declares `scope`
   (read/create/update/delete) AND `reach` (self/user/instance/external)
   from hermiq's closed vocabularies, because hermiq fail-closes an
   undeclared reach to `external` (its most-restricted class) and we want
   reads grantable at `user` reach. Publication tools are honestly
   `reach: external` (they alter the anonymous open-data surface), as is
   the EOL sync trigger (outbound HTTP to endoflife.date).
5. **Named exclusions, not silent ones** — admin configuration plumbing
   (the ~50 `settings#*` config get/set endpoints, email templates,
   cronjob config, user-group config), identity/credential operations
   (`contactpersonen#convertToUser/changePassword/disable/enable`),
   ArchiMate import/export (file-transfer shaped), and federation peer
   management are deliberately NOT tools in this change — each with its
   rationale recorded in design.md so the coverage claim is auditable.

Not BREAKING: purely additive — no existing route, controller, or schema
property changes; REST surface untouched.
