# Tasks — adopt-live-updates-ui

## 1. Dependency

- [x] 1.1 Bump `@conduction/nextcloud-vue` to `^1.0.0-beta.212` (liveUpdatesPlugin
      default-on in `createObjectStore`; first-subscription transport fix).

## 2. View wiring

- [x] 2.1 `src/composables/useLiveCollections.js` — collection subscriptions for a static
      type list via the library's `useObjectSubscription`, gated on lazy type registration,
      released on scope dispose.
- [x] 2.2 Wire into `KwetsbaarhedenView`, `ComplianceMatrixView`, `LicensePostureView`,
      `LifecycleRoadmapView`, `OrganisatieIndex` (each subscribes to exactly the types its
      `getCollection`-backed computeds render).

## 3. Verification

- [x] 3.1 `npm run lint` clean on touched files.
- [x] 3.2 Unit suite green.
- [x] 3.3 `npm run build` green against the published beta.212 package.
