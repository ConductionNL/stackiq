---
status: implemented
---

# Module Overlay Rendering Specification

## Purpose
Defines how application/module nodes injected by the enrichment API are rendered on GEMMA ArchiMate views, including visual styling, positioning, performance requirements, and interaction behavior. Module overlay nodes represent organization-specific software applications plotted on top of the standard GEMMA reference architecture, enabling organizations to visualize their application landscape in the context of the national standard.

## Context
GEMMA views are ArchiMate diagrams that show the Dutch municipal reference architecture. They contain referentiecomponenten (reference components) that represent abstract architectural functions. Organizations map their actual software applications (modules) to these referentiecomponenten. This spec defines how those mapped modules are visually rendered as overlay nodes within the GEMMA view, using JointJS as the rendering engine.

**Relation to existing specs:**
- `view-enrichment-api`: Provides the enriched view data (base GEMMA + module overlay nodes) that this spec renders
- `deelnames-gebruik`: Provides deelnames-type module nodes that require distinct visual styling
- `org-archimate-export`: Uses the same module-referentiecomponent relationships but exports to XML rather than rendering

**Technical foundation:**
- Rendering engine: JointJS (JavaScript diagramming library)
- View data: ArchiMate viewNodes with x/y/width/height positioning
- Parent-child: JointJS parent embedding for nesting modules inside referentiecomponenten
- Performance: paper.freeze()/unfreeze() pattern for batch rendering

## Requirements

### Requirement: Module nodes MUST render as children of referentiecomponenten
Module overlay nodes returned by the enrichment API MUST be rendered inside their parent referentiecomponent using the existing JointJS parent-child hierarchy.

#### Scenario: Module node with parent reference renders inside referentiecomponent
- GIVEN a viewNode with `_isModuleExpansion: true` and `parent` set to a referentiecomponent's `viewNodeId`
- WHEN the rendering pipeline processes all viewNodes
- THEN the module node MUST be rendered as a child of the referentiecomponent in the JointJS graph
- AND the module node MUST appear visually nested within the referentiecomponent's bounds

#### Scenario: Module appears in multiple referentiecomponenten
- GIVEN a module that is linked to 3 referentiecomponenten on the current view
- WHEN the enrichment API returns viewNodes
- THEN there MUST be 3 separate module viewNodes, one per referentiecomponent
- AND each MUST have a different `viewNodeId` but the same `modelNodeId`
- AND each MUST have `parent` pointing to its respective referentiecomponent

#### Scenario: Module node without valid parent reference is skipped
- GIVEN a viewNode with `_isModuleExpansion: true` but `parent` set to a non-existent `viewNodeId`
- WHEN the rendering pipeline processes this node
- THEN the node MUST be skipped (not rendered)
- AND a warning MUST be logged with the invalid parent reference
- AND the remaining nodes MUST render normally

#### Scenario: Referentiecomponent with no modules renders normally
- GIVEN a referentiecomponent viewNode that has no mapped modules
- WHEN the rendering pipeline processes this node
- THEN the referentiecomponent MUST render exactly as in the base GEMMA view
- AND no empty child container or placeholder MUST be added

#### Scenario: Module node respects parent bounds
- GIVEN a module overlay node with `parent` pointing to referentiecomponent R1
- AND R1 has bounds (x=100, y=200, width=300, height=150)
- WHEN the module node is rendered
- THEN the module node's position MUST be within R1's visual bounds
- AND the module node MUST NOT overflow or clip outside R1

### Requirement: Module nodes MUST be visually distinct from GEMMA elements
Module overlay nodes MUST be styled differently from standard GEMMA referentiecomponenten so users can distinguish organization-specific applications from the architecture standard.

#### Scenario: Module node receives distinct fill color
- GIVEN a viewNode with `_isModuleExpansion: true`
- WHEN `setNodeColor` processes this node
- THEN the node's fill color MUST differ from standard referentiecomponent colors
- AND the node's border color MUST differ from standard referentiecomponent borders

