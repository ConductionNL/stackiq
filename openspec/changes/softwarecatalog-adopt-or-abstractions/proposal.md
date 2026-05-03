# SoftwareCatalog — adopt OR abstractions (manifest, register-resolver, multi-tenancy)

## Why

The 2026-05-03 OR-abstraction audit (`.claude/audit-2026-05-03/`)
identified the same three adoption gaps in SoftwareCatalog as in
LarpingApp:

1. **No architectural manifest** — SoftwareCatalog wires its router
   by hand and has no `src/manifest.json`. Per **ADR-024**
   (`hydra/openspec/architecture/`) and the migration order in
   `hydra/openspec/changes/adopt-app-manifest/`, SoftwareCatalog is
   in the second-wave cohort (small, schema-driven) — adopt after
   MyDash (the pilot).
2. **`getValueString(...register/schema...)` consolidation** — five
   service classes (`ModuleComplianceService`, `GebruikSyncService`,
   `OrganizationSyncService`, `ViewService`, plus the
   `UserProfileUpdatedEventListener`) resolve register / schema
   IDs from `IAppConfig::getValueString` per-call. The new
   `RegisterResolverService` from
   `openregister/openspec/changes/register-resolver-service/`
   consolidates the pattern. SoftwareCatalog has more call sites
   than LarpingApp.
3. **No multi-tenancy wiring** — SoftwareCatalog manages applications
   and organisations across municipalities. Frontend has no
   `useTenantContext()` wiring. When `multi-tenancy-context` ships
   in nc-vue, SoftwareCatalog adopts it for refetch and write
   header stamping.

> **Note**: This change concerns the **internal Conduction
> SoftwareCatalog app** at `/softwarecatalog/` (lowercase), NOT the
> VNG client repo at `Softwarecatalogus/` (capitalised). Per project
> memory the VNG repo is read-only and MUST NOT be committed to.

## What Changes

### Manifest adoption (Tier 2 → Tier 3)

- Add `src/manifest.json` with:
  - top-level menu entries: Apps, Components, Organisations,
    Catalogs, Concept Organisations, Settings
  - per-entity `index` pages (`type: "index"`) and `detail` pages
    (`type: "detail"`)
  - sidebars (currently in `src/sidebars/`) registered via the
    `slots` map for relevant pages
  - dialogs (currently in `src/dialogs/`) registered via
    `customComponents` for any `type: "custom"` pages
- Set `dependencies: ["openregister"]` (SoftwareCatalog's ADR-001
  already requires OR per its config.yaml).
- Tier 2 first; Tier 3 (manifest-driven nav) tracked as follow-up.

### `RegisterResolverService` consumption

- Replace `IAppConfig::getValueString` calls that resolve
  register/schema pairs in:
  - `lib/Service/ModuleComplianceService.php`
  - `lib/Service/GebruikSyncService.php`
  - `lib/Service/OrganizationSyncService.php`
  - `lib/Service/ViewService.php`
  - `lib/EventListener/UserProfileUpdatedEventListener.php`
- DI `RegisterResolverService` into each constructor.
- Keep `getValueString` calls for non-register keys (sync
  intervals, feature flags, admin tunables) on `IAppConfig`
  directly.

### Multi-tenancy wiring

- Adopt `useTenantContext()` in:
  - `src/views/` apps / components / organisations index views
  - and corresponding detail views
- Refetch on tenant switch.
- Stamp `X-OpenRegister-Organisation` on writes.

### i18n wiring

- Pass `?_lang=` on OR fetches.
- Pass `X-Translation-Target-Language` on writes when editing
  non-default-language content.
- Display "(translated from {lang})" badge on lists where the
  served language differs from `sourceLanguage`.

## Problem

SoftwareCatalog already complies with ADR-001 (data in OR) and
ADR-012 (nc-vue components only). The remaining adoption gap is
purely operational:

- **Hand-wired routes** — adding a new entity type means editing
  three places (`router/index.js`, `navigation/...`,
  `views/{type}/...`).
- **Five duplicated resolver call shapes** — each service that
  needs an OR object resolves register and schema IDs identically.
  A typo in one call returns silent empty results.
- **No tenant switch reactivity** — the frontend cannot tell when
  the active organisation changes. Lists show stale data on
  switch.
- **No language negotiation** — translatable application
  descriptions silently overwrite source language on edit.

The cohort solution exists; SoftwareCatalog adopts it.

## Proposed Solution

A single `softwarecatalog-adopt-or-abstractions` change with five
phases (see `tasks.md`):

1. Manifest at Tier 2.
2. `RegisterResolverService` consumption (5 files).
3. i18n wiring (`?_lang=`, `X-Translation-Target-Language`,
   `sourceLanguage` display).
4. Multi-tenancy wiring (gated on nc-vue release).
5. Manifest Tier 3 graduation (follow-up tracking).

Each phase is independently shippable.

## Out of Scope

- The VNG `Softwarecatalogus/` client repo. Read-only per project
  memory.
- Sync engine refactors. The three sync services
  (`Gebruik`, `Organization`, `Module`) keep their integration
  with external GEMMA / GitHub feeds; this change only consolidates
  their register/schema resolution.
- Custom-icon / image-upload paths. Untouched.
- Newman / API test suite reorganisation. Tracked in a separate
  swc-test concern.

## See also

- `openregister/openspec/changes/register-resolver-service/` — the
  service this change consumes.
- `openregister/openspec/changes/pluggable-integration-registry/`
  (ADR-019) — future GEMMA / GitHub sync sources may register as
  integration providers.
- `openregister/openspec/changes/i18n-source-of-truth/` (ADR-025).
- `openregister/openspec/changes/i18n-api-language-negotiation/`
  (ADR-025).
- `nextcloud-vue/openspec/changes/multi-tenancy-context/`.
- `hydra/openspec/changes/adopt-app-manifest/` — fleet-wide
  manifest convention (ADR-024).
- ADR-001 — All data in OR.
- ADR-012 — nc-vue components only.
- ADR-022 — Apps consume OR abstractions.
- ADR-024 — App manifest fleet-wide adoption.
- ADR-025 — i18n source-of-truth + API language negotiation.
- `.claude/audit-2026-05-03/` — source audit.
