# suite-wizard Specification

## Purpose
TBD - created by archiving change suite-wizard. Update Purpose after archive.
## Requirements
### Requirement: The wizard SHALL guide suite creation through details, application-attachment, and confirmation steps
The suite creation wizard MUST present exactly three steps in order — `details` (suite name, short description, long description, website), `applications` (attach existing module applications), `confirm` (review before submit) — using `CnWizardDialog`'s step navigation, and MUST NOT allow creating a brand-new module from within the wizard.

#### Scenario: Opening the wizard starts on the details step
- GIVEN the user is on the Suites index page
- WHEN they click the "New suite" action
- THEN the wizard dialog MUST open showing the `details` step first
- AND the progress indicator MUST show three steps: Details, Applications, Confirm

#### Scenario: The applications step only offers modules that already exist
- GIVEN the wizard is on the `applications` step
- WHEN the user opens the application picker
- THEN only `module` objects already present in the voorzieningen register MUST be offered
- AND there MUST be no control to create a new module from this step

### Requirement: The wizard MUST require at least one attached application before advancing past the applications step
Advancing from the `applications` step to `confirm` MUST be blocked with a validation message when zero modules are selected, and MUST succeed once one or more modules are selected.

#### Scenario: Advancing with zero applications is blocked
- GIVEN the wizard is on the `applications` step with no modules selected
- WHEN the user clicks Next
- THEN the wizard MUST NOT advance to the `confirm` step
- AND a validation error MUST be shown explaining at least one application is required

#### Scenario: Advancing with one or more applications succeeds
- GIVEN the wizard is on the `applications` step with at least one module selected
- WHEN the user clicks Next
- THEN the wizard MUST advance to the `confirm` step
- AND the confirm step MUST list the selected applications by name

### Requirement: Submitting the wizard SHALL create a suite object with the attached applications
On submit, the wizard MUST save a new `suite` object via the voorzieningen register with the entered `naam`/`beschrijvingKort`/`beschrijvingLang`/`website` and an `applicaties` array containing the id of every module attached in the applications step, and MUST enter the result phase showing success or a recoverable error.

#### Scenario: Successful submission creates the suite with its members
- GIVEN the wizard is on the `confirm` step with naam "Centric Leefomgeving", beschrijvingKort "Bundled leefomgeving product", and two applications attached
- WHEN the user clicks Submit
- THEN a `suite` object MUST be created in the voorzieningen register with `naam` "Centric Leefomgeving" and `beschrijvingKort` "Bundled leefomgeving product"
- AND its `applicaties` array MUST contain the ids of both attached modules
- AND the wizard MUST show a success result

#### Scenario: A failed save keeps the wizard open with an error
- GIVEN the wizard is on the `confirm` step and the user clicks Submit
- WHEN the save request fails
- THEN the wizard MUST show an error message on the current step rather than closing
- AND the entered step data MUST remain intact so the user can retry

### Requirement: The system SHALL register the suite and module object types by schema slug
Any object-type registration the wizard or the Suites index page performs for `suite` or `module` MUST use the schema slug as both the registration key and the schema identifier against the voorzieningen register id, and MUST NOT depend on a `voorzieningen_config.<type>_schema` key being populated.

#### Scenario: The applications picker loads modules without a populated module_schema config key
- GIVEN the settings configuration blob does not include a `module_schema` key
- WHEN the wizard's applications step loads
- THEN the module collection MUST still be fetched successfully by registering `module` against the voorzieningen register id directly

### Requirement: The suite index page SHALL list existing suites
A Suites index page, reachable from a nav entry, MUST list existing `suite` objects from the voorzieningen register and MUST offer the guided wizard as its primary creation action.

#### Scenario: The Suites nav entry opens the suite index
- GIVEN the app is loaded
- WHEN the user clicks "Suites" in the navigation menu
- THEN the Suites index page MUST render showing existing suite objects

### Requirement: The suite detail page SHALL show suite data and its member applications
A suite detail page MUST render the suite's own data (name, descriptions, website, logo, contact person) and MUST surface its attached applications with a click-through to each application's own detail page.

#### Scenario: Opening a suite shows its attached applications
- GIVEN a suite exists with two attached applications
- WHEN the user opens that suite's detail page
- THEN the suite's own data MUST be shown
- AND both attached applications MUST be listed and clicking one MUST navigate to that application's detail page

### Requirement: The module detail page SHALL surface suite membership
A module's own detail page MUST show which suite(s), if any, include that module among their attached applications, using the platform's existing generic relation index rather than a bespoke per-schema lookup.

#### Scenario: A module's detail page shows the suite it belongs to
- GIVEN a suite exists whose `applicaties` array includes module M
- WHEN the user opens module M's detail page
- THEN the suite that includes M MUST appear among M's related objects

