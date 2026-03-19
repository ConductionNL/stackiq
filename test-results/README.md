# GEMMA Softwarecatalogus — Test Results Summary

**Date:** 2026-03-19
**Environment:** http://localhost:3000 (Frontend), http://localhost:8080 (Backend)
**Method:** Combined API tests (Newman) + Browser tests (7 persona agents)

---

## Overall Results

| Status | Count | Percentage |
|--------|-------|------------|
| **PASS** | 33 | 39% |
| **PARTIAL** | 27 | 32% |
| **FAIL** | 7 | 8% |
| **CANNOT_TEST** | 18 | 21% |
| **Total tested** | 85 | — |

## API Test Results
- 454 assertions executed, 417 passed (91.9%)
- 334 requests, 28s duration, avg 65ms response time
- 25 of 37 failures are systemic (OpenCatalogi publications API returns HTTP 500)
- Core API (folders 00-10): 12 failures across 7 issues

## FAIL Issues (Requires Attention)

| Issue | Title | Severity | Agent(s) | Summary |
|-------|-------|----------|----------|---------|
| #395 | Menu linkerkant verdwijnt | HIGH | Gemeente, Security | Left navigation menu completely absent on all beheer pages. Console: "Beheer menu (position 7) not found or has no children". Affects all authenticated personas. |
| #455 | Koppelingen/contactpersonen tabs publiekelijk niet getoond | HIGH | Bezoeker, Security | Koppelingen and Contactpersonen tabs missing from public AND authenticated detail pages. Backend `/uses` and `/used` endpoints return HTTP 500. |
| #349 | UUID's onder standaarden filter | HIGH | Gemeente | Standaardversies on search result cards show raw UUIDs. Name resolution returns 404 for standard version references. |
| #377 | Tabel toont diensten niet | MEDIUM | Leverancier | Diensten column shows "-" for all rows in beheer table, despite applications having linked diensten. |
| #400 | Koppeling opslaan geeft foutmelding | MEDIUM | API | API tests: koppeling visibility in list, re-save, and data persistence all fail (3 assertions). |
| #155 | Definities via interactieve optie (Begrippenlijst) | MEDIUM | API, Func. Beheerder | All 5 Newman glossary assertions fail (endpoint empty/not returning data). API returns 6 terms but interactive UI untestable. |
| #332 | Voorpagina inrichten | MEDIUM | API, Func. Beheerder | Authenticated search endpoint assertion fails in API tests. Homepage loads but CMS editing untestable. |

## PARTIAL Issues

