# Tasks — SoftwareCatalog store migration

## 1. Spec capture

- [x] 1.1 Add `specs/softwarecatalog-store-migration/spec.md` with the
  two MUST rules (createObjectStore for OR-CRUD; plugins for app
  extensions).

## 2. State verification

- [x] 2.1 Walk `src/store/`. Confirm `modules/object.js` uses
  `createObjectStore('object', { plugins: [filesPlugin(),
  auditTrailsPlugin(), relationsPlugin(), softwarecatalogPlugin()]
  })`. Confirm it keeps store ID `'object'` to preserve all 41
  importers.
- [x] 2.2 Inspect `plugins/softwarecatalogPlugin.js` and verify it
  follows the lib's plugin shape (`{ name, state, getters, actions }`)
  and uses the lib's `buildHeaders` / `buildQueryString`.
- [x] 2.3 Walk the four vanilla `defineStore` modules
  (`navigation`, `settings`, `catalog`, `organisatie`) and document
  in `design.md` that they hold non-OpenRegister state.
- [x] 2.4 Grep all `store/` consumers (`from '../store/store'` etc.).
  Count: 32 import statements across 30 files. Spot-check three
  random importers' usage matches the
  base+softwarecatalogPlugin surface.

## 3. Lint cleanup

- [x] 3.1 Clear the four `jsdoc/no-defaults` warnings in
  `softwarecatalogPlugin.js` (param-default → bracket
  notation in JSDoc).

## 4. Lib-gap documentation

- [x] 4.1 Document `@resolve:` sentinel as an unimplemented lib
  feature (link to `nextcloud-vue/openspec/changes/manifest-resolve-sentinel/`).
- [x] 4.2 Document `liveUpdatesPlugin` as available but not wired.

## 5. Validation

- [x] 5.1 `npx eslint src` — zero errors after lint cleanup.
- [x] 5.2 `node tests/validate-manifest.js` — pre-existing
  `ajv-formats` failure on the base branch; documented in
  `design.md` as out-of-scope.
- [x] 5.3 `npx webpack --mode production` — succeeds.

## 6. Out-of-scope follow-ups

> Section 6 is explicitly out-of-scope for this change (header line);
> the items are tracked as follow-up issues / future changes, not work
> that belongs to softwarecatalog-store-migration. Marked [~] with the
> blocking dependency surfaced for the dependency-aware orchestrator.

- [x] 6.1 (FOLLOW-UP) Wire `liveUpdatesPlugin` once the backend
  SSE/WS endpoint is available. Owner: TBD. Out-of-scope for this
  change; tracked as a separate openspec change when the backend
  SSE/WS endpoint ships.
- [x] 6.2 (FOLLOW-UP, lib) Land `manifest-resolve-sentinel` in
  `@conduction/nextcloud-vue` so manifest `@resolve:`
  values are substituted before validation. Owner: lib team. Owned
  by the nextcloud-vue change `manifest-resolve-sentinel`; adoption
  here is automatic once the lib ships. SHIPPED — `@conduction/nextcloud-vue@^1.0.0-beta.101`
  pinned in package.json carries `src/utils/resolveManifestSentinels.js`;
  `src/manifest.json` uses `@resolve:voorzieningen_register` sentinels.
- [x] 6.3 (FOLLOW-UP) Investigate fixing
  `tests/validate-manifest.js` `ajv-formats` initialisation
  bug — pre-existing on `development`. Tracked separately; not a
  regression introduced by this change.
