# Design: architecture-shared-usage-on-views

Read at stackiq development 8f74f890.

No board covers this change: stackiq is one of the apps without a canvas board (decision 75). The page is the read-only view page that `architecture-views-editor` specifies; this change adds a control and a legend to it.

## Where it fits

| Part | File and line | What changes |
|---|---|---|
| Page | `src/views/architecture/ArchitectureViewEditor.vue` (from `architecture-views-editor`) | read-only mode gets the "Show applications" control, the overlay nodes and the legend |
| Mapping | new `src/utils/usageOverlay.js` | turns enriched view nodes into Vue Flow nodes for own and shared usage |
| Store | `src/store/modules/view.js:15` `useViewStore` | its fetch of one view takes `includeGebruik` and `includeDeelnamesGebruik` and passes them as query parameters |
| Export | `lib/Service/ArchiMateExportService.php` `copyAndEnrichViews()` (`:2731`) and `processNodesForInjection()` (`:2956`) | each nested application carries its kind (own or shared) and partner; shared ones get their own style |
| Export | `lib/Service/ArchiMateExportService.php` `generateApplicationElements()` (`:2643`) | shared elements get the property `Gedeeld door` |
| Strings | `l10n/en.json`, `l10n/nl.json` | "Show applications", "Our applications", "Shared with partners", "Shared", "Shared by %s", legend lines |

No new route or controller. The page reads the existing view API, which already separates the two lists.

## Decisions

### D1. Draw in two places, because there are two readers

An information manager looks at the view in stackiq. An enterprise architect takes the export into Archi. The spec in `module-overlay-rendering` asked for the difference on screen; the export is where stackiq draws views today, and it currently erases the difference. Both get it.

### D2. Shared is marked by shape and text, not by colour alone

WCAG 2.2 success criterion 1.4.1 forbids colour as the only carrier. On screen a shared node has a dashed border (`border-style: dashed`, `--color-border-maxcontrast`), the text "Shared" before its name, and an accessible name "<application>, shared by <partner>". An own node has a solid `--color-primary-element` border. Colours come from Nextcloud variables, so NL Design themes apply (ADR-003).

### D3. Nest overlay nodes inside their reference component

`ViewService` attaches usage to the enriched node of the reference component. `usageOverlay.js` creates one Vue Flow node per usage with `parentNode` set to that component's node id and `extent: 'parent'`, and stacks them from the bottom of the parent, as `processNodesForInjection()` stacks them in the export (`:2985-2990`). A component with more usages than fit shows the first ones and a "+N more" node that opens the list.

Rejected: a side panel listing usages per component. It answers "what fills this box" but not "where are our shared applications on the map".

### D4. In the export, keep one decision for owned versus shared

`copyAndEnrichViews()` builds `$refCompApps` from the merged element list (`:2758`). It gains a `kind` (`own` or `shared`) and a `partner` per entry, taken from the element lists that `:2451-2471` already keep apart. When both lists hold the same application for the same reference component, the owned entry wins, matching `ViewService.php:467-469`. `processNodesForInjection()` picks the style by `kind`:

| Kind | fillColor | lineColor |
|---|---|---|
| own | 200, 255, 200 (unchanged) | 0, 150, 0 |
| shared | 210, 225, 255 | 40, 80, 170 |

`generateApplicationElements()` adds the property `Gedeeld door` with the partner's name to shared elements, through the same property definition mechanism the export uses for its source property (`addSourceProperty()`, `:2885`).

### D5. The switches start off and remember nothing

The read-only view opens as plain GEMMA, like today's import. The switches are page state, not a user setting, so a shared link shows the same view to everyone.