| Issue | Title | Agent(s) | Summary |
|-------|-------|----------|---------|
| #144 | Overzicht organisaties met zoek- en filteropties | Gemeente, Leverancier | Filters present and working. Some koppeling card names show UUIDs instead of readable names. |
| #278 | Filterteksten aanpassen | Gemeente, Bezoeker | Filter labels updated correctly (Type, Licentievorm, etc.). Wizard consistency and VNG documentation untested. |
| #340 | Bevindingen tussenoplevering Zoeken | Gemeente | Most criteria met (sorting, Type filter, dates on cards). Diensttype filter and active filter indicator untested. |
| #187 | Tekstvoorstellen (remaining text changes) | Leverancier, Func. Beheerder | Dashboard text correct. Many wizard/dialog texts untestable due to org fetch errors. |
| #57 | Pakketten opvoeren voor samenwerkingsverband | Samenwerking | 5/6 criteria pass. Login, dashboard, wizards all work. Member municipality delegation feature not implemented. |
| #186 | Koppelingen | Samenwerking | Detail pages render. External services resolve. Module name resolution fails causing UUID-only titles. |
| #85 | Publieke API toegang tot aanbodinformatie | Security, Func. Beheerder | Public API returns data correctly. OAS documentation endpoint returns HTTP 500. |
| #183 | Wachtwoord vergeten optie | Security | UI flow works (button, form, navigation). Email delivery cannot be tested (SMTP disabled). |
| #148 | GEMMA-architectuur opvraagbaar met API | Architectuur, Func. Beheerder | API data correct (4,353 elements, 6,049 relations, 248 views). OAS regression to 500. "Gemma downloaden" button disappeared. Model-id filter non-functional. |
| #92 | Webstatistiekenpakket (Piwik Pro) | Func. Beheerder | Piwik Pro script shell present but not configured (empty srcUrl, dataLayerName, id). SiteImprove removed. |
| #169 | Rest issues Organisatie en Configuratie | Func. Beheerder | Organisation activation works via API. Frontend org display untestable due to org fetch errors. |
| #141 | Organisaties samenvoegen (Merge) | Func. Beheerder | Merge test org created/deleted via API. Merge UI dialog in backend not tested. |
| #15 | Data exporteren | Func. Beheerder, Gemeente | CSV and Excel export work via API. Includes human-readable `_columnName` columns. Frontend export button untestable. |
| #316 | Dienst toevoegen: Stap 1 | Gemeente | Uses "publiceren" flow instead of "toevoegen" flow. Text mismatch with acceptance criteria. |
| #317 | Dienst toevoegen: Stap 2 | Gemeente | Different flow than expected. Shows dienst detail fields instead of gebruiksinformatie. |
| #318 | Dienst toevoegen: Stap 3 | Gemeente | Review step present. Text partially matches acceptance criteria. |
| #319 | Koppeling toevoegen: Stap 1 | Gemeente | Uses "publiceren" flow. Title and section header text mismatch. |
| #320 | Koppeling toevoegen: Stap 2 | Gemeente | Status and startdatum fields present. Text differs from expected. |
| #322 | Koppeling toevoegen: Stap 4 | Gemeente | Review present. Blue info box text about visibility differs. |
| #323 | Applicatie toevoegen: Stap 1 | Gemeente | Most text matches. Extra "klanten" paragraph incorrect for gemeente perspective. |
| #324 | Applicatie toevoegen: Stap 2 | Gemeente | Fields correct. Hosting showed "Geen hosting opties beschikbaar". |
| #327 | Applicatie toevoegen: Stap 5 Controleren | Gemeente | Review text uses "applicatiegebruik melding" instead of expected text. |
| #314 | Wizard koppeling vindt applicaties niet | Leverancier | Applications found in dropdown, but wizard blocked at next step (Volgende disabled). |
| #454 | Wizard koppelingen bestaande niet gevonden | Leverancier | Wizard correctly showed "Geen bestaande koppelingen" but blocked at next step. |
| #456 | Consistentie in werking van wizards | Leverancier | Applicatie and Dienst wizards consistent. Koppeling wizard has Volgende button bug. |
| #397 | Pagina aanmaken via CMS | Func. Beheerder | CMS pages API returns 2 pages (Home, About). Admin CMS navigation untestable. |
| #135 | Non-functionele eisen Referentiearchitectuur | Architectuur | API performance acceptable. Architecture UI section completely inaccessible (CMS page 404). Regression. |

## Results by Agent

### 1. Leverancier — Jan Pietersen (browser-1)
| PASS | PARTIAL | FAIL | CANNOT_TEST | CLOSED |
|------|---------|------|-------------|--------|
| 19 | 4 | 2 | 15 | 33 |

