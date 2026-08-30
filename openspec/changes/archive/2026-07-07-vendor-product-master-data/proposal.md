---
kind: code
depends_on: []
---

# softwarecatalog — vendor & product master data via OpenRegister MDM + semantic types

## Why

The catalog's core entities are master data by nature:

- **`organisatie`** (title "Organisatie") is the software **vendor / supplier**
  master — every `module.aanbieder`, `dienst.aanbieder`, and `koppeling.aanbieder`
  ("Leverancier") points at it.
- **`module`** (title "Applicatie") and **`suite`** are the software **product**
  master.

Two abstraction gaps exist against fleet ADRs:

1. **No canonical semantic type (ADR-048).** `organisatie`, `module`, and
   `suite` carry an **empty** `x-openregister` block — no
   `configuration.jsonld.type` / `implements`. The sibling ArchiMate
   `organization` schema already declares `jsonld`, but the *domain vendor
   master* does not. Meanwhile pipelinq, shillinq, and procest already reference
   the canonical vendor role `https://openregister.app/ns#Vendor`. softwarecatalog
   — the app that most literally *is* a government software-vendor registry —
   neither advertises that role nor lets its own `aanbieder` references resolve
   by kind. Its vendor/product master is invisible to the rest of the fleet.

2. **No MDM dedup/quality (ADR-045).** OpenRegister owns the generic MDM
   surface: any schema that declares `x-openregister-dedup` / `x-openregister-quality`
   gets duplicate detection, quality scoring, golden-record survivorship, and a
   reversible merge wizard **for free, from schema metadata — no app code**.
   softwarecatalog declares neither. This bites hardest under **federation**:
   `FederationMerger` correctly matches mirrors only by *peer provenance* (peer
   id per peer), so the *same real vendor* — say "Centric" — published by two
   peer catalogs lands as **two separate `organisatie` mirrors that are never
   reconciled**. There is no golden vendor, no product dedup, and (per ADR-045)
   building an app-local reconciler would be the exact drift ADR-022 exists to
   prevent.

## What Changes

Schema-metadata only (`lib/Settings/softwarecatalogus_register.json`) — no
controllers, no services, no app-local dedup/merge engine.

### Semantic types (ADR-048)

- `organisatie` → `configuration.jsonld.type: https://schema.org/Organization`
  and `configuration.implements: ["https://schema.org/Organization",
  "https://openregister.app/ns#Vendor"]` — it advertises that it **provides**
  the canonical software-vendor role.
- `module` and `suite` → `configuration.jsonld.type:
  https://schema.org/SoftwareApplication`.
- `module.aanbieder`, `dienst.aanbieder`, `koppeling.aanbieder` → add
  `referenceSemanticType: https://openregister.app/ns#Vendor` alongside the
  existing local `$ref` to `organisatie`. Standalone behaviour is unchanged
  (softwarecatalog remains its own vendor provider); the reference now *names
  the role it targets*, so cross-app tooling and the integration registry can
  resolve it.

### MDM markers (ADR-045)

- `organisatie` → `x-openregister-dedup` (match candidates on identical
  `contactsUid`, else high similarity of the resolved contact name + website)
  and `x-openregister-quality` (completeness of contact link, type, status).
- `module` → `x-openregister-dedup` (similarity of `naam` + `aanbieder` +
  `website`) and `x-openregister-quality` (completeness of `naam`, `aanbieder`,
  `licentie`, description).
- OpenRegister then surfaces vendor/product **duplicate candidates**, quality
  scores, and golden-record merge through its own MDM surface. `FederationMerger`
  stays provenance-only — entity reconciliation is OR's, not the app's.

## Impact

- **Cross-app**: softwarecatalog vendors/products become referenceable by
  canonical kind (ADR-048), aligning with the fleet `ns#Vendor` convention.
- **Federation quality**: duplicate vendors imported from multiple peers become
  reconcilable golden records via OR MDM — without any app-local dedup code.
- **Risk**: additive schema metadata. Existing objects and references keep
  working; markers only *enable* OR capabilities. Re-validate the register JSON
  after edit (single canonical file — no dup-key merge).

## Dependencies

OpenRegister MDM foundation (`x-openregister-quality` / `x-openregister-dedup`,
similarity calculator, golden-record + merge surface — ADR-045) and the
cross-app semantic-reference resolver (`configuration.jsonld.type` /
`implements` / `referenceSemanticType` — ADR-048). ADR-022 (consume OR
abstractions). No app code depends on these being present; absence degrades
gracefully to today's behaviour.
