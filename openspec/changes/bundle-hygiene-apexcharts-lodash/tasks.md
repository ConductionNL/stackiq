# Tasks — bundle-hygiene-apexcharts-lodash

## 1. Remove unused apexcharts dependency

- [ ] 1.1 Remove `"apexcharts"` from `package.json` `dependencies`.
- [ ] 1.2 Regenerate `package-lock.json` (`npm install`) and confirm the
  top-level `node_modules/apexcharts` entry is gone while
  `@conduction/nextcloud-vue`'s nested copy remains untouched.
- [ ] 1.3 Grep `src/` for `apexcharts`/`CnChartWidget` to reconfirm zero
  direct usages before removing (guards against a stale grep).

## 2. Scope lodash imports

- [ ] 2.1 `src/modals/object/ObjectModal.vue:226` — replace
  `import _ from 'lodash'` with
  `import cloneDeep from 'lodash/cloneDeep'`; update the call site at
  `:467` from `_.cloneDeep(activeObject)` to `cloneDeep(activeObject)`.
- [ ] 2.2 Grep `ObjectModal.vue` for any other `_.` usages beyond `:467`
  to confirm `cloneDeep` is the only lodash function used in this file
  before removing the barrel import.
- [ ] 2.3 `src/sidebars/search/SearchSideBar.vue:52` — replace
  `import { debounce } from 'lodash'` with
  `import debounce from 'lodash/debounce'`; confirm the call site(s)
  using `debounce(...)` are unaffected by the import shape change.

## 3. Verification

- [ ] 3.1 `npm run build` — confirm the production bundle no longer
  includes a top-level `apexcharts` chunk from stackiq's own
  dependency (only nc-vue's nested copy, if any chart widget pulls it in
  transitively).
- [ ] 3.2 Run existing vitest suite (`npm run test:unit` or equivalent) to
  confirm `ObjectModal.vue` and `SearchSideBar.vue` behavior (clone /
  debounced search) is unchanged.
- [ ] 3.3 `npm run test:l10n` and `npm run check:manifest` (if present) —
  confirm no unrelated regressions from the `package.json` edit.
