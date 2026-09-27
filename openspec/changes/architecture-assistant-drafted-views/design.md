# Design: architecture-assistant-drafted-views

Read at development 49e65cb4, with the open changes `architecture-views-editor` and `mcp-full-action-surface` as the ground this change stands on.

## Where it fits

| Layer | Touched | Notes |
|---|---|---|
| MCP provider | `lib/Mcp/StackiqToolProvider.php` (created by `mcp-full-action-surface`, its `tasks.md` 2.1) | two descriptors and two dispatch entries, no logic |
| Argument checks | `lib/Mcp/McpArgumentValidator.php` (same change, `tasks.md` 2.3) | reused as is |
| Service | new `lib/Service/ArchitectureViewDraftService.php` | validation, element resolution, relation reuse, writes |
| Register | new fragment `lib/Settings/register.d/architecture-assistant-drafted-views.json` | appends `assistant` to `origin` on `view`, `element` and `relation`, adds `draftedFor` and `draftedAt` to `view` |
| Page | `ViewEditor` from `architecture-views-editor` (`src/views/architecture/ArchitectureViewEditor.vue`) | a notice and a first-open layout |
| Routes | none | the tools are reached through OpenRegister's MCP server (`openregister-ro/appinfo/routes.php:1990`, `POST /api/mcp`) and Hermiq's facade |

The fragment merges through `SettingsService::loadSettings` (`lib/Service/SettingsService.php:1653-1680`). `deepMergeConfig` (:7338) appends list values, so `"enum": ["assistant"]` under `origin` adds the value to the enum `architecture-views-editor` declares. The fragment bumps `view`, `element` and `relation` once more.

## Decisions

### D1. Two curated tools on the provider `mcp-full-action-surface` creates

| Tool id | Delegates to | Scope | Reach | Hints |
|---|---|---|---|---|
| `stackiq.searchArchitectureElements` | `ArchitectureViewDraftService::searchElements(query, types, limit)` | read | user | `readOnlyHint: true` |
| `stackiq.draftView` | `ArchitectureViewDraftService::draftView(name, description, elements, relations)` | create | instance | `readOnlyHint: false`, `destructiveHint: false`, `idempotentHint: false` |

`draftView` is `reach: instance` because a drafted view is visible to the members of the caller's organisation, not only to the caller. Hermiq fail-closes an undeclared reach to `external` (`mcp-full-action-surface` design section 5), so both tools declare one.

Rejected: an `#[McpTool]` attribute on the service method (ADR-063 Decision 2). `mcp-full-action-surface` builds stackiq's tools as a hand-written provider with one argument validator and per-object gates. A second mechanism in the same app would split where a reviewer looks for stackiq's tools.

Rejected: derived `x-openregister-mcp` writes on `view`, `element` and `relation`. Both open MCP changes exclude the AMEF schemas for good reason (`element` has more than 80 properties, and a raw `view.create` would take the node list as free JSON). A draft is a composite write across three schemas, which is what a curated tool is for.

### D2. The input is structure, not a picture

`draftView` takes:

```json
{
  "name": "Zaakgericht werken, concept",
  "description": "How the case system hands documents to document management.",
  "elements": [
    { "key": "a", "uuid": "00000000-0000-0000-0000-000000000000" },
    { "key": "b", "type": "ApplicationComponent", "name": "Documentbeheer" }
  ],
  "relations": [
    { "source": "a", "target": "b", "type": "Flow" }
  ]
}
```

An element names either an existing AMEF element by `uuid` or a new one by ArchiMate `type` and `name`. A relation names two element keys and an ArchiMate relation type. Types come from closed lists in the service: the ArchiMate 3.2 element types and the eleven relation types. A draft holds at most 60 elements and 120 relations. An unknown type, a dangling key or a list over the cap returns a validation error before anything is written.

The result is `{ "uuid": ..., "url": "/apps/stackiq/views/<uuid>", "created": { "elements": n, "relations": n } }`, so the assistant can hand the user a link.

### D3. The mark is written with the object (ADR-088)

Every object the tool writes carries `origin: assistant` in the same `saveObject` call that creates it: the view, each new element and each new relation. The view also carries `draftedFor` (the Nextcloud user id of the session the tool ran in) and `draftedAt`. There is no second write that could fail after the first, so an unmarked draft cannot exist (ADR-088 Decisions 1 and 5).

