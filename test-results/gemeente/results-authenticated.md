# Test Results: Gemeente (Authenticated) - Maria van der Berg

**Date:** 2026-03-10 (Session 11 - Full Re-verification with Live Data)
**Previous Sessions:** Sessions 5-10 (2026-02-24 through 2026-03-10)
**Persona:** Maria van der Berg - ICT-coordinator, Test Gemeente
**Role:** gebruik-beheerder
**Credentials:** maria.vanderberg@test.nl / WelcomeToTest2026
**Environment:** Frontend SPA: http://localhost:3000 | Backend: http://localhost:8080
**Browser:** Chromium (Playwright MCP, browser-2, headless, viewport 1280x800)

---

## Session 11 Context

This session performed a full re-verification with a live data environment containing 13,114 publications (6,105 applicaties, 3,455 koppelingen, 3,537 organisaties, 17 diensten). All three mandatory wizard walkthroughs were completed successfully before testing issues. The Nextcloud backend occasionally entered maintenance mode (503) during heavy queries, requiring manual `occ maintenance:mode --off` to recover.

**Environment health**: Backend generally stable but entered maintenance mode during Z-A sort query (likely database timeout on 13K+ records). Database (PostgreSQL) restarted once during the session.

---

## Wizard Walkthroughs

### Applicatie Wizard (Gebruik) - PASS
- **Route:** /forms/gebruik/applicatie
- **Steps completed:** Step 1 (search "Centric" -> select "Centric Betalen"), Step 2 (usage info), Step 3 (referentiecomponenten), Step 4 (deelnemer), Step 5 (review + save)
- **Result:** Successfully saved. Object visible in Beheer > Gebruik table as "Centric Betalen".
- **Issues observed:** None during this session.

### Dienst Wizard - PASS
- **Route:** /forms/dienst
- **Steps completed:** Step 1 (dienst info: "Test Gemeente Dienst"), Step 2 (gebruiksinformatie), Step 3 (review + save)
- **Result:** Successfully saved. Object visible in Beheer > Diensten table as "Test Gemeente Dienst".
- **Issues observed:** None.

### Koppeling Wizard - PASS (after retry)
- **Route:** /forms/koppeling
- **Steps completed:** Step 1 (applicatie search), Step 2 (koppeling details), Step 3 (deelnemer), Step 4 (review + save)
- **Result:** Initially failed with 503 (database restarting). After `docker restart nextcloud`, re-did the wizard from scratch. Successfully saved on second attempt.
- **Object created:** "Centric Betalen -> MijnOverheid.nl" koppeling visible in Beheer > Koppelingen.
- **Issues observed:** 503 error on first save attempt due to database restart. Schema loading delay (~5-8s) after server restart.

---

## Issue Test Results

### #15: Data vanuit softwarecatalogus exporteren - PARTIAL FAIL
**Status:** PARTIAL FAIL
**Findings:**
- Dienst CSV export via API works: `curl -u 'maria.vanderberg@test.nl:WelcomeToTest2026' '.../api/objects/voorzieningen/dienst/export?format=csv'` returns HTTP 200 with valid CSV data
- Gebruik CSV export via API fails: same endpoint for `gebruik` schema returns HTTP 500 (empty response body)
- Excel export not tested from frontend (would require Acties dropdown interaction)
- **BUG**: Gebruik/applicatie export returns HTTP 500 server error

### #144: Overzicht organisaties met zoek- en filteropties - PARTIAL FAIL
**Status:** PARTIAL FAIL
**Findings:**
- Beheer left nav includes "Mijn Organisatie" link
- Navigating to /beheer/my-organisation renders an empty page (left nav present, main content area blank)
- No organisation data displayed for Test Gemeente
- Cannot verify search/filter on organisations overview

### #266: Na inloggen: Mijn account & persoonlijke gegevens leeg? - PASS
**Status:** PASS
**Findings:**
- /beheer/my-account (Account page) displays all personal data:
  - Email: maria.vanderberg@test.nl
  - Voornaam: Maria
  - Tussenvoegsels: van der
  - Achternaam: Berg
  - Organisatie: Test Gemeente
  - Functie: ICT-coordinator
  - Groups displayed
- Data loads within a few seconds
- All fields populated correctly from linked contact person

