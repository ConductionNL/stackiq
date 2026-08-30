---
status: done
---

# repair-init Specification

## Purpose
Provides the install-time repair step that initialises the app's default settings on install and upgrade, seeding the configuration so the catalog is ready to run. It exposes a human-readable step name shown while the repair framework runs.

@e2e exclude PHP repair-step backend (InitializeRegister: register/schema import on app enable) — install-time plumbing with no UI surface; covered by PHPUnit repair-step tests.

## Requirements
### Requirement: The system SHALL initialise default settings during a repair step (REQ-001)

`InitializeSettings::run(output)` MUST seed/initialise the app's default settings on install/upgrade; `getName()` MUST return the human-readable step name shown during the repair run.

#### Scenario: REQ-001 case 1
- WHEN the repair step executes
- THEN `run()` MUST initialise the default settings

#### Scenario: REQ-001 case 2
- WHEN the repair framework lists steps
- THEN `getName()` MUST return the step's display name

