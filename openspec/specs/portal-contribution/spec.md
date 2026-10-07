---
capability: portal-contribution
status: implemented
built_by: openspec/changes/archive/2026-10-07-portal-contribution
---

# portal-contribution Specification

**Status**: implemented
**Scope**: stackiq
**OpenSpec changes**:
- [portal-contribution](../../changes/archive/2026-10-07-portal-contribution/) _(archived 2026-10-07)_ — ADR-046 provider class with `vendor-org` + `participant-org` organisatie-scoped read manifests, `via` one-hop joins, field whitelists, unit tests (kind: code)

## Purpose

Software Catalog contributes read surfaces to portaliq, the shared external
portal for people without Nextcloud accounts (hydra ADR-046, contribution
contract v2.1). The contribution is one plain, dependency-free provider class
(`OCA\Stackiq\Portal\PortalContributionProvider`, duck-typed by FQCN —
inert without portaliq) that declares, for the `vendor-org` (software supplier)
and `participant-org` (municipality/collaboration) audiences, the OpenRegister
collections a portal subject may read — each scoped to the subject's own
`organisatie` UUID (claim `organisationId`) and field-projected so no other
organisation's data leaks. Adopting it lets the catalog retire its managed-NC-
account provisioning for external contactpersonen and its anonymous public API
sprawl (see the change's design.md).

## Requirements

Detailed requirements (REQ-PORT-001 … REQ-PORT-004) are defined in the active
change's delta spec —
[`openspec/changes/portal-contribution/specs/portal-contribution/spec.md`](../../changes/portal-contribution/specs/portal-contribution/spec.md)
— and are merged here by `openspec sync` when the change is archived. The
umbrella requirement below anchors the capability until then.

### Requirement: Software Catalog ships the ADR-046 read contribution (REQ-PORT-000)

The app MUST serve its entire portal contribution through the single artefact
this capability owns: the plain, dependency-free
`OCA\Stackiq\Portal\PortalContributionProvider` class (duck-typed by
FQCN, inert without portaliq). Every declared collection MUST be scoped to the
subject's `organisatie` UUID (directly or via a single one-hop join) and
field-projected to exclude staff-only and counterparty-organisation columns. No
other portal logic, UI, or dependency may exist in stackiq, and no
create or endpoint action ships in this wave.

#### Scenario: Contribution surface is exactly the provider class

- GIVEN a stackiq checkout at this capability's `in-progress` (or later) status
- WHEN portaliq's registry (contract v2) discovers and duck-types the provider
- THEN the whole contribution resolves from `lib/Portal/PortalContributionProvider.php`
- AND removing that file removes the contribution without affecting any other app behaviour
- @e2e exclude backend-only contract surface with no stackiq UI; the portal renders inside portaliq — covered by PHPUnit (tests/Unit/Portal/PortalContributionProviderTest.php)

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

## Notes

- Discovery is pull-based from portaliq (`method_exists`, never `instanceof`);
  stackiq registers nothing in `lib/AppInfo/Application.php`.
- `scopeClaim`, `via`, `minTrust` and `fields` are contract-v2.1 fields;
  portaliq's reader currently scopes on `scopeField` alone, so `via` collections
  fail closed until portaliq lands one-hop joins.
- Related ADRs: hydra ADR-046 (+ amendments A1–A7), ADR-022, ADR-005.
