# Tasks: portal-contribution

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 10.
     Acceptance criteria are plain bullets, not checkboxes. -->

## Implementation Tasks

### Task 1: Ship the plain PortalContributionProvider class

- **spec_ref**: `openspec/changes/portal-contribution/specs/portal-contribution/spec.md#requirement-provider-is-a-plain-dependency-free-class-req-port-001`
- **files**: `lib/Portal/PortalContributionProvider.php`
- **acceptance_criteria**:
  - GIVEN the new class WHEN inspected THEN it is namespace `OCA\Stackiq\Portal`, has NO `use` of any portaliq symbol, NO `implements` clause, NO constructor dependencies, and carries the repo-standard EUPL-1.2/SPDX docblock header plus `@spec` tags
  - GIVEN portaliq is absent WHEN the app runs THEN nothing references the class (no DI registration, no route) — it is inert
- [x] Implement
- [x] Test

### Task 2: Implement the v2+v1 audience contract and both manifests

- **spec_ref**: `openspec/changes/portal-contribution/specs/portal-contribution/spec.md#requirement-contribution-is-a-declarative-organisatie-scoped-read-manifest-req-port-003`
- **files**: `lib/Portal/PortalContributionProvider.php`
- **acceptance_criteria**:
  - GIVEN the provider WHEN `getAudiences()` / `getAudience()` are called THEN they return `['vendor-org','participant-org']` / `'vendor-org'` (REQ-PORT-002)
  - GIVEN an unserved or audience-less subject WHEN `getContribution()` is called THEN it returns `null`
  - GIVEN a `vendor-org` subject THEN the manifest declares `vendorDiensten`, `vendorGebruik`, `vendorContracts` (`via: dienst`, `minTrust: substantial`), `vendorCompliancy` (`via: module`), empty actions/notifications; GIVEN a `participant-org` subject THEN `participantGebruik` and `participantContracts` (`via: gebruik`)
- [x] Implement
- [x] Test

### Task 3: Declare organisatie scoping + field whitelists (no leakage)

- **spec_ref**: `openspec/changes/portal-contribution/specs/portal-contribution/spec.md#requirement-reads-are-field-projected-to-prevent-cross-organisation-leakage-req-port-004`
- **files**: `lib/Portal/PortalContributionProvider.php`
- **acceptance_criteria**:
  - GIVEN each collection WHEN inspected THEN it carries `scopeClaim: organisationId` and a `fields` whitelist that omits `gebruik.interneAantekening`, `contract.opmerkingen`, and the counterparty contactpersoon
  - GIVEN the manifests WHEN scanned THEN no collection uses schema `kwetsbaarheid` (documented exclusion)
- [x] Implement
- [x] Test

### Task 4: Unit-test the full provider contract + register-drift pin

- **spec_ref**: `openspec/changes/portal-contribution/specs/portal-contribution/spec.md#requirement-reads-are-field-projected-to-prevent-cross-organisation-leakage-req-port-004`
- **files**: `tests/Unit/Portal/PortalContributionProviderTest.php`
- **acceptance_criteria**:
  - GIVEN the test class WHEN it constructs the provider THEN it does so directly (`new`, no mocks/container)
  - GIVEN the drift pin WHEN run THEN it loads `lib/Settings/softwarecatalogus_register.json` and asserts every scopeField (direct or via-target) and projected field exists on its schema
  - GIVEN the suite WHEN run via phpunit (php 8.3 container) THEN it asserts audiences, null for unserved subjects, both manifest shapes, and the exclusions — and passes
- [x] Implement
- [x] Test

### Task 5: Register the capability spec and pass the quality gates

- **spec_ref**: `openspec/changes/portal-contribution/specs/portal-contribution/spec.md`
- **files**: `openspec/specs/portal-contribution/spec.md`, `openspec/changes/portal-contribution/*`
- **acceptance_criteria**:
  - GIVEN the declared capability WHEN the change is in flight THEN `openspec/specs/portal-contribution/spec.md` exists with status `in-progress` pointing at this change
  - GIVEN the repo gates WHEN run (php -l, phpcs, phpstan, psalm, unit suite via the php:8.3-cli container; `openspec validate`) THEN the new/changed files pass with zero new violations
- [x] Implement
- [x] Test

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- No new API endpoints → no Newman collection needed; no UI change → no Playwright needed (portal renders in portaliq)
- All tests pass (phpunit against `tests/Unit/Portal/` in the php 8.3 container)
- No user-facing strings added inside stackiq (manifest labels are portal-side data; English source per i18n policy)
- `openspec validate` passes
