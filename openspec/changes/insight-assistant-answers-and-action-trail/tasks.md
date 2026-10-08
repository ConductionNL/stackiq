# Tasks: insight-assistant-answers-and-action-trail

## Implementation tasks

### Task 1: Ask service and route
- **spec_ref**: openspec/changes/insight-assistant-answers-and-action-trail/specs/assistant-insight/spec.md#requirement-req-ask-001-an-answer-lists-the-catalogue-entries-it-used
- **files**: `lib/Service/AskService.php`, `lib/Controller/AskController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a question WHEN `POST /api/ask` runs THEN OpenRegister's search runs as the caller over the seven schemas in design D1, capped at 20 entries
  - GIVEN the search result WHEN hermiq replies THEN the answer holds `reply`, `sources` (cited refs only) and `searched`
  - GIVEN an empty search WHEN asked THEN hermiq is not called
  - GIVEN hermiq is absent WHEN asked THEN the answer is 503
- [ ] Implement
- [ ] Test (PHPUnit `tests/Unit/Service/AskServiceTest.php` with an ObjectService double on the real interface and a recorded converse reply; `tests/Unit/Controller/AskControllerTest.php` for 503 and the rate limit)

### Task 2: Ask panel
- **spec_ref**: openspec/changes/insight-assistant-answers-and-action-trail/specs/assistant-insight/spec.md#requirement-req-ask-002-the-ask-panel-is-only-offered-when-hermiq-is-installed
- **files**: `src/views/AskView.vue`, `src/manifest.json` (page `Ask` at `/ask`, menu entry under Reports & Compliance), `lib/Listener` or `lib/AppInfo` initial state `hermiqAvailable`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN hermiq enabled WHEN the user opens Ask and submits a question THEN the reply and a linked Sources list render
  - GIVEN hermiq disabled WHEN the app loads THEN no Ask entry shows
- [ ] Implement
- [ ] Test (Playwright `tests/e2e/workflows/ask-the-catalogue.spec.ts`)

### Task 3: Assistant actions page
- **spec_ref**: openspec/changes/insight-assistant-answers-and-action-trail/specs/assistant-insight/spec.md#requirement-req-ask-003-assistant-actions-on-catalogue-records-are-listed
- **files**: `src/views/AssistantActionsView.vue`, `src/manifest.json` (page `AssistantActions` at `/assistant-actions`), `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN audit records with `mcp.` actions and `stackiq.` tool ids WHEN an administrator opens the page THEN each shows when, user, tool, linked record and outcome
  - GIVEN OpenRegister's `GET /api/audit-trails` WHEN the page loads THEN it filters server side on register and action prefix; if the API cannot, file the gap on OpenRegister and stop
- [ ] Implement
- [ ] Test (Playwright `tests/e2e/workflows/assistant-actions.spec.ts`)

### Task 4: History tab label
- **spec_ref**: openspec/changes/insight-assistant-answers-and-action-trail/specs/assistant-insight/spec.md#requirement-req-ask-004-the-history-tab-marks-assistant-actions
- **files**: `src/manifest.json` (the `audit` widget config on the 11 detail pages)
- **acceptance_criteria**:
  - GIVEN the library audit widget takes a label per action WHEN configured THEN `mcp.*` entries read "Assistant: {toolId}"
  - GIVEN it does not WHEN checked THEN an issue is filed on nextcloud-vue and the tab is left as it is
- [ ] Implement
- [ ] Test (vitest on the manifest config, or the library's own test once the label map exists)

### Task 5: Documentation
- **spec_ref**: openspec/changes/insight-assistant-answers-and-action-trail/specs/assistant-insight/spec.md#requirement-req-ask-001-an-answer-lists-the-catalogue-entries-it-used
- **files**: `docs/features/ask-the-catalogue.md`, `docs/features/assistant-actions.md`
- **acceptance_criteria**:
  - GIVEN the docs site WHEN a reader opens either page THEN it explains what is searched, what is sent to hermiq, and where to review assistant actions
- [ ] Implement
- [ ] Test (docs build)
