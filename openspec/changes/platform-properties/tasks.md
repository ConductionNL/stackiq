# Tasks: platform-properties

## Implementation tasks

### Task 1: Tag the files that carry each property
- **spec_ref**: openspec/changes/platform-properties/specs/platform-properties/spec.md
- **files**: `appinfo/info.xml`, `lib/AppInfo/Application.php`, `src/manifest.json`, `lib/Settings/flows/itsm-outbound-applications.json`
- **acceptance_criteria**:
  - GIVEN `appinfo/info.xml` WHEN read THEN an XML comment above `<licence>` cites REQ-PLAT-001
  - GIVEN `lib/AppInfo/Application.php` WHEN read THEN its class docblock cites REQ-PLAT-001 and REQ-PLAT-002
  - GIVEN the Store page and the History tabs in `src/manifest.json` WHEN read THEN their `_note` cites REQ-PLAT-007 and REQ-PLAT-006
- [x] Implement
  - Done: an XML comment above `<licence>` and the Application class docblock cite REQ-PLAT-001/002; the Store page `_note` cites REQ-PLAT-007 and every History tab carries a `_note` citing REQ-PLAT-006.
- [x] Test (`tests/validate-manifest.js`)
  - `node tests/validate-manifest.js` passes.

### Task 2: Checks that keep the properties true
- **spec_ref**: openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-the-catalogue-runs-on-the-customers-own-nextcloud-req-plat-001
- **files**: `tests/Unit/AppInfo/PlatformPropertiesTest.php`, `tests/vitest/manifestHistoryTabs.spec.js`
- **acceptance_criteria**:
  - GIVEN `appinfo/info.xml` and `LICENSE` WHEN the test reads them THEN both name the EUPL-1.2 (REQ-PLAT-001)
  - GIVEN `appinfo/routes.php` WHEN the test parses every routed controller method THEN each carries a Nextcloud access attribute, so the REST API keeps the schema rules (REQ-PLAT-002)
  - GIVEN the merged manifest WHEN the vitest reads every page of type detail THEN each one declares a History tab with an `audit` widget (REQ-PLAT-006)
- [x] Implement
  - Done. The route check accepts an access attribute or an `@auth admin-only` line; 45 settings and contact-person routes kept Nextcloud's admin-only default without saying so, and now carry that line (no behaviour change).
- [x] Test (PHPUnit `PlatformPropertiesTest`, vitest `manifestHistoryTabs.spec.js`)
  - `tests/Unit/AppInfo/PlatformPropertiesTest.php` (3) and `tests/vitest/manifestHistoryTabs.spec.js` (15, every detail page of the merged manifest).

### Task 3: No hard-coded colours
- **spec_ref**: openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-the-look-comes-from-the-theme-not-the-code-req-plat-003
- **files**: `stylelint.config.js`, the 10 `.vue` files `grep -rlE '#[0-9a-fA-F]{6}\b' src --include=*.vue` lists on development
- **acceptance_criteria**:
  - GIVEN `stylelint.config.js` WHEN `npm run stylelint` runs THEN `color-no-hex` fails on a hex colour outside a `var()` fallback
  - GIVEN the 10 files WHEN the rule runs THEN each colour is a Nextcloud theme variable (`--color-primary-element`, `--color-main-text`, `--color-error`, ...), with a hex only as a `var()` fallback
- [x] Implement
- [x] Test (`npm run stylelint`)
  > Built 21 Nov: the built-in `color-no-hex` also refuses a hex inside a `var()` fallback, which the requirement allows, so the rule is `stackiq/color-no-hex-outside-var` (`scripts/stylelint/color-no-hex-outside-var.js`), on for `src/**/*.vue` in `stylelint.config.js`. It reports a hex outside a var() fallback as an error and leaves custom property definitions alone. The four files still carrying bare hex colours on development (`ContactpersonenList.vue`, `OrganisatieCard.vue`, `MigrationObject.vue`, `ArchiMateImportExport.vue`) now read Nextcloud theme variables (`--color-success-text`, `--color-warning-text`, `--color-error-text`, `--color-info-text`, `--color-primary-element-light`, `--color-background-hover`, `--color-border`, ...) with the old hex as fallback; the other six of the ten had been fixed already. Test: `tests/vitest/stylelintThemeColours.spec.js` (8), including a stylelint run over every `.vue` under `src/`.

