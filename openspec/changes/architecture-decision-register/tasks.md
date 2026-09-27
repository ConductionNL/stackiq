# Tasks: architecture-decision-register

## Implementation tasks

### Task 1: Register fragment with lifecycle and notifications
- **spec_ref**: openspec/changes/architecture-decision-register/specs/architecture-decision-register/spec.md#requirement-req-adreg-001-an-information-manager-shall-record-an-architecture-decision-with-its-context-options-and-consequences
- **files**: `lib/Settings/register.d/architecture-decision-register.json`, `tests/Unit/Settings/ArchitectureDecisionRegisterShapeTest.php`
- **acceptance_criteria**:
  - GIVEN the merged register WHEN it loads THEN the `stackiq` register lists `architectureDecision` with magic mapping on and read rules matched on `_organisation`
  - GIVEN the lifecycle WHEN the shape test reads it THEN every `from` and `to` value is a status enum value and every `requires` equals the guard's class name
  - GIVEN the notifications WHEN the shape test reads them THEN review-requested uses the field recipient reviewer, review-concluded the object-acl manage recipient, and both subjects have nl and en
  - GIVEN `boardDecisions` WHEN the shape test reads it THEN it matches the shape of `catalogContract.decisions`
  - GIVEN a fresh install WHEN the seed runs THEN the two demo decisions exist
- [ ] Implement
- [ ] Test (PHPUnit `ArchitectureDecisionRegisterShapeTest`, `RegisterFragmentMergeTest`)

### Task 2: Review guard
- **spec_ref**: openspec/changes/architecture-decision-register/specs/architecture-decision-register/spec.md#requirement-req-adreg-002-a-decision-shall-be-reviewed-by-a-second-person-through-a-declared-lifecycle
- **files**: `lib/Lifecycle/ArchitectureDecisionReviewGuard.php`, `tests/Stubs/`, `tests/Unit/Lifecycle/ArchitectureDecisionReviewGuardTest.php`
- **acceptance_criteria**:
  - GIVEN submit WHEN the reviewer is empty, unknown or the caller THEN the guard denies with a message
  - GIVEN accept or reject WHEN the caller is not the reviewer THEN the guard denies
  - GIVEN supersede WHEN supersededBy is not an accepted decision THEN the guard denies
  - GIVEN any action WHEN the guard runs THEN it writes nothing
- [ ] Implement
- [ ] Test (PHPUnit `ArchitectureDecisionReviewGuardTest`, psalm and phpstan on the guard)

### Task 3: Decision pages and menu
- **spec_ref**: openspec/changes/architecture-decision-register/specs/architecture-decision-register/spec.md#requirement-req-adreg-001-an-information-manager-shall-record-an-architecture-decision-with-its-context-options-and-consequences
- **files**: `src/manifest.d/architecture-decision-register.json`, `src/menu-layout.json`, `tests/e2e/workflows/architecture-decisions.spec.ts`
- **acceptance_criteria**:
  - GIVEN the effective manifest WHEN it is built THEN `Architectuurbesluiten` and `ArchitectuurbesluitDetail` exist with the quick filter "To review by me" on `@me`
  - GIVEN the effective menu WHEN it renders THEN Architecture decisions sits under the Architecture group
  - GIVEN a decision page WHEN it renders THEN its lifecycle actions and History tab show
- [ ] Implement
- [ ] Test (`tests/validate-manifest.js`, Playwright record and accept scenarios)

### Task 4: Decisions on the usage and standard pages
- **spec_ref**: openspec/changes/architecture-decision-register/specs/architecture-decision-register/spec.md#requirement-req-adreg-004-a-decision-shall-link-to-the-applications-and-elements-it-affects-and-to-board-decisions
- **files**: `src/manifest.d/usages.json`, `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN a decision that names a usage WHEN the usage page opens THEN Architecture decisions lists it
  - GIVEN a decision that names a standard WHEN the standard page opens THEN Architecture decisions lists it
- [ ] Implement
- [ ] Test (Playwright usage page scenario)

### Task 5: Documentation and translations
- **spec_ref**: openspec/changes/architecture-decision-register/specs/architecture-decision-register/spec.md#requirement-req-adreg-002-a-decision-shall-be-reviewed-by-a-second-person-through-a-declared-lifecycle
- **files**: `docs/features/architecture-decisions.md`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the feature page WHEN it is read THEN it shows a decision page, the review step and the list on a usage page, each in a screenshot, and explains when to use decidiq
  - GIVEN a Dutch instance WHEN the pages render THEN every new label and enum value reads in Dutch
- [ ] Implement
- [ ] Test (`tests/l10n` key parity, screenshots captured with Playwright)

## Verification

- `openspec validate architecture-decision-register --type change --strict`
- PHPUnit: `ArchitectureDecisionRegisterShapeTest`, `ArchitectureDecisionReviewGuardTest`, `RegisterFragmentMergeTest`
- Playwright: `tests/e2e/workflows/architecture-decisions.spec.ts`
- Documentation in `docs/features/architecture-decisions.md` with screenshots (ADR-010)
- English and Dutch strings for every new label, enum value and notification subject (ADR-005)
