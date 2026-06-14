---
kind: feature
depends_on: []
---

# softwarecatalog — module standards-compliance assessment

## Why

"Does this application support standard X?" is **the** procurement question a
municipal buyer asks a software catalog — GEMMA reference components,
API-standaarden, Common Ground compliance are PvE checklist lines. The
2026-06-11 feature re-evaluation lists this as EXPECTED-GAP 3
(`module-compliance-assessment`).

The building blocks exist but are invisible to the spec system and barely
surfaced in the UI:

- a `compliancy` schema (`standaardversie` → GEMMA element with
  `gemmaType=standaardversie`, `standaardGemma`, `module`, `bewijs`
  evidence file, `url`);
- `module` carries `compliancy`, `standaarden`, `standaardenGemma`,
  `standaardVersies`, and `referentieComponenten`;
- a working event pipeline — `ModuleComplianceSubscriber` +
  `ModuleComplianceService` (documented in
  `docs/MODULE_COMPLIANCE_SUBSCRIBER.md`) — keeps `module.standaarden` in
  sync with linked compliancy objects on every module create/update;
- standards themselves are GEMMA `element` objects imported via the existing
  `archimate-import` capability.

None of this has a spec (gate-16/19 blind spot on security-relevant evidence
handling), there is no matrix view answering "which modules support which
standards", no compliance filter in the catalog, and no per-organisation
answer to "do MY applications support standard X".

## What Changes

- **Retrofit-spec the existing pipeline first** (re-evaluation's own advice):
  compliancy records link module ↔ standaardversie with evidence; the
  subscriber derives `module.standaarden` from linked compliancy objects —
  specced as-is, then hardened (idempotent, no save loop).
- **Compliance matrix view**: modules × standards (GEMMA standaardversies),
  cell states *verified* (compliancy record with evidence), *claimed*
  (declared without evidence), *none*; evidence opens from the cell.
- **Compliance filter in the catalog**: filter module listings/search by
  supported standard — the PvE shortlist in one click.
- **Per-organisation compliance summary**: for an organisation's in-use
  applications (gebruiken), show coverage of a selected standard — "which of
  my applications support Zaakgericht werken via the ZGW API standard".
- **Evidence stays auditable**: every verified cell traces to a compliancy
  record with its `bewijs`/`url`; new evidence is linked via NC Files
  (link, don't store) while the existing base64 `bewijs` field remains
  readable for legacy records.

## Capabilities

### New Capabilities

- `module-compliance-assessment`: specced compliance pipeline (compliancy
  records + standards sync subscriber), a modules×standards compliance
  matrix with evidence states, standard-based catalog filtering, and
  per-organisation compliance coverage.

## Impact

- **Retrofit (existing code, new spec):**
  `lib/EventListener/ModuleComplianceSubscriber.php`,
  `lib/Service/ModuleComplianceService.php` — behavior specced, `@spec` tags
  added, idempotency hardened.
- **Changed:** `src/manifest.json` — compliance matrix page, standard filter
  on module listings, compliance tab on module detail, organisation coverage
  view.
- **Changed:** `lib/Settings/softwarecatalogus_register.json` — `compliancy`
  gains an optional NC Files reference for evidence (`bewijsReferentie`);
  existing base64 `bewijs` stays for backward compatibility.
- **Depends on (runtime, existing):** GEMMA standards present as `element`
  objects (`archimate-import`); the matrix degrades to "no standards
  imported" guidance when absent.
- **Relation to `federated-catalog-sync` / `open-data-publishing`:**
  compliance data on published modules federates/publishes like any other
  catalog data; nothing extra here.

## Caveats

- **Compliance is self-declared by suppliers** unless evidence is attached —
  the matrix MUST visually distinguish *verified* from *claimed* so a PvE
  reader is never misled. The catalog records claims and evidence; it is not
  a certification authority.
- The `standaardGemma` string field duplicates what the `standaardversie`
  relation expresses; the matrix keys on the relation, with the string as a
  fallback for records imported without resolved relations.
- Matrix size: a full modules×standards matrix is large; the view is
  filter-first (pick standards and/or module subset) rather than rendering
  the full cartesian product by default.
