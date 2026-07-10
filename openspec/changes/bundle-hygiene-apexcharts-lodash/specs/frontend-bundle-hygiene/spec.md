## ADDED Requirements

### Requirement: No duplicate charting library dependency
softwarecatalog's own `package.json` MUST NOT declare a direct dependency
on `apexcharts` (or any other charting library already provided by
`@conduction/nextcloud-vue`'s `CnChartWidget`) unless the app directly
imports and uses it. Charting needs MUST be satisfied via nc-vue's shared
component so only one version of the library is ever bundled fleet-wide.

#### Scenario: No direct apexcharts usage exists
- GIVEN softwarecatalog's `src/` tree contains no direct `apexcharts`
  import and no usage of `CnChartWidget`
- WHEN `package.json` is inspected
- THEN it MUST NOT list `apexcharts` as a dependency

#### Scenario: A future feature needs a chart
- GIVEN a developer wants to add a chart to softwarecatalog
- WHEN they implement the widget
- THEN they MUST use nc-vue's `CnChartWidget` (which supplies its own
  apexcharts dependency)
- AND MUST NOT add a second, separately-versioned `apexcharts` dependency
  to softwarecatalog's own `package.json`

### Requirement: Utility library imports MUST be scoped to the function used
Where only one or two functions from a large CJS utility library (e.g. `lodash`) are used, the import MUST reference the specific submodule (`lodash/cloneDeep`, `lodash/debounce`) rather than the package barrel.
Importing the full barrel (`import _ from 'lodash'` / `import { fn } from 'lodash'`) pulls in the whole module graph instead of just the function used, so bundlers cannot tree-shake it away.

#### Scenario: A single lodash function is used in a component
- GIVEN a `.vue` component uses exactly one lodash function (e.g.
  `cloneDeep`)
- WHEN the import statement is written
- THEN it MUST import the scoped submodule path
  (`import cloneDeep from 'lodash/cloneDeep'`)
- AND MUST NOT import the full `lodash` package barrel
