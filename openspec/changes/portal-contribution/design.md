# Design: portal-contribution

## Architecture Overview

Portaliq (hydra ADR-046) is the one shared external portal for people without
Nextcloud accounts. Domain apps contribute by shipping a single plain class at a
convention FQCN; portaliq's `PortalContributionRegistry` resolves
`OCA\{App}\Portal\PortalContributionProvider` per installed app and duck-types it
(`method_exists`, never `instanceof`). Software Catalog therefore adds exactly one
new file under `lib/Portal/` and touches nothing else in the runtime app:

```
portaliq (if installed)
  └─ registry resolves OCA\Stackiq\Portal\PortalContributionProvider (FQCN)
       └─ getAudiences() → ['vendor-org','participant-org']   (v2, preferred)
       └─ getAudience()  → 'vendor-org'                       (v1 fallback)
       └─ getContribution($subject) → manifest (pure data) or null
            └─ collections read via OpenRegister, scoped by the subject's
               organisatie UUID (claim organisationId) == dienst.aanbieder /
               gebruik.aanbieder|afnemer, or one hop via contract.dienst /
               contract.gebruik / compliancy.module
```

Without portaliq the class is never instantiated: inert dead-weight of ~5 KB, by
design (A1). There is deliberately **no** DI registration in
`lib/AppInfo/Application.php` — portal discovery is pull-based from portaliq's
side.

## Declarative-vs-imperative decision

The contribution is **declarative by nature**: `getContribution()` returns a
pure-data manifest (label, collections, actions, notifications) that portaliq
interprets — the same philosophy as the ADR-024 app manifest and ADR-031
declarative business logic. No behaviour, no I/O, no callbacks live in the
provider. A provider *class* (not a JSON file) is used only because it is the
delivery vehicle ADR-046 mandates: a class is autoloadable cross-app without
file-path coupling, discoverable by FQCN, and can branch on the server-derived
`$subject` (audience filtering) without portaliq parsing app-private config. The
only imperative surface is the two-branch audience switch; everything portaliq
renders or enforces (scoping, trust, RBAC, field projection) is data in the
manifest, evaluated portaliq-side.

## Claim-names contract

All subject identity is server-derived by portaliq's auth edge and MUST NOT be
trusted from the client (ADR-005). Software Catalog's scoping contract is:

| Claim              | Value                                   | Used for |
|--------------------|-----------------------------------------|----------|
| `organisationId`   | the subject's `organisatie` object UUID | every collection's `scopeClaim`; matched against `dienst.aanbieder` / `gebruik.aanbieder` / `gebruik.afnemer`, or the via-target's `aanbieder` / `afnemer` |

