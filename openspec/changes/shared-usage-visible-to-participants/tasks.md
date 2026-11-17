# Tasks: shared-usage-visible-to-participants

## Implementation tasks

### Task 1: Read rules for participants
- **spec_ref**: openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-participant-reads-the-usages-shared-with-it-and-their-connections-req-dlg-010
- **files**: `lib/Settings/softwarecatalogus_register.json`, `tests/Unit/Settings/UsageParticipantReadRuleTest.php`
- **acceptance_criteria**:
  - GIVEN the usage schema authorization WHEN read THEN `read` has entries for gebruik-beheerder and gebruik-raadpleger matching `participants` contains `$organisation`, and `update` and `delete` do not
  - GIVEN the connection schema WHEN read THEN `read` lets a participant of a usage that lists the connection read it (an OpenRegister relation match on `usage.koppelingen`; if OpenRegister cannot express it, a stackiq `participantConnections` lookup in task 2 serves it instead)
  - GIVEN an installed instance WHEN the app upgrades THEN the register import applies the new rules (repair step or register version bump)
- [x] Implement
  - `lib/Settings/register.d/shared-usage-visible-to-participants.json` (design D5: a fragment, not the monolith) restates the merged usage `read` list and adds `gebruik-beheerder` and `gebruik-raadpleger` matching `participants` `$contains` `$organisation`, cross-organisation; `update` and `delete` are untouched. Usage version 1.6.2. The connection half is not expressible as a rule on the connection schema (it would have to match through `usage.koppelingen`), so it falls to task 2's lookup, as the criterion allows. Three existing register tests that pinned the merged version or the read count now say why the count and version moved.
- [x] Test (PHPUnit `UsageParticipantReadRuleTest`)

### Task 2: Participant reads on the API
- **spec_ref**: openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-participant-reads-the-usages-shared-with-it-and-their-connections-req-dlg-010
- **files**: `lib/Controller/GebruikController.php`, `lib/Service/GebruikService.php`, `tests/Unit/Controller/GebruikControllerParticipantTest.php`
- **acceptance_criteria**:
  - GIVEN a participant WHEN calling `GET /api/gebruik` THEN own and shared usages are returned, each shared one with `_shared: {cooperation, participantCount}`
  - GIVEN a participant WHEN calling PUT or DELETE on a shared usage THEN it is refused with 403 before any write
- [x] Implement
  - `GET /api/gebruik`: a gebruik-beheerder's first page (no own `consumer` filter) gets the usages shared with its organisation added by `SharedUsageScope::merge`, each with `_shared: {cooperation, participantCount}` (cooperation is the owning organisation; the count is the owner plus every participant, each once). `GebruikService::getSharedGebruiken` forces `participants` to the caller's organisation, drops consumer and paging, and re-checks every record, so an ignored filter cannot leak another organisation's usage. The existing scope table (admin, ambtenaar, vendor, own consumer) is unchanged.
  - `GET /api/gebruik/shared-connections` (gebruik-beheerder or gebruik-raadpleger with an organisation): each connection of a shared usage the organisation owns or takes part in, with `sharedWith` and `usages`. This is the connection lookup task 1 leaves to this task, and it feeds task 4's column.
  - PUT and DELETE: stackiq has no usage write endpoint; writes go to OpenRegister, whose `update` and `delete` rules on the usage schema have no participant entry (task 1, pinned by `UsageParticipantReadRuleTest`), so OpenRegister refuses them before any write.
- [x] Test (PHPUnit `GebruikControllerParticipantTest`, `SharedUsageScopeTest`, `GebruikServiceSharedTest`)

### Task 3: Applications in use and usage detail (boards SkInGebruik, SkGebruikDetail)
- **spec_ref**: openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-participant-reads-the-usages-shared-with-it-and-their-connections-req-dlg-010
- **files**: `src/manifest.d/usages.json`, `src/components/usage/UsageParticipantsCard.vue`, `src/customComponents.js`, `tests/vitest/usageParticipantsCard.spec.js`, `tests/e2e/spec-coverage/shared-usage-participants.spec.ts`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the Gebruik page WHEN it renders THEN it has a Shared column and a quick filter "Shared through a cooperation", and the header says how many usages are shared
  - GIVEN GebruikDetail WHEN it renders THEN the Participants card names the cooperation, the owner and each participant with the date they joined; for a participant, edit and delete are hidden
- [x] Implement
  - Gebruik: column Shared (`participants`, cell widget `sharedUsage`, "owner, N" with the owner counted once), quick filter "Shared through a cooperation" (`participants` IS NOT NULL) and `headerMeta` "[{count:shared} shared through a cooperation]" from a `headerMetaCounts` list on the same filter. Whether OpenRegister stores an empty participants list as NULL (so the filter and count leave it out) needs a check on a live instance.
  - GebruikDetail: body widget `UsageParticipantsCard` (in `src/components/usages/`, the folder the other usage widgets use): the owner, how many organisations share it, each participant linked to its organisation with the date it joined when the record carries one (`since`/`joinedAt`; the usage schema has no join date yet, so it reads "Not recorded"), and a note when `@self.can.update` is false. `permissions: true` on the page withdraws Edit while the record's update right is false.
- [ ] Test (vitest `usageParticipantsCard.spec.js` and `sharedUsage.spec.js` pass; Playwright `shared-usage-participants.spec.ts` written, not yet run on a live instance)

### Task 4: Shared with on Connections (board SkKoppelingen)
- **spec_ref**: openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-the-connections-list-says-with-whom-a-connection-is-shared-req-dlg-011
- **files**: `src/manifest.d/connections.json`, `src/components/connections/SharedWithCell.vue`, `tests/vitest/sharedWithCell.spec.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the Koppelingen page WHEN it renders THEN a "Shared with" column lists the participants of the usages that hold each connection, or "nobody"
  - GIVEN a participant WHEN it opens a shared connection THEN KoppelingDetail opens without edit actions
- [ ] Implement
  - Done: column "Shared with" on Koppelingen (cell widget `sharedWith`, one `GET /api/gebruik/shared-connections` per page load, names resolved, "nobody" when no shared usage holds it, empty when the lookup is refused), and `permissions: true` on KoppelingDetail so Edit follows the record's update right.
  - Left: a participant still cannot open or list a shared connection, because the connection schema has no read rule for it (task 1: not expressible through `usage.koppelingen`). It needs either an OpenRegister rule that matches through a relation, or a stackiq read of the connection by id that checks `/api/gebruik/shared-connections` first.
- [x] Test (vitest `sharedWithCell.spec.js`)

### Task 5: Cooperation participants on Organisations (board SkOrganisaties)
- **spec_ref**: openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-cooperation-shows-its-participants-req-dlg-012
- **files**: `src/manifest.json`, `src/components/cards/OrganisatieCard.vue`, `tests/vitest/organisatieCardParticipants.spec.js`
- **acceptance_criteria**:
  - GIVEN a cooperation WHEN listed on Organisaties THEN its subtitle reads "Cooperation · N participants"
  - GIVEN its detail page WHEN opened THEN a Participants list links each participant organisation
- [ ] Implement
- [ ] Test (vitest `organisatieCardParticipants.spec.js`)
