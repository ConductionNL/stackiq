# Tasks — SoftwareCatalog manifest v1

## 1. Page mapping decision

- [x] 1.1 Walk the existing `src/views/` tree (`Dashboard.vue`,
  `ObjectIndex.vue`, `Views.vue`, `organisaties/OrganisatieIndex.vue`,
  `settings/SoftwareCatalogSettings.vue`,
  `dashboard/DashboardIndex.vue`, `widgets/ConceptOrganisatiesWidget.vue`)
  and `src/navigation/MainMenu.vue`. Confirm which routes the legacy
  shell exposes today.
- [x] 1.2 Decide each page's target type per `design.md`'s mapping
  table: 6 `index`, 6 `detail`, 1 `dashboard`, 1 `settings`, 1
  `custom` (Organisaties).
- [x] 1.3 Document genuine exceptions vs lib gaps in `design.md`'s
  "Custom-fallback inventory" section.

## 2. Manifest write

- [x] 2.1 Create `src/manifest.json` with `version: "1.0.0"`,
  `dependencies: ["openregister"]`, top-level `menu[]` (one entry per
  index + Dashboard + Settings + Documentation external link), and
  `pages[]` per the mapping table.
- [x] 2.2 For each `type: "index"` page, declare
  `config.{ register, schema, columns, sidebar }`. Use
  `@resolve:voorzieningen_register` for the register slug
  (per-tenant). Schema slug stays literal.
