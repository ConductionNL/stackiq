# Tasks: architecture-process-mapping

## Implementation tasks

### Task 1: Register fragment for process and processStep
- **spec_ref**: openspec/changes/architecture-process-mapping/specs/business-process-mapping/spec.md#requirement-req-bpm-003-a-step-shall-carry-a-risk-level-and-a-compliance-check
- **files**: `lib/Settings/register.d/architecture-process-mapping.json`, `tests/Unit/Settings/ProcessMappingRegisterShapeTest.php`
- **acceptance_criteria**:
  - GIVEN the merged register WHEN it loads THEN the `stackiq` register lists `process` and `processStep` with magic mapping on
  - GIVEN `processStep` WHEN the shape test reads it THEN `riskLevel` and `complianceCheck` have the enums and defaults of the design and are facetable
  - GIVEN the process lifecycle WHEN the shape test reads it THEN every `from` and `to` value is a status enum value
  - GIVEN both schemas WHEN the shape test reads their read rules THEN they match on `_organisation` as `usage` does
  - GIVEN a fresh install WHEN the seed runs THEN the process and its three steps exist
- [ ] Implement
- [ ] Test (PHPUnit `ProcessMappingRegisterShapeTest`, `RegisterFragmentMergeTest`)

### Task 2: Processes index, process page and step page
- **spec_ref**: openspec/changes/architecture-process-mapping/specs/business-process-mapping/spec.md#requirement-req-bpm-001-a-municipal-information-manager-shall-record-a-business-process-with-ordered-steps
- **files**: `src/manifest.d/architecture-process-mapping.json`, `src/menu-layout.json`
- **acceptance_criteria**:
  - GIVEN the effective manifest WHEN it is built THEN `Processen`, `ProcesDetail` and `ProcesStapDetail` exist with the routes of the design
  - GIVEN the effective menu WHEN it renders THEN Processes sits under the Architecture group and the top-level count is unchanged
  - GIVEN a process page WHEN it renders THEN its steps list is sorted on position and its lifecycle actions show
- [ ] Implement
- [ ] Test (`tests/validate-manifest.js`, Playwright `tests/e2e/workflows/process-mapping.spec.ts` create and lifecycle scenarios)

### Task 3: Step flow on CnGraphCanvas
- **spec_ref**: openspec/changes/architecture-process-mapping/specs/business-process-mapping/spec.md#requirement-req-bpm-004-the-process-page-shall-show-the-steps-as-a-read-only-flow
- **files**: `src/views/architecture/ProcessStepFlow.vue`, `src/utils/processStepFlow.js`, `src/customComponents.js`, `tests/vitest/processStepFlow.spec.js`
- **acceptance_criteria**:
  - GIVEN steps without a follows list WHEN the flow is built THEN edges run in position order
  - GIVEN a step that follows two steps WHEN the flow is built THEN it has two incoming edges
  - GIVEN a step with high risk or not compliant WHEN it renders THEN its node uses `--color-error` and says so in text
- [ ] Implement
- [ ] Test (vitest `processStepFlow.spec.js`, Playwright flow scenario)

### Task 4: Supporting applications on the usage and step pages
- **spec_ref**: openspec/changes/architecture-process-mapping/specs/business-process-mapping/spec.md#requirement-req-bpm-002-a-step-shall-name-the-applications-in-use-that-support-it
- **files**: `src/manifest.d/usages.json`, `src/manifest.d/architecture-process-mapping.json`, `src/views/architecture/ProcessStepUsages.vue`, `tests/vitest/processStepUsages.spec.js`
- **acceptance_criteria**:
  - GIVEN a step that names a usage WHEN that usage's page opens THEN the list "Process steps this application supports" shows the step and its process
  - GIVEN two stored usage uuids of which one is returned WHEN the step page renders THEN it shows the visible usage and says one is hidden
- [ ] Implement
- [ ] Test (vitest `processStepUsages.spec.js`, Playwright usage page scenario)

### Task 5: Documentation and translations
- **spec_ref**: openspec/changes/architecture-process-mapping/specs/business-process-mapping/spec.md#requirement-req-bpm-001-a-municipal-information-manager-shall-record-a-business-process-with-ordered-steps
- **files**: `docs/features/business-processes.md`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the feature page WHEN it is read THEN it shows the process page with its flow and the usage page with its step list, each in a screenshot
  - GIVEN a Dutch instance WHEN the Processes page renders THEN every new label and enum value reads in Dutch
- [ ] Implement
- [ ] Test (`tests/l10n` key parity, screenshots captured with Playwright)

## Verification

- `openspec validate architecture-process-mapping --type change --strict`
- PHPUnit: `ProcessMappingRegisterShapeTest`, `RegisterFragmentMergeTest`
- vitest: `processStepFlow.spec.js`, `processStepUsages.spec.js`
- Playwright: `tests/e2e/workflows/process-mapping.spec.ts`
- Documentation in `docs/features/business-processes.md` with screenshots (ADR-010)
- English and Dutch strings for every new label and enum value (ADR-005)
