## ADDED Requirements

### Requirement: Object-action modal text MUST be translatable
Every user-visible string rendered by the object-action modal family (`src/modals/object/*.vue`, `src/modals/BulkSyncDialog.vue`) and the ArchiMate import/export settings section (`src/views/settings/sections/ArchiMateImportExport.vue`) MUST be wrapped in `t('softwarecatalog', '…')` with the English literal as the translation key, per ADR-004.
No literal English prose (headings, table headers, button labels, empty-state text, placeholders) MAY be rendered directly in a `<template>` block.

#### Scenario: A Dutch-locale user opens the object-merge modal
- GIVEN a Nextcloud user with language set to Dutch (`nl`)
- WHEN they open the "Merge objects" modal
  (`src/modals/object/MergeObject.vue`)
- THEN every heading, table header, button label, and status message MUST
  render in Dutch (via `l10n/nl.json`)
- AND no residual English literal MUST appear

#### Scenario: A Dutch-locale user opens ArchiMate import/export settings
- GIVEN a Nextcloud admin with language set to Dutch (`nl`)
- WHEN they open the ArchiMate import/export admin settings section
- THEN every label, button, and status message MUST render in Dutch
- AND no residual English literal MUST appear

#### Scenario: `test:l10n` tooling catches a future un-wrapped literal
- GIVEN a developer adds a new hardcoded English string to an
  object-action modal instead of using `t()`
- WHEN `npm run test:l10n` (or an equivalent template-literal scan) is run
- THEN the CI job SHOULD flag the un-wrapped literal so this class of
  regression cannot silently reappear
  (existing `check-l10n.js` only validates keys that ARE wrapped in
  `t()`; extending it to flag un-wrapped `<template>` prose is a
  reasonable follow-up but not required to satisfy this requirement — the
  MUST above concerns the modals' actual rendered text, not the tooling)