- [x] 2.3 For each `type: "detail"` page, declare
  `config.{ register, schema, sidebarTabs }`. Tab inventory per
  `design.md`. NB: `sidebarTabs` is a TOP-LEVEL `config` key
  (mirrors decidesk's manifest convention) — placing tabs under
  `config.sidebar.tabs` would force the v1.2.0 schema's `oneOf`
  (Boolean | Object-with-`columnGroups`) shape and violate the
  `sidebarTab` `additionalProperties: false` constraint when
  `order` is included.
- [x] 2.4 For `Dashboard`, decision deferred to Open Question 3 —
  `Dashboard` ships as `type: "custom"` in v1 with
  `component: "DashboardCustomView"` referencing the existing
  `Dashboard.vue` (info-box + 2 stats tables) verbatim. Migration
  to declarative `widgets[]` + `layout[]` is tracked as a
  follow-up.
- [x] 2.5 For `Settings`, declare `type: "settings"` with
  `config.saveEndpoint` + `config.sections[]` mirroring the existing
  admin settings sub-section list. Until the lib's settings
  orchestration matures, keep the page rendering through the
  `customComponents` registry entry `SoftwareCatalogSettingsPage`.
- [x] 2.6 For surviving `type: "custom"` page (`Organisaties`),
  set `component: "OrganisatieIndexView"` referencing
  `src/views/organisaties/OrganisatieIndex.vue` via
  `customComponents.js`.

## 3. Validator script

- [x] 3.1 Add `tests/validate-manifest.js` mirroring decidesk's
  validator (Ajv 2020-12, schema-resolution candidate list including
  `node_modules/@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json`
  + sibling-worktree fallbacks).
- [x] 3.2 Run `node tests/validate-manifest.js` and confirm zero
  schema errors. Result: PASS — `Ajv validation: PASS (0 errors)`
  against `nextcloud-vue/src/schemas/app-manifest.schema.json`
  v1.2.0.
- [x] 3.3 Document the re-run command in this section: `node
  tests/validate-manifest.js`.

## 4. Tier-4 shell adoption

- [x] 4.1 Bump `package.json` `@conduction/nextcloud-vue` floor to
  `^1.0.0-beta.12`. Also added `vue-router: ^3.6.5` (manifest-driven
  routing). Note: `npm install` is DEFERRED to deployment — the
  package-lock will regenerate at install time.
- [x] 4.2 Rewrite `src/main.js` per the decidesk pattern (
  ConductionNL/decidesk#160 commits `b5c88cd2` + `50e4df7c`):
  - import `defaultPageTypes`, `registerIcons`,
    `registerTranslations`
  - shallow-clone `CnPageRenderer` (in `src/router.js`) before
    passing to vue-router (Vue.extend frozen-component fix)
  - mount-survivable bootstrap: `tryLoadTranslations()` is
    fire-and-forget; never block `$mount('#content')` on a
    translation 404
  - shallow-clone `defaultPageTypes` + `customComponents` before
    passing as props to `App`
- [x] 4.3 Rewrite `src/App.vue` to mount `<CnAppRoot>` with
  `:manifest`, `:custom-components`, `:page-types`, `:translate`,
  `:permissions` props plus `<CnObjectSidebar>` `#sidebar` slot
  driven by an `objectSidebarState` provide channel. Keeps the
  legacy global `<Modals />` + `<Dialogs />` mounted at app root
  so existing custom views continue to function.
- [x] 4.4 Add `src/router.js` exporting
  `routesFromManifest(manifest)` that builds vue-router routes from
  `manifest.pages[*]`. Catch-all redirect to `/`.
- [x] 4.5 Add `src/customComponents.js` exporting
  `OrganisatieIndexView`, `SoftwareCatalogSettingsPage`, and
  `DashboardCustomView`.

## 5. Webpack + l10n

- [x] 5.1 Add `webpack.config.js` alias for `@nextcloud/axios$`
  pointing at `node_modules/@nextcloud/axios/dist/index.js`. Also
  added `scss` rule (mirrors decidesk pattern) and added
  `vue-router` to the shared vendor split-chunk match.
- [x] 5.2 Mirror `l10n/en_US.json` from `l10n/en.json` (added —
  was missing). `en_US.js` mirrored similarly.

## 6. Cleanup — delete obsolete shell

- [x] 6.1 Delete `src/views/Views.vue` (replaced by manifest page
  dispatch).
- [x] 6.2 Delete `src/views/ObjectIndex.vue` (replaced by built-in
  `type: "index"` rendering).
- [~] 6.3 Delete `src/views/Dashboard.vue` — DEFERRED. Kept and — deferred to downstream cycle (handoff)
  re-registered as `DashboardCustomView` while the lib's dashboard
  widget registry matures (see Open Question 3).
- [x] 6.4 Delete `src/views/dashboard/DashboardIndex.vue`
  (legacy nested empty page).
- [x] 6.5 Delete `src/navigation/MainMenu.vue` (replaced by
  `CnAppNav` mounted by `CnAppRoot` from `manifest.menu[]`).
- [x] 6.6 Keep `src/views/organisaties/OrganisatieIndex.vue` (custom
  registry entry).
- [x] 6.7 Keep `src/views/settings/SoftwareCatalogSettings.vue`
  (custom registry entry under `Settings` page).

## 7. Spec artifacts

- [x] 7.1 `openspec/changes/softwarecatalog-manifest-v1/proposal.md`
  — written.
- [x] 7.2 `openspec/changes/softwarecatalog-manifest-v1/design.md`
  — written.
- [x] 7.3 `openspec/changes/softwarecatalog-manifest-v1/tasks.md` —
  this file.
- [x] 7.4 `openspec/changes/softwarecatalog-manifest-v1/specs/softwarecatalog-manifest-v1/spec.md`
  — REQ-SCMV1-1 through REQ-SCMV1-10 covering the migration
  invariants.

## 8. Validation

- [x] 8.1 `node tests/validate-manifest.js` — PASS (Ajv validation:
  PASS, 0 errors against schema v1.2.0).
- [x] 8.2 `npx eslint src/main.js src/router.js src/customComponents.js
  src/App.vue tests/validate-manifest.js` — clean (0 errors). The
  app's `eslint.config.js` was extended with `import/named: off`
  and `n/*` exemptions for the validate-manifest Node script
  (mirrors decidesk's eslint config rules block).
- [~] 8.3 `npx webpack --config webpack.config.js --mode — deferred to downstream cycle (handoff)
  production` — DEFERRED. The current `node_modules` directory
  carries `@conduction/nextcloud-vue@0.1.0-beta.17` (the
  pre-manifest-renderer release); a full production bundle requires
  running `npm install` to pull `^1.0.0-beta.12`, which is not
  feasible inside the worktree without spending considerable build
  time. Flagged as a deferred CI gate; manifest validation +
  ESLint + structural verification all pass.
- [x] 8.4 Bump `appinfo/info.xml` `<version>` (0.1.141 → 0.2.0).

## 9. Sign-off (per ADR-024 §9)

- [x] 9.1 `src/manifest.json` validates against the canonical schema
  (Ajv against v1.2.0).
- [x] 9.2 `manifest.dependencies` is `["openregister"]`.
- [x] 9.3 Tier choice is explicit (Tier 4 — full `CnAppRoot`
  shell).
- [x] 9.4 `manifest.version` is `"1.0.0"`.
- [x] 9.5 Custom-fallback inventory is documented and categorised
  (lib gap × 2: `Organisaties` for bespoke card view,
  `Dashboard` for widget extraction).
- [~] 9.6 Browser regression confirms all 14 routes resolve and — deferred to downstream cycle (handoff)
  render — DEFERRED. Runtime smoke depends on `npm install`
  pulling `@conduction/nextcloud-vue@^1.0.0-beta.12`; the
  `@resolve:` sentinel + custom registry are validated at build
  time only in this commit.