#### Scenario: Module node from deelnames has different styling
- GIVEN a viewNode with `_isModuleExpansion: true` and `_type: "deelnames"`
- WHEN `setNodeColor` processes this node
- THEN the node MUST be styled differently from regular module nodes (e.g., different opacity or color shade)
- AND the user MUST be able to distinguish owned modules from shared (deelnames) modules

#### Scenario: Module node displays application name
- GIVEN a module overlay node with name "Topdesk"
- WHEN the node is rendered
- THEN the text label "Topdesk" MUST be visible inside the node
- AND the text MUST be legible (minimum contrast ratio of 4.5:1 against the fill color)
- AND long names MUST be truncated with ellipsis if they exceed the node width

#### Scenario: Module node color comes from node data, not hardcoded
- GIVEN a module overlay node with `color: "#4CAF50"` and `borderColor: "#388E3C"`
- WHEN the node is rendered
- THEN the fill color MUST be `#4CAF50`
- AND the border color MUST be `#388E3C`
- AND the colors MUST NOT be overridden by the default GEMMA color palette

#### Scenario: Deelnames node has visual indicator of shared ownership
- GIVEN a deelnames module node with `_sourceOrganization: "Gemeente Utrecht"`
- WHEN the node is rendered
- THEN the node MUST have a visual indicator distinguishing it from owned modules (e.g., dashed border, reduced opacity, or badge)
- AND the indicator MUST be consistent across all deelnames nodes on the view

### Requirement: Rendering MUST use paper.freeze optimization
All view rendering that includes module overlay nodes MUST use the JointJS `paper.freeze()`/`paper.unfreeze()` pattern to maintain performance.

#### Scenario: View with 388 base nodes and 200 module overlay nodes renders
- GIVEN a view with 388 GEMMA nodes and 200 additional module overlay nodes
- WHEN the rendering pipeline executes
- THEN `paper.freeze()` MUST be called before `ViewRenderer.renderToGraph()`
- AND `paper.unfreeze()` MUST be called after `renderToGraph()` completes
- AND total render time MUST be under 3 seconds

#### Scenario: View with no overlay nodes renders unchanged
- GIVEN a view with only base GEMMA nodes (no enrichment)
- WHEN the rendering pipeline executes
- THEN the render behavior and performance MUST be identical to the current implementation

#### Scenario: Paper unfreeze is called even if rendering throws an error
- GIVEN a view rendering that encounters an error during `renderToGraph()`
- WHEN the error occurs
- THEN `paper.unfreeze()` MUST still be called (via try/finally)
- AND the error MUST be propagated after unfreezing
- AND the paper MUST NOT remain in a frozen state

#### Scenario: Incremental re-render on toggle change uses freeze
- GIVEN a view is already rendered with base GEMMA nodes
- WHEN the user enables the "Gebruik" toggle (adding module overlay nodes)
- THEN `paper.freeze()` MUST be called before adding the new nodes
- AND `paper.unfreeze()` MUST be called after all nodes are added
- AND the view MUST NOT flicker during the re-render

#### Scenario: Performance with maximum realistic node count
- GIVEN a view with 500 base nodes and 500 module overlay nodes (1000 total)
- WHEN the rendering pipeline executes with freeze/unfreeze
- THEN the total render time MUST be under 5 seconds
- AND the browser MUST remain responsive (no jank or dropped frames after unfreeze)

### Requirement: Topological sort MUST handle module overlay nodes
The existing topological sort that orders parent nodes before children MUST correctly process module overlay nodes that reference referentiecomponenten as parents.

#### Scenario: Module overlay nodes sorted after their parent referentiecomponenten
- GIVEN a viewNodes array containing both GEMMA nodes and module overlay nodes
- WHEN the topological sort runs
- THEN every module overlay node MUST appear after its parent referentiecomponent in the sorted array
- AND the sort MUST not produce errors for overlay nodes

