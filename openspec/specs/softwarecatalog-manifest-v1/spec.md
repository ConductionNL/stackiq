---
status: done
---

# stackiq-manifest-v1 Specification

## Purpose
Defines the version 1.0.0 architectural manifest that drives Stackiq's UI declaratively: schema-backed list pages become type "index", single-object pages become type "detail" with sidebar tabs, the settings page becomes type "settings", and only two custom-fallback pages remain. Register slugs are driven by the @resolve:voorzieningen_register sentinel for per-tenant configurability, the manifest validates against the nc-vue schema, and bootstrap mounts CnAppRoot with shallow-cloned registries.
## Requirements
### Requirement: REQ-SCMV1-1 Stackiq MUST ship `src/manifest.json`

The repository MUST contain `src/manifest.json` referencing the
`@conduction/nextcloud-vue` app-manifest schema as its `$schema`
URL, with `version: "1.0.0"` and
`dependencies: ["openregister"]`.

#### Scenario: Manifest exists at the canonical path
- GIVEN a Stackiq repository checkout
- WHEN reading `src/manifest.json`
- THEN the file MUST exist
- AND `manifest.version` MUST equal `"1.0.0"`
- AND `manifest.dependencies` MUST equal `["openregister"]`

### Requirement: REQ-SCMV1-2 Index pages MUST be `type: "index"` with declarative config

Schema-backed list pages MUST be declared as `type: "index"` with declarative config. Pages that render schema-backed list views — `Contactpersonen`, `Contracten`, `Standaarden`, `Reviews`, `Komplianties`, `Moduleversies` — MUST declare `type: "index"`. Each entry MUST
declare `config.register`, `config.schema`, and
`config.columns: string[]`. The `config.register` value MUST be
`"@resolve:voorzieningen_register"` so the loader resolves the
per-tenant register slug at manifest-load time.

#### Scenario: Contactpersonen index validates with sentinel register
- GIVEN `src/manifest.json` page entry for `Contactpersonen` with `type: "index"`, `config: { register: "@resolve:voorzieningen_register", schema: "contactpersoon", columns: [...] }`
- WHEN `validateManifest()` runs against the v1.x schema
- THEN it MUST return `{ valid: true, errors: [] }`

#### Scenario: All six index pages use the sentinel
- GIVEN the manifest's six `type: "index"` page entries
- WHEN inspecting each entry's `config.register`
- THEN every entry MUST equal `"@resolve:voorzieningen_register"`

### Requirement: REQ-SCMV1-3 Detail pages MUST be `type: "detail"` with `sidebarTabs`