### #278: Filterteksten aanpassen - PASS
**Status:** PASS
**Findings:**
- Filter labels on /zoeken display correct Dutch text:
  - "Type" (not "Schema" or "Objecttype")
  - "Status"
  - "Samenwerkingstype"
  - "Geregistreerd door"
  - "Leverancier"
  - "Licentievorm"
  - "Referentiecomponenten"
  - "Standaardversies"
  - "Type koppeling"
  - "Organisatietype"
  - "Diensttype"
- "Soort dienst" renamed to "Diensttype" as required
- Filter terminology consistent with management pages

### #280: Zoeken: sorteren gaat niet goed - FAIL
**Status:** FAIL
**Findings:**
- Default sorting "Naam - A naar Z" is correctly set (selected by default)
- Changing sort to "Naam - Z naar A" triggers HTTP 503 from backend (server maintenance mode)
- The sort value format is correct (`name|desc`), API call fires, but server cannot handle the descending sort on 13K+ records
- Sort options available: Meest relevant, Datum oud->nieuw, Datum nieuw->oud, Naam A->Z, Naam Z->A
- **BUG**: Descending sort triggers server timeout/503

### #315: Zoekpagina toont deel van gemeentelijk applicatielandschap - PASS
**Status:** PASS (CLOSED issue)
**Findings:**
- Search results show supplier-provided applications (e.g., "Aangeboden door Syntrophos", "Aangeboden door Utrecht")
- No municipality names appear as "Leverancier" in results
- "Geregistreerd door" filter correctly separates "Gemeente", "Leverancier", "Samenwerking"
- Municipal application landscape data not visible to Maria's role in public results

### #316-#328: Wizard Step Issues - PASS (all)
**Status:** PASS
**Findings from wizard walkthroughs:**
- **#316 (Dienst Step 1):** Dienst search works, form fields load correctly
- **#317 (Dienst Step 2):** Gebruiksinformatie fields present and fillable
- **#318 (Dienst Step 3):** Review page shows all entered data, save works
- **#319 (Koppeling Step 1):** Applicatie search dropdown works (React-Select combobox)
- **#320 (Koppeling Step 2):** Koppeling details fillable (richting, type, etc.)
- **#321 (Koppeling Step 3):** Deelnemer step works
- **#322 (Koppeling Step 4):** Review shows all data, save works
- **#323 (App Step 1):** Applicatie search works ("Centric" -> results appear)
- **#324 (App Step 2):** Gebruiksinformatie fields fillable (status, korte beschrijving)
- **#325 (App Step 3):** Referentiecomponenten step present
- **#326 (App Step 4):** Deelnemer step works
- **#327 (App Step 5):** Review page shows all data, save works
- **#328 (App Step 1.1):** New applicatie creation substep accessible

### #340: Bevindingen op tussenoplevering Zoeken - PARTIAL
**Status:** PARTIAL
**Findings:**
- [PASS] Default sorting is "Naam - A naar Z"
- [PASS] "Type" filter is present (replacing "Schema" filter)
- [PASS] "Soort dienst" renamed to "Diensttype"
- [PASS] Date visible on cards (e.g., "01 januari 2025", "02 maart 2026")
- [FAIL] Filters load slowly (initial page shows "0 resultaten" and "No filters available" for several seconds before enrichment kicks in)
- [FAIL] Sorting after text search untested (Z-A sort crashes server with 503)
- [NOT TESTED] "Meest relevant" tooltip
- [NOT TESTED] Active filter indicator with text search

### #342: Zoeken: op kaartjes referentiecomponenten duidelijk maken - PARTIAL
**Status:** PARTIAL
**Findings:**
- Applicatie cards on page 2+ correctly show "Geschikt voor: [component name]" (e.g., "Geschikt voor: Inspectiecomponent", "Geschikt voor: Callcentercomponent")
- Multiple components shown: "Geschikt voor: Afvalbeheercomponent, Afvalinzamelingcomponent"
- Rollup text for many: "Geschikt voor 5 referentiecomponenten"
- **BUG on page 1**: Koppeling cards on the first page (sorted A-Z) show arrows as titles (←, →, ↔) instead of readable koppeling names. Source/target applications show as "Onbekend". This is because koppeling _name is derived from source->target and the arrow direction, but the source/target UUIDs are not resolved to names.

