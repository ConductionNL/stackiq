---
kind: docs
---

# Proposal: Beta Cross-Surface Alignment — SoftwareCatalog

## Problem

Four surfaces describe SoftwareCatalog (`appinfo/info.xml`, `src/manifest.json`, the conduction.nl product page, and `docs/`) and they disagreed badly enough to block a beta release:

1. **License tag wrong.** `appinfo/info.xml` declared `<licence>agpl</licence>` even though the shipped `LICENSE` file is the European Union Public Licence v1.2 and the description text already said "EUPL". Many PHP/Vue/JS file headers also carry `SPDX-License-Identifier: AGPL-3.0-or-later` — a pre-existing, wider inconsistency noted below but not touched in this change (see "Deferred").
2. **The product page (`conduction-website/src/pages/apps/softwarecatalog.mdx` + its NL translation) was substantially fabricated.** It described an app that pulls IT-asset inventory from Microsoft Intune/Jamf/GLPI/OCS Inventory via OpenConnector, computes a "dependency graph" with "deprecation impact" analysis, federates specifically to Forum Standaardisatie/data.overheid.nl, and ships dashboard widgets named "Renewals due", "Inventory snapshot" and "Discovery deltas". None of this exists in `lib/` or `src/`. The page also claimed version `v1.1` / status "Stable" against an actual `info.xml` version of `0.2.13`.
3. **`docs/FEATURES.md` was stale** (missing contracts, standards/compliance matrix, ArchiMate, portfolio roadmap, reviews, moderated self-registration — all real, shipped features) though not fabricated.
4. **`docs/GOVERNMENT-FEATURES.md`** (a VNG-style requirements checklist) was largely accurate and unusually self-critical, but repeated the wrong license (`AGPL`) and an outdated attribution (`GitHub` instead of Codeberg).

## Canonical Feature Vocabulary (verified against `lib/`, `src/manifest.json`, `src/registry.js`, `appinfo/routes.php`, and the machine-generated `docs/features.json`)

1. **Software portfolio register** — applications, module versions, connections (koppelingen), services (diensten), usage (gebruik), sectors, suites — one OpenRegister-backed catalogue per organisation.
2. **Organisation & contact management** — organisation register with contact roles (`contactpersoon`); `ContactpersonenController`/`ContactPersonHandler` convert contact roles into real Nextcloud user accounts and group memberships (automatic user provisioning).
3. **Contract administration with approval workflow** — `contract` schema (period, cost, status); `ContractApprovalController` (submit/submitRenewal); quick filters (active/expiring/in-negotiation); `ContractStatusJob` background job keeps status current.
4. **GEMMA standards, compliance matrix, ArchiMate** — `standaard` + `compliancy` schemas; `ComplianceMatrixView.vue` cross-tabs modules × standard versions; `ArchiMateImportService`/`ArchiMateExportService` round-trip AMEF XML via the Settings UI (`ArchiMateImportExport.vue`, `/api/archimate/*` routes).
5. **Application lifecycle / portfolio roadmap** — `LifecycleRoadmapView.vue` groups an organisation's applications-in-use by derived lifecycle phase with end-of-support/end-of-life urgency.
6. **Reviews** — `beoordeeling` schema; ratings/assessments of modules, services, connections and usage, with supporting evidence.
7. **Federated catalog sync** — `FederationController`/`FederationService` delegate to **OpenCatalogi's** DirectoryService/BroadcastService (peer add/remove, manual pull, status). This is an **optional, soft integration**: `FederationService::OPENCATALOGI_APP_ID` gates it, and the service degrades cleanly when OpenCatalogi is not installed. It is NOT a hard-coded federation to "Forum Standaardisatie" — that name does not appear anywhere in `lib/` or `src/`.
8. **Open data publishing + moderated self-registration** — `PublicationController`/`PublicationService` publish/depublish entries via the live OpenRegister RBAC model (`publicatiedatum`/`depublicatiedatum`, not the deprecated `@self.published`); `IntakeController` (public, write-only) + `ModerationController` (admin-only approve/reject queue) let anonymous organisations self-register.
9. **Dashboard** — a management-information panel (`Beheer Informatie`) plus two per-object-type statistics tables (`Object Statistieken`), NOT the "Renewals due / Inventory snapshot / Discovery deltas" widgets the old product page described.

## Claims Removed (fabricated, no code support)