Key findings:
- Applicatie and Dienst wizards complete successfully end-to-end
- **New bug:** Koppeling wizard Volgende button stays disabled despite all required fields filled
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
- Many wizard text issues: "publiceren" flow used instead of "toevoegen" flow (#316-#327)

### 3. Security Officer — Mark Jansen (browser-3)
| PASS | PARTIAL | FAIL | CANNOT_TEST |
|------|---------|------|-------------|
| 6 | 5 | 2 | 1 |

Key findings:
- RBAC enforcement solid: gemeente contacts blocked for unauthenticated, 305 visible for authenticated gebruik-beheerder
- #455 FAIL: Koppelingen/Contactpersonen tabs missing (uses/used endpoints return 500)
- #395 FAIL: Left sidebar menu completely absent
- **Security finding (MEDIUM):** Public search facets expose private data type counts (305 contactpersonen, 19,502 gebruik records)
- SiteImprove completely removed (#406 PASS)

### 4. Functioneel Beheerder — Peter van Dijk (browser-4)
| PASS | PARTIAL | FAIL | CANNOT_TEST |
|------|---------|------|-------------|
| 9 | 7 | 0 | 7 |

Key findings:
- #410 PASS: Dashboard writes "softwarecatalogus" (lowercase) correctly
- #286 PASS: Password change works without 500 error
- #396 PASS: Nextcloud version 32.0.5
- #393 PASS: Backend register endpoints all return 200; CSV/Excel export works
- OAS endpoint returns HTTP 500 for both register 3 and 4
- Organisation UUID mapping broken for all test users (systemic environment issue)

### 5. Samenwerking — Linda Bakker (browser-5)
| PASS | PARTIAL | FAIL | CANNOT_TEST |
|------|---------|------|-------------|
| 0 | 2 | 0 | 0 |

Key findings:
- #57 PARTIAL (stable): TypeError fix confirmed stable (8th consecutive test). New org dropdown added. Member municipality delegation not yet implemented.
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
| 0 | 2 | 0 | 1 |

Key findings:
- #148 PARTIAL: GEMMA API data correct (4,353 elements, 6,049 relations, 248 views, 1 model). OAS endpoint regression to 500. "Gemma downloaden" button disappeared. Model-id filter non-functional.
- #160 CANNOT_TEST: Entire referentiearchitectuur section inaccessible (CMS page 404). Regression from previous run.
- #135 PARTIAL: API performance acceptable but architecture UI completely inaccessible. Worse than previous run.

## PASS Issues (Verified Working)

| Issue | Title | Agent(s) |
|-------|-------|----------|
| #267 | Naam is softwarecatalogus i.p.v. Softwarecatalogus | Bezoeker |
| #263 | Niet ingelogd: gebruik tab toont gemeenten | Bezoeker |
| #315 | Zoekpagina toont gemeentelijk applicatielandschap | Bezoeker, Gemeente, Security |
| #394 | Contactpersonen gemeenten publiekelijk zichtbaar | Bezoeker, Security |
| #266 | Na inloggen: Mijn account leeg? | Gemeente |
| #280 | Zoeken: sorteren gaat niet goed | Gemeente |
| #344 | Zoeken: Geen resultaten bij Gravenbeheercomponent | Gemeente, Leverancier |
| #353 | Mijn account functie niet aangepast | Gemeente |
| #343 | Filter 'Type koppeling' toevoegen | Gemeente |
| #346 | Paginering werkt niet | Gemeente |
| #325 | Applicatie toevoegen: Stap 3 Referentiecomponenten | Gemeente |
| #326 | Applicatie toevoegen: Stap 4 Deelnemer | Gemeente |
| #321 | Koppeling toevoegen: Stap 3 Deelnemer | Gemeente |
| #328 | Applicatie toevoegen: Stap 1.1 Nieuwe applicatie | Gemeente |
| #354 | Diensten: incomplete lijst applicaties | Leverancier |
| #357 | Diensten: Diensttype en Type door elkaar | Leverancier |
| #371 | Applicatie: UUID onder compliance | Leverancier |
| #373 | Applicatie: Gekoppelde diensten niet getoond | Leverancier |
| #375 | Applicaties: versie voor SaaS applicaties | Leverancier |
| #376 | Applicaties: labels wizard en tabel anders | Leverancier |
| #381 | Applicaties: non-compliant vervangen door niet ondersteund | Leverancier |
| #384 | Applicaties: eenduidige manier van bewerken | Leverancier |
| #443 | Dienst pagina: diensttypen aan elkaar geschreven | Leverancier |
| #444 | Vormgeving veranderd bij te lange URLs | Leverancier |
| #445 | Nieuwe dienst verkeerde afsluitende pagina | Leverancier |
| #446 | Dienst publiceren: tekstuele inconsistenties | Leverancier |
| #404 | Regelmatig witte schermen | Security |
| #409 | Footer anders: inlog of uitgelogd | Security, Func. Beheerder |
| #406 | SiteImprove verwijderen | Security, Func. Beheerder |
| #410 | Dashboard schrijfwijze softwarecatalogus | Func. Beheerder |
| #286 | 500-error bij wachtwoord wijzigen | Func. Beheerder |
| #396 | Verouderde NextCloud versie | Func. Beheerder |
| #393 | Backend fouten in voorzieningenregister | Func. Beheerder |

## Bugs Fixed This Session (11 total)

| # | Bug | Repo | Impact |
|---|-----|------|--------|
| 1 | Register `languages` property missing | openregister | ALL API 500s |
| 2 | Named parameter `_rbac` mismatch | openregister | ALL PATCH/PUT 500s |
| 3 | Deelnemers empty if block | softwarecatalog | Endpoint always 500 |
| 4 | Publications schema enrichment | opencatalogi | Blank search page |
| 5 | explode() on array in RenderObject | openregister | POST response 500s |
| 6 | Schema URL path wrong | tilburg-woo-ui (5 files) | Gemeente wizards blocked |
| 7 | Diensten missing from catalog | softwarecatalog + data | Diensten invisible in search |
| 8 | View rendering wrong component | tilburg-woo-ui | /beheer/views empty |
| 9 | Default Org UUID mapping | Data + openregister | Functioneel-beheerder blocked |
| 10 | Koppeling wizard missing Naam field | tilburg-woo-ui | "Volgende" permanently disabled |
| 11 | Glossary URL validation + Delete dialog | opencatalogi + nextcloud-vue | Form blocked + UUID shown |

## CANNOT_TEST Issues

| Issue | Title | Agent(s) | Reason |
|-------|-------|----------|--------|
| #105 | Aanbieders zien applicatielandschappen niet | Leverancier | `/beheer/applicatielandschappen` route not found |
| #312 | Koppeling heeft verplicht een naam | Leverancier | Koppeling wizard blocked (Volgende disabled) |
| #348 | Standaarden bij Centric Begraven | Leverancier | No imported data in test environment |
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
| #160 | Performance plotten views | Architectuur | Entire referentiearchitectuur section inaccessible (CMS page 404) |
| #278 (func.) | Filterteksten (backend) | Func. Beheerder | Search page blocked by org fetch error for this agent |

## Recommendations

### Immediate (P0)
1. **Fix `/uses` and `/used` endpoints** returning HTTP 500 on publication detail pages (#455) -- breaks tabs for all users (both public and authenticated)
2. **Fix OAS endpoint** regression on registers 3 and 4 -- investigate `organisation` field causing 500
3. **Fix beheer left sidebar menu** (position 7) not rendering (#395) -- affects all authenticated users
4. **Fix koppeling wizard** Volgende button validation logic -- blocks koppeling creation entirely

### High Priority (P1)
5. **Fix search facets** to exclude private data types (contactpersoon, gebruik, koppeling) for unauthenticated users -- information leakage
6. **Fix wizard routing**: "toevoegen" buttons should route to gebruik flow, not publiceren flow (affects #316-#322)
7. **Fix referentiearchitectuur CMS pages** -- only "home" and "about" exist, all architecture routes broken (#160, #135)
8. **Fix name resolution** for module UUIDs in koppelingen -- causes UUID-only titles throughout the platform

### Before Next Test Run (P2)
9. Fix organisation UUID mapping: ensure test-setup.sh creates register objects matching Nextcloud user org UUIDs
10. Create dienst and koppeling publications in test data so bezoeker can test #345, #347, #443, #448, #453
11. Create at least one concept-status organisation to test #447
12. Configure Piwik Pro analytics (srcUrl, dataLayerName, id) on test environment to verify #92
