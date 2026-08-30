# Design — SoftwareCatalog store migration

## Architectural decision: keep the four vanilla stores

The project-memory rule reads "Do not use custom stores; use Options
API with createObjectStore." Read literally that would suggest every
Pinia store in the app moves to `createObjectStore`. That reading
is wrong, and the lib's API confirms it: `createObjectStore` is a
factory **for OpenRegister CRUD** — its base accepts `register` /
`schema` per type, exposes `fetchObject` / `fetchCollection` /
`patchObject` / `lockObject` / `publishObject` and so on. State that
isn't a CRUD wrapper around an OpenRegister entity does NOT fit the
factory.

softwarecatalog has five Pinia stores and we map each to its correct
shape:

| Store         | Shape                              | What it holds                                                    | Decision         |
| ------------- | ---------------------------------- | ---------------------------------------------------------------- | ---------------- |
| object        | `createObjectStore` + 4 plugins    | All OpenRegister CRUD across voorzieningen schemas               | Migrated         |
| navigation    | vanilla `defineStore`              | `selected` menu item, modal, dialog, transferData                | Stay vanilla     |
| settings      | vanilla `defineStore`              | settings load/save, ArchiMate import/export polling, configs    | Stay vanilla     |
| catalog       | vanilla `defineStore`              | placeholder (currentCatalog, loading, error)                    | Stay vanilla     |
| organisatie   | vanilla `defineStore`              | contactpersoon endpoints, user-mgmt (password, groups, enable)  | Stay vanilla     |

The four vanilla stores hit **softwarecatalog-specific backend
endpoints** under `/index.php/apps/softwarecatalog/api/...`, NOT
OpenRegister. Forcing them through `createObjectStore` would mean:

- inventing fake "type" registrations for non-entity APIs
  (ArchiMate import status, email config, user-group config),
- tunnelling state mutations through an unfit
  `register / schema / objectId` URL builder, and
- exposing a CRUD surface (`fetchObject`, `patchObject`, etc.)
  that has no semantics for these endpoints.

That's the same anti-pattern the rule was written to avoid, just
inverted.

## What the object store now exposes

The migration centred on `src/store/modules/object.js`:

```js
import { createObjectStore, filesPlugin, auditTrailsPlugin, relationsPlugin } from '@conduction/nextcloud-vue'
import { softwarecatalogPlugin } from '../plugins/softwarecatalogPlugin.js'

export const useObjectStore = createObjectStore('object', {
  plugins: [
    filesPlugin(),
    auditTrailsPlugin(),
    relationsPlugin(),
    softwarecatalogPlugin(),
  ],
})
```

The `'object'` ID matches the legacy Pinia store ID, so all 41
existing importers of `objectStore` from `store/store.js` keep
working without source edits. The plugin contributes everything
the legacy store had that the lib base does not:

- `settings`, `objectItem`, `activeObjects`, `relatedData`,
  `selectedObjects`, `success`, `objectErrors`, `metadata`,
  `properties`, `columnFilters` state slots
- 16 lib-getter shims (`objectTypes`, `availableRegisters`,
  `availableSchemas`, `getActiveObject`, `getRelatedData`,
  `getAuditTrails`, `getCollection` array-or-results normaliser, …)
- 27 actions split across:
  1. settings management (`fetchSettings`,
     `initializeVoorzieningenObjectTypes`, `getSchemaConfig`)
  2. active-object management (`setActiveObject`, `clearActiveObject`,
     `setObjectItem`, `downloadObject`, `fetchRelatedData`)
  3. CRUD shims that accept BOTH the legacy
     `(objectItem, {register,schema})` AND the new
     `(type, data)` signatures (`saveObject`, `deleteObject`,
     `patchObject`, `copyObject`)
  4. lifecycle ops (`publishObject`, `depublishObject`, `lockObject`,
     `unlockObject`, `validateObject`)
  5. mass ops (`_runMassOperation`, `massPublishObjects`,
     `massDepublishObjects`, `massDeleteObjects`, `massLockObjects`,
     `massUnlockObjects`, `massValidateObjects`)
  6. selection mgmt (`setSelectedObjects`, `toggleSelectAllObjects`)
  7. error mgmt (`setObjectError`, `clearObjectError`,
     `clearAllObjectErrors`, `getObjectError`)
  8. column mgmt (`updateColumnFilter`, `initializeProperties`,
     `initializeColumnFilters`)
  9. merge & migration (`mergeObjects`, `getMappings`,
     `refreshObjectList`)
  10. state mgmt (`setState`, `clearSoftwarecatalog`)

