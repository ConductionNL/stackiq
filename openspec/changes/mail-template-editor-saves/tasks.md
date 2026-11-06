# Tasks: mail-template-editor-saves

## Implementation tasks

### Task 1: Save and reset call the backend (board SkBeheerToegang)
- **spec_ref**: openspec/changes/mail-template-editor-saves/specs/email-delivery/spec.md#requirement-an-administrator-edits-and-saves-a-mail-template-req-010
- **files**: `src/views/settings/sections/EmailConfiguration.vue`, `tests/vitest/emailTemplateSave.spec.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN `saveTemplate()` WHEN called THEN it posts `{template}` to `/api/email/templates/{templateName}` and shows success only on `success: true`; the "not yet implemented" text and the console call are gone
  - GIVEN an error response WHEN saving THEN the backend message is shown with `showError`
  - GIVEN Reset to default WHEN chosen THEN the editor loads `/api/email/templates/{templateName}/default`
- [ ] Implement
- [ ] Test (vitest `emailTemplateSave.spec.js`)

### Task 2: Warning for the null transport
- **spec_ref**: openspec/changes/mail-template-editor-saves/specs/email-delivery/spec.md#requirement-the-settings-say-when-mail-is-not-being-sent-req-011
- **files**: `src/views/settings/sections/EmailConfiguration.vue`, `src/views/settings/sections/UserGroupsConfiguration.vue`, `tests/vitest/emailTransportWarning.spec.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN transport `null` or empty WHEN either section renders THEN an `NcNoteCard` warning says mails are not sent and links to the transport tab
- [ ] Implement
- [ ] Test (vitest `emailTransportWarning.spec.js`)
