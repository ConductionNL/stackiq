# Tasks: landscape-ai-system-inventory

## Implementation tasks

### Task 1: The aiSystem schema
- **spec_ref**: openspec/changes/landscape-ai-system-inventory/specs/ai-system-inventory/spec.md#requirement-req-ais-001-an-organisation-registers-the-ai-systems-it-uses-next-to-their-applications
- **files**: `lib/Settings/register.d/ai-system-inventory.json`, `lib/Settings/stackiq_mock_register.json`
- **acceptance_criteria**:
  - GIVEN the merged register WHEN it is imported THEN the stackiq register lists aiSystem with its lifecycle and file tags
- [ ] Implement
- [ ] Test (PHPUnit `tests/Unit/Settings/AiSystemFragmentTest.php`)

### Task 2: Pages and the application page section
- **spec_ref**: openspec/changes/landscape-ai-system-inventory/specs/ai-system-inventory/spec.md#requirement-req-ais-002-an-ai-system-carries-its-ai-act-classification-and-evidence
- **files**: `src/manifest.d/ai-systems.json`, `src/manifest.json` (ModuleDetail list), `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN an application with one AI feature WHEN its page opens THEN the AI systems section lists it with its risk category
  - GIVEN the AI systems list WHEN the user filters on high risk THEN only high-risk systems remain
- [ ] Implement
- [ ] Test (Playwright `tests/e2e/workflows/ai-systems.spec.ts`)

### Task 3: Evidence checklist and missing FRIA flag
- **spec_ref**: openspec/changes/landscape-ai-system-inventory/specs/ai-system-inventory/spec.md#requirement-req-ais-003-a-high-risk-ai-system-without-a-fundamental-rights-impact-assessment-is-flagged
- **files**: `src/components/ai/AiActChecklist.vue`, `src/customComponents.js`, `src/manifest.d/ai-systems.json` (quick filter and badge column)
- **acceptance_criteria**:
  - GIVEN a high-risk AI system without a FRIA reference WHEN the list is filtered on High risk without FRIA THEN it is listed with a warning
  - GIVEN its FRIA reference is filled WHEN the list reloads THEN it is no longer listed
- [ ] Implement
- [ ] Test (vitest `tests/vitest/aiActChecklist.spec.js`; Playwright case in `tests/e2e/workflows/ai-systems.spec.ts`)

### Task 4: Documentation
- **spec_ref**: openspec/changes/landscape-ai-system-inventory/specs/ai-system-inventory/spec.md#requirement-req-ais-001-an-organisation-registers-the-ai-systems-it-uses-next-to-their-applications
- **files**: `docs/features/ai-systems.md`, `docs/images/ai-systems.png`
- **acceptance_criteria**:
  - GIVEN the docs site WHEN a reader opens AI systems THEN registering, classifying and the evidence checklist are explained with a screenshot
- [ ] Implement
- [ ] Test (docs build, screenshot with Playwright)

## Verification

- `openspec validate landscape-ai-system-inventory --type change --strict` passes.
- `composer check:strict` and `npm run lint` pass; the PHPUnit, vitest and Playwright cases above pass.
- English and Dutch strings for every new label (ADR-005); docs with a screenshot (ADR-010).
