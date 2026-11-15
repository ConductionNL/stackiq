---
kind: code
---

## Why

`@conduction/nextcloud-vue` 1.0.0-beta.212 turns the `liveUpdatesPlugin` on by default for
every `createObjectStore`-based store (lazy — fully inert until the first `subscribe()`
call) and fixes the first-subscription-stranded transport bug. OpenRegister already pushes
`or-object-{uuid}` and `or-collection-{register-slug}-{schema-slug}` events for all
OpenRegister-backed objects, so Softwarecatalog's store gains a working `subscribe(type,
id?)` API from the dependency bump alone. The module views all render reactively from
`objectStore.getCollection(type)`, so a collection subscription is the ONLY wiring needed —
the plugin's refetch lands exactly where the views read.

## What Changes

- Bump `@conduction/nextcloud-vue` to `^1.0.0-beta.212`.
- New `src/composables/useLiveCollections.js`: subscribes a store to the collection scope of
  a static type list, built on the library's `useObjectSubscription` (scope-bound release),
  gated on the lazy type registration in each view's `loadData()`.
- Wire it into the five store-rendered views: `KwetsbaarhedenView` (kwetsbaarheid, gebruik),
  `ComplianceMatrixView` (module, compliancy, element), `LicensePostureView` (module,
  gebruik, organisatie, contract), `LifecycleRoadmapView` (gebruik, moduleVersie, module,
  organisatie), `OrganisatieIndex` (organisatie).

## What Is Deliberately NOT Wired

- `Dashboard.vue` widgets and the declarative manifest pages: manifest surfaces render
  through the library's default `conduction-objects` store (no liveUpdatesPlugin, no
  `objectStore` prop on `CnIndexPage`) — that adoption belongs in
  `@conduction/nextcloud-vue`. Detail interactions go through modals/sidebars that reopen
  from fresh store state.

## Impact

- Affected specs: `realtime-updates-ui` (new)
- Affected code: `package.json`, `src/composables/useLiveCollections.js`, the five views
  listed above