#### Scenario: Circular reference detection includes module nodes
- GIVEN a malformed viewNodes array where a module node references itself as parent
- WHEN the topological sort runs
- THEN the circular reference MUST be detected
- AND the affected node MUST be skipped with a warning logged
- AND remaining nodes MUST sort and render correctly

#### Scenario: Multiple levels of nesting are supported
- GIVEN a GEMMA group node G containing referentiecomponent R, and module M inside R
- WHEN the topological sort runs
- THEN the sort order MUST be: G, then R, then M
- AND JointJS parent embedding MUST correctly nest M inside R inside G

#### Scenario: Sort is stable for nodes at the same level
- GIVEN 5 module overlay nodes all with the same parent referentiecomponent
- WHEN the topological sort runs
- THEN the 5 modules MUST appear consecutively after their parent
- AND their relative order MUST be deterministic (sorted by viewNodeId or name)

### Requirement: setNodeColor MUST handle module overlay nodes
The `setNodeColor` function MUST apply appropriate styling to module overlay nodes based on their metadata markers.

#### Scenario: setNodeColor processes a module node
- GIVEN a rendered SVG element for a viewNode with `_isModuleExpansion: true`
- WHEN `setNodeColor` is called for this node
- THEN it MUST apply the module-specific fill color from the node data
- AND it MUST apply the module-specific border color from the node data
- AND text elements MUST remain readable (sufficient contrast)

#### Scenario: setNodeColor handles missing color data gracefully
- GIVEN a module overlay node without `color` or `borderColor` fields
- WHEN `setNodeColor` is called for this node
- THEN it MUST apply a default module color (distinct from GEMMA colors)
- AND a default border color MUST be applied
- AND the node MUST still be visually distinguishable from referentiecomponenten

#### Scenario: setNodeColor applies deelnames styling
- GIVEN a module overlay node with `_type: "deelnames"`
- WHEN `setNodeColor` is called
- THEN the fill opacity MUST be reduced (e.g., 0.7) or a different color variant MUST be used
- AND the border MUST be styled differently (e.g., dashed or different color)
- AND the distinction from owned modules MUST be clearly visible

#### Scenario: setNodeColor does not affect standard GEMMA nodes
- GIVEN a standard GEMMA referentiecomponent node (no `_isModuleExpansion` flag)
- WHEN `setNodeColor` is called for this node
- THEN the existing GEMMA styling logic MUST be applied unchanged
- AND no module-specific colors or styling MUST be applied

### Requirement: Module nodes MUST support click interaction
Users MUST be able to click on module overlay nodes to view details about the mapped application.

#### Scenario: Click on module node opens detail panel
- GIVEN a rendered module overlay node for "Topdesk"
- WHEN the user clicks on the node
- THEN a detail panel or sidebar MUST open showing information about the "Topdesk" application
- AND the panel MUST include the application name, owning organization, and linked referentiecomponenten

#### Scenario: Click on deelnames module node shows source organization
- GIVEN a rendered deelnames module node for "Topdesk" owned by "Gemeente Utrecht"
- WHEN the user clicks on the node
- THEN the detail panel MUST show that this is a shared application
- AND it MUST display the source organization "Gemeente Utrecht"
- AND it MUST show the participation relationship

#### Scenario: Click on module node does not interfere with parent click
- GIVEN a module node nested inside referentiecomponent R1
- WHEN the user clicks on the module node
- THEN the module detail panel MUST open (not the referentiecomponent detail)
- AND the click event MUST NOT propagate to R1

### Requirement: Multiple modules inside one referentiecomponent MUST stack correctly
When a referentiecomponent has multiple mapped modules, the modules MUST be positioned without overlap.

#### Scenario: Three modules stacked vertically inside referentiecomponent
- GIVEN referentiecomponent R1 with bounds (x=100, y=200, width=300, height=150)
- AND 3 modules mapped to R1
- WHEN the overlay nodes are positioned
- THEN the 3 modules MUST be stacked vertically within R1's bounds
- AND each module MUST have equal height (parent height / number of modules, with padding)
- AND no module MUST overlap with another

