# SoftwareCatalog — Store-migration Spec (delta)

## ADDED Requirements

### Requirement: createObjectStore for OpenRegister-CRUD stores

OpenRegister-CRUD stores MUST be created via `createObjectStore`.

Every Pinia store in softwarecatalog that wraps an
**OpenRegister-CRUD entity** (an OR `register / schema / object`
triple) MUST be instantiated via
`createObjectStore(id, { plugins: [...] })` from
`@conduction/nextcloud-vue`. Vanilla `defineStore` MUST NOT be used
for OpenRegister-CRUD stores.

The factory contract gives apps:

- a uniform `fetchObject(type, id)` /
  `fetchCollection(type, params)` / `saveObject(type, data)` /
  `patchObject(type, id, changes)` / `deleteObject(type, id)`
  surface,
- compatibility with lib plugins (`liveUpdatesPlugin`,
  `searchPlugin`, `selectionPlugin`, `lifecyclePlugin`,
  `auditTrailsPlugin`, `relationsPlugin`, `filesPlugin`,
  `logsPlugin`, `registerMappingPlugin`),
- compatibility with the lib composables (`useListView`,
  `useDetailView`).

#### Scenario: object store uses createObjectStore

- **GIVEN** the store at `src/store/modules/object.js`
- **WHEN** the file is parsed
- **THEN** the default export MUST be the result of
  `createObjectStore('object', { plugins: [...] })`
- **AND** the store ID `'object'` MUST be preserved across
  refactors so existing `from '../store/store.js'` importers
  do not break

#### Scenario: registerObjectType is invoked on bootstrap

- **GIVEN** the softwarecatalogPlugin's `initializeVoorzieningenObjectTypes`
  action
- **WHEN** `fetchSettings` resolves a non-empty `availableRegisters`
  list with a `voorzieningen` register
- **THEN** every schema in that register MUST be registered via
  `registerObjectType(slug, schemaId, registerId)` so subsequent
  `fetchCollection(slug)` calls have a known
  `register / schema` mapping

### Requirement: plugin shape for app-specific extensions

App-specific store extensions MUST be expressed as createObjectStore plugins.

Per-app extensions to the OpenRegister-CRUD surface (settings glue,
mass operations, app-specific metadata, related-data fan-out) MUST
be expressed as a `createObjectStore` plugin — a factory returning
`{ name, state, getters, actions }` — and added to the plugins array
on the same `createObjectStore` call. Apps MUST NOT create
parallel `defineStore` modules with their own CRUD methods that
duplicate the lib base.

#### Scenario: plugin uses lib helpers

- **GIVEN** `src/store/plugins/softwarecatalogPlugin.js`
- **WHEN** the file is parsed
- **THEN** it MUST import `buildHeaders` and `buildQueryString` from
  `@conduction/nextcloud-vue` rather than redefining them locally
- **AND** it MUST export a factory function
  `softwarecatalogPlugin()` that returns
  `{ name: 'Softwarecatalog', state, getters, actions }`

#### Scenario: legacy CRUD signatures keep working

- **GIVEN** the softwarecatalogPlugin's `saveObject` and
  `deleteObject` actions
- **WHEN** an existing view calls
  `objectStore.saveObject(objectItem, { register, schema })` (legacy)
  or `objectStore.saveObject('organisatie', objectData)` (new)
- **THEN** both signatures MUST resolve to the same
  OpenRegister POST/PUT call against
  `/index.php/apps/openregister/api/objects/{registerId}/{schemaId}[/{id}]`

### Requirement: vanilla defineStore allowed for non-CRUD state

Non-CRUD stores MAY use vanilla defineStore and MUST NOT be migrated to createObjectStore.

Stores that hold UI shell state (active menu item, modal/dialog
flags), settings glue (load/save against an app-specific endpoint,
ArchiMate import polling), or wrappers over **non-OpenRegister
backend endpoints** (softwarecatalog's `/api/contactpersonen/*`,
`/api/email/*`, `/api/user-groups/*`) MAY use vanilla `defineStore`.

These stores MUST NOT be migrated to `createObjectStore` —
`createObjectStore` exposes a CRUD surface
(`register / schema / objectId` URL builder) that has no semantics
for these endpoints.

#### Scenario: non-CRUD stores remain vanilla

- **GIVEN** the four current vanilla stores
  `navigation`, `settings`, `catalog`, `organisatie`
- **WHEN** linted and built
- **THEN** they MUST remain `defineStore`-based
- **AND** their state MUST NOT include OpenRegister
  `register` / `schema` / `objectId` references that would
  qualify them as OR-CRUD stores
