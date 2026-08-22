---
capability: portal-contribution
status: in-progress
built_by: openspec/changes/portal-contribution
---

# portal-contribution Specification

**Status**: in-progress
**Scope**: stackiq
**OpenSpec changes**:
- [portal-contribution](../../changes/portal-contribution/) _(active)_ — ADR-046 provider class with `vendor-org` + `participant-org` organisatie-scoped read manifests, `via` one-hop joins, field whitelists, unit tests (kind: code)

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

## Notes

- Discovery is pull-based from portaliq (`method_exists`, never `instanceof`);
  stackiq registers nothing in `lib/AppInfo/Application.php`.
- `scopeClaim`, `via`, `minTrust` and `fields` are contract-v2.1 fields;
  portaliq's reader currently scopes on `scopeField` alone, so `via` collections
  fail closed until portaliq lands one-hop joins.
- Related ADRs: hydra ADR-046 (+ amendments A1–A7), ADR-022, ADR-005.