ADR-088 Decision 2 asks for the mark in the object's own metadata. OpenRegister's object metadata has no agent or provenance field (`openregister-ro/lib/Db/ObjectEntity.php:174` to :759 holds `owner`, `application`, `organisation` and the like), and `application` reads stackiq for a human save and a tool save alike. So the mark is the schema field `origin`, which the editor already reads and the Views index already facets.

Rejected: an OpenRegister change that adds an agent field to the object metadata. It is the better long-term home, but it is OpenRegister's to design for every app, and stackiq's field can move there later without losing data.

Stackiq does not record which agent drafted the view. `ToolRegistryFacade::invokeTool` passes no agent identity to a provider (`openregister-ro/lib/Service/Mcp/ToolRegistryFacade.php:351-353`). Hermiq's tool trace records the agent with the tool id and the returned view uuid (ADR-088 Decision 3), and OpenRegister's invocation audit records the call (ADR-063 Decision 8). The mark says "an assistant drafted this", the trace says which one.

A person editing a drafted view keeps the mark. The editor shows "Drafted by an assistant for <user> on <date>" on every open, and the Views index shows `assistant` in its origin facet.

### D4. Drafts carry no positions and are laid out on first open

The service writes nodes without `x` and `y`. `ArchitectureViewEditor.vue` checks the nodes with `needsFullLayout` and places them with `layoutFlowNodes` (`@conduction/nextcloud-vue` 2.57.1, `src/composables/flowGraphLayout.js:129` and :329), a deterministic layered layout. The first human save stores the positions. A draft has no nested nodes, so the flat layout fits.

The package root does not export these two helpers: `src/index.js:438` exports `useFlowStore`, which uses them (`src/composables/useFlowStore.js:28`), but not the helpers themselves. The editor imports them through the package's `./src/*` export (`package.json` `exports`), and the vitest spec imports the same path, so a rename in the library fails the test instead of the page.

Rejected: a layout in PHP. It would be a second layout algorithm next to the one the shared library already ships and tests.

### D5. The caller's rights decide

The tool runs in the caller's session (ADR-034 Decision 7). `ArchitectureViewDraftService` writes through OpenRegister's `ObjectService` with RBAC and multitenancy on, so a caller without create rights on `view` gets a forbidden result and nothing is written. The draft is scoped to the caller's active organisation. `searchArchitectureElements` reads the same way, so it only returns elements the caller may see.

### D6. A draft stays out of shared readers

`architecture-views-editor` makes `GET /api/views` and the full ArchiMate export keep only views whose `origin` is empty or `imported`. `assistant` is neither, so a draft is never cached for all callers and never exported with the GEMMA model. This change adds no filter of its own and adds a test that the existing readers skip `assistant`.

## Declarative versus imperative

The draft is imperative: one call writes up to three kinds of object, resolves keys and reuses relations, which no `x-openregister-*` extension expresses. The status stays under the lifecycle `architecture-views-editor` declares, and the tool cannot move it past draft. No notification, aggregation or widget is added.

## Seed data

The fragment changes the `view`, `element` and `relation` schemas, so it seeds one drafted view next to the drawn examples `architecture-views-editor` seeds. All objects live in the `vng-gemma` register.

### Schema: `view`

| Field | Object 1 |
|---|---|
| slug | `seed-view-assistant-zaak-dms` |
| name | Zaaksysteem en documentbeheer, concept van de assistent |
| status | draft |
| origin | assistant |
| draftedFor | admin |
| draftedAt | 2026-09-27T09:00:00+00:00 |
| nodes | `seed-el-zaaksysteem` and `seed-el-dms`, without `x` and `y` |
| connections | one Flow connection that reuses `seed-rel-zaak-dms` |

The seed reuses the drawn example elements and relation, so it adds no element or relation of its own, and a fresh install shows the notice and the first-open layout on a real object.

## Risks

- **A wrong element.** The assistant may pick a GEMMA element that does not mean what the user meant. The notice and the draft status tell the reviewer the view is unreviewed, and `searchArchitectureElements` returns the GEMMA type and name so the assistant can show its picks.
- **Duplicate new elements.** An assistant can create "Documentbeheer" when a GEMMA element with that name exists. The service matches a new element's type and name against existing elements in the caller's scope and reuses an exact match.
- **Dependency order.** `lib/Mcp/StackiqToolProvider.php` does not exist until `mcp-full-action-surface` lands. This change is blocked on it.
- **A deep import.** The layout helpers come from `@conduction/nextcloud-vue/src/composables/flowGraphLayout.js`, not the package root. A library release that moves the file breaks the import at build time, which the vitest spec catches. Asking the library to export them from the root is the clean fix and can follow.