- "Discovery via OpenConnector" pulling from Microsoft Intune, Jamf, GLPI, OCS Inventory — **removed**. Grepped `lib/`, `src/`, `appinfo/`: zero hits for Intune/Jamf/GLPI/"OCS Inventory"; the only OpenConnector references are unrelated doc-header boilerplate (`@link` URLs) and an unrelated user-config comment.
- "Dependency graph between applications" / "deprecation impact" — **removed**. No such feature, view, or service exists.
- "Federation to Forum Standaardisatie and data.overheid.nl" — **corrected** to "federated sync via OpenCatalogi's directory network (optional)". Forum Standaardisatie does not appear in code; the real mechanism is OpenCatalogi delegation, which is itself optional.
- Dashboard widgets "Renewals due", "Inventory snapshot", "Discovery deltas" — **removed/replaced** with the two widgets `Dashboard.vue` actually renders (management info + object statistics).
- Version `v1.1` / status "Stable" — **corrected** to `v0.2` (matches `info.xml` `0.2.13`) / status "Beta".
- Pairs-well-with "LaunchPad" — **removed**. The only "launchpad" hit in the codebase is an unrelated code comment (`procest #512 / launchpad #206 — first reference migrations` in `src/registry.js`), not an integration.

## Claims Verified (kept)

- Application/module/connection/organisation/contract registration, GEMMA compliance, open data publishing, automatic user provisioning, federated synchronization (re-scoped as above) — all present in `info.xml`'s existing description and confirmed against controllers/services.
- ArchiMate import/export, compliance matrix, portfolio roadmap, contract approval, moderated self-registration — present in code but were **missing** from the product page and `docs/FEATURES.md`; added to both.
- `docs/GOVERNMENT-FEATURES.md`'s detailed F-05a/b/c rows (compliance matrix, contract administration, lifecycle tracking) and its F-06/F-08 "Gedeeltelijk" (partial) federation/publishing caveats — read in full; left as-is (out of scope to re-verify the OpenRegister-side blocker they describe, and the rest of that document is unusually precise/self-critical already, unlike the marketing page).

## Fixes Applied

1. `appinfo/info.xml`: `<licence>agpl</licence>` → `<licence>EUPL-1.2</licence>`; EN+NL description "Key Features" lists expanded to include contract administration, GEMMA/ArchiMate standards & compliance, portfolio roadmap, and moderated open-data publishing; federated-sync bullet reworded to name OpenCatalogi as the optional delegate.
2. `conduction-website/src/pages/apps/softwarecatalog.mdx` (EN) and the NL i18n copy: hero (version, status), intro, FeatureList, RotatingCards, WidgetShelf, Showcase, PairRow, and CtaBanner rewritten around the verified feature list; all fabricated claims removed.
3. `softwarecatalog/docs/FEATURES.md`: added Contract Administration, Standards/Compliance Matrix/ArchiMate, Application Lifecycle & Portfolio Roadmap, Reviews, and Open Data Publishing & Moderated Self-Registration sections; Federated Synchronization section reworded to name OpenCatalogi.
4. `softwarecatalog/docs/GOVERNMENT-FEATURES.md`: license line and open-source attribution corrected (AGPL→EUPL-1.2, GitHub→Codeberg).
5. Icon (`img/app.svg`): checked against the brand convention (24×24 viewBox, single `#fff` fill) — already compliant, no change needed.

## Deferred (flagged, not fixed in this change)

- **File-header license drift.** ~18 PHP/Vue/JS files under `lib/`/`src/` still carry `SPDX-License-Identifier: AGPL-3.0-or-later` / `@license AGPL-3.0-or-later` in their docblocks, inconsistent with the repo's actual `LICENSE` (EUPL-1.2) and the now-corrected `info.xml` tag. Out of scope here (large mechanical change across many files); tracked as a follow-up.
- **`GemmaViewIndex.vue`** (`src/views/gemmaviews/`) is not referenced by `src/manifest.json` or `src/registry.js` — an orphaned/unwired view. Not surfaced on the product page or docs; left as-is (may be dead code or a future menu item, needs a maintainer decision).
- **`docs/GOVERNMENT-FEATURES.md` F-06/F-08** describe an OpenRegister-side blocker ("magic-mapped objects cannot set the publish predicate yet") that may be stale given `PublicationService` already implements the live `publicatiedatum` RBAC model rather than the deprecated `@self.published` predicate it references. Not re-verified end-to-end in this change; flagged for a maintainer to confirm whether F-06/F-08 status should move from "Gedeeltelijk" to "Beschikbaar".

## Note on Scope

softwarecatalog is a VNG client repository (`Softwarecatalogus/` external client is separate and untouched). All edits in this change are local — no push, no PR, per repo convention for this app.
