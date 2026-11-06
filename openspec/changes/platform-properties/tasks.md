# Tasks: platform-properties

## Implementation tasks

### Task 1: Tag the files that carry each property
- **spec_ref**: openspec/changes/platform-properties/specs/platform-properties/spec.md
- **files**: `appinfo/info.xml`, `lib/AppInfo/Application.php`, `src/manifest.json`, `lib/Settings/flows/itsm-outbound-applications.json`
- **acceptance_criteria**:
  - GIVEN `appinfo/info.xml` WHEN read THEN an XML comment above `<licence>` cites REQ-PLAT-001
  - GIVEN `lib/AppInfo/Application.php` WHEN read THEN its class docblock cites REQ-PLAT-001 and REQ-PLAT-002
  - GIVEN the Store page and the History tabs in `src/manifest.json` WHEN read THEN their `_note` cites REQ-PLAT-007 and REQ-PLAT-006
- [ ] Implement
- [ ] Test (`tests/validate-manifest.js`)

### Task 2: Checks that keep the properties true
- **spec_ref**: openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-the-catalogue-runs-on-the-customers-own-nextcloud-req-plat-001
- **files**: `tests/Unit/AppInfo/PlatformPropertiesTest.php`, `tests/vitest/manifestHistoryTabs.spec.js`
- **acceptance_criteria**:
  - GIVEN `appinfo/info.xml` and `LICENSE` WHEN the test reads them THEN both name the EUPL-1.2 (REQ-PLAT-001)
  - GIVEN `appinfo/routes.php` WHEN the test parses every routed controller method THEN each carries a Nextcloud access attribute, so the REST API keeps the schema rules (REQ-PLAT-002)
  - GIVEN the merged manifest WHEN the vitest reads every page of type detail THEN each one declares a History tab with an `audit` widget (REQ-PLAT-006)
- [ ] Implement
- [ ] Test (PHPUnit `PlatformPropertiesTest`, vitest `manifestHistoryTabs.spec.js`)

### Task 3: No hard-coded colours
- **spec_ref**: openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-the-look-comes-from-the-theme-not-the-code-req-plat-003
- **files**: `stylelint.config.js`, the 10 `.vue` files `grep -rlE '#[0-9a-fA-F]{6}\b' src --include=*.vue` lists on development
- **acceptance_criteria**:
  - GIVEN `stylelint.config.js` WHEN `npm run stylelint` runs THEN `color-no-hex` fails on a hex colour outside a `var()` fallback
  - GIVEN the 10 files WHEN the rule runs THEN each colour is a Nextcloud theme variable (`--color-primary-element`, `--color-main-text`, `--color-error`, ...), with a hex only as a `var()` fallback
- [ ] Implement
- [ ] Test (`npm run stylelint`)

### Task 4: Lifecycle threshold on the service desk exchange (board SkInstellingenUitwisseling)
- **spec_ref**: openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-entries-go-to-outside-systems-only-from-a-chosen-lifecycle-state-req-plat-004
- **files**: `src/views/settings/sections/ItsmExchange.vue`, `lib/Service/ItsmExchangeService.php`, `lib/Controller/ItsmExchangeController.php`, `lib/Settings/flows/itsm-outbound-applications.json`, `tests/Unit/Service/ItsmExchangeLifecycleThresholdTest.php`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the exchange settings WHEN they render THEN a select "Send only from lifecycle state" lists the usage lifecycle states, default "In gebruik"
  - GIVEN a chosen state WHEN the administrator saves THEN `ItsmExchangeService::buildFlows()` writes it into the filter node of the outbound template and the stored config keeps it
  - GIVEN an application in use below the threshold WHEN it is saved THEN the outbound flow does not send it
- [ ] Implement
- [ ] Test (PHPUnit `ItsmExchangeLifecycleThresholdTest`)

### Task 5: Webhooks section in the exchange settings (board SkInstellingenUitwisseling)
- **spec_ref**: openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
- **files**: `src/views/settings/sections/CatalogueWebhooks.vue`, `src/modals/webhook/EditWebhookModal.vue`, `src/views/settings/StackiqSettings.vue`, `tests/vitest/catalogueWebhooks.spec.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN OpenRegister webhooks on the catalogue register and on another app's register WHEN the section loads THEN only the catalogue ones are listed, with address, events and last delivery
  - GIVEN an administrator WHEN they add a webhook with an address and the events "Applicatie gewijzigd" and "Contract gewijzigd" THEN it is created through OpenRegister's webhooks API, filtered to the module and contract schemas
  - GIVEN a listed webhook WHEN the administrator edits or removes it from its actions menu THEN OpenRegister holds the new state
  - GIVEN no webhook WHEN the section loads THEN it shows an empty state with "Add webhook"
- [ ] Implement
- [ ] Test (vitest `catalogueWebhooks.spec.js`)

### Task 6: Matrix
- **spec_ref**: openspec/changes/platform-properties/specs/platform-properties/spec.md
- **files**: `openspec/parity/capabilities.json`
- **acceptance_criteria**:
  - GIVEN the tasks are done WHEN the change is archived THEN the seven rows point at `built.spec: openspec/specs/platform-properties`
- [ ] Implement
