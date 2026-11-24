# gemma-plate Specification (delta)

## Purpose

The GEMMA plate page: one GEMMA view with the organisation's applications per reference component, what comparable organisations use, and GEMMA definitions in place. Covers rows arch-gemma-views, mkt-compare-peers and arch-definitions.

## ADDED Requirements

### Requirement: The plate draws the GEMMA view the user picks (REQ-GPL-001)

The GEMMA plate page SHALL offer a picker of the imported GEMMA views of the application architecture, showing the GEMMA release. For the chosen view it SHALL draw the view's reference components in the view's groups (for example Front-office, Mid-office, Back-office en basisregistraties), each with the organisation's applications for it, read through `GET /api/views/{viewId}` with `include_gebruik`. The chosen view SHALL be kept in the URL.

#### Scenario: A user switches plate
- GIVEN two imported GEMMA views
- WHEN the user picks "GEMMA Applicatiearchitectuur, gemeentebreed"
- THEN the plate shows that view's groups and reference components with the organisation's applications, and the URL names the view

#### Scenario: No GEMMA model
- GIVEN no GEMMA views are imported
- WHEN the user opens the plate
- THEN the page says the GEMMA model is needed and the picker is empty

### Requirement: The plate shows what comparable organisations use (REQ-GPL-002)

The plate SHALL offer a layer "What comparable organisations use" and a table with, per reference component, the organisation's own applications and the applications comparable organisations use with their counts. Comparable SHALL mean the same organisation type and the same inhabitants band; without an inhabitants value, the same type. The counts SHALL be computed server side over all usages, SHALL never name another organisation, and a count below 3 SHALL be shown as "fewer than 3".

#### Scenario: A municipality compares its case handling
- GIVEN Zuiddrecht (45,000 inhabitants) uses Mozard for the Zaakafhandelcomponent, and 21 municipalities between 30,000 and 60,000 inhabitants use OpenZaak-UI for it
- WHEN a user of Zuiddrecht switches on the layer
- THEN the table row Zaakafhandelcomponent shows Mozard as own and "OpenZaak-UI 21" under comparable organisations

#### Scenario: A gap that peers fill
- GIVEN Zuiddrecht has no application for the Kennisbankcomponent and 12 comparable municipalities use Djuma Kennisbank
- WHEN the user selects the Kennisbankcomponent on the plate
- THEN the side panel says it is not covered and lists "Djuma Kennisbank (12)"

#### Scenario: A rare choice stays anonymous
- GIVEN only one comparable municipality uses a given application for a component
- WHEN the table renders
- THEN that application shows "fewer than 3" and no organisation is named

### Requirement: GEMMA terms show their definition where they appear (REQ-GPL-003)

Where a GEMMA reference component or standard is named as a choice, its GEMMA definition (`element.documentation`) SHALL be readable in place: in the side panel of the plate for the selected component, in the reference component facet of the Applications and Services pages (name, "from GEMMA", the definition and a link "More in GEMMA Online" to `element.gemmaUrl`), and on a standard's page.

#### Scenario: A user reads a definition in the facet
- GIVEN the Applications page with the reference component facet open
- WHEN the user opens the information button next to "Zaaksysteem"
- THEN a popover shows "Zaaksysteem, from GEMMA", its definition and a link to GEMMA Online

#### Scenario: A component without a definition
- GIVEN a reference component with no documentation
- WHEN its popover opens
- THEN it shows the name and the GEMMA Online link only