## Plugin fates

This change creates no new plugins. For the record, the existing
plugin's responsibilities map to lib equivalents as follows:

| softwarecatalogPlugin section | Lib alternative          | Decision                                                                                                                                                                            |
| ----------------------------- | ------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| settings management           | none                     | KEEP local — softwarecatalog has its own `/api/settings` shape (voorzieningen + amef configs, version info, etc.) that the lib doesn't model.                                       |
| active-object management      | partly `selectionPlugin` | KEEP local — `activeObjects` is keyed-per-type with related-data fan-out (`logs`/`uses`/`used`/`files`), which is richer than `selectionPlugin`'s single-active-object focus.       |
| CRUD shims (dual signature)   | base store               | KEEP local — necessary for legacy `(objectItem, {register,schema})` callers in 41 view files. Could be deprecated incrementally; out-of-scope here.                                |
| lifecycle ops                 | `lifecyclePlugin`        | KEEP local — softwarecatalog's `lockObject` accepts `(process, duration)` extras the base `lifecyclePlugin` doesn't carry. Refactoring is its own change.                          |
| mass ops                      | `selectionPlugin`        | KEEP local — `_runMassOperation` adds per-object error tracking (`setObjectError`) the lib doesn't yet model. Refactoring is its own change.                                       |
| selection mgmt                | `selectionPlugin`        | KEEP local — couples to the local `objectErrors` flow.                                                                                                                              |
| error mgmt                    | none                     | KEEP local — per-object error keyed map (used by mass-ops UX).                                                                                                                      |
| column mgmt                   | none                     | KEEP local — `metadata` + `properties` + `columnFilters` are softwarecatalog-specific UI state.                                                                                    |
| merge & migration             | none                     | KEEP local — `mergeObjects` is a softwarecatalog escalation flow over the OR merge endpoint.                                                                                       |

## Lib gaps flagged

### Gap 1 — `@resolve:` sentinel not implemented

`src/manifest.json` ships 12 `@resolve:voorzieningen_register`
sentinels (PR #218). The lib openspec change
`nextcloud-vue/openspec/changes/manifest-resolve-sentinel/` defines
the loader semantics:

> "The loader walks an object tree and replaces every `@resolve:{key}`
> string with the result of `getAppConfigValue(appId, key)`. … walks
> only `pages[].config` subtrees by default."

Tasks are unchecked. Phase 1 (`src/utils/resolveManifestSentinels.js`)
is **not** implemented. The frontend still receives literal
`@resolve:voorzieningen_register` strings as the `register` field
on 12 manifest pages. Until the loader lands, every consumer (incl.
softwarecatalog) must either:

- pre-resolve the manifest server-side before serving it, OR
- substitute at runtime in the consumer (forbidden — that's exactly
  the divergence the lib change is supposed to prevent).

This change DOES NOT fix the gap. It flags it for the lib roadmap.

### Gap 2 — `liveUpdatesPlugin` not wired

The motivation for the project-memory rule (decidesk #162) is that
`liveUpdatesPlugin` requires `fetchObject` / `fetchCollection` on the
store. softwarecatalog's `useObjectStore` now satisfies that contract
via `createObjectStore`, but the plugin is NOT in the plugin list.
Adding it requires:

- backend SSE/WS endpoint exposing object-changed events,
- a plugin-options block declaring the exclusion key namespace,
- end-to-end test of cache-invalidation on remote edits.

That work belongs in its own change; the migration only unblocks it.

## Validation checklist

- `npx eslint src` — zero new errors. Pre-existing warnings
  (`jsdoc/no-defaults` × 4 in `softwarecatalogPlugin.js`) cleared
  in this change.
- `node tests/validate-manifest.js` — PRE-EXISTING failure
  unrelated to store migration (`ajv-formats` constructor
  TypeError on `addFormats` — a tooling bug introduced separately).
  Documented; not in-scope.
- `npx webpack --mode production` — succeeds, three
  entrypoint-size warnings (pre-existing).
- All 41 importers of `objectStore` from `store/store.js`
  continue to compile and resolve their named exports.
