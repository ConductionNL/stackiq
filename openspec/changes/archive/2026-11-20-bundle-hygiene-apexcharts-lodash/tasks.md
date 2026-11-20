# Tasks — bundle-hygiene-apexcharts-lodash

## 1. Remove unused apexcharts dependency

- [x] 1.1 Remove `"apexcharts"` from `package.json` `dependencies`.
- [x] 1.2 Regenerate `package-lock.json` (`npm install`) and confirm the
  top-level `node_modules/apexcharts` entry is gone while
  `@conduction/nextcloud-vue`'s nested copy remains untouched.
- [x] 1.3 Grep `src/` for `apexcharts`/`CnChartWidget` to reconfirm zero
  direct usages before removing (guards against a stale grep).

## 2. Scope lodash imports

- [x] 2.1 `src/modals/object/ObjectModal.vue:226` — replace
  `import _ from 'lodash'` with
  `import cloneDeep from 'lodash/cloneDeep'`; update the call site at
  `:467` from `_.cloneDeep(activeObject)` to `cloneDeep(activeObject)`.
- [x] 2.2 Grep `ObjectModal.vue` for any other `_.` usages beyond `:467`
  to confirm `cloneDeep` is the only lodash function used in this file
  before removing the barrel import.
- [x] 2.3 `src/sidebars/search/SearchSideBar.vue:52` — replace
  `import { debounce } from 'lodash'` with
  `import debounce from 'lodash/debounce'`; confirm the call site(s)
  using `debounce(...)` are unaffected by the import shape change.

## 3. Verification

- [x] 3.1 `npm run build` — confirm the production bundle no longer
  includes a top-level `apexcharts` chunk from stackiq's own
  dependency (only nc-vue's nested copy, if any chart widget pulls it in
  transitively).
- [x] 3.2 Run existing vitest suite (`npm run test:unit` or equivalent) to
  confirm `ObjectModal.vue` and `SearchSideBar.vue` behavior (clone /
  debounced search) is unchanged.
- [x] 3.3 `npm run test:l10n` and `npm run check:manifest` (if present) —
  confirm no unrelated regressions from the `package.json` edit.

## Build notes

20 Nov 2026: the code was already on development. `package.json` declares no `apexcharts`; the one locked copy, `node_modules/apexcharts` 4.7.0, is `@conduction/nextcloud-vue`'s own dependency. `ObjectModal.vue` imports `lodash/cloneDeep` and `SearchSideBar.vue` imports `lodash/debounce`; no `_.` call or lodash barrel import is left in `src/`. Since this change was written, `PortfolioReport.vue` has started using `CnChartWidget`. That is the spec's second scenario: a chart goes through nextcloud-vue, which brings apexcharts. `tests/vitest/bundleHygiene.spec.js` now guards all of this.
- 3.1: `npm run build` passes, and apexcharts lives only in `js/stackiq-shared-nc-vue.js` (from `node_modules/apexcharts/dist/apexcharts.esm.js`), once.
- 3.2: the `objectModal*` and `searchSideBar` vitest suites pass (8 files, 59 tests).
- 3.3: `npm run test:l10n` and `npm run check:manifest` pass.
