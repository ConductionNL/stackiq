---
kind: code
depends_on:
  - landscape-usage-registration
---

# Classify the applications you use for availability, integrity and confidentiality

## Summary

A CISO wants to classify the applications in their own organisation's overview with a BIO baseline level, and share that knowledge with other municipalities. Stackiq has one BBN level per product, set in the catalogue, the same for every municipality and not split into availability, integrity and confidentiality. This change lets an organisation classify each application it uses on those three aspects, with a reason each, suggests the levels from GEMMA's reference components, derives the overall BBN level, and shows other municipalities how organisations classify an application, as counts that name no one.

## Why

This change covers one matrix row.

- `stackiq:sec-baseline-classification`, "Classify each application with a baseline security level for availability, integrity and confidentiality." Stackiq rates itself partial: `module.bbnLevel` holds BBN1 to BBN3, one level per application, not per organisation and not split into availability, integrity and confidentiality. The demand is a feature request on the GEMMA Softwarecatalogus: "Als CISO wil ik de pakketten in mijn pakketoverzicht van een BBN classificatie voorzien, opdat deze kennis met andere gemeenten gedeeld wordt en wij passende beveiligingsmaatregelen kunnen nemen" (https://github.com/VNG-Realisatie/Softwarecatalogus/issues/46, labels IBD and PvE wens). No competitor cell is rated yes for this row.

The row is partial and built: this change builds the classification split into availability, integrity and confidentiality, made by the organisation that uses the application.

## What stackiq has today

Read at development 49e65cb4, with the lead's merged changes on development 9a5ece6a.

- `lib/Settings/softwarecatalogus_register.json:7225` `module.bbnLevel`, enum BBN1, BBN2, BBN3, facetable. It is part of the product record, which every organisation reads.
- `ModuleDetail` shows it in `md-data` (`src/manifest.json:500`), and the Modules page lists it as a column (`:608`) with quick filters BBN1, BBN2, BBN3 and Without DPIA (from `:611`).
- `usage` (`:2654`, version 1.5.0) is the organisation's own use of an application. It has no security classification. `landscape-usage-registration` gives it the pages `Gebruik` and `GebruikDetail`.
- The GEMMA reference components in the `vng-gemma` register carry the split already: `element.availability`, `element.integrity` and `element.confidentiality`, each with a main reason, and `element.bivScoreBbn` (GEMMA properties Beschikbaarheid, Integriteit, Vertrouwelijkheid and BIV score BBN, `lib/Settings/GEMMA_release.xml`, for example a score of 122 on a component of BBN 2). `usage.usedForReferenceComponents` (`:2982`) and `module.referenceComponents` (`:6992`) point at them.
- `bioMeasure.bbnLevel` names the BBN levels a BIO measure applies to.

## What this change builds

- On `usage`: `availabilityLevel`, `integrityLevel` and `confidentialityLevel` (BBN1 to BBN3), a reason for each, `bbnLevel` derived as the highest of the three, `classifiedAt` and `classifiedBy`.
- On `GebruikDetail`: a Security classification panel where the organisation's CISO or functional administrator sets the three levels, with a Suggest from GEMMA action that takes the highest level per aspect from the application's reference components.
- On `Gebruik`: the BBN level as a column and a quick filter.
- On `ModuleDetail`: a panel How organisations classify this application, with the number of organisations per level for each aspect, shown to municipal users once at least three organisations have classified it, and never naming an organisation.

## Out of scope

- Changing `module.bbnLevel`. It stays the catalogue's level for the product, and the Modules filters keep working.
- Listing the BIO measures that apply at the derived level (`bioMeasure.bbnLevel`). A follow-up can add that list to the usage page.
- A cross-organisation aggregate in OpenRegister. OpenRegister's aggregation filters rows by the reader's rights first (`lib/Service/Rbac/AggregateVisibility.php` in OpenRegister) and has no rule for a minimum group size across organisations; if it gains one, the stackiq endpoint can go.
- A DPIA or risk assessment workflow. The DPIA fields on `module` stay as they are.

## Risks

- Aggregates can disclose. With two organisations, a count tells each what the other chose. The summary is withheld below three classifying organisations, shows counts only, and is shown only to users of municipalities and collaborations and to admins.
- A derived `bbnLevel` goes stale if a level is changed outside stackiq. It is recomputed on every save of the usage, so a direct API write also updates it.
- New properties on `usage`: its version and the register version must go up, or the import skips the change.
