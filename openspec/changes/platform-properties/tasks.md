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
  - Done. `color-no-hex` is on in `stylelint.config.js`. Stylelint's rule has no `var()` exemption, so the few hex fallbacks went too: every Nextcloud theme variable is always defined. Badges read a theme text colour (`--color-success-text`, `--color-warning-text`, `--color-error-text`, `--color-primary-element-light-text`) on `--color-background-hover` or `--color-primary-element-light`; the JSON editor highlighting reads the same variables, so it follows a dark theme without a separate palette.
- [x] Test (`npm run stylelint`)
  - 0 errors over `src/` and `css/`; a probe file with `color: #123456` is refused by `color-no-hex`.

### Task 4: Lifecycle threshold on the service desk exchange (board SkInstellingenUitwisseling)
- **spec_ref**: openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-entries-go-to-outside-systems-only-from-a-chosen-lifecycle-state-req-plat-004
- **files**: `src/views/settings/sections/ItsmExchange.vue`, `lib/Service/ItsmExchangeService.php`, `lib/Controller/ItsmExchangeController.php`, `lib/Settings/flows/itsm-outbound-applications.json`, `tests/Unit/Service/ItsmExchangeLifecycleThresholdTest.php`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN the exchange settings WHEN they render THEN a select "Send only from lifecycle state" lists the usage lifecycle states, default "In gebruik"
  - GIVEN a chosen state WHEN the administrator saves THEN `ItsmExchangeService::buildFlows()` writes it into the filter node of the outbound template and the stored config keeps it
  - GIVEN an application in use below the threshold WHEN it is saved THEN the outbound flow does not send it
- [x] Implement
  - Done. The usage lifecycle is Acquisition, Planned, In production ("In gebruik"), To be phased out, Phased out; the threshold sends that state and every later one. `buildFlows()` fills `%SENT_STATES%` into an `in` condition of the outbound template's `ours` filter node, `setUp()` takes the threshold (empty keeps the stored one, an unknown state creates nothing), and the stored set-up and `status()` keep it. The section offers the states in a select, default In production.
- [x] Test (PHPUnit `ItsmExchangeLifecycleThresholdTest`)
  - 7 tests: the states sent, a refused unknown state, the default keeping a planned application home (the filter is evaluated against usage payloads), only the outbound flow carrying it, a chosen threshold published and stored, a second set-up keeping it, and an unknown one creating nothing; plus the controller passing it through and the section posting it (vitest).

### Task 5: Webhooks section in the exchange settings (board SkInstellingenUitwisseling)
- **spec_ref**: openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
- **files**: `src/views/settings/sections/CatalogueWebhooks.vue`, `src/modals/webhook/EditWebhookModal.vue`, `src/views/settings/StackiqSettings.vue`, `tests/vitest/catalogueWebhooks.spec.js`, `l10n/en.json`, `l10n/nl.json`
- **acceptance_criteria**:
  - GIVEN OpenRegister webhooks on the catalogue register and on another app's register WHEN the section loads THEN only the catalogue ones are listed, with address, events and last delivery
  - GIVEN an administrator WHEN they add a webhook with an address and the events "Applicatie gewijzigd" and "Contract gewijzigd" THEN it is created through OpenRegister's webhooks API, filtered to the module and contract schemas
  - GIVEN a listed webhook WHEN the administrator edits or removes it from its actions menu THEN OpenRegister holds the new state
  - GIVEN no webhook WHEN the section loads THEN it shows an empty state with "Add webhook"
- [x] Implement
  - Done. A webhook follows actions (Created, Changed, Deleted) on catalogue entries (Applications, Contracts, Applications in use): OpenRegister delivers each listed event class when every filter matches, so "Applicatie gewijzigd" and "Contract gewijzigd" are Changed on Applications and Contracts, written as `events: [ObjectUpdatedEvent]` and `filters: {register: [catalogue], schema: [module, contract]}`. The filters are lists, so OpenRegister compares them as "one of these" whatever type the payload carries the ids in. The register and schema ids come from `GET /api/voorzieningen/config`; only webhooks filtered to that register are listed. `src/utils/catalogueWebhooks.js` holds the mapping.
- [x] Test (vitest `catalogueWebhooks.spec.js`)
  - 12 tests: the scope, which webhooks are the catalogue's, form and body both ways, form problems, last delivery; the section listing, empty state, create, edit, remove and refusals; the dialog refusing an incomplete form and opening on the webhook it edits.

### Task 6: Matrix
- **spec_ref**: openspec/changes/platform-properties/specs/platform-properties/spec.md
- **files**: `openspec/parity/capabilities.json`
- **acceptance_criteria**:
  - GIVEN the tasks are done WHEN the change is archived THEN the seven rows point at `built.spec: openspec/specs/platform-properties`
- [ ] Implement
