# SoftwareCatalog — store migration: createObjectStore + plugins

## Why

Project memory rule **`feedback_store-pattern.md`** is unambiguous:

> "Store pattern guidance — Do not use custom stores; use Options API
> with createObjectStore"

Decidesk **#162** is the canonical failure mode of *not* following
this rule: the live-updates plugin can't activate when an app keeps
its own custom Pinia store, because the lib's plugins reach into
fetchObject / fetchCollection on a `createObjectStore`-shaped store.
Apps with hand-rolled stores can't opt into:

- `liveUpdatesPlugin` (server-pushed cache invalidation),
- `searchPlugin`, `selectionPlugin`, `lifecyclePlugin` (full lib
  catalog),
- `useListView` / `useDetailView` composables (require a store
  exposing fetchCollection / fetchObject and registerObjectType).

Today's softwarecatalog tree under `src/store/`:

```
store.js                         (re-exports a Pinia composable per module)
modules/
  catalog.js                     defineStore — placeholder (4 actions)
  navigation.js                  defineStore — UI state (menu / modal / dialog / transferData)
  navigation.spec.js             jest spec for navigation
  object.js                      createObjectStore('object', { plugins: [...] })   ← already migrated
  organisatie.js                 defineStore — contactpersonen + user-mgmt actions
  settings.js                    defineStore — settings load/save + ArchiMate import-export polling
plugins/
  softwarecatalogPlugin.js       createObjectStore-compatible plugin (state/getters/actions)
```

PR #189 already migrated the **object** store to
`createObjectStore('object', { plugins: [filesPlugin(),
auditTrailsPlugin(), relationsPlugin(), softwarecatalogPlugin()] })`
and re-implemented the legacy single-app helpers as a
`softwarecatalogPlugin()` factory consumed by that same call. The
remaining `defineStore` modules deliberately stay vanilla: they hold
**non-OpenRegister** state (UI shell, settings shell, custom
backend endpoints).

This change is the **specification + verification** that the migration
is complete for the OpenRegister-CRUD surface, plus the spec entry
that future contributors must read before adding a new `defineStore`.

## What Changes

1. **Spec capture** — add `softwarecatalog-store-migration/spec.md`
   stating the two MUST rules:
   - Every store wrapping an OpenRegister-CRUD entity MUST be
     created via `createObjectStore(id, { plugins: [...] })`.
     Vanilla `defineStore` for OpenRegister CRUD is forbidden.
   - Per-app extensions to the CRUD surface (settings glue, mass
     ops, app-specific metadata) MUST be expressed as
     `createObjectStore` plugins (state/getters/actions),
     not separate `defineStore` modules with parallel CRUD
     methods. softwarecatalog's `softwarecatalogPlugin.js` is the
     reference implementation.

2. **Verify state** — confirm that:
   - `src/store/modules/object.js` matches the rule.
   - All 41 importers of `objectStore` from `store/store.js` keep
     working under the lib-backed store.
   - The four remaining vanilla `defineStore` modules
     (catalog, navigation, organisatie, settings) hold
     non-OpenRegister state and are exempt per the spec.

3. **Lib-gap flags** — document, but do not fix in-app:
   - `@resolve:` sentinel resolution is **not yet implemented** in
     `@conduction/nextcloud-vue` (the openspec change exists at
     `nextcloud-vue/openspec/changes/manifest-resolve-sentinel/`
     but tasks are unchecked). softwarecatalog's `manifest.json`
     uses 12 `@resolve:voorzieningen_register` sentinels that the
     consumer-side reader must currently still resolve manually
     until the lib lands the loader. This is tracked upstream;
     no in-repo workaround is added here.
   - `liveUpdatesPlugin` is **not** wired into softwarecatalog's
     plugin list yet. This change does NOT enable it (live-updates
     wiring is its own change with backend SSE/WS work). The
     migration only ensures the store *can* accept it without
     refactor.

4. **Lint cleanup** — clear the four `jsdoc/no-defaults` warnings
   in `softwarecatalogPlugin.js` (single-line edit per warning).

## Why Not …

- **Drop the vanilla defineStore modules and re-implement them as
  plugins.** Out of scope: those four hold non-CRUD state. A
  `defineStore` for UI shell state is the correct Pinia pattern;
  the project memory rule applies specifically to OpenRegister-CRUD
  stores.
- **Wire `liveUpdatesPlugin` here.** Out of scope: live updates need
  matching backend SSE/WS endpoints, exclusion-key wiring, and a
  dedicated test pass.
- **Implement the `@resolve:` sentinel in this app.** Out of scope:
  the loader belongs in `@conduction/nextcloud-vue` per the lib's
  own `manifest-resolve-sentinel` openspec change. Implementing it
  here would create drift the lib release will need to delete.

## References

- Project memory: `feedback_store-pattern.md`
- Decidesk failure case: ConductionNL/decidesk#162
- Lib openspec change: `nextcloud-vue/openspec/changes/manifest-resolve-sentinel/`
- Prior PR that migrated object.js + introduced softwarecatalogPlugin: ConductionNL/softwarecatalog#189
- Prior PR that introduced the `@resolve:` sentinel in manifest.json: ConductionNL/softwarecatalog#218
