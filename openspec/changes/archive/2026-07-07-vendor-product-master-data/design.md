# Design: vendor-product-master-data

## Decision 1 — softwarecatalog is a *provider* of ns#Vendor, not a consumer

Unlike pipelinq (whose `product.vendor` *consumes* shillinq's Payee), the whole
point of a GEMMA software catalog is to **own** the software-supplier master.
So `organisatie` declares `implements: [schema:Organization, ns#Vendor]` — it
advertises the role. Other fleet apps that need a *software* vendor can resolve
against softwarecatalog when it is installed; softwarecatalog itself keeps its
local `$ref` as the provider and runs standalone. We do **not** repoint
`aanbieder` at an external app; we only add `referenceSemanticType` so the
reference is legible by kind (ADR-048 §2). schema.org-first (ADR-011):
`Organization` is the primary `@type`; `ns#Vendor` is the added capability URI
because "software supplier" is narrower than "any organisation".

## Decision 2 — MDM belongs to OpenRegister; the app only declares markers

Per ADR-045, OR owns the MDM surface (quality dashboard, duplicate-candidate
list + merge wizard, golden-record survivorship, reversible merge). A schema
*opts in* by declaring `x-openregister-dedup` / `x-openregister-quality`. This
change adds those declarations and **nothing else**. We do NOT:

- add an app-local dedup service, merge controller, or survivorship engine
  (that is the pipelinq-MDM-rebuild anti-pattern ADR-045 calls out);
- change `FederationMerger`, whose provenance matching (one mirror per peer
  entry) is *correct* — cross-peer entity reconciliation is a different concern
  (golden records), owned by OR MDM, applied on top of the mirrors.

If the OR MDM surface is absent at runtime, the markers are inert metadata and
behaviour is exactly today's — graceful degradation, no hard dependency.

## Decision 3 — Dedup keys reflect the real identity model

- **`organisatie`** has no `naam` of its own; its display identity lives on the
  linked NC contact via `contactsUid` (softwarecatalog-contacts-to-nc). So the
  dedup rule is: **exact `contactsUid` match ⇒ same vendor** (highest
  confidence), falling back to similarity of the *resolved* contact name +
  website for records that predate or lack a contact link. Quality scores the
  presence of `contactsUid`, `type`, and `status`.
- **`module`** dedup keys on `naam` + `aanbieder` (the vendor) + `website`
  similarity — "same product name from the same supplier" is a duplicate.
  Quality scores completeness of `naam`, `aanbieder`, `licentie`, and a
  description. Exact thresholds are tuned during apply against real federated
  data; the spec fixes the *keys*, not the numeric cutoffs.
- **`suite`** gets the semantic type now; dedup is deferred (low duplicate
  incidence, and a suite is defined by its member `applicaties`) unless apply
  finds real suite duplicates.

## Decision 4 — Single-file, re-validate

All edits land in the one canonical `softwarecatalogus_register.json`. There is
no `register.d` fragment merge for these schemas, so no union/dup-key risk; the
only discipline is re-validating the JSON after the edit and confirming the OR
register import still succeeds (via the Repair step — ADR-037).
