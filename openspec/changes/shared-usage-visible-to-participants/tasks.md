# Tasks: shared-usage-visible-to-participants

## Implementation tasks

### Task 1: Read rules for participants
- **spec_ref**: openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-participant-reads-the-usages-shared-with-it-and-their-connections-req-dlg-010
- **files**: `lib/Settings/softwarecatalogus_register.json`, `tests/Unit/Settings/UsageParticipantReadRuleTest.php`
- **acceptance_criteria**:
  - GIVEN the usage schema authorization WHEN read THEN `read` has entries for gebruik-beheerder and gebruik-raadpleger matching `participants` contains `$organisation`, and `update` and `delete` do not
  - GIVEN the connection schema WHEN read THEN `read` lets a participant of a usage that lists the connection read it (an OpenRegister relation match on `usage.koppelingen`; if OpenRegister cannot express it, a stackiq `participantConnections` lookup in task 2 serves it instead)
  - GIVEN an installed instance WHEN the app upgrades THEN the register import applies the new rules (repair step or register version bump)
- [ ] Implement
- [ ] Test (PHPUnit `UsageParticipantReadRuleTest`)

### Task 2: Participant reads on the API
- **spec_ref**: openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-participant-reads-the-usages-shared-with-it-and-their-connections-req-dlg-010
- **files**: `lib/Controller/GebruikController.php`, `lib/Service/GebruikService.php`, `tests/Unit/Controller/GebruikControllerParticipantTest.php`
- **acceptance_criteria**:
  - GIVEN a participant WHEN calling `GET /api/gebruik` THEN own and shared usages are returned, each shared one with `_shared: {cooperation, participantCount}`
  - GIVEN a participant WHEN calling PUT or DELETE on a shared usage THEN it is refused with 403 before any write
- [ ] Implement
- [ ] Test (PHPUnit `GebruikControllerParticipantTest`)

### Task 3: Applications in use and usage detail (boards SkInGebruik, SkGebruikDetail)
- **spec_ref**: openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-participant-reads-the-usages-shared-with-it-and-their-connections-req-dlg-010
- **files**: `src/manifest.d/usages.json`, `src/components/usage/UsageParticipantsCard.vue`, `src/customComponents.js`, `tests/vitest/usageParticipantsCard.spec.js`, `tests/e2e/spec-coverage/shared-usage-participants.spec.ts`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the Gebruik page WHEN it renders THEN it has a Shared column and a quick filter "Shared through a cooperation", and the header says how many usages are shared
  - GIVEN GebruikDetail WHEN it renders THEN the Participants card names the cooperation, the owner and each participant with the date they joined; for a participant, edit and delete are hidden
- [ ] Implement
- [ ] Test (vitest `usageParticipantsCard.spec.js`, Playwright `shared-usage-participants.spec.ts`)

### Task 4: Shared with on Connections (board SkKoppelingen)
- **spec_ref**: openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-the-connections-list-says-with-whom-a-connection-is-shared-req-dlg-011
- **files**: `src/manifest.d/connections.json`, `src/components/connections/SharedWithCell.vue`, `tests/vitest/sharedWithCell.spec.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the Koppelingen page WHEN it renders THEN a "Shared with" column lists the participants of the usages that hold each connection, or "nobody"
  - GIVEN a participant WHEN it opens a shared connection THEN KoppelingDetail opens without edit actions
- [ ] Implement
- [ ] Test (vitest `sharedWithCell.spec.js`)

### Task 5: Cooperation participants on Organisations (board SkOrganisaties)
- **spec_ref**: openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-cooperation-shows-its-participants-req-dlg-012
- **files**: `src/manifest.json`, `src/components/cards/OrganisatieCard.vue`, `tests/vitest/organisatieCardParticipants.spec.js`
- **acceptance_criteria**:
  - GIVEN a cooperation WHEN listed on Organisaties THEN its subtitle reads "Cooperation · N participants"
  - GIVEN its detail page WHEN opened THEN a Participants list links each participant organisation
- [ ] Implement
- [ ] Test (vitest `organisatieCardParticipants.spec.js`)
