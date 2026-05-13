# SoftwareCatalog — manifest v1: adopt JSON manifest renderer (Tier 4)

## Why

SoftwareCatalog has no `src/manifest.json` today. The app shell is a
hand-wired `MainMenu` + `Views` dispatcher driven by the
`navigationStore.selected` string, with one `ObjectIndex.vue` that
re-implements `CnIndexPage`'s job for every voorzieningen-register
schema. Per **ADR-024** (`hydra/openspec/architecture/adr-024-app-manifest.md`)
SoftwareCatalog is in the second-wave manifest cohort.

`@conduction/nextcloud-vue@1.0.0-beta.12` is now published and exposes
the full Tier-4 stack — `CnAppRoot`, `CnAppNav`, `CnPageRenderer`,
`defaultPageTypes`, plus seven page types (`index | detail |
dashboard | logs | settings | chat | files | custom`). The decidesk
migration (ConductionNL/decidesk#160, merged) is the canonical
reference for the migration and ships the surviving-custom +
`@resolve:` sentinel patterns.

This change ports the decidesk migration to softwarecatalog:

- 6 `type: "index"` pages — Organisaties, Contactpersonen, Contracten,
  Standaarden, Reviews, Komplianties (one per voorzieningen-register
  schema with a real existing route).
- 6 `type: "detail"` pages — one matching detail route per index above.
- 1 `type: "dashboard"` page — the existing `Dashboard.vue` info-box +
  two stats tables, expressed as `widgets[]` + `layout[]`.
- 1 `type: "settings"` page — the existing softwarecatalog admin
  settings (`SoftwareCatalogSettings.vue`).
- 1 `type: "custom"` survivor — the bespoke `OrganisatieIndex.vue`
  (it ships a custom `OrganisatieCard` template + a contactpersoon
  add modal inline).
- Register slugs are NOT hardcoded. They flow through `@resolve:`
  sentinels (`@resolve:voorzieningen_register`,
  `@resolve:amef_register_id`) — see `nextcloud-vue/openspec/changes/manifest-resolve-sentinel/proposal.md`.
  This honours the existing per-tenant `IAppConfig` keys
  (`softwarecatalog.voorzieningen_register`,
  `softwarecatalog.amef_register_id`) referenced from
  `lib/Service/SettingsService.php`. Schema slugs (`organisatie`,
  `contactpersoon`, …) are stable across tenants and stay literal.

The cleanup follow-up (decidesk's commit pattern) is folded in:
`@conduction/nextcloud-vue` is bumped to `^1.0.0-beta.12`,
`src/main.js` + `src/App.vue` are rewritten to mount `CnAppRoot` +
`CnPageRenderer`, the legacy `MainMenu.vue` / `Views.vue` /
`ObjectIndex.vue` / `Dashboard.vue` shell is replaced with
manifest-driven dispatch, and `src/customComponents.js` is added with
the surviving custom registry entries.

## What Changes

- **Add `src/manifest.json`** — declarative manifest with 14 page
  entries (6 index, 6 detail, 1 dashboard, 1 settings, 1 custom),
  validated against the published v1.x schema. Register slug values
  use the `@resolve:` sentinel.
- **Bump `@conduction/nextcloud-vue` to `^1.0.0-beta.12`** in
  `package.json`. The lib now ships the full manifest renderer.
- **Rewrite `src/main.js`** — derive vue-router routes from
  `manifest.pages[*]`, mount each route with `CnPageRenderer`,
  shallow-clone `defaultPageTypes` + `customComponents` before
  passing them as props (Vue 2 `Vue.extend()` mutation guard).
  Mount-survivable bootstrap (decidesk pattern from `50e4df7c`):
  `tryLoadTranslations()` is fire-and-forget so a 404 on the
  `l10n/{locale}.json` route never blocks `$mount('#content')`.
- **Rewrite `src/App.vue`** — replace `NcContent` + `MainMenu` +
  `Views` with `<CnAppRoot>` + `<CnObjectSidebar>` slot, providing
  the `objectSidebarState` channel.
- **Add `src/customComponents.js`** — exports the surviving custom
  components: `OrganisatieIndexView` (the bespoke organisations
  index with `OrganisatieCard` + `AddContactpersoonModal`) and
  `SoftwareCatalogSettingsPage` (the admin settings tab).
- **Add `src/router.js`** — `routesFromManifest()` helper, mirrors
  decidesk's main.js builder.
- **Add webpack alias** for `@nextcloud/axios$` (decidesk pattern;
  works around `@nextcloud/vue`'s CJS bundle still requiring
  `@nextcloud/axios` while the package's `exports` field only
  declares the `import` condition).
- **Mirror `l10n/en_US.json`** from `l10n/en.json` (already mirrored;
  this change keeps it in sync and adds new manifest-driven keys).
- **Update `appinfo/info.xml`** — bump `<version>` (current 0.1.140 →
  0.2.0 to mark the manifest-renderer adoption).
- **Add `tests/validate-manifest.js`** — Node script that loads the
  manifest and validates against the v1.x schema (mirrors decidesk's
  `tests/validate-manifest.js`).
- **Delete legacy shell** — `src/views/Views.vue`,
  `src/views/ObjectIndex.vue`, `src/views/Dashboard.vue`,
  `src/views/dashboard/DashboardIndex.vue`,
  `src/navigation/MainMenu.vue`. (`src/views/organisaties/OrganisatieIndex.vue`
  and `src/views/settings/SoftwareCatalogSettings.vue` are KEPT and
  registered in `customComponents.js`; the rest are obsoleted by
  built-in page types.)

## Custom-fallback inventory

Pages that stay `type: "custom"` after this change:

| Page id | Reason | Category |
|---|---|---|
| `Organisaties` | Bespoke `OrganisatieCard` + inline `AddContactpersoonModal`/`OrganisationModal`. The custom card view + contact-add flow doesn't fit `CnIndexPage`'s built-in form-dialog override yet. | Lib gap |
| `Settings` | `SoftwareCatalogSettings.vue` orchestrates ArchiMate import/export, status polling, register selection, and 8 sub-section tabs (`SectionGeneral`, `SectionRegisters`, `SectionImportExport`, `SectionEmail`, etc.). The new `type: "settings"` shape covers field/widget rendering but not the sub-section tab orchestration. | Lib gap |

Total: **2 customs** (vs. decidesk's `LiveMeeting` + `Settings` =
2 customs). Pattern matches.

> **NOTE:** the `Settings` route is initially declared as `type:
> "settings"` to honour the manifest contract; the actual rendering
> still falls through to the custom registry entry
> `SoftwareCatalogSettingsPage` while the lib's `type: "settings"`
> rich-section orchestration matures. Tracked as Open Question 1
> below.

## Capabilities

### New Capabilities

- `softwarecatalog-manifest-v1` — full manifest adoption + Tier-4
  shell adoption (CnAppRoot + CnPageRenderer + custom registry).

## Impact

- **New files**:
  - `softwarecatalog/src/manifest.json` — declarative manifest.
  - `softwarecatalog/src/customComponents.js` — surviving custom
    registry.
  - `softwarecatalog/src/router.js` — `routesFromManifest()`.
  - `softwarecatalog/tests/validate-manifest.js` — Ajv schema
    validator.

- **Modified files**:
  - `softwarecatalog/src/main.js` — Vue 2 bootstrap rewrite around
    `CnAppRoot`.
  - `softwarecatalog/src/App.vue` — `<CnAppRoot>` shell.
  - `softwarecatalog/package.json` — `@conduction/nextcloud-vue`
    floor bumped to `^1.0.0-beta.12`.
  - `softwarecatalog/webpack.config.js` — add `@nextcloud/axios$`
    alias.
  - `softwarecatalog/appinfo/info.xml` — `<version>` bump.
  - `softwarecatalog/l10n/en_US.json` — mirror of `en.json` (already
    mirrored; no-op edit OK).

- **Deleted files**:
  - `softwarecatalog/src/views/Views.vue`
  - `softwarecatalog/src/views/ObjectIndex.vue`
  - `softwarecatalog/src/views/Dashboard.vue`
  - `softwarecatalog/src/views/dashboard/DashboardIndex.vue`
  - `softwarecatalog/src/navigation/MainMenu.vue`

- **Validates against**:
  - `node_modules/@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json`
    (v1.x; the `@conduction/nextcloud-vue@1.0.0-beta.12` release).

## Risks

- **`@resolve:` sentinel is a v1.x feature** — relies on the loader
  in `useAppManifest()` performing the substitution. If the consumer
  bumps to a `@conduction/nextcloud-vue` build prior to the resolver
  change, register-slug fields render literally as
  `"@resolve:voorzieningen_register"` and the renderer emits its
  "register not configured" empty state. Mitigated by pinning to
  `^1.0.0-beta.12` (the published build that ships the resolver).
- **Custom-component overrides may be incomplete.** The bespoke
  `OrganisatieCard` lives behind a `#card` slot on `CnIndexPage`,
  which is only available when the index is rendered through a
  consumer-owned wrapper component. Until the lib's manifest-driven
  `CnIndexPage` exposes a `cardComponent` config field, the
  Organisaties page must stay `type: "custom"`. Tracked as Open
  Question 2.
- **Settings sub-section orchestration.** `SoftwareCatalogSettings.vue`
  orchestrates 8 sub-section components, ArchiMate status polling,
  and a custom register-selector dropdown. The lib's
  `type: "settings"` `widgets[]` rich-sections cover individual
  widget rendering but not the full orchestration. Settings stays
  `type: "custom"` (under the rendered `route: "/settings"` path)
  until the lib's settings renderer matures.

## Out of scope

- **Multi-tenancy consumer wiring.** `useTenantContext()` adoption is
  parked for `softwarecatalog-multi-tenancy-v1`.
- **i18n consumer wiring.** Language selector / translation header /
  `sourceLanguage` badges are parked for `softwarecatalog-i18n-v1`.
- **`RegisterResolverService` consumption.** PHP-side resolver
  consolidation lives in the parallel
  `softwarecatalog-adopt-or-abstractions` change (Phase 2). This
  change consumes the same IAppConfig keys via the manifest's
  `@resolve:` sentinel, but does NOT refactor the PHP resolver path.
- **Backend `/api/manifest` endpoint.** Driven by App Builder; not
  blocking for Tier-4 adoption.

## See also

- `decidesk/openspec/changes/decidesk-manifest-v1/` — canonical
  reference. Three-phase migration (manifest rewrite → Tier-4
  adoption → sidebar tab implementations).
- `https://github.com/ConductionNL/decidesk/pull/160` — merged
  reference PR.
- `nextcloud-vue/openspec/changes/manifest-resolve-sentinel/proposal.md`
  — `@resolve:` sentinel design (per-tenant register slug
  resolution).
- `hydra/openspec/architecture/adr-024-app-manifest.md` —
  fleet-wide manifest convention.
- `softwarecatalog/openspec/changes/softwarecatalog-adopt-or-abstractions/`
  — broader OR-adoption umbrella (5 phases). This change ships
  Phase 1 (manifest pilot) at Tier 4.
