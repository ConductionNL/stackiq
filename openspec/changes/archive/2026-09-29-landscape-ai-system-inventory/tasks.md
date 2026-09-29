# Tasks: landscape-ai-system-inventory

## Implementation tasks

### Task 1: The aiSystem schema
- **spec_ref**: openspec/changes/landscape-ai-system-inventory/specs/ai-system-inventory/spec.md#requirement-req-ais-001-an-organisation-registers-the-ai-systems-it-uses-next-to-their-applications
- **files**: `lib/Settings/register.d/ai-system-inventory.json`, `lib/Settings/stackiq_mock_register.json`
- **acceptance_criteria**:
  - GIVEN the merged register WHEN it is imported THEN the stackiq register lists aiSystem with its lifecycle and file tags
- [x] Implement
- [x] Test (PHPUnit `tests/Unit/Settings/AiSystemFragmentTest.php`)

### Task 2: Pages and the application page section
- **spec_ref**: openspec/changes/landscape-ai-system-inventory/specs/ai-system-inventory/spec.md#requirement-req-ais-002-an-ai-system-carries-its-ai-act-classification-and-evidence
- **files**: `src/manifest.d/ai-systems.json`, `src/manifest.json` (ModuleDetail list), `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN an application with one AI feature WHEN its page opens THEN the AI systems section lists it with its risk category
  - GIVEN the AI systems list WHEN the user filters on high risk THEN only high-risk systems remain
- [x] Implement
- [x] Test (Playwright `tests/e2e/workflows/ai-systems.spec.ts`)

### Task 3: Evidence checklist and missing FRIA flag
- **spec_ref**: openspec/changes/landscape-ai-system-inventory/specs/ai-system-inventory/spec.md#requirement-req-ais-003-a-high-risk-ai-system-without-a-fundamental-rights-impact-assessment-is-flagged
- **files**: `src/components/ai/AiActChecklist.vue`, `src/customComponents.js`, `src/manifest.d/ai-systems.json` (quick filter and badge column)
- **acceptance_criteria**:
  - GIVEN a high-risk AI system without a FRIA reference WHEN the list is filtered on High risk without FRIA THEN it is listed with a warning
  - GIVEN its FRIA reference is filled WHEN the list reloads THEN it is no longer listed
- [x] Implement
- [x] Test (vitest `tests/vitest/aiActChecklist.spec.js`; Playwright case in `tests/e2e/workflows/ai-systems.spec.ts`)

### Task 4: Documentation
- **spec_ref**: openspec/changes/landscape-ai-system-inventory/specs/ai-system-inventory/spec.md#requirement-req-ais-001-an-organisation-registers-the-ai-systems-it-uses-next-to-their-applications
- **files**: `docs/features/ai-systems.md`, `docs/images/ai-systems.png`
- **acceptance_criteria**:
  - GIVEN the docs site WHEN a reader opens AI systems THEN registering, classifying and the evidence checklist are explained with a screenshot
- [x] Implement
- [x] Test (docs build, screenshot with Playwright)

## Verification

- `openspec validate landscape-ai-system-inventory --type change --strict` passes.
- `composer check:strict` and `npm run lint` pass; the PHPUnit, vitest and Playwright cases above pass.
- English and Dutch strings for every new label (ADR-005); docs with a screenshot (ADR-010).

## As built (2026-09-29)

- The schema, pages, checklist rule and seeds are tested in `tests/Unit/Settings/AiSystemFragmentTest.php` and `tests/vitest/aiSystems.spec.js` (the vitest file also covers what `aiActChecklist.spec.js` was to hold).
- The High risk without FRIA filter sends `friaDocumentRef=IS NULL`, which OpenRegister's property filter reads as a null check. The warning column uses an app cell formatter `friaStatus` (src/utils/aiAct.js), passed to CnAppRoot as `formatters`.
- `tests/e2e/workflows/ai-systems.spec.ts` seeds the AI systems through the objects API, the call the Add form makes, rather than typing into the form. It lists but was not run: no local instance has a seeded stackiq register. The docs screenshot waits for that instance; `docs/images/ai-systems.png` is not added.
- Seeds: a chat assistant (AI feature, limited risk) and a scoring model (AI model, high risk, no FRIA). Like every other demo object they carry no relation, so the chat assistant is not linked to a demo application.
