# portal-contribution Specification

**Status**: in-progress
**Scope**: stackiq
**OpenSpec changes**:
- `openspec/changes/portal-contribution/`

## Purpose

Software Catalog contributes read surfaces to portaliq, the shared external
portal for people without Nextcloud accounts (hydra ADR-046, contribution
contract v2.1). The contribution is one plain, dependency-free provider class
that declares, per audience, the OpenRegister collections a portal subject may
read — each scoped to the subject's own `organisatie` UUID and field-projected
so no other organisation's data leaks.

## ADDED Requirements

### Requirement: Provider is a plain, dependency-free class (REQ-PORT-001)

The app MUST ship `OCA\Stackiq\Portal\PortalContributionProvider` as a
plain PHP class: no imports from portaliq, no `implements` clause, no `info.xml`
dependency on portaliq, and no constructor dependencies. Portaliq discovers it by
convention FQCN and duck-types it via `method_exists` (never `instanceof`), so
without portaliq installed the class MUST be inert and MUST NOT change any app
behaviour (ADR-046 amendment A1).

#### Scenario: Provider constructs standalone

- GIVEN a PHP runtime where portaliq is not installed and no portaliq class is autoloadable
- WHEN `new PortalContributionProvider()` is called
- THEN the class instantiates without error
- AND it declares no `implements` clause and no `use` of any portaliq symbol
- @e2e exclude backend-only contract class with no stackiq UI surface; the portal renders inside portaliq — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)

### Requirement: Provider declares both v2 and v1 audience methods (REQ-PORT-002)

The provider MUST implement `getAudiences(): array` returning
`['vendor-org', 'participant-org']` (contract v2, preferred by the registry) AND
`getAudience(): string` returning `'vendor-org'` (v1 fallback), so it works
against both registry generations (ADR-046 amendment A2). The two audiences exist
because the same `gebruik` object is scoped by a different property for each side.

#### Scenario: Audience methods agree

- GIVEN a constructed provider
- WHEN `getAudiences()` and `getAudience()` are called
- THEN `getAudiences()` returns exactly `['vendor-org', 'participant-org']`
- AND `getAudience()` returns `'vendor-org'`
- AND the primary audience is a member of the audiences list
- @e2e exclude backend-only contract methods with no stackiq UI surface — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)

### Requirement: Contribution is a declarative, organisatie-scoped read manifest (REQ-PORT-003)

`getContribution(array $subject): ?array` MUST return `null` unless
`$subject['audience']` is `'vendor-org'` or `'participant-org'`. For a served
audience it MUST return a declarative manifest labelled `'Software Catalog'` with
READ `collections` scoped by the subject's `organisatie` UUID (each collection
carrying `scopeClaim: organisationId`), an empty `actions` list, and an empty
`notifications` list. The manifest MUST be pure data — no callbacks, no service
calls; all subject identity (subjectRef, audience, organisation, trust) is
server-derived by portaliq and MUST NOT be echoed back or trusted from the client.

For `vendor-org` the collections MUST be, in order: `vendorDiensten`
(schema `dienst`, scopeField `aanbieder`); `vendorGebruik` (schema `gebruik`,
scopeField `aanbieder`); `vendorContracts` (schema `contract`, `via: dienst`,
scopeField `aanbieder`, `minTrust: substantial`); `vendorCompliancy`
(schema `compliancy`, `via: module`, scopeField `aanbieder`).

For `participant-org` the collections MUST be, in order: `participantGebruik`
(schema `gebruik`, scopeField `afnemer`); `participantContracts`
(schema `contract`, `via: gebruik`, scopeField `afnemer`, `minTrust: substantial`).

#### Scenario: Vendor subject receives the vendor manifest

- GIVEN a subject array whose `audience` is `'vendor-org'` with a subjectRef, organisation and trust level
- WHEN `getContribution($subject)` is called
- THEN it returns a manifest labelled `'Software Catalog'` whose collections are exactly `vendorDiensten`, `vendorGebruik`, `vendorContracts`, `vendorCompliancy`
- AND `vendorContracts` declares `via: dienst`, scopeField `aanbieder`, and `minTrust: substantial`
- AND `vendorCompliancy` declares `via: module` and scopeField `aanbieder`
- AND `actions` and `notifications` are both empty
- @e2e exclude manifest is consumed and rendered by portaliq, not by any stackiq UI — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)

