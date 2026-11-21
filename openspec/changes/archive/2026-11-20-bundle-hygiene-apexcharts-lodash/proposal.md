---
kind: code
depends_on: []
---

# stackiq — drop unused own `apexcharts` dependency, scope `lodash` imports

## Why

**Unused duplicate `apexcharts` dependency.** `package.json:` lists
`"apexcharts": "^3.50.0"` as a direct dependency, and the installed copy
resolves to `3.54.1` (`node_modules/apexcharts/package.json`). A repo-wide
grep for `apexcharts` under `src/` returns **zero matches** — the app never
imports it directly, and `CnChartWidget` (nc-vue's chart component, which
does depend on apexcharts) is never used either. Meanwhile
`@conduction/nextcloud-vue` — which stackiq already depends on —
ships its own `apexcharts@^4.7.0`
(`node_modules/@conduction/nextcloud-vue/package.json`), nested at
`node_modules/@conduction/nextcloud-vue/node_modules/apexcharts` because
the major-version ranges (`^3.50.0` vs `^4.7.0`) don't overlap and npm
cannot dedupe them. Per the fleet convention ("apexcharts from nc-vue, not
duplicated"), stackiq is shipping a second, unused, mismatched
copy of a large charting library (~500KB+ minified) in its own dependency
tree for no functional benefit — pure bundle/install-size bloat.

**Unscoped `lodash` imports.** stackiq depends on the full
`lodash` package (`package.json`) but only uses two functions from it:

- `src/modals/object/ObjectModal.vue:226` — `import _ from 'lodash'`,
  used solely for `_.cloneDeep()` at `:467`.
- `src/sidebars/search/SearchSideBar.vue:52` — `import { debounce } from 'lodash'`.

Lodash's CJS structure means named/default imports from the `'lodash'`
package entry point pull in the whole library regardless of what's
actually referenced (tree-shaking does not apply the way it would for an
ESM package) — the full ~70KB (minified) lodash bundle ships for two
single-function call sites.

## What Changes

- Remove `"apexcharts"` from stackiq's own `package.json`
  dependencies. The app has no direct usage; if a future feature needs
  charting, it MUST use nc-vue's `CnChartWidget` (which already brings its
  own apexcharts) rather than re-adding a direct dependency.
- Change `src/modals/object/ObjectModal.vue:226` from
  `import _ from 'lodash'` to a scoped import
  (`import cloneDeep from 'lodash/cloneDeep'`), updating the call site at
  `:467` from `_.cloneDeep(...)` to `cloneDeep(...)`.
- Change `src/sidebars/search/SearchSideBar.vue:52` from
  `import { debounce } from 'lodash'` to
  `import debounce from 'lodash/debounce'`.
- After both scoped imports land, remove `"lodash"` from `package.json`
  dependencies is NOT proposed here — the scoped `lodash/xxx` submodule
  imports still resolve against the same installed `lodash` package,
  so the dependency itself stays; only the import path changes so
  webpack can pull in a single function's module graph instead of the
  package barrel.
- NOT BREAKING — no behavior change; `cloneDeep`/`debounce` are pure
  utility functions with identical semantics via the scoped import path.

## Non-goals

- Not evaluating whether `moment` is a bundle concern — verified during
  this sweep that "moment" only appears in code comments/docblocks
  (`lib`/`src/utils/lifecyclePhase.js`, `src/views/LifecycleRoadmapView.vue`),
  never as an actual imported library. No finding there.