### #343: Zoeken: Filter 'Type koppeling' toevoegen - PASS
**Status:** PASS
**Findings:**
- "Type koppeling" filter is present on /zoeken
- Filter has exactly two options: "extern (885)" and "intern (2570)"
- Filter is visible to logged-in Maria

### #344: Zoeken: Geen resultaten bij Gravenbeheercomponent - PARTIAL
**Status:** PARTIAL
**Findings:**
- Searching for "Gravenbeheercomponent" in the text search returns 165 results
- However, results are broad matches on "beheer" substring, not specifically "Gravenbeheercomponent"
- First results: "Aansluitingenbeheer E+K", "Accres Groenbeheer", "AIM Beheermodule" etc. -- none actually about Gravenbeheercomponent
- The Referentiecomponenten filter (168 options) should include "Gravenbeheercomponent" if it exists as a reference component
- **Note**: The original issue was about FILTERING by referentiecomponent, not text search. The Type filter works correctly (Applicatie 100, Koppeling 46, Organisatie 19 for this query). The Referentiecomponenten filter was collapsed and not tested individually.

### #346: Zoeken: paginering werkt niet - PASS
**Status:** PASS
**Findings:**
- Page 1 shows koppeling cards (arrows as titles)
- Clicking "Ga naar pagina 2" navigates to `?_page=2` with DIFFERENT results (050media, 12view, 1Password, etc.)
- Each page shows unique, non-overlapping results
- Page indicator correctly shows current page (button "Pagina 2" vs "Ga naar pagina X")
- Total: 656 pages (13,114 / 20 per page)
- Previous/Next navigation buttons present and functional

### #349: Zoeken: UUID's onder standaarden filter - PASS
**Status:** PASS
**Findings:**
- "Standaardversies" filter shows 61 human-readable standard names
- No raw UUIDs visible in the filter dropdown (names resolved via batch names API)
- **Note**: On CARDS, Standaardversies still show as raw UUIDs (e.g., "4edb406c-f544-4b31-b35b-4074e5a79ed9"). The FILTER is fixed but card display is not.

### #350: De link achter de gebruikersnaam verwijzen naar Mijn account - PASS
**Status:** PASS
**Findings:**
- Username "Maria van der Berg (Test Gemeente)" in header links to /beheer/my-account
- Breadcrumb on account page shows "Mijn account"
- User dropdown menu also includes "Maria van der Berg" link to /beheer/my-account

### #353: Mijn account - Je "functie" wordt niet aangepast na bewerken en opslaan - PASS
**Status:** PASS
**Findings:**
- Navigated to /beheer/my-account (or /account)
- Changed "functie" field from "ICT-coordinator" to "ICT Test Coordinator"
- Saved successfully
- Refreshed page -- value persisted as "ICT Test Coordinator"
- Reverted back to "ICT-coordinator" successfully
- No cache clearing needed

