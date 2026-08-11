## Context

Token-aware scan: **17 schemas / 86 Dutch properties**, **15 files / 5 classes /
49 methods**. Every schema lives in one register,
`lib/Settings/softwarecatalogus_register.json`, which describes itself as *"AMEF and
Voorzieningen schemas for the VNG Software Catalog application"*.

The property count is mid-table. The **risk** is not: softwarecatalog holds
**client-imported production data from VNG-Realisatie's Softwarecatalogus** — roughly
3,400 `koppelingen` and 6,100 `modules`. This is the only app in the programme where a
property rename touches thousands of live records rather than a handful of seeds.

## Goals / Non-Goals

**Goals:**

- Rename the schema properties softwarecatalog owns, with the VNG field names mapped at
  the import boundary rather than carried through into storage.
- Preserve every published external identifier — BIO measure codes, ArchiMate/AMEF
  element attributes, GEMMA and GGM namespaced keys, NORA values.
- Treat the ~9,500 stored objects as the primary constraint, not an afterthought.

**Non-Goals:**

- Any change to `Softwarecatalogus/`, the VNG-Realisatie client repo. It is never
  committed to.
- Renaming the published BIO 2.0 measure list, or the `ggm-`/`gemma-` namespaced
  attributes on `element`.
- Landing the rename without a migration. There is no greenfield reseed option here.

## Decisions

### 1. Three tiers, because the register mixes all three

| tier | examples | action |
|---|---|---|
| **published external identifiers** | `bioMaatregel` codes (BIO 2.0 published measures list), `ggm-bron`, `ggm-naam`, `ggm-toelichting`, `ggm-datum-tijd-export`, `gemma-toelichting`, `noraKernwaarde`, `noraKwaliteitsdoel` | preserve, mark |
| **VNG import field names** | the names the importer reads from the VNG payload | preserve **at the importer**, map to English on the way in |
| **our storage schema** | `naam`, `beschrijvingKort`, `beschrijvingLang`, `contactpersoon`, `organisatie`, `kosten`, `startDatum`, … | rename |

The `ggm-` and `gemma-` prefixes are the giveaway for tier 1: a namespaced key is by
construction someone else's vocabulary. `bioMaatregel`'s own description says it comes
"uit de gepubliceerde maatregelenlijst" and is used "als selectiebron" — a selection
source keyed on published codes.

### 2. The description cluster splits three ways here, and softwarecatalog is why

Seven of its schemas (`suite`, `dienst`, `kwetsbaarheid`, `koppeling`, `beoordeeling`,
`module`, `moduleVersie`) carry **both** `beschrijvingKort` and `beschrijvingLang`. That
is seven within-schema collisions, and it is the evidence that settled the fleet-level
question: `beschrijving` cannot be a single English word.

**Decision:** `beschrijvingKort` → `shortDescription`, `beschrijvingLang` →
`longDescription`, plain `beschrijving` → `description`, `omschrijving` → `description`,
`toelichting` → `notes`.

⚠️ `element` carries `toelichting` **and** `gemma-toelichting` **and** `ggm-toelichting`.
Only the unprefixed one is ours. The two namespaced ones stay exactly as they are, which
means this schema will legitimately hold `notes`, `gemma-toelichting` and
`ggm-toelichting` side by side. That looks wrong and is correct.

### 3. Validity dates take the fleet words

`startDatum`/`eindDatum` on `contract`, and the `gebruik` family
(`startDatumVerwerving`, `startDatumGepland`, `startDatumInProductie`,
`startDatumUitTeFaseren`, `startDatumUitGefaseerd`) — the last five are **lifecycle
milestones**, not a validity window, and take event names
(`acquiredOn`, `plannedOn`, `inProductionOn`, `phaseOutStartedOn`, `phasedOutOn`) rather
than being forced into `validFrom`.

`contract.startDatum`/`eindDatum` → `validFrom`/`validUntil` per the fleet list.

`koppeling` and `moduleVersie` share four date properties (`datumInOntwikkeling`,
`datumInGebruik`, `datumEindeOndersteuning`, `datumTeruggetrokken`) — also lifecycle
events: `inDevelopmentOn`, `inUseOn`, `supportEndsOn`, `withdrawnOn`.

### 4. `contactpersoon` and `organisatie` are schema names *and* property names

`contactpersoon` and `organisatie` are both **schemas** and **properties on other
schemas** (`suite.contactpersoon`, `dienst.contactpersoon`, `module.contactpersoon`,
`gebruik.contactpersoon`, `contract.contactpersoonAanbieder`,
`contract.contactpersoonGebruiker`, `organisatie` on `contactpersoon`).

**Decision:** `contactpersoon` → `contactPerson`, `organisatie` → `organisation`, and the
contract pair → `providerContact` / `consumerContact`. The schema and the properties must
move in one commit — renaming the schema while properties still `$ref` the old slug
resolves to nothing, and ⚠️ `$ref` slug resolution is **instance-global**, so a dangling
slug can silently bind to another app's schema of the same name rather than failing.

### 5. The migration is the change

~9,500 stored objects carry the old keys. Unlike the greenfield apps, softwarecatalog
cannot reseed. The migration must:

- run per schema against the `oc_openregister_table_<reg>_<schema>` shard tables, not the
  shared objects table, which is empty and would read as total success;
- count only rows where the soft-delete marker is null, since `deleteObject()` is a soft
  delete;
- be reversible until the importer has been updated and re-run at least once.

**Decision:** the migration is written and tested against a copy of production data
**before** the schema rename is merged, not after. This is the reverse of the order used
in the smaller apps, and it is deliberate.

## Risks / Trade-offs

- **~9,500 live objects orphaned by a rename** → the highest-consequence risk in the
  programme. Mitigated by writing the migration first and testing on copied data.
- **The VNG importer keeps writing Dutch keys after the schema moves to English** → every
  subsequent import silently reintroduces the old vocabulary alongside the new, and
  nothing errors. Mitigated by landing the importer mapping in the same commit as the
  schema, and by re-running an import as an acceptance check rather than trusting tests.
- **A published BIO or GGM identifier is internationalised** → compliance mapping breaks
  against the published list. Mitigated by tier 1 and its marker.
- **A renamed schema leaves a dangling `$ref` slug** → resolution is instance-global, so
  it can bind to a *different app's* schema with the same slug instead of failing loudly.
  Mitigated by renaming schema and referring properties atomically, then asserting each
  `$ref` resolves to a softwarecatalog schema specifically.
- **`debug_contactpersoon_data.php` / `debug_contactpersoon_database.php` are committed
  debug scripts at repo root** → they will be caught by the rename sweep. They should be
  deleted rather than renamed; a debug script is not vocabulary worth migrating.

## Migration Plan

1. Classify all 86 properties into the three tiers; record the classification.
2. Write and test the data migration against copied production data.
3. Rename schemas + properties + importer mapping in one commit.
4. Run the migration; re-run a VNG import and diff the result.
5. Rename the 5 classes and 49 methods; delete the two debug scripts.
6. `l10n/nl.json`, `check-l10n`, gates.

**Rollback:** reversible until step 4's import has run. After that, rollback requires the
inverse migration plus reverting the importer, so step 4 is the point of no easy return.

## Open Questions

- Which exact field names does the VNG importer read? The classification in decision 1
  names the tiers but the tier-2 membership list has not been enumerated — that is task 1
  and it gates everything else.
- Is the ~3,400 / ~6,100 figure still current? It is from a 158-day-old note and has not
  been re-measured. The migration's cost scales directly with it.
