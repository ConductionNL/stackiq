# Tasks: Beta Cross-Surface Alignment — Stackiq

## 1. Derive canonical feature vocabulary

- [x] 1.1 Read `appinfo/info.xml`, `src/manifest.json` (menu/pages), `src/registry.js` for the user-visible feature/page list
- [x] 1.2 Grep `lib/Controller/*.php` and `lib/Service/**/*.php` for real behaviour (federation, publication, moderation, intake, contract approval, ArchiMate import/export)
- [x] 1.3 Cross-check against the machine-generated `docs/features.json` (openspec-derived spec summaries) as the highest-confidence source
- [x] 1.4 Enumerate schemas in `lib/Settings/softwarecatalogus_register.json` (organisatie, applicatie/module, moduleVersie, contract, koppeling, standaard, compliancy, beoordeeling, contactpersoon, gebruik, dienst, sector, suite, kwetsbaarheid, element/view/model/relation/property-definition)

## 2. Fix code metadata (`appinfo/info.xml`)

- [x] 2.1 `<licence>agpl</licence>` → `<licence>EUPL-1.2</licence>`
- [x] 2.2 Expand EN description "Key Features" list: contract administration, GEMMA/ArchiMate standards & compliance, portfolio roadmap, moderated open-data publishing
- [x] 2.3 Expand NL description "Belangrijkste functies" list to match
- [x] 2.4 Reword federated-sync bullets (EN+NL) to name OpenCatalogi as the optional delegate, not a bare claim

## 3. Reconcile product page (conduction-website)

- [x] 3.1 Rewrite `src/pages/apps/stackiq.mdx` hero: version `v0.2` (was `v1.1`), status Beta (was Stable)
- [x] 3.2 Rewrite intro paragraph and `FeatureList` around the verified feature list
- [x] 3.3 Rewrite `RotatingCards` (Register / Assess / Federate) removing OpenConnector-discovery and Forum Standaardisatie claims
- [x] 3.4 Rewrite `WidgetShelf` to the two real dashboard widgets (object statistics, management information)
- [x] 3.5 Rewrite `Showcase` (contract approval, standards/ArchiMate, OpenCatalogi federation)
- [x] 3.6 Rewrite `PairRow` — drop OpenConnector and LaunchPad (no code support), keep OpenRegister (hard dependency) + OpenCatalogi (optional)
- [x] 3.7 Rewrite `CtaBanner` copy
- [x] 3.8 Mirror all of the above in the NL i18n page (`i18n/nl/docusaurus-plugin-content-pages/apps/stackiq.mdx`)

## 4. Reconcile docs

- [x] 4.1 `docs/FEATURES.md`: add Contract Administration, Standards/Compliance Matrix/ArchiMate, Application Lifecycle & Portfolio Roadmap, Reviews, Open Data Publishing & Moderated Self-Registration; reword Federated Synchronization to name OpenCatalogi
- [x] 4.2 `docs/GOVERNMENT-FEATURES.md`: fix license (AGPL→EUPL-1.2) and open-source attribution (GitHub→Codeberg)
- [x] 4.3 Read `docs/GOVERNMENT-FEATURES.md` F-06/F-08 in full; leave as-is, flag the possible staleness in the proposal (deferred, needs maintainer confirmation against `PublicationService`'s live `publicatiedatum` implementation)

## 5. Icon check

- [x] 5.1 Verify `img/app.svg` — 24×24 viewBox, single `#fff` fill class, matches brand convention — no change needed

## 6. Deliverable

- [x] 6.1 Write this openspec change (proposal.md, tasks.md, specs/beta-alignment/spec.md, `.openspec.yaml`)
- [x] 6.2 No push/PR — local edits only per repo convention (VNG client repo)