There is exactly one claim because every portal-visible surface is scoped by the
subject's organisation. The subject's `organisation` (portaliq's tenant field)
carries the same UUID; `organisationId` is the STABLE claim name apps and
portaliq agree on. Scoping never uses a Nextcloud uid — externals have no NC
account by premise (A4).

### Runtime note: contract v2.1 support in portaliq

Portaliq's `ContributionController` forwards `scopeField`, `scopeClaim`, `via`
and `fields` from each declared collection into `PortalObjectReader`, which
consumes them all: `scopeClaim` resolves the subject's server-managed claim,
`via` performs the one-hop join, `minTrust` filters below-threshold entries, and
`fields` projects rows after per-row verification (contract v2 + field-projection,
both merged). So every collection below works end-to-end once portaliq maps
`organisationId` for these audiences:

- **Direct-scoped collections** (`dienst`, `gebruik`) scope on `scopeField`
  (`aanbieder`/`afnemer`) resolved from the `organisationId` claim.
- **`via` collections** (`contract`, `compliancy`) resolve the one-hop join first
  (e.g. `contract` → `dienst` → `aanbieder`), then read only the joined targets;
  a malformed `via` or an unresolvable claim fails **CLOSED** (empty), never to a
  wider read.

## Scoping map

`via` reads the collection's own relation property first, then scopes on the
target object's field. `via: X` + `scopeField: Y` means: follow property `X` to
its referenced object, keep rows whose `Y` equals the subject's organisatie UUID.

### Audience `vendor-org` (organisatie.type "Leverancier")

| Collection        | Schema      | scopeField | via     | scopeClaim       | minTrust    |
|-------------------|-------------|------------|---------|------------------|-------------|
| vendorDiensten    | dienst      | aanbieder  | —       | organisationId   | —           |
| vendorGebruik     | gebruik     | aanbieder  | —       | organisationId   | —           |
| vendorContracts   | contract    | aanbieder  | dienst  | organisationId   | substantial |
| vendorCompliancy  | compliancy  | aanbieder  | module  | organisationId   | —           |

### Audience `participant-org` (organisatie.type "Gemeente"/"Samenwerking"/"Community")

| Collection           | Schema   | scopeField | via     | scopeClaim     | minTrust    |
|----------------------|----------|------------|---------|----------------|-------------|
| participantGebruik   | gebruik  | afnemer    | —       | organisationId | —           |
| participantContracts | contract | afnemer    | gebruik | organisationId | substantial |

`contract` is gated at eIDAS-**substantial** trust on both sides because it
carries commercial terms (`kosten`, `kostenPeriode`).

## Field-whitelist (read projection) tables

Portaliq whitelist-projects rows after per-row verification (identifiers always
survive; a malformed `fields` declaration degrades to identifiers-only). Only the
listed properties are exposed; everything else — notably staff-only and
counterparty-organisation columns — is dropped.

### dienst (vendorDiensten)

| Projected | Dropped (why) |
|-----------|---------------|
| naam, beschrijvingKort, beschrijvingLang, website, type, modules | logo (base64 bloat), contactpersoon (person PII in a list), aanbieder (identifier survives anyway), koppelingen (internal integration refs) |

### gebruik (vendorGebruik — scoped by aanbieder)

| Projected | Dropped (why) |
|-----------|---------------|
| status, startDatumVerwerving, startDatumGepland, startDatumInProductie, startDatumUitTeFaseren, startDatumUitGefaseerd, afnemer, module, moduleVersie | **interneAantekening (staff-only internal note)**, contactpersoon (counterparty PII), deelnemers, gebruiktVoorReferentiecomponenten, amefElements, koppelingen, diensten, cloudDienstverleningsmodel (internal/technical) |

`afnemer` (the customer organisatie UUID) is kept: it is the vendor's own
commercial counterparty (there is a contract), and it is a UUID identifier, not
detailed PII.

### gebruik (participantGebruik — scoped by afnemer)

| Projected | Dropped (why) |
|-----------|---------------|
| status, startDatumVerwerving, startDatumGepland, startDatumInProductie, startDatumUitTeFaseren, startDatumUitGefaseerd, aanbieder, module, moduleVersie, diensten | **interneAantekening**, contactpersoon, deelnemers, gebruiktVoorReferentiecomponenten, amefElements, koppelingen, cloudDienstverleningsmodel |

### contract (vendorContracts — via dienst)

| Projected | Dropped (why) |
|-----------|---------------|
| dienst, gebruik, startDatum, eindDatum, contractNummer, contractType, kosten, kostenPeriode, **contactpersoonAanbieder (own contact)**, documentReferentie, status | **contactpersoonGebruiker (counterparty PII)**, opmerkingen (internal remarks) |

### contract (participantContracts — via gebruik)

| Projected | Dropped (why) |
|-----------|---------------|
| dienst, gebruik, startDatum, eindDatum, contractNummer, contractType, kosten, kostenPeriode, **contactpersoonGebruiker (own contact)**, documentReferentie, status | **contactpersoonAanbieder (counterparty PII)**, opmerkingen |

### compliancy (vendorCompliancy — via module)

| Projected | Dropped (why) |
|-----------|---------------|
| standaardversie, standaardGemma, module, url | bewijs (base64 evidence file — kept out of a list projection) |

## Exclusions

- **kwetsbaarheid** — organisatie link is `kwetsbaarheid.modules[]` (an ARRAY of
  module refs) → `module.aanbieder`: a two-level, array-membership path, not a
  clean single-ref one-hop `via`. Also CVE data is public. Excluded from this
  wave; revisit once portaliq supports array-membership `via` joins or the schema
  materialises a direct vendor ref.
- **Create-actions** — deferred:
  - `dienst` create would duplicate the catalog's existing public
    self-registration intake and bypass the aanbod accept/deny governance; the
    correct portal write path is the A6 accept/deny endpoint actions, themselves
    deferred.
  - `moduleVersie` create has no direct `organisatie` scopeField (its link is
    `moduleVersie.module` → `module.aanbieder`, indirect), so the stamp-only
    write contract (`data[scopeField] = subjectRef`) cannot express ownership;
    it needs a client-supplied parent-module ref plus server-side verification
    that the module belongs to the subject's organisation. Out of scope.
- **A6 endpoint actions** (aanbod accept/deny) — already implemented in
  `AanbodController` (`acceptAanbod`, `denyAanbod`); wiring them as portaliq
  endpoint actions with receiver-side assertion verification is a follow-up.

## API Design

None. No routes, controllers, or endpoints are added. Reads go through
OpenRegister's existing object API, invoked by portaliq server-side with subject
scoping (ADR-022 — no app-local CRUD wrappers).

## Database Changes

None. Software Catalog is a thin OR client; the register JSON is unchanged (the
scopeFields — `aanbieder`, `afnemer`, and the via relations `dienst`, `gebruik`,
`module` — already exist on the schemas, so no property is added).

## Migration arc: retiring managed NC accounts + public API sprawl

TODAY, to serve external stakeholders without a portal, Software Catalog:

1. **Provisions REAL managed Nextcloud accounts** for external contactpersonen —
   `ContactpersonenController::convertToUser`, `changePassword`,
   `updateUserGroups`, `disableUser`/`enableUser` ("contactpersoon-sync"); each
   external supplier/municipality contact becomes an NC user in a group.
2. **Exposes an anonymous `@PublicPage` API surface** for those externals —
   `AanbodController` (getAanbod / acceptAanbod / denyAanbod),
   `AangebodenGebruikController` (afnemer/deelnemer lists, set-self),
   `GebruikController` — scoping by the "active organisation" rather than an
   authenticated subject.

Portaliq adoption **retires both**: externals authenticate once at portaliq's
edge (no NC account minted) and read their own organisatie-scoped data through
this single governed contribution. The managed-account provisioning and the
public endpoints become removable in a later migration; this change only adds
the read contribution so the two surfaces can be retired incrementally without a
flag day. The A6 accept/deny endpoint actions will replace the AanbodController
accept/deny endpoints in that same follow-up.

## Nextcloud Integration

- Controllers / Services / Mappers / Events: none. No `Application.php`
  registration by design (see Architecture Overview).

## Security Considerations

- **Server-derived subject only** (ADR-005 / ADR-046 A6): `$subject` (subjectRef,
  audience, organisation, trust) is constructed by portaliq's auth edge. The
  provider only reads `audience` to branch; it never echoes subject data into the
  manifest and never trusts anything client-supplied.
- **Fail-closed audience filter**: any audience other than `vendor-org` /
  `participant-org` yields `null`.
- **UUID organisatie scoping** (A4): reads are scoped by the subject's
  `organisatie` UUID, never an NC uid.
- **No cross-organisation leakage**: field whitelists drop staff-only columns and
  the counterparty organisation's contactpersoon; a malformed `via` or
  unresolvable claim fails closed (empty), never to a wider read.
- **Trust gate**: `contract` requires eIDAS-substantial trust (commercial terms).
- No secrets, no tokens, no endpoints in this change.

## File Structure

```
lib/
  Portal/
    PortalContributionProvider.php       (new — plain class, no deps)
tests/
  Unit/
    Portal/
      PortalContributionProviderTest.php (new — incl. register-drift pin)
openspec/
  changes/portal-contribution/            (this change)
  specs/portal-contribution/spec.md       (capability status stub)
```

## Seed Data

No new objects are seeded by this change. Portal scoping needs `organisatie`
objects and `dienst`/`gebruik`/`contract`/`compliancy` rows whose scope fields
point at a subject organisatie UUID; those live in the existing register seed
(`components.objects`) and the tutorial/demo environment. Where a demo needs a
placeholder organisatie ref before real objects exist, use the **nil-UUID
placeholder `00000000-0000-0000-0000-000000000000`**; the import replaces it with
the UUID of a real seeded `organisatie` (type "Leverancier" for a vendor demo,
"Gemeente" for a participant demo). No object is required to carry a portal field
that it does not already have — the scopeFields are pre-existing schema
properties — so seeds remain valid unchanged.

### Illustrative demo rows (placeholders)

| Schema   | Field                | Vendor demo value                         | Participant demo value                    |
|----------|----------------------|-------------------------------------------|-------------------------------------------|
| dienst   | aanbieder            | `00000000-0000-0000-0000-000000000000`    | — (participants own no dienst)            |
| gebruik  | aanbieder / afnemer  | aanbieder = nil-UUID                       | afnemer = nil-UUID                        |
| contract | (via dienst/gebruik) | dienst.aanbieder = nil-UUID               | gebruik.afnemer = nil-UUID                |

## Trade-offs

- **Two audiences vs one** — the same `gebruik` object is scoped by `aanbieder`
  for suppliers and `afnemer` for consumers, so a single audience cannot express
  both without leaking the other side; two audiences keep each read fail-closed.
- **Declaring `via` now vs waiting for portaliq** — declaring the full v2.1
  contract lets `contract`/`compliancy` light up the moment portaliq lands `via`,
  and they fail closed meanwhile; the alternative (omitting them) would need a
  second change later. Consistent with pipelinq's forward-contract `scopeClaim`.
- **READ-only vs shipping a dienst create** — a create would either duplicate the
  existing self-registration intake or bypass the aanbod accept/deny governance;
  deferring keeps the wave low-risk and honest.
- **Plain class vs shared interface package** — an interface import would give
  static safety but create exactly the coupling A1 forbids; duck typing is the
  accepted cost of optionality.
