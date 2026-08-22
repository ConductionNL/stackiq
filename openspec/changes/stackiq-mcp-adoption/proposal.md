# Proposal: softwarecatalog-mcp-adoption

## Summary
Adopt ADR-063 ("MCP as Platform Abstraction") in Software Catalog by declaring the
`x-openregister-mcp` schema dialect on a curated set of 9 register schemas
(`module`, `moduleVersie`, `dienst`, `organisatie`, `contactpersoon`, `koppeling`,
`compliancy`, `gebruik`, `contract`), so OpenRegister derives read-only
`softwarecatalog.<schema>.search` / `.get` MCP tools for Hermiq without any
hand-written provider code. Software Catalog has no existing `IMcpToolProvider`,
so this is a pure dialect declaration (`kind: config`) — no PHP is written or
deleted.

## Motivation
Software Catalog is a VNG (Dutch government) software catalogue: municipalities,
suppliers, applications, service offerings, integrations, standards-compliance
evidence, and procurement contracts. This is exactly the kind of structured,
frequently-queried registry an AI assistant (via Hermiq) should be able to answer
questions against — "which modules does supplier X offer", "is module Y compliant
with standard Z", "which contracts are active for service W" — without a
developer hand-writing bespoke MCP glue for every question shape. ADR-063
establishes the declarative pattern (hydra #102, merged) and the pipelinq leaf
migration (`mcp-provider-declarative-migration`, PR #390) is the working
exemplar. Software Catalog currently exposes zero MCP surface; this change closes
that gap for a curated, read-only slice of the catalogue.

## Affected Projects
- [ ] Project: `softwarecatalog` — declare `x-openregister-mcp` (search/get,
  read-only) on 9 curated schemas via a new `lib/Settings/register.d/` fragment;
  no PHP changes (no provider exists to migrate or delete).

## Scope

### In Scope
- Declare `configuration.x-openregister-mcp` on: `module`, `moduleVersie`,
  `dienst`, `organisatie`, `contactpersoon`, `koppeling`, `compliancy`,
  `gebruik`, `contract`.
- `search` + `get` verbs only, `scope: read`, honest MCP hints
  (`readOnlyHint: true`, `destructiveHint: false`, `idempotentHint: true`).
- `search.filters` restricted to real, cross-checked schema properties.
- Agent-facing `description` prose per verb per schema (what an LLM reads to
  decide when to call the tool).
- A dedicated `register.d/softwarecatalog-mcp-adoption.json` fragment (ADR-037)
  so this change never edits the shared `softwarecatalogus_register.json`
  monolith.

### Out of Scope
- No write verb (`create`/`update`/`delete`) on any schema — see design.md
  "Why no writes" for the per-domain reasoning (every mutating path already
  runs through a dedicated workflow service this change would otherwise let an
  agent bypass).
- The AMEF/GEMMA architecture-model schemas (`element`, `view`, `model`,
  `organization`, `property-definition`, `relation`) and the two schemas the
  softwarecatalogus register itself documents as unused
  (`kwetsbaarheid`, `beoordeeling`) — excluded, see design.md exclusion table.
- No hand-written `#[McpTool]` service method — no genuine non-CRUD action was
  identified worth curating (deferred; see design.md).
- No change to `SettingsService::loadSettings()`, `ConfigFileLoaderService`
  equivalents, or the fragment-merge mechanism itself — this change only adds a
  fragment file using the existing ADR-037 mechanism.

## Approach
Declare the dialect purely as data: one new `register.d/*.json` fragment
(ADR-037 union-merge) that adds a `configuration.x-openregister-mcp` block to
each curated schema. OpenRegister's `SchemaDerivedToolProvider` then derives
`softwarecatalog.<schema>.search` / `softwarecatalog.<schema>.get` tools at
runtime — no controller, service, or provider class is touched. Details
(exact per-verb JSON, filter lists, hint values) live in design.md.

## New Dependencies
None.

## Impact
- `lib/Settings/register.d/softwarecatalog-mcp-adoption.json` (new file).
- OpenRegister's MCP surface for Hermiq (JSON-RPC `/api/mcp` + chat facade)
  gains 18 new derived tools (9 schemas × search/get) once imported.
- No change to existing REST controllers, Vue frontend, or existing register
  fragments.

## Cross-Project Dependencies
Depends on OpenRegister's `SchemaDerivedToolProvider` / `McpAnnotationValidator`
(already merged at origin/development) to actually derive and serve the tools;
this change is inert configuration until that import runs, same posture as the
pipelinq exemplar.

## Risks

### Risk 1: A declared search filter doesn't match a real schema property
**Severity:** Medium — **Mitigation:** Every filter in design.md was
cross-checked against `lib/Settings/softwarecatalogus_register.json` at HEAD
(property dumps recorded in design.md); `openregister`'s
`McpAnnotationValidator` also hard-rejects the whole schema import on any
unknown filter property, so a mistake fails loudly at import time rather than
silently.

### Risk 2: Exposing `contract`/`gebruik` read tools surfaces commercially
sensitive data (pricing, negotiation status) to any MCP-connected agent
**Severity:** Low — **Mitigation:** Read-only, and OpenRegister RBAC (not the
MCP layer) is the authoritative access gate at invoke time per
`McpAnnotationValidator`'s own docblock — the same RBAC that already scopes
these objects in the Vue frontend applies unchanged to MCP callers.

## Rollback Strategy
Delete `lib/Settings/register.d/softwarecatalog-mcp-adoption.json` (or flip
every `enabled` to `false`) and re-run the settings import; the fragment
signature changes so OpenRegister re-imports and the derived tools disappear.
No other file is touched, so rollback is a single-file revert.

## Open Questions
None — see design.md `DEFERRED_QUESTIONS` for follow-up items that don't block
this change.
