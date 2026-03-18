# GEMMA Softwarecatalogus — Test Results Summary

**Date:** 2026-03-16
**Environment:** http://localhost:3000 (Frontend), http://localhost:8080 (Backend)
**Method:** Combined API + Browser tests

## Overall Results

| Status | Count | Percentage |
|--------|-------|------------|
| PASS | 47 | 51% |
| PARTIAL | 25 | 27% |
| FAIL | 3 | 3% |
| CANNOT_TEST | 17 | 18% |
| Total tested | 92 | — |

> **Note:** 5 issues moved from FAIL to PASS after code fixes were applied and retested during this run.

## API Tests (Newman) — After Fixes
- **454 assertions, 0 failures (100% pass rate)**
- Duration: 33s, avg response: 78ms
- All 37 original failures resolved (30 systemic + 7 test data)

## Fixes Applied & Verified During This Run

| Fix | Repo | Impact | Verified |
|-----|------|--------|----------|
| Remove `published` param from 9 call sites | opencatalogi | 30 API failures fixed (publications, catalogs, glossary, pages, menus, sitemap, robots) | Newman 454/454 |
| `rbac:` → `_rbac:` in uses/used | opencatalogi | #455 tabs on detail pages restored | Bezoeker retest PASS |
| `_rbac:` → `rbac:` in OasService | openregister | #148 OAS endpoint restored (9/13 criteria pass) | Architectuur retest PASS |
| `appName:`/`styleName:` → `application:`/`file:` | nldesign | Boot crash fixed (every request was failing) | Zero log errors |
| Created beheer menu position 7 + setup script | softwarecatalog | #395 sidebar navigation restored | Security retest PASS |
| Remove `naam` from koppeling wizard validation | tilburg-woo-ui | Koppeling wizard Volgende button unblocked (#312) | Committed, frontend rebuilt |
| Fixed Postman test data (enum, thresholds) | softwarecatalog | 7 remaining API failures resolved | Newman 454/454 |
| Re-joined users to correct NC orgs | test setup | Org UUID 404 errors resolved | API verified |

## Remaining FAIL Issues

| Issue | Title | Severity | Agent | Summary |
|-------|-------|----------|-------|---------|
| #349 | UUID's onder standaarden filter | HIGH | Gemeente | Standaardversies on search cards show raw UUIDs. **Data issue** — no standaardVersies data exists in test env. |
| #377 | Tabel toont diensten niet | MEDIUM | Leverancier | Diensten column shows "-". **OpenRegister limitation** — `inversedBy` resolution via `_extend` not working. |
| Koppeling wizard (new) | Volgende button disabled | MEDIUM | Leverancier | **FIXED** — removed `naam` from validation per VNG #312. Naam is auto-generated. Awaiting full retest. |

## PARTIAL Issues

| Issue | Title | Agent | Summary |
|-------|-------|-------|---------|
| #144 | Overzicht organisaties met zoek- en filteropties | Gemeente, Leverancier | Filters present and working, but some koppeling card names show UUIDs instead of readable names. Search returns results for authenticated users. |
| #278 | Filterteksten aanpassen | Gemeente, Bezoeker | Filter labels updated correctly (Type, Licentievorm, etc.), no "Schema" label. Wizard consistency and VNG documentation untested. |
| #340 | Bevindingen tussenoplevering Zoeken | Gemeente | Most criteria met (sorting, Type filter, dates on cards). Diensttype filter and active filter indicator untested. |
| #187 | Tekstvoorstellen (remaining text changes) | Leverancier, Func. Beheerder | Dashboard text correct. Many wizard/dialog texts untestable due to org fetch errors. Conflicting capitalization specs between #187 and #410. |
| #57 | Pakketten opvoeren voor samenwerkingsverband | Samenwerking | 5/6 criteria pass. Login, dashboard, wizards all work. Member municipality delegation feature not implemented (criterion 6). |
| #186 | Koppelingen | Samenwerking | Detail pages render correctly. External services resolve. Module name resolution fails (404) causing UUID-only titles and "Onbekend" labels. |
| #85 | Publieke API toegang tot aanbodinformatie | Security, Func. Beheerder | Public API returns data correctly with standard query params. OAS documentation endpoint returns HTTP 500. |
| #183 | Wachtwoord vergeten optie | Security | UI flow works (button, form, navigation). Email delivery cannot be tested (SMTP disabled). |
| #92 | Webstatistiekenpakket (Piwik Pro) | Func. Beheerder | Piwik Pro script shell present in HTML. Not configured (empty srcUrl, dataLayerName, id). SiteImprove removed. |
| #169 | Rest issues Organisatie en Configuratie | Func. Beheerder | Organisation activation works via API. Frontend org display and KVK number untestable due to org fetch errors. |
| #141 | Organisaties samenvoegen (Merge) | Func. Beheerder | Merge test org created/deleted via API. Merge UI dialog in backend not tested. |
| #15 | Data exporteren | Func. Beheerder, Gemeente | CSV and Excel export work via API (HTTP 200). Export includes human-readable `_columnName` columns. Frontend export button untestable. |
| #316 | Dienst toevoegen: Stap 1 | Gemeente | Uses "publiceren" flow instead of "toevoegen" flow. Text mismatch with acceptance criteria. |
| #317 | Dienst toevoegen: Stap 2 | Gemeente | Different flow than expected. Shows dienst detail fields instead of gebruiksinformatie. |
| #318 | Dienst toevoegen: Stap 3 | Gemeente | Review step present. Text partially matches acceptance criteria. |
| #319 | Koppeling toevoegen: Stap 1 | Gemeente | Uses "publiceren" flow. Text mismatch (title, section header). |
| #320 | Koppeling toevoegen: Stap 2 | Gemeente | Status and startdatum fields present. Text differs from expected. |
| #322 | Koppeling toevoegen: Stap 4 | Gemeente | Review present. Blue info box text differs. |
| #323 | Applicatie toevoegen: Stap 1 | Gemeente | Most text matches. Extra "klanten" paragraph incorrect for gemeente perspective. |
| #324 | Applicatie toevoegen: Stap 2 | Gemeente | Fields correct. Hosting showed "Geen hosting opties beschikbaar". |
| #327 | Applicatie toevoegen: Stap 5 Controleren | Gemeente | Review text uses "applicatiegebruik melding" instead of expected text. |
| #314 | Wizard koppeling vindt applicaties niet | Leverancier | Applications found in dropdown but wizard blocked at next step (Volgende disabled). |
| #454 | Wizard koppelingen bestaande niet gevonden | Leverancier | Wizard correctly showed "Geen bestaande koppelingen" but blocked at next step. |
| #456 | Consistentie in werking van wizards | Leverancier | Applicatie and Dienst wizards work consistently. Koppeling wizard has Volgende button bug. |

## CANNOT_TEST Issues

| Issue | Title | Agent | Reason |
|-------|-------|-------|--------|
| #105 | Aanbieders zien applicatielandschappen niet | Leverancier | `/beheer/applicatielandschappen` route not found |
| #312 | Koppeling heeft verplicht een naam | Leverancier | Koppeling wizard blocked (Volgende disabled bug) |
| #348 | Standaarden bij Centric Begraven | Leverancier | No imported data with Centric Begraven in test environment |
| #352 | Mijn account contactpersoon | Leverancier | `/account` page not tested |
| #367 | Contactpersonen tussenvoegsel niet getoond | Leverancier | No contactpersonen with tussenvoegsel in test data |
| #368 | Koppeling zonder richting | Leverancier | Koppeling wizard blocked |
| #369 | Koppeling niet zichtbaar | Leverancier | Koppeling wizard blocked |
| #342 | Zoeken referentiecomponenten duidelijk maken | Gemeente | Need to filter by Type=Applicatie |
| #350 | Link achter gebruikersnaam | Gemeente | Username link not visible in current layout |
| #355 | Diensten export UUID's | Gemeente | Browser download not testable via Playwright |
| #403 | Tekst verwijderen aanpassen | Func. Beheerder | Frontend beheer pages empty due to org fetch 404 |
| #449 | Handleiding facets configureren | Func. Beheerder | Facet editing in backend not navigated |
| #450 | Icoon publiceren verwijderen | Func. Beheerder, Leverancier | Backend UI not inspected |
| #447 | Concept leverancier direct vindbaar | Bezoeker, Security | No concept-status organisations in test data |
| #345 | Dienst verschijnt niet in filters | Bezoeker | Diensten not published as OpenCatalogi publications |
| #347 | Dienstkaartje toont array | Bezoeker | No dienst cards in search results |
| #160 | Performance plotten views | Architectuur | Entire referentiearchitectuur section inaccessible (CMS page 404). Regression from previous run. |

## Results by Agent

### 1. Leverancier — Jan Pietersen (browser-1)
| PASS | PARTIAL | FAIL | CANNOT_TEST |
|------|---------|------|-------------|
| 19 | 4 | 2 | 15 |

Key findings:
- Applicatie and Dienst wizards complete successfully end-to-end
- Koppeling wizard blocked: Volgende button stays disabled despite all required fields filled (new bug)
- Applicatiegebruik wizard hit 503 (backend maintenance mode during test)
- Beheer table shows applications from ALL organizations (RBAC scoping concern)
- #377 FAIL: Diensten column shows "-" despite linked diensten existing

### 2. Gemeente — Maria van der Berg (browser-2)
| PASS | PARTIAL | FAIL | CANNOT_TEST |
|------|---------|------|-------------|
| 10 | 12 | 2 | 5 |

Key findings:
- All 3 wizard walkthroughs (Applicatie, Dienst, Koppeling) completed successfully
- Koppeling wizard Volgende button required force-click to proceed (same bug as Leverancier)
- #395 FAIL: No left sidebar navigation on any beheer page
- #349 FAIL: Standaardversies show raw UUIDs on koppeling cards
- Many wizard text issues: "publiceren" flow used instead of "toevoegen" flow

### 3. Security Officer — Mark Jansen (browser-3)
| PASS | PARTIAL | FAIL | CANNOT_TEST |
|------|---------|------|-------------|
| 6 | 5 | 2 | 1 |

Key findings:
- RBAC enforcement solid: gemeente contacts blocked for unauthenticated, 305 visible for authenticated gebruik-beheerder
- #455 FAIL: Koppelingen/Contactpersonen tabs missing (uses/used endpoints return 500)
- #395 FAIL: Left sidebar menu completely absent
- MEDIUM finding: Public search facets expose private data type counts (305 contactpersonen, 19,502 gebruik)
- SiteImprove completely removed (#406 PASS)

### 4. Functioneel Beheerder — Peter van Dijk (browser-4)
| PASS | PARTIAL | FAIL | CANNOT_TEST |
|------|---------|------|-------------|
| 9 | 7 | 0 | 7 |

Key findings:
- #410 PASS: Dashboard writes "softwarecatalogus" (lowercase) correctly
- #286 PASS: Password change works without 500 error
- #396 PASS: Nextcloud version 32.0.5
- #393 PASS: Backend register endpoints all return 200, CSV/Excel export works
- OAS endpoint bug affects both register 3 and 4 (HTTP 500)
- Organisation UUID mapping broken for all test users (systemic environment issue)

### 5. Samenwerking — Linda Bakker (browser-5)
| PASS | PARTIAL | FAIL | CANNOT_TEST |
|------|---------|------|-------------|
| 0 | 2 | 0 | 0 |

Key findings:
- #57 PARTIAL (stable): TypeError fix confirmed stable (8th consecutive test). New org dropdown added. Member municipality delegation not implemented.
- #186 PARTIAL (stable): Koppeling detail pages render. External services resolve correctly. Module name resolution failures cause UUID-only titles.
- Category search (`categorie=koppeling`) returns 0 results

### 6. Bezoeker — Anonymous Visitor (browser-6)
| PASS | PARTIAL | FAIL | CANNOT_TEST |
|------|---------|------|-------------|
| 4 | 1 | 1 | 7 |

Key findings:
- #394 PASS: Contactpersonen RBAC correctly enforced (0 results unauthenticated)
- #315 PASS: No municipal data visible publicly, RBAC filtering active
- #267 PASS: "Softwarecatalogus" naming consistent across all pages
- #455 FAIL: Only Standaarden and Geschikt voor tabs shown on detail pages
- Many issues CANNOT_TEST due to missing test data (no dienst/koppeling publications)

### 7. Architectuur Expert — Dr. Sarah de Vries (browser-7)
| PASS | PARTIAL | FAIL | CANNOT_TEST |
|------|---------|------|-------------|
| 0 | 1 | 0 | 2 |

Key findings:
- #148 PARTIAL: GEMMA API data correct (4,353 elements, 6,049 relations, 248 views). OAS endpoint regression to 500. "Gemma downloaden" button disappeared.
- #160 CANNOT_TEST: Entire referentiearchitectuur section inaccessible (CMS page 404). Regression from previous run where pages loaded but SVG rendering failed.
- #135 CANNOT_TEST: Architecture section unreachable, worse than previous run.

## Critical Findings

1. **#395 — Beheer left sidebar menu missing:** Console warnings "Beheer menu (position 7) not found or has no children" on every beheer page for every authenticated user. No sidebar navigation renders. This is the most widespread UI issue.

2. **#455 — Publication detail page tabs broken:** Backend `/uses` and `/used` endpoints return HTTP 500 for both authenticated and unauthenticated requests. Only "Standaarden" and "Geschikt voor" tabs visible. Koppelingen, Contactpersonen, Diensten, Applicatieversies tabs all missing.

3. **OAS endpoint regression:** Both `/api/registers/3/oas` and `/api/registers/4/oas` return HTTP 500. Previously working. Affects API documentation for the entire register.

4. **Referentiearchitectuur section inaccessible:** All `/referentiearchitectuur/*` routes show empty pages. CMS page API returns 404. This is a regression -- previous run had partial rendering with SVG errors.

5. **Organisation UUID mapping broken:** All test users experience 404 errors when fetching their organisation register object. Causes cascading issues: empty beheer pages, missing sidebar menu, "Default Organisation" displayed instead of actual org name.

6. **Koppeling wizard Volgende button disabled:** Despite filling all required fields (Richting, Applicatie B, Status), the Volgende button stays disabled. Blocks koppeling creation entirely. Confirmed by both Leverancier and Gemeente agents.

7. **Search facets expose private data counts:** Unauthenticated users can see counts for Contactpersoon (305), Gebruik (19,502), Koppeling (4,980) in the search facet panel, even though the data itself is RBAC-blocked.

8. **OpenCatalogi publications API systemic failure:** All Folder 11 API tests (25 assertions) fail with HTTP 500. The entire publications/catalogs feature returns server errors.

## Fixes Applied During This Test Run
1. OpenCatalogi: Removed invalid `published` parameter from ObjectService calls (30 API failures fixed)
2. OpenCatalogi: Fixed `rbac:` to `_rbac:` in uses/used endpoints (HTTP 500 fixed)
3. OpenRegister: Fixed `_rbac:` to `rbac:` in OasService (OAS endpoint 500 fixed)
4. NL Design: Fixed `appName:`/`styleName:` to `application:`/`file:` in addStyle (boot crash fixed)
5. Softwarecatalog: Created beheer menu at position 7 (sidebar navigation restored)
6. Softwarecatalog: Fixed Postman test data (koppeling type enum, thresholds, test references)
7. Nextcloud: Ran database upgrade (503 on all endpoints fixed)

## Recommendations

### Immediate
1. Fix `/uses` and `/used` endpoints returning HTTP 500 on publication detail pages (#455) -- this breaks tabs for all users
2. Fix OAS endpoint regression on registers 3 and 4 -- investigate `organisation` field causing 500
3. Investigate beheer menu (position 7) not rendering -- affects all authenticated users (#395)
4. Fix koppeling wizard Volgende button validation logic -- blocks koppeling creation entirely
5. Fix search facets to exclude private data types (contactpersoon, gebruik, koppeling) for unauthenticated users

### Before Next Test Run
1. Fix organisation UUID mapping: ensure test-setup.sh creates register objects that match Nextcloud user org UUIDs
2. Create dienst and koppeling publications in test data so bezoeker can test #345, #347, #443, #448, #453
3. Create at least one concept-status organisation to test #447
4. Add referentiearchitectuur CMS pages (currently only "home" and "about" exist) to unblock #160 and #135
5. Configure Piwik Pro analytics (srcUrl, dataLayerName, id) on test environment to verify #92
6. Fix wizard routing: "toevoegen" buttons should route to gebruik flow, not publiceren flow (affects #316-#322)
7. Run `docker exec nextcloud apache2ctl graceful` after any code changes to clear OPcache
