---
kind: code
---

# Proposal: portal-contribution

Tracking issue: Conduction/softwarecatalog#72

## Summary

Ship Software Catalog's ADR-046 portal contribution: one plain, dependency-free
class (`lib/Portal/PortalContributionProvider.php`) that declares what an
external portal subject may READ in the catalog, scoped to the subject's own
`organisatie` UUID. It serves two audiences — `vendor-org` (a software supplier,
organisatie.type "Leverancier") and `participant-org` (a municipality/
collaboration, "Gemeente"/"Samenwerking"/"Community") — because the same
`gebruik` object is scoped by a different property for each side (`aanbieder`
vs `afnemer`). Reads that reach the organisatie UUID indirectly declare a single
`via` one-hop join (contract → dienst/gebruik, compliancy → module). This is a
READ-only wave: create-actions and the A6 accept/deny endpoint actions are
deferred and documented.

## Motivation

ADR-046 makes portaliq the single external portal for people without Nextcloud
accounts; its contribution contract v2.1 defines multi-audience providers, trust
levels, claim-based UUID scoping, one-hop `via` joins and read-side field
projection. Software Catalog today provisions REAL managed Nextcloud accounts
for external contactpersonen (`ContactpersonenController::convertToUser`,
"contactpersoon-sync") and exposes a sprawl of anonymous `@PublicPage` API
endpoints (aanbod, aangeboden-gebruik, gebruik) to serve those externals.
Adopting portaliq retires BOTH: externals authenticate at portaliq's edge and
read their own organisatie-scoped data through one governed contract, so the
catalog can stop minting NC accounts and stop widening its public API surface.

## Affected Projects

- [x] Project: `softwarecatalog` — new `lib/Portal/PortalContributionProvider.php`; new `tests/Unit/Portal/PortalContributionProviderTest.php`; new OpenSpec capability `portal-contribution`. No register-schema, route, controller, service, frontend or info.xml change.

## Scope

### In Scope

- A plain `OCA\SoftwareCatalog\Portal\PortalContributionProvider` class (no
  portaliq import, no `implements`, no info.xml dependency) exposing
  `getAudiences()`, `getAudience()`, and `getContribution(array $subject): ?array`.
- Declarative READ manifests for `vendor-org` and `participant-org`, scoped by
  `organisatie` UUID via claim `organisationId`, with `via` one-hop joins where
  the org link is indirect and `fields` whitelists that drop staff-only and
  counterparty-organisation columns.
- PHPUnit unit tests, including a register-drift pin asserting every scopeField
  and projected field exists on its schema.

### Out of Scope

- Any portal UI, auth edge, inbox, or rendering — portaliq owns the entire
  external surface (ADR-046); Software Catalog ships zero portal frontend.
- **Create-actions** (dienst self-registration, moduleVersie updates) — deferred:
  a dienst create would duplicate the catalog's existing public self-registration
  intake and bypass the aanbod accept/deny governance; a moduleVersie create has
  no direct organisatie scopeField the stamp-only write contract can express.
  Rationale in design.md.
- **A6 endpoint actions** (aanbod accept/deny) — the accept/deny surface already
  exists in `AanbodController`; wiring it as portaliq endpoint actions is a
  documented follow-up (contribution contract A6).
- `kwetsbaarheid` — excluded: its organisatie link is `kwetsbaarheid.modules[]`
  (an array of module refs) → `module.aanbieder`, a two-level array-membership
  path, not a clean single-ref one-hop `via` (and CVE data is public).
- Retiring the NC-account provisioning and public API endpoints — a later
  migration once portaliq is adopted; this change only adds the read contribution.

## Approach

Duck-typed discovery per ADR-046 A1: portaliq's registry resolves
`OCA\{App}\Portal\PortalContributionProvider` by FQCN and probes it with
`method_exists` — so Software Catalog ships a plain class with the three
contract methods and nothing else. The contribution is a declarative manifest
(data, not behaviour). Scoping follows A4: collections carry the subject's
`organisatie` UUID via `scopeClaim: organisationId`, never a Nextcloud user id.
`scopeClaim`, `via`, `minTrust` and `fields` are contract-v2.1 fields that
portaliq's reader is still catching up to (it currently scopes on `scopeField`
alone), so `via` collections fail CLOSED until portaliq lands one-hop joins —
the same forward-contract pattern pipelinq already ships. Details in design.md.

## New Dependencies

None. The provider is dependency-free by contract and inert when portaliq is
not installed.

## Impact

- `lib/Portal/PortalContributionProvider.php` — new, self-contained.
- `tests/Unit/Portal/PortalContributionProviderTest.php` — new.
- No routes, controllers, services, frontend, register JSON, or info.xml changes.

## Cross-Project Dependencies

None at build or install time (the point of ADR-046 A1). At runtime, portaliq —
when installed — discovers and renders the contribution. Contract v2.1 lands in
portaliq in parallel, which is why the provider implements both the v1
(`getAudience`) and v2 (`getAudiences`) audience methods.

## Risks

### Risk 1: `via` / `scopeClaim` consumed by portaliq later than declared

**Severity:** Medium — **Mitigation:** the manifest declares the full v2.1
contract, but portaliq's reader currently scopes on `scopeField` == subjectRef
only. Direct-scoped collections (`dienst`, `gebruik`) work today; `via`
collections (`contract`, `compliancy`) fail CLOSED (return empty) until portaliq
lands one-hop joins — never leaking. Unit tests pin the exact manifest shape so
any contract change is a visible, reviewed edit.

### Risk 2: Vendor reads leak a counterparty organisation's data

**Severity:** Medium — **Mitigation:** every collection ships an explicit
`fields` whitelist that drops staff-only columns (`gebruik.interneAantekening`,
`contract.opmerkingen`) and the counterparty's contactpersoon
(`contactpersoonGebruiker` on the vendor side, `contactpersoonAanbieder` on the
participant side). A register-drift test pins each whitelist to real schema
properties.

## Rollback Strategy

Delete `lib/Portal/` and `tests/Unit/Portal/`. Nothing else references the
class (no DI registration, no route), so without it portaliq discovery finds
nothing and the catalog shows no portal section — the app itself is unaffected.

## Open Questions

- Whether a `vendor-org` dienst self-registration create should be re-introduced
  through portaliq once the catalog's existing intake is retired, or kept out to
  preserve the aanbod accept/deny governance. Deferred to the create/A6 follow-up.
