# English vocabulary for softwarecatalog

> Implements `hydra/openspec/changes/fleet-english-vocabulary`.

## Why

Scan found **1 Dutch-named schema and 34 Dutch property names** — the highest
property-to-schema ratio in the fleet, because the debt sits inside a few large
software-registration schemas rather than in schema names.

⚠️ **`Softwarecatalogus/` is the VNG client repo and must never be committed
to.** This change targets the `softwarecatalog` app only. Confirm which
properties are dictated by the VNG exchange format before renaming any of them —
those are an external wire contract (§1), not ours.

## What changes

### Internationalised (§2)

| Dutch | English |
|---|---|
| `bioMaatregel` | `BioSecurityMeasure` (+ statute marker, §4 — BIO is NL government infosec baseline) |
| `beschrijving` / `beschrijvingKort` / `beschrijvingLang` | `description` / `shortDescription` / `longDescription` |
| `omschrijving` | `description` (disambiguate from the above — two Dutch words, one English concept) |
| `bewijs` / `bewijsReferentie` | `evidence` / `evidenceReference` |
| `naam` | `name` · `opmerkingen` → `notes` |
| `datumInGebruik` / `datumInOntwikkeling` / `datumTeruggetrokken` / `datumEindeOndersteuning` | `inUseDate` / `inDevelopmentDate` / `withdrawnDate` / `supportEndDate` |
| `startDatum` / `eindDatum` | `startDate` / `endDate` |
| `geplandeVervangingsDatum` | `plannedReplacementDate` |
| `contractNummer` | `contractNumber` · `deelnemers` → `participants` |
| `contactpersoonGebruiker` | `userContactPerson` |
| `buitengemeentelijkVoorziening` | `nonMunicipalService` |
| `dpiaVolgendeBeoordeling` | `dpiaNextAssessment` (DPIA is GDPR — already international) |
| `pakketversie_beschrijving` | `packageVersionDescription` |

⚠️ `beschikbaarheid(belangrijksteReden)` and `integriteit(belangrijksteReden)`
contain **parentheses in the property name**. Renaming these to
`availabilityPrimaryReason` / `integrityPrimaryReason` also fixes a malformed
identifier — check nothing parses the parenthesised form first.

### Statutory marker (§4)

`BIO` (Baseline Informatiebeveiliging Overheid) is NL government policy with no
1:1 international counterpart. English identifier + `x-statutory-basis`
(`jurisdiction: NL`, `instrument: BIO`).

## Tasks

- [ ] Inventory per schema and per lib/+src/ file — real counts.
- [ ] Separate VNG-dictated exchange fields (external, keep) from our own.
- [ ] Rename properties; resolve the `beschrijving`/`omschrijving` collision to
      distinct English names.
- [ ] Fix the parenthesised property names.
- [ ] `x-statutory-basis` on the BIO schema.
- [ ] Rename classes/methods/files; `l10n/nl.json` + `check-l10n`.
- [ ] Full suite + hydra gates.

## Risks

- VNG exchange-format fields are an external contract — renaming a *read* breaks
  the integration with the Softwarecatalogus client.
- Two Dutch words (`beschrijving`, `omschrijving`) map to one English word;
  picking `description` for both would collide within a schema.
