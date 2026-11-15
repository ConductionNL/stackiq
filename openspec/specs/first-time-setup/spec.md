# first-time-setup Specification

## Purpose
An administrator opening stackiq for the first time gets a short setup wizard that offers example data, so a new installation can be tried out straight away and a production installation stays empty. The wizard follows the CnSetupWizard contract (ADR-042): the steps live in `src/manifest.json` under `setup`, and `lib/Controller/SetupController.php` answers `/api/setup/status`, `/api/setup/config` and `/api/setup/action/{actionId}`. `lib/Service/DemoDataService.php` imports the dataset through OpenRegister. Written after the fact (7 Oct 2026) to describe what the code does today. Matrix row `stackiq:land-demo-data`. The change `wizard-dataset-card-load` (archived 2026-10-07) added the per-card load and the step-id contract on top of this spec.

## Requirements

### Requirement: Example data is loaded on request only, by an administrator

Stackiq SHALL NOT import example data when the app is installed or upgraded. Example data SHALL only be imported when an administrator asks for it in the setup wizard. Every setup endpoint SHALL require the stackiq admin setting (`#[AuthorizedAdminSetting(StackiqAdmin::class)]` in `lib/Controller/SetupController.php`).

#### Scenario: A fresh install holds no example objects
- **WHEN** stackiq is installed on a Nextcloud instance with OpenRegister
- **THEN** no object from `lib/Settings/stackiq_mock_register.json` is imported
- **AND** the setup wizard offers the example data step
- @e2e exclude written after the fact; tests/e2e/spec-coverage/demo-data-setup-step.spec.ts drives this but carries no scenario marker yet, and this round adds no test code

#### Scenario: A user who is not a stackiq administrator cannot load example data
- **WHEN** a signed-in user without the stackiq admin setting posts to `/api/setup/action/load-demo-data`
- **THEN** Nextcloud refuses the request before the controller runs
- **AND** nothing is imported
- @e2e exclude enforced by Nextcloud's AuthorizedAdminSetting middleware, which this repo does not test

### Requirement: The server owns the list of datasets the wizard offers

`GET /api/setup/status` SHALL return a `datasets` list built by `DemoDataService::listChoices()`. The list SHALL always hold the `none` choice ("None, I will set this up myself"). It SHALL hold the `demo` choice ("Example data") only when `lib/Settings/stackiq_mock_register.json` exists and parses, with an `objectCount` counted from that file. The card description SHALL carry no number, so it stays translatable.

#### Scenario: The shipped dataset is offered with the count it carries
- **GIVEN** the shipped descriptor holds 133 objects
- **WHEN** an administrator reads `/api/setup/status`
- **THEN** `datasets` holds `none` and `demo`
- **AND** the `demo` entry has `objectCount` 133
- @e2e exclude written after the fact; tests/e2e/spec-coverage/demo-data-setup-step.spec.ts drives this but carries no scenario marker yet, and this round adds no test code

#### Scenario: Without a usable descriptor only declining is offered
- **GIVEN** the descriptor is missing or is not valid JSON
- **WHEN** an administrator reads `/api/setup/status`
- **THEN** `datasets` holds only `none`
- @e2e exclude needs a build without the descriptor; covered by tests/Unit/Service/DemoDataServiceTest.php

### Requirement: Declining is an answer that closes the step

Choosing `none`, or the `skip-demo-data` action, SHALL store `none` under the app-config key `demo_dataset`, mark the step decided, and import nothing. `/api/setup/status` SHALL then report the `demo-data` step as done, so the wizard does not reopen. The status SHALL always report `completed: true`, because no setup step is required to use the app.

#### Scenario: The administrator picks None
- **WHEN** the administrator loads the `none` dataset
- **THEN** the answer is `success: true` with the message "No example data was loaded."
- **AND** no object is imported
- **AND** `/api/setup/status` reports `demo-data` as done
- @e2e exclude a wizard click on a live instance; covered by tests/Unit/Controller/SetupControllerTest.php