### Task 4: Lifecycle threshold on the service desk exchange (board SkInstellingenUitwisseling)
- **spec_ref**: openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-entries-go-to-outside-systems-only-from-a-chosen-lifecycle-state-req-plat-004
- **files**: `src/views/settings/sections/ItsmExchange.vue`, `lib/Service/ItsmExchangeService.php`, `lib/Controller/ItsmExchangeController.php`, `lib/Settings/flows/itsm-outbound-applications.json`, `tests/Unit/Service/ItsmExchangeLifecycleThresholdTest.php`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the exchange settings WHEN they render THEN a select "Send only from lifecycle state" lists the usage lifecycle states, default "In gebruik"
  - GIVEN a chosen state WHEN the administrator saves THEN `ItsmExchangeService::buildFlows()` writes it into the filter node of the outbound template and the stored config keeps it
  - GIVEN an application in use below the threshold WHEN it is saved THEN the outbound flow does not send it
- [x] Implement
- [x] Test (PHPUnit `ItsmExchangeLifecycleThresholdTest`)
  > Built 20 Nov: the usage schema's lifecycle (`status`: Acquisition, Planned, In production, To be phased out, Phased out) gives the order (`lib/Service/Itsm/ItsmSendFrom.php`). The chosen state is an app setting (`itsm_exchange_send_from`, default "In production"): `ItsmExchangeService::chooseSendFrom()` stores it, an unknown state is refused, and `ItsmExchangeController::setUp(sendFrom:)` chooses it before the set-up runs, answering 422 for an unknown state. `buildFlows()` writes the chosen state and every later one into an `in` condition on `json.status` in the outbound template's `ours` filter (`%SEND_STATES%`), so the stored config keeps it and the next set-up publishes it. `status()` returns `sendFrom` and `lifecycleStates`, and the exchange section has the select "Send only from lifecycle state". The spec's examples name "Gepland", "In gebruik" and "Concept"; the schema's English states take their place (Planned, In production), and there is no Concept state. Tests: `ItsmExchangeLifecycleThresholdTest` (5), `ItsmExchangeControllerTest`, `itsmExchangeSendFrom.spec.js`.

### Task 5: Webhooks section in the exchange settings (board SkInstellingenUitwisseling)
- **spec_ref**: openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
- **files**: `src/views/settings/sections/CatalogueWebhooks.vue`, `src/modals/webhook/EditWebhookModal.vue`, `src/views/settings/StackiqSettings.vue`, `tests/vitest/catalogueWebhooks.spec.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN OpenRegister webhooks on the catalogue register and on another app's register WHEN the section loads THEN only the catalogue ones are listed, with address, events and last delivery
  - GIVEN an administrator WHEN they add a webhook with an address and the events "Applicatie gewijzigd" and "Contract gewijzigd" THEN it is created through OpenRegister's webhooks API, filtered to the module and contract schemas
  - GIVEN a listed webhook WHEN the administrator edits or removes it from its actions menu THEN OpenRegister holds the new state
  - GIVEN no webhook WHEN the section loads THEN it shows an empty state with "Add webhook"
- [x] Implement
- [x] Test (vitest `catalogueWebhooks.spec.js`)
  > Built 20 Nov: `src/utils/catalogueWebhooks.js` reads the catalogue register and the module and contract schema ids from the voorzieningen config. It lists the OpenRegister webhooks whose `filters.register` is the catalogue register, and builds a webhook on `ObjectCreatedEvent` and `ObjectUpdatedEvent`, filtered to the register and the chosen events' schemas ("Application changed", "Contract changed"). `CatalogueWebhooks.vue` (in StackiqSettings after the service desk exchange) lists address, events and last delivery (the later of the last success and failure). Its actions menu edits and removes through `/apps/openregister/api/webhooks`; without webhooks it shows an empty state with "Add webhook". `src/modals/webhook/EditWebhookModal.vue` takes the address, a name and the events. Test: `catalogueWebhooks.spec.js` (7).

### Task 6: Matrix
- **spec_ref**: openspec/changes/platform-properties/specs/platform-properties/spec.md
- **files**: `openspec/parity/capabilities.json`
- **acceptance_criteria**:
  - GIVEN the tasks are done WHEN the change is archived THEN the seven rows point at `built.spec: openspec/specs/platform-properties`
- [ ] Implement