#### Scenario: Participant subject receives the participant manifest

- GIVEN a subject array whose `audience` is `'participant-org'`
- WHEN `getContribution($subject)` is called
- THEN it returns a manifest whose collections are exactly `participantGebruik` (scopeField `afnemer`) and `participantContracts` (`via: gebruik`, scopeField `afnemer`)
- AND `actions` and `notifications` are both empty
- @e2e exclude manifest consumed by portaliq, no stackiq UI surface — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)

#### Scenario: Unserved audience receives null

- GIVEN a subject array whose `audience` is `'client'` (or any unserved value, or absent)
- WHEN `getContribution($subject)` is called
- THEN it returns `null`
- @e2e exclude backend-only fail-closed filter with no stackiq UI surface — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)

### Requirement: Reads are field-projected to prevent cross-organisation leakage (REQ-PORT-004)

Every collection MUST declare a `fields` whitelist that omits staff-only columns
(at least `gebruik.interneAantekening` and `contract.opmerkingen`) and the
counterparty organisation's contactpersoon (`contract.contactpersoonGebruiker`
on the vendor side; `contract.contactpersoonAanbieder` on the participant side).
Every declared `scopeField` and every projected `fields` entry MUST correspond to
a real property of the register schema it is declared against (for `via`
collections, the `via` property MUST exist on the collection schema and the
`scopeField` MUST exist on the schema the `via` property references). `kwetsbaarheid`
MUST NOT appear as a collection (its organisatie link is an array-membership,
multi-hop path).

#### Scenario: Register-drift pin — every scope and projected field exists on its schema

- GIVEN the shipped `lib/Settings/softwarecatalogus_register.json`
- WHEN each collection across both audiences is checked against it
- THEN every direct collection's `scopeField` is a property of its schema
- AND every `via` collection's `via` property exists on its schema and its `scopeField` exists on the referenced schema
- AND every projected `fields` entry is a property of its collection's schema
- @e2e exclude declarative manifest ↔ register-config invariant with no UI surface — covered by the register-drift PHPUnit test (tests/Unit/Portal/PortalContributionProviderTest.php)

#### Scenario: Staff-only and counterparty columns are never projected

- GIVEN the vendor-org and participant-org manifests
- WHEN their collection `fields` whitelists are inspected
- THEN neither `gebruik` projection contains `interneAantekening`
- AND neither `contract` projection contains `opmerkingen`
- AND the vendor `contract` projection omits `contactpersoonGebruiker` while the participant `contract` projection omits `contactpersoonAanbieder`
- AND no collection uses schema `kwetsbaarheid`
- @e2e exclude backend-only data-minimisation invariant with no UI surface — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)

## Non-Functional Requirements

- **Performance:** `getContribution()` is pure data assembly — no I/O, no
  container access; sub-millisecond by construction.
- **Accessibility:** N/A in stackiq — the rendering surface is portaliq's
  SPA (ADR-046), which owns WCAG compliance.
- **Internationalization:** manifest labels ship in English source per fleet i18n
  policy; portaliq owns portal-side translation of contributed labels.

## Acceptance Criteria

- Unit suite proves: audiences, null for unserved subjects, both full manifest
  shapes (collections, scopeFields, via, minTrust), the exclusion of staff-only
  and counterparty columns, and the register-drift pin.
- `php -l`, phpcs, phpstan and psalm pass on the new provider file.
- No register JSON, route, controller, service, frontend or info.xml change.

## Notes

- The provider is deliberately NOT registered in `lib/AppInfo/Application.php` —
  discovery is by FQCN from portaliq's side.
- `scopeClaim`, `via`, `minTrust` and `fields` are contract-v2.1 fields; portaliq's
  reader currently scopes on `scopeField` alone, so `via` collections fail closed
  (empty) until portaliq lands one-hop joins — see design.md.
- Related: hydra ADR-046 (+ amendments A1–A7), ADR-022 (apps consume OR
  abstractions), ADR-005 (security — server-derived scope, fail-closed).
- Deferred: create-actions (dienst self-registration / moduleVersie) and the A6
  accept/deny endpoint actions — see design.md and proposal.md.