#### Scenario: Loading without any choice is refused
- **GIVEN** no dataset was posted or stored
- **WHEN** `/api/setup/action/load-demo-data` is called
- **THEN** the server answers 400 with "Pick a dataset first."
- **AND** nothing is imported
- @e2e exclude covered by tests/Unit/Controller/SetupControllerTest.php

### Requirement: Example data is imported through OpenRegister and the outcome is reported

`DemoDataService::install()` SHALL import the descriptor through OpenRegister's `ConfigurationService::importFromApp` under the app id `stackiq.demo`, after resolving each object's schema slug to the schema of the register the object names. It SHALL report how many objects the file carries. A missing or unreadable descriptor, or a missing OpenRegister, SHALL raise an error that the controller reports as `success: false` with HTTP 500, and the step SHALL stay undecided. Running the import a second time SHALL be safe.

#### Scenario: A load reports how much landed
- **WHEN** an administrator loads the `demo` dataset
- **THEN** the answer is `success: true` with "Imported 133 demo object(s)."
- **AND** `demo_dataset` holds `demo`
- @e2e exclude written after the fact; tests/e2e/spec-coverage/demo-data-setup-step.spec.ts drives this but carries no scenario marker yet, and this round adds no test code

#### Scenario: A failed import is reported, not hidden
- **GIVEN** OpenRegister is not installed
- **WHEN** an administrator loads the `demo` dataset
- **THEN** the answer is `success: false` with HTTP 500 and the reason
- **AND** the step is still open in `/api/setup/status`
- @e2e exclude needs OpenRegister removed on a live instance; covered by tests/Unit/Controller/SetupControllerTest.php and tests/Unit/Service/DemoDataServiceTest.php

#### Scenario: Loading twice is safe
- **GIVEN** the example data was loaded once
- **WHEN** the administrator loads it again
- **THEN** the import succeeds again
- @e2e exclude written after the fact; tests/e2e/spec-coverage/demo-data-setup-step.spec.ts drives this but carries no scenario marker yet, and this round adds no test code

### Requirement: Each example data card loads itself

The `demo-data` setup step MUST be a cards choice step with `loadAction: load-demo-data`. The setup wizard MUST NOT carry a separate run-action step that loads the picked dataset.

#### Scenario: The operator loads a dataset from its card

- GIVEN the setup wizard shows the example data cards
- WHEN the operator presses Load on a card
- THEN the wizard posts `{ "dataset": <card value> }` to `/api/setup/action/load-demo-data`
- AND the server loads that dataset
- AND the server records the dataset as the pick only after the load succeeds
- @e2e exclude the card and its spinner are CnSetupWizard UI, tested in nextcloud-vue; the posted body is covered by tests/Unit/Controller/SetupControllerTest.php

#### Scenario: An unknown dataset is refused

- GIVEN a dataset id that no card offers
- WHEN it is posted to `/api/setup/action/load-demo-data`
- THEN the server answers 400 with `success: false`
- AND nothing is loaded or stored
- @e2e tests/e2e/spec-coverage/demo-data-setup-step.spec.ts

#### Scenario: A call without a body keeps working

- GIVEN a dataset was stored through `/api/setup/config`
- WHEN `/api/setup/action/load-demo-data` is called without a body
- THEN the stored dataset is loaded
- @e2e tests/e2e/spec-coverage/demo-data-setup-step.spec.ts

#### Scenario: A failed load leaves the step open

- GIVEN the load of the posted dataset fails
- WHEN the server answers
- THEN the answer carries `success: false`
- AND no pick or decision is stored
- @e2e exclude needs a load that fails on a live instance; covered by tests/Unit/Controller/SetupControllerTest.php

### Requirement: Setup status reports every manifest step

`GET /api/setup/status` MUST report a `done` state for every step id in `manifest.setup.steps`.

#### Scenario: The status ids match the manifest

- GIVEN the Stackiq manifest
- WHEN an administrator reads `/api/setup/status`
- THEN `steps` holds an entry for every manifest step id
- AND the retired load step is not reported
- @e2e tests/e2e/spec-coverage/demo-data-setup-step.spec.ts