#### Scenario: Many modules trigger referentiecomponent resize
- GIVEN referentiecomponent R1 with 10 mapped modules
- WHEN the overlay nodes are positioned
- THEN R1's height MUST be expanded to accommodate all 10 modules
- AND each module MUST have a minimum readable height (at least 20px)
- AND the expansion MUST NOT cause R1 to overlap with adjacent nodes if possible

#### Scenario: Single module uses available space
- GIVEN referentiecomponent R1 with exactly 1 mapped module
- WHEN the overlay node is positioned
- THEN the module MUST be centered or top-aligned within R1
- AND the module MUST use appropriate padding from R1's edges

### Requirement: Legend MUST explain module overlay styling
The view MUST include a legend explaining the visual meaning of module overlay nodes.

#### Scenario: Legend shows owned module style
- GIVEN a view with owned module overlay nodes
- WHEN the legend is displayed
- THEN it MUST include a swatch showing the owned module fill and border color
- AND the label MUST read "Eigen applicaties" or equivalent

#### Scenario: Legend shows deelnames module style
- GIVEN a view with deelnames module overlay nodes
- WHEN the legend is displayed
- THEN it MUST include a swatch showing the deelnames module styling
- AND the label MUST read "Deelnames applicaties" or equivalent

#### Scenario: Legend updates when toggles change
- GIVEN only owned modules are enabled (no deelnames)
- WHEN the user enables the deelnames toggle
- THEN the legend MUST update to include the deelnames swatch
- AND the legend MUST NOT show deelnames styling when the toggle is disabled

### Requirement: Module overlay rendering MUST be accessible
Module overlay nodes MUST meet WCAG AA accessibility requirements.

#### Scenario: Module nodes have sufficient color contrast
- GIVEN any module overlay node (owned or deelnames)
- WHEN rendered with its fill color and text color
- THEN the contrast ratio between text and background MUST be at least 4.5:1
- AND the contrast ratio between the node border and the view background MUST be at least 3:1

#### Scenario: Module nodes are keyboard navigable
- GIVEN a view with module overlay nodes
- WHEN the user navigates using Tab key
- THEN module nodes MUST be focusable
- AND the focused module MUST have a visible focus indicator
- AND pressing Enter on a focused module MUST open the detail panel

#### Scenario: Module nodes have accessible names
- GIVEN a module overlay node for "Topdesk"
- WHEN a screen reader encounters the node
- THEN the accessible name MUST include "Applicatie: Topdesk"
- AND for deelnames nodes, it MUST include "Gedeelde applicatie: Topdesk (via Gemeente Utrecht)"

## MODIFIED Requirements

_None -- this is a new capability._

## REMOVED Requirements

_None._

## Current Implementation Status
- **Not yet implemented**: No module overlay rendering exists in the OpenCatalogi or softwarecatalog codebase.
- **Building blocks that exist**:
  - JointJS-based GEMMA view rendering in the softwarecatalog frontend
  - `paper.freeze()`/`paper.unfreeze()` pattern used in current view rendering
  - Topological sort for ordering parent-before-child nodes
  - `setNodeColor` function for applying colors to rendered SVG elements
  - ViewNode data model with x/y/width/height positioning
- **Key gaps**:
  - No module-specific node creation or embedding logic
  - No deelnames-specific styling
  - No click interaction on overlay nodes
  - No stacking/positioning logic for multiple modules per referentiecomponent
  - No legend component for module overlay explanation
  - No accessibility attributes on rendered SVG elements

## Dependencies
- `view-enrichment-api` spec (provides the enriched node data)
- `deelnames-gebruik` spec (provides deelnames-type metadata)
- JointJS library (rendering engine)
- Softwarecatalog frontend view renderer