Single-object detail pages MUST be declared as `type: "detail"` with `sidebarTabs`. Pages that render single-object detail views — `ContactpersoonDetail`, `ContractDetail`, `StandaardDetail`, `ReviewDetail`, `KompliantieDetail`, `ModuleversieDetail` — MUST declare `type: "detail"`. Each entry MUST declare
`config.register: "@resolve:voorzieningen_register"`,
`config.schema: <slug>`, and
`config.sidebarTabs: SidebarTab[]` (top-level `sidebarTabs` array,
preserving compatibility with decidesk's manifest convention) with
at minimum an `overview`
tab and an `audit` tab.

#### Scenario: ContractDetail dispatches via detail with sidebarTabs
- GIVEN `pages[]` contains `{ id: "ContractDetail", route: "/contracten/:id", type: "detail", title: "Contract", config: { register: "@resolve:voorzieningen_register", schema: "contract", sidebarTabs: [...] } }`
- WHEN `validateManifest()` runs
- THEN it MUST return `{ valid: true, errors: [] }`

### Requirement: REQ-SCMV1-4 Custom-fallback inventory MUST stay at exactly 2 entries

After this migration, exactly two pages MUST stay `type: "custom"`:
`Organisaties` (bespoke card view + add-contactpersoon flow) and
`Dashboard` (info-box + 2 stats tables; pending widget extraction).
Each surviving custom entry MUST declare its `component` field
referencing `customComponents.js`.

#### Scenario: Exactly two custom pages
- GIVEN `src/manifest.json`
- WHEN counting `pages[*].type === "custom"`
- THEN the count MUST be exactly 2
- AND the two ids MUST be `Organisaties` and `Dashboard`

#### Scenario: Custom pages reference customComponents entries
- GIVEN the `Organisaties` and `Dashboard` page entries
- WHEN inspecting each `component` field
- THEN `Organisaties.component` MUST equal `"OrganisatieIndexView"`
- AND `Dashboard.component` MUST equal `"DashboardCustomView"`

### Requirement: REQ-SCMV1-5 The settings page MUST be `type: "settings"`

The `Settings` page MUST declare `type: "settings"` with
`config.saveEndpoint` pointing at the existing
`/index.php/apps/stackiq/api/settings` endpoint. The
section orchestration MUST delegate to a custom component
(`StackiqSettingsPage`) registered in `customComponents.js`
while the library's settings orchestration matures.

#### Scenario: Settings declares type=settings and delegates to custom registry
- GIVEN `pages[]` contains `{ id: "Settings", route: "/settings", type: "settings", config: { saveEndpoint: "/index.php/apps/stackiq/api/settings", sections: [...] } }`
- WHEN `validateManifest()` runs
- THEN it MUST return `{ valid: true, errors: [] }`
- AND at least one section MUST reference `StackiqSettingsPage` via `widgets[].component` or a `custom` widget

### Requirement: REQ-SCMV1-6 The manifest version MUST be 1.0.0

`src/manifest.json`'s top-level `version` field MUST equal
`"1.0.0"` to mark Tier-4 adoption parity with decidesk.

#### Scenario: Version is 1.0.0
- GIVEN `src/manifest.json`
- WHEN reading `manifest.version`
- THEN it MUST equal `"1.0.0"`

### Requirement: REQ-SCMV1-7 Manifest MUST validate against the v1.x schema

`src/manifest.json` MUST validate without errors against the
`@conduction/nextcloud-vue` v1.0.0-beta.12 (or later) app-manifest
schema. Validation MUST be runnable from the repo with `node
tests/validate-manifest.js`.

#### Scenario: Validator script exits 0
- GIVEN the migrated `src/manifest.json`
- AND the schema bundle from
  `node_modules/@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json`
- WHEN running `node tests/validate-manifest.js`
- THEN the script MUST exit with status code 0
- AND it MUST print a success line confirming zero validation errors

### Requirement: REQ-SCMV1-8 Bootstrap MUST mount CnAppRoot with shallow-cloned registries

`src/main.js` MUST import `CnAppRoot` (via `App.vue`),
`CnPageRenderer`, and `defaultPageTypes` from
`@conduction/nextcloud-vue`, build vue-router routes from the
manifest, and pass shallow-cloned `defaultPageTypes` and
`customComponents` as props to `App.vue`. The clone is REQUIRED
to avoid Vue 2's `Vue.extend()` mutation guard ("Cannot add
property `_Ctor`, object is not extensible") against frozen module
exports.

#### Scenario: defaultPageTypes is shallow-cloned at bootstrap
- GIVEN `src/main.js`
- WHEN reading the bootstrap code
- THEN it MUST include a shallow clone of `defaultPageTypes` (e.g. `{ ...defaultPageTypes }`) before passing to `App.vue`'s `pageTypes` prop

#### Scenario: customComponents is shallow-cloned at bootstrap
- GIVEN `src/main.js`
- WHEN reading the bootstrap code
- THEN it MUST include a shallow clone of `customComponents` (e.g. `{ ...customComponents }`) before passing to `App.vue`'s `customComponents` prop

### Requirement: REQ-SCMV1-9 `@resolve:` sentinel MUST drive register slugs

Every `index` and `detail` page's `config.register` MUST equal
`"@resolve:voorzieningen_register"` (NOT a literal slug). This
ensures per-tenant configurability — admins changing the
Stackiq `voorzieningen_register` IAppConfig key see the
manifest reflect that change without a rebuild.

#### Scenario: No literal voorzieningen register slug
- GIVEN every page in `src/manifest.json` of type `index` or `detail`
- WHEN inspecting each entry's `config.register`
- THEN no entry MUST contain a literal value (e.g. `"voorzieningen"`)
- AND every entry MUST equal `"@resolve:voorzieningen_register"`

### Requirement: REQ-SCMV1-10 Webpack MUST alias `@nextcloud/axios$`

`webpack.config.js` MUST add an exact-match alias for
`@nextcloud/axios$` pointing at the package's CJS entry
(`node_modules/@nextcloud/axios/dist/index.js`). This works around
`@nextcloud/vue`'s CJS bundle still requiring `@nextcloud/axios`
while the package's `exports` field only declares the `import`
condition.

#### Scenario: webpack alias exists
- GIVEN `stackiq/webpack.config.js`
- WHEN reading the resolved webpack config
- THEN `webpackConfig.resolve.alias['@nextcloud/axios$']` MUST equal `path.resolve(__dirname, 'node_modules/@nextcloud/axios/dist/index.js')`