### #355: Diensten: Export geeft allerlei UUID's - PARTIAL
**Status:** PARTIAL
**Findings:**
- Dienst CSV export via API returns HTTP 200 with data
- `_modules` and `_aanbieder` columns contain resolved human-readable names (not UUIDs)
- However, the raw `aanbieder` column still contains a UUID alongside the resolved `_aanbieder` column
- **BUG**: Gebruik export returns HTTP 500 (see #15)

### #395: Menu linkerkant verdwijnt - PASS
**Status:** PASS
**Findings:**
- Left navigation menu visible on /beheer dashboard (Dashboard, Mijn Account, Mijn Organisatie, Diensten, Contactpersonen, Applicaties, Gebruik, Koppelingen, View)
- Menu persists when navigating to /beheer/applicaties
- Menu persists when navigating to /beheer/my-organisation
- Menu visible after direct URL navigation (not just SPA navigation)
- **Note**: Initial snapshot rendering didn't show the nav (DOM timing issue), but screenshot confirms it is present

---

## Summary

| Issue | Title | Status | Notes |
|-------|-------|--------|-------|
| #15 | Data exporteren | PARTIAL FAIL | Dienst export works, Gebruik export 500 |
| #144 | Overzicht organisaties | PARTIAL FAIL | My-organisation page blank |
| #266 | Mijn account leeg na inloggen | PASS | All fields populated |
| #278 | Filterteksten aanpassen | PASS | All filter labels correct |
| #280 | Sorteren gaat niet goed | FAIL | Z-A sort triggers 503 |
| #315 | Gemeentelijk applicatielandschap | PASS | Closed, supplier data correct |
| #316 | Dienst wizard Step 1 | PASS | Wizard completed |
| #317 | Dienst wizard Step 2 | PASS | Wizard completed |
| #318 | Dienst wizard Step 3 | PASS | Wizard completed |
| #319 | Koppeling wizard Step 1 | PASS | Wizard completed |
| #320 | Koppeling wizard Step 2 | PASS | Wizard completed |
| #321 | Koppeling wizard Step 3 | PASS | Wizard completed |
| #322 | Koppeling wizard Step 4 | PASS | Wizard completed |
| #323 | App wizard Step 1 | PASS | Wizard completed |
| #324 | App wizard Step 2 | PASS | Wizard completed |
| #325 | App wizard Step 3 | PASS | Wizard completed |
| #326 | App wizard Step 4 | PASS | Wizard completed |
| #327 | App wizard Step 5 | PASS | Wizard completed |
| #328 | App wizard Step 1.1 | PASS | Substep accessible |
| #340 | Bevindingen zoeken | PARTIAL | Filters slow, sort crashes |
| #342 | Referentiecomponenten op kaartjes | PARTIAL | Works on apps, broken on koppelingen |
| #343 | Filter Type koppeling | PASS | extern/intern present |
| #344 | Gravenbeheercomponent | PARTIAL | Text search too broad |
| #346 | Paginering werkt niet | PASS | Different results per page |
| #349 | UUIDs in standaarden filter | PASS | Filter shows names, cards still UUIDs |
| #350 | Link gebruikersnaam | PASS | Points to /beheer/my-account |
| #353 | Functie niet aangepast | PASS | Persists after save+refresh |
| #355 | Export geeft UUIDs | PARTIAL | Resolved names in export but raw UUID in aanbieder column |
| #395 | Menu linkerkant verdwijnt | PASS | Menu persists across navigations |

**Totals:** 20 PASS, 5 PARTIAL, 1 PARTIAL FAIL, 2 FAIL, 1 PARTIAL FAIL

---

## Critical Bugs Found

1. **Gebruik/Applicatie CSV export returns HTTP 500** (#15) - Server error when exporting gebruik objects
2. **Descending sort (Z-A) triggers HTTP 503** (#280) - Server enters maintenance mode on 13K+ record sort
3. **Koppeling cards show arrows as titles** (#342) - Source/target application names not resolved, displayed as "Onbekend"
4. **Standaardversies UUIDs on cards** (#349) - Filter dropdown resolved but card display still shows raw UUIDs
5. **My Organisation page blank** (#144) - /beheer/my-organisation renders no content
6. **Initial page render shows "0 resultaten" and "Geen titel"** (#340) - Several seconds of broken display before enrichment kicks in

---

## Screenshots

Key screenshots from this session:
- `zoeken-full-page.png` - Search page with 13,114 results, arrow titles on koppelingen
- `zoeken-gravenbeheer-search.png` - Search for "Gravenbeheercomponent" showing 165 broad results
- `beheer-no-left-nav.png` - Beheer dashboard (left nav visible in screenshot despite snapshot timing)
- `wizard-gemeente-app-success.png` - Applicatie wizard success
- `wizard-gemeente-dienst-success.png` - Dienst wizard success
- `wizard-gemeente-koppeling-success.png` - Koppeling wizard success
- `beheer-gebruik-table.png` - Gebruik table with Centric Betalen
- `beheer-diensten-table.png` - Diensten table with Test Gemeente Dienst
- `beheer-koppelingen-table.png` - Koppelingen table with test koppeling
- `account-page-before-edit.png` - Account page showing all user details

---

## Test Data Cleanup

The following test objects were created during wizard walkthroughs and should be cleaned up:
- **Gebruik:** "Centric Betalen" (in voorzieningen/gebruik)
- **Dienst:** "Test Gemeente Dienst" (in voorzieningen/dienst)
- **Koppeling:** "Centric Betalen -> MijnOverheid.nl" (in voorzieningen/koppeling)
