# Tasks — english-vocabulary (softwarecatalog)

Scan: **17 schemas / 86 Dutch properties** (all in
`lib/Settings/softwarecatalogus_register.json`), **15 files / 5 classes / 49 methods**.

⚠️ This app holds **client-imported VNG production data** — roughly 3,400 `koppelingen`
and 6,100 `modules`. It cannot reseed. The migration is the change; the rename is the
easy part.

## 1. Classify and measure — nothing renames before this

- [ ] 1.1 Classify all 86 properties into: published external identifier (preserve),
      VNG import field (map at the boundary), or our storage vocabulary (rename).
      Record the classification; it gates every later task.
- [ ] 1.2 Enumerate exactly which field names the VNG importer reads. Named as a tier in
      1.1 but the membership list does not exist yet.
- [ ] 1.3 Re-measure the stored-object count per schema — the 3,400 / 6,100 figure is
      from a 158-day-old note. Read the `oc_openregister_table_<reg>_<schema>` shards,
      not the shared objects table (which is empty and reads as total success), and
      exclude soft-deleted rows.

## 2. Write the migration first

- [ ] 2.1 Author the data migration for every tier-3 property, and run it successfully
      against a **copy of production data** before any rename is proposed for merge.
      This ordering is deliberately the reverse of the smaller apps.

## 3. Rename storage vocabulary + importer, atomically

- [ ] 3.1 Descriptions: `beschrijvingKort`/`beschrijvingLang` →
      `shortDescription`/`longDescription` (7 schemas carry both — this is the collision
      that settled the fleet-level split), `beschrijving`/`omschrijving` → `description`,
      unprefixed `toelichting` → `notes`. Leave `gemma-toelichting` and `ggm-toelichting`
      untouched; `element` will legitimately hold all three side by side.
- [ ] 3.2 Schemas `contactpersoon` → `contactPerson` and `organisatie` → `organisation`,
      **together with** every property that references them, including
      `contract.contactpersoonAanbieder`/`contactpersoonGebruiker` →
      `providerContact`/`consumerContact`. One commit — `$ref` slug resolution is
      instance-global, so a dangling slug can bind to another app's schema rather than fail.
- [ ] 3.3 Validity vs lifecycle dates: `contract.startDatum`/`eindDatum` →
      `validFrom`/`validUntil` per the fleet list; the `gebruik` milestones and the
      `koppeling`/`moduleVersie` date family become event names
      (`acquiredOn`, `inProductionOn`, `phasedOutOn`, `inDevelopmentOn`, `inUseOn`,
      `supportEndsOn`, `withdrawnOn`) rather than being forced into the validity pair.
- [ ] 3.4 Remaining tier-3 properties: `naam` → `name`, `kosten`/`kostenPeriode` →
      `cost`/`costPeriod`, `waardering` → `rating`, `bron`/`heeftBron`/`eolBron` →
      `source`/`hasSource`/`eolSource`, `detailniveau` → `detailLevel`, `beheerder` →
      `maintainer`, `hostingLocatie` → `hostingLocation`, `samenwerkingtype` →
      `collaborationType`, `bbnNiveau` → `bbnLevel`, `versieaanduiding` →
      `versionDesignation`, `gegevensuitwisselingRichting` → `dataExchangeDirection`,
      `titelViewSwc` → `viewTitle`, `pakketversie_beschrijving` → `packageVersionDescription`.
- [ ] 3.5 Update the VNG importer mapping **in the same commit** — an importer still
      writing Dutch keys silently reintroduces them on every run.

## 4. Run and verify the migration

- [ ] 4.1 Run the migration; re-run a VNG import and diff the result. A passing suite is
      not evidence here.

## 5. Code layer

- [ ] 5.1 Rename `ContactpersoonService`, `OrganisatieService`, `MergeOrganisatieService`,
      `ConceptOrganisatiesWidget` and the 49 Dutch methods, plus the Vue components
      (`OrganisatieCard.vue`, `AddContactpersoonModal.vue`,
      `ChangeOrganisatieStatusDialog.vue`, `conceptOrganisatiesWidget.js`).
- [ ] 5.2 **Delete** `debug_contactpersoon_data.php` and `debug_contactpersoon_database.php`
      from the repo root rather than renaming them — a committed debug script is not
      vocabulary worth migrating.

## 6. Translations and gates

- [ ] 6.1 `l10n/nl.json` re-pointed not re-extracted; `check-l10n`.
- [ ] 6.2 Re-run the token-aware scan; residual Dutch SHALL be exactly the tier-1
      published identifiers, and nothing else.
- [ ] 6.3 Full suite plus hydra gates 46/53/54/55/57/61.

## Acceptance criteria

- Classification recorded for all 86 properties; only tier 3 renamed.
- Migration run successfully against copied production data **before** merge.
- A post-migration VNG import produces a clean diff.
- Every `$ref` resolves to a softwarecatalog schema, not a same-named schema elsewhere.
- Every BIO, GGM, GEMMA and NORA identifier byte-identical.
- The two debug scripts are gone.
- ⚠️ `Softwarecatalogus/` (the VNG-Realisatie client repo) is untouched.
