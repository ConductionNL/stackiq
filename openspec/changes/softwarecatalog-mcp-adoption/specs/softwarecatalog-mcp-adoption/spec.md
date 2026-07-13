# softwarecatalog-mcp-adoption Specification

**Status**: planned
**Scope**: softwarecatalog
**OpenSpec changes**:
- _(none yet)_

## Purpose
Adopts ADR-063 ("MCP as Platform Abstraction", hydra #102) in Software Catalog
by declaring the `x-openregister-mcp` schema dialect on a curated, read-only
set of catalogue schemas, so OpenRegister's `SchemaDerivedToolProvider` derives
Hermiq-consumable MCP tools without any hand-written provider PHP. This is the
Software Catalog counterpart to the pipelinq `mcp-provider-declarative-migration`
exemplar (PR #390), scoped to a government software catalogue's read-only
query surface rather than a CRM's mixed read/write surface.

## ADDED Requirements

### Requirement: REQ-001 — Curated read-only MCP dialect on the 9 core catalogue schemas
The 9 curated schemas (`module`, `moduleVersie`, `dienst`, `organisatie`, `contactpersoon`, `koppeling`, `compliancy`, `gebruik`, `contract`) MUST each declare a
`configuration.x-openregister-mcp` block with `enabled: true` and exactly the
`search` and `get` tool verbs, each with `scope: "read"` and
`readOnlyHint: true`.

#### Scenario: A curated schema exposes derived search and get tools
- GIVEN the softwarecatalog register merged with `register.d/softwarecatalog-mcp-adoption.json`
- WHEN OpenRegister's `SchemaDerivedToolProvider` lists MCP tools for the `softwarecatalog` app
- THEN the tool list MUST include `softwarecatalog.module.search` and `softwarecatalog.module.get`
- AND every one of the 9 curated schemas MUST similarly expose its `search` and `get` tools
- AND no curated schema MUST expose a `create`, `update`, or `delete` tool

#### Scenario: A non-curated schema exposes no MCP tools
- GIVEN the softwarecatalog register merged with `register.d/softwarecatalog-mcp-adoption.json`
- WHEN OpenRegister's `SchemaDerivedToolProvider` lists MCP tools for the `softwarecatalog` app
- THEN the tool list MUST NOT include any tool for `sector`, `suite`, `kwetsbaarheid`, `beoordeeling`, `element`, `view`, `model`, `organization`, `property-definition`, or `relation`

### Requirement: REQ-002 — Every declared search filter names a real schema property
Each curated schema's `search` verb `filters` list MUST contain only strings
that name a property declared in that same schema's `properties` map.

#### Scenario: Import succeeds because every filter is a real property
- GIVEN `register.d/softwarecatalog-mcp-adoption.json` declares `lead`-style filters such as `module.search.filters = ["naam", "type", "aanbieder", "licentietype", "hostingJurisdictie"]`
- WHEN OpenRegister's `McpAnnotationValidator` validates the merged `module` schema at import time
- THEN validation MUST report zero `mcp-unknown-filter-property` errors for the `module` schema
- AND this MUST hold for all 9 curated schemas' filter lists

### Requirement: REQ-003 — MCP tools are derived without app-level PHP
Software Catalog MUST NOT ship a hand-written `IMcpToolProvider` implementation
or any `#[McpTool]`-attributed service method as part of this change; the
entire MCP surface introduced by this change MUST be expressed as
`x-openregister-mcp` dialect data in `lib/Settings/register.d/`.

#### Scenario: No provider class exists after this change
- GIVEN this change is applied
- WHEN searching `lib/` for a class implementing `OCA\OpenRegister\Mcp\IMcpToolProvider` or `IMcpScannableServices`
- THEN no such class MUST exist in the `softwarecatalog` app

### Requirement: REQ-004 — MCP dialect is declared via a register fragment, not the monolith
The `x-openregister-mcp` blocks introduced by this change MUST live in a new
`lib/Settings/register.d/softwarecatalog-mcp-adoption.json` fragment file;
`lib/Settings/softwarecatalogus_register.json` MUST NOT be modified by this
change.

#### Scenario: The monolith is untouched
- GIVEN a diff of this change against the base commit
- WHEN inspecting which files changed under `lib/Settings/`
- THEN the diff MUST include `lib/Settings/register.d/softwarecatalog-mcp-adoption.json`
- AND the diff MUST NOT include `lib/Settings/softwarecatalogus_register.json`

## Non-Functional Requirements

- **Performance:** Declaring the dialect adds no runtime cost to Software
  Catalog's own request path — tool derivation happens inside OpenRegister at
  MCP-serve time, not inside any Software Catalog controller or service.
- **Accessibility:** Not applicable — this change has no user-facing UI
  surface (it is a backend register-configuration change consumed by an AI
  agent, not a human interface).
- **Internationalization:** Agent-facing tool `description` text is authored
  in English per fleet convention (i18n keys/agent prose = English); the
  underlying Dutch field names (`naam`, `beschrijvingKort`, ...) are unchanged
  and remain the actual data values returned to callers.

## Acceptance Criteria

- [ ] `register.d/softwarecatalog-mcp-adoption.json` declares `configuration.x-openregister-mcp` on exactly the 9 curated schemas listed in REQ-001.
- [ ] Every declared verb is `search` or `get` only, `scope: "read"`.
- [ ] Every `search.filters` entry is a real property of its schema (REQ-002), verified against `lib/Settings/softwarecatalogus_register.json` at apply time.
- [ ] No `create`/`update`/`delete` verb, and no `#[McpTool]`/`IMcpToolProvider` PHP, is introduced (REQ-001, REQ-003).
- [ ] `lib/Settings/softwarecatalogus_register.json` is unmodified (REQ-004).
- [ ] The new fragment file is valid JSON (`python3 -m json.tool`).

## Notes
Related ADRs: ADR-063 (MCP as Platform Abstraction), ADR-037 (register
fragments). Exemplar: pipelinq `mcp-provider-declarative-migration`
(archived, PR #390) declared the same dialect shape on `client`/`lead`/
`ticket`. This spec deliberately excludes the AMEF/GEMMA architecture-model
schemas and any write verb — see the parent change's `design.md` curation
table and exclusion list for the full per-schema reasoning, and
`DEFERRED_QUESTIONS` for follow-up work not part of this change.
