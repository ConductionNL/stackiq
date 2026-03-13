# GEMMA Softwarecatalogus — Test Results Summary

**Date:** 2026-03-10 (Run #10)
**Previous Runs:** 2026-02-23 through 2026-03-10 (Runs #1-9)
**Environment:** http://localhost:3000 (Frontend), http://localhost:8080 (Backend)
**Method:** Browser tests (7 persona agents in parallel) + API tests (Newman, 454 assertions)

---

## Overall Results

| Status | Count | Percentage |
|--------|-------|------------|
| **PASS** | 74 | 60% |
| **PARTIAL** | 19 | 15% |
| **FAIL** | 12 | 10% |
| **CANNOT_TEST** | 18 | 15% |
| **Total tested** | 123 | — |

**API Tests:** 453/454 assertions passed (99.8%)

---

## FAIL Issues (Requires Attention)

| Issue | Title | Severity | Agent | Summary |
|-------|-------|----------|-------|---------|
| #447 | Concept leverancier direct vindbaar | CRITICAL | Bezoeker, Security | 18 concept-status organisations publicly visible without VNG approval |
| #160 | Performance plotten views | CRITICAL | Architectuur | ArchiMate view rendering completely broken — JointJS SVGMatrix errors, blank SVGs |
| #135 | Non-functionele eisen Referentiearchitectuur | HIGH | Architectuur | Views don't render, AMEFF export `falset()` typo crash |
| #280 | Sorteren gaat niet goed | HIGH | Gemeente | Z-A sort triggers HTTP 503 (server maintenance mode on 13K+ records) |
| #448 | Overzichtspagina's vormgeving | HIGH | Bezoeker | Dienst detail page has completely different/minimal layout vs Applicatie |
| #453 | Filters van slag met Type filter | HIGH | Bezoeker | Facet counts do not re-scope after selecting a Type filter |
| #455 | Koppelingen/contactpersonen niet publiek getoond | HIGH | Bezoeker, Security | Koppelingen and Contactpersonen tabs absent from public detail pages |
| #312 | Koppeling verplicht een naam | MEDIUM | Leverancier | App-wizard koppelingen use UUID-based names instead of proper names |
| #403 | Tekst verwijderen aanpassen | MEDIUM | Func. Beheerder | Delete dialog uses generic English text, no type differentiation |
| #187 | Tekstvoorstellen | MEDIUM | Func. Beheerder | Text changes not implemented, English text in dialogs/charts |
| #144 | Overzicht organisaties | MEDIUM | Gemeente | /beheer/my-organisation page renders blank |
| #15 | Data exporteren | MEDIUM | Gemeente | Gebruik/applicatie CSV export returns HTTP 500 |

---

## CANNOT_TEST Issues (Blocked)

| Issue | Title | Agent | Reason |
|-------|-------|-------|--------|
| #294 | Uitlijning rechthoek | Leverancier | Referentiecomponenten step blocked by 500 error |
| #300 | Overzicht teveel applicaties | Leverancier | Applicaties table blocked by `_extend` 500 / stale org UUID |
| #302 | Applicatie bewerken | Leverancier | Applicaties table not loading |
| #370 | Teveel kolommen | Leverancier | Applicaties table not loading |
| #376 | Labels wizard en tabel anders | Leverancier | Cannot compare — table blocked |
| #377 | Tabel toont diensten niet | Leverancier | Applicaties table blocked |
| #378 | Standaarden na wijzigen | Leverancier | Cannot access edit flow |
| #399 | Versie andere leverancier | Leverancier | Not navigated to other vendor's app |
| #443 | Diensttypen aan elkaar geschreven | Bezoeker | Diensttype field not rendered on detail page (blocked by #448) |
| #365 | Contactpersonen error opslaan | Leverancier | Not tested to avoid modifying data |
| #366 | Rollen niet consistent | Leverancier | Rollen column not visible |
| #410 | Dashboard schrijfwijze | Func. Beheerder | Softwarecatalog app shows "Update needed" |
| #141 | Organisaties samenvoegen | Func. Beheerder | PostgreSQL OOM crash |
| #449 | Handleiding facets | Func. Beheerder | Server crash |
| #450 | Icoon publiceren verwijderen | Func. Beheerder | Peter has 0 publications |
| #105 | Applicatielandschappen niet zichtbaar | Leverancier | Route does not exist for aanbod-beheerder |
| #391 | Testen bestaande org gebruiker | Leverancier | No second leverancier user available |
| #451 | UUIDs bij standaardversies | Leverancier | Koppeling without standards tested |

---

## Results by Agent

### 1. Leverancier — Jan Pietersen (browser-1)
| PASS | PARTIAL | FAIL | CANNOT_TEST |
|------|---------|------|-------------|
| 41 | 2 | 1 | 17 |

Key findings:
- All 4 wizard flows completed successfully (including gebruik wizard — now works)
- #312 FAIL: App-wizard koppelingen still use UUID names
- All beheer tables blocked by stale org UUID regression
- Found & fixed PublicationsController `$extend` parameter crash

### 2. Gemeente — Maria van der Berg (browser-2)
| PASS | PARTIAL | FAIL | CANNOT_TEST |
|------|---------|------|-------------|
| 20 | 5 | 2 | 0 |

Key findings:
- All 3 wizard walkthroughs (Applicatie, Dienst, Koppeling) completed successfully
- #280 FAIL: Z-A sort triggers 503 on 13K+ records
- #15 PARTIAL FAIL: Gebruik export returns 500; dienst export works
- Koppeling cards show arrows as titles with "Onbekend" names

### 3. Security Officer — Mark Jansen (browser-3)
| PASS | PARTIAL | FAIL | CANNOT_TEST |
|------|---------|------|-------------|
| 8 | 2 | 1 | 0 |

Key findings:
- RBAC enforcement solid: gemeente contacts hidden, leverancier apps visible
- #447 FAIL: 18 concept organisations visible publicly — security risk
- Intermittent 500 on search causes ghost cards ("Geen titel")

### 4. Functioneel Beheerder — Peter van Dijk (browser-4)
| PASS | PARTIAL | FAIL | CANNOT_TEST |
|------|---------|------|-------------|
| 12 | 4 | 2 | 4 |

Key findings:
- #155 FIXED: Glossary external links and keywords both work now
- #397 FIXED: CMS pages now accessible
- #278 FIXED: Search filters visible and working (was 500)
- #403 FAIL: Delete dialog English text, no type differentiation
- PostgreSQL OOM errors caused server crashes during testing

### 5. Samenwerking — Linda Bakker (browser-5)
| PASS | PARTIAL | FAIL | CANNOT_TEST |
|------|---------|------|-------------|
| 0 | 2 | 0 | 0 |

Key findings:
- #57 PARTIAL (improved): Org restored, wizards visible, 5/6 criteria pass
- #186 PARTIAL (improved): Detail pages work again (was 500), but arrow-only headings persist
- Confirmed arrow/null display bug affects even NEW koppelingen (not just legacy data)

### 6. Bezoeker — Anonymous Visitor (browser-6)
| PASS | PARTIAL | FAIL | CANNOT_TEST |
|------|---------|------|-------------|
| 5 | 2 | 4 | 1 |

Key findings:
- #394 PASS: Contactpersonen RBAC correctly enforced (0 results unauthenticated)
- #447 FAIL: 18 concept orgs publicly visible
- #448 FAIL: Dienst detail page completely different layout from Applicatie
- #453 FAIL: Facet counts don't re-scope when filtering

### 7. Architectuur Expert — Dr. Sarah de Vries (browser-7)
| PASS | PARTIAL | FAIL | CANNOT_TEST |
|------|---------|------|-------------|
| 0 | 1 | 2 | 0 |

Key findings:
- #148 PARTIAL: APIs work well (fast responses), but GEMMA download CORS error
- #160 FAIL: SVG view rendering completely broken (JointJS SVGMatrix errors)
- #135 FAIL: AMEFF export crashes on `falset()` typo in ArchiMateExportService.php:1214

---

## Critical Findings

1. **#447 — Concept organisations publicly visible (SECURITY):** 18 "Concept" status organisations are searchable without VNG triage/approval. Needs RBAC rule or status filter to exclude concept items from public results.

2. **#160/#135 — ArchiMate views completely broken:** JointJS SVGMatrix errors prevent any view from rendering. AMEFF export also crashes (`falset()` typo). The entire Referentiearchitectuur component is non-functional.

3. **Stale org UUID regression (Leverancier):** All beheer tables for the leverancier persona show "Geen data gevonden" because the frontend uses stale org UUID `fd62b364...` which returns 404. This blocks ~17 issues from being tested.

4. **#280 — Sort crash on large datasets:** Descending sort (Z-A) on 13,000+ records triggers HTTP 503 (server maintenance mode). Database cannot handle the query.

5. **#453 — Faceting scoping broken:** After selecting a Type filter, other facet counts don't update to reflect the filtered subset — they still show full-dataset counts.

---

## Improvements Since Last Run

| Issue | Title | Previous | Current | Agent |
|-------|-------|----------|---------|-------|
| #155 | Begrippenlijst | PARTIAL | **PASS** | Func. Beheerder |
| #397 | CMS pagina's | CANNOT_TEST | **PASS** | Func. Beheerder |
| #278 | Filterteksten | CANNOT_TEST | **PASS** | Func. Beheerder, Gemeente |
| #57 | Samenwerking pakketten | PARTIAL (regressed) | **PARTIAL** (improved) | Samenwerking |
| #186 | Koppelingen | PARTIAL (regressed) | **PARTIAL** (improved) | Samenwerking |
| #352 | Mijn account | FAIL | **PASS** | Leverancier |
| #375 | SaaS versie | FAIL | **PASS** | Leverancier |
| #373 | Gekoppelde diensten | FAIL | **PASS** | Leverancier |
| Gebruik wizard | — | BLOCKED | **PASS** | Leverancier |

---

## Regressions

| Issue | Title | Previous | Current | Agent |
|-------|-------|----------|---------|-------|
| Beheer tables (leverancier) | All tables | Working | **BLOCKED** | Leverancier |
| #410 | Dashboard schrijfwijze | PASS | **CANNOT_TEST** | Func. Beheerder |

---

## Environment Limitations

- PostgreSQL OOM errors (`SQLSTATE[53200]`) caused server crashes during Func. Beheerder testing
- Softwarecatalog Nextcloud app shows "Update needed" — blocks #410 testing
- Frontend `ENABLE_AUTHENTICATION=false` means Architectuur Expert tests are effectively unauthenticated
- Single Chromium engine prevents #402 (Edge vs Chrome) testing
- Leverancier's stale org UUID blocks all beheer table tests (~17 issues)

---

## Recommendations

### Immediate (Security)
1. Add RBAC rule to exclude `status=Concept` organisations from public search (#447)
2. Clean up 18 test concept organisations from the dataset

### High Priority
3. Fix ArchiMate SVG rendering — investigate JointJS SVGMatrix NaN/Infinity errors (#160)
4. Fix `falset()` typo in `ArchiMateExportService.php:1214` (#135)
5. Fix Z-A sort timeout on large datasets — add database index or pagination (#280)
6. Fix facet count re-scoping after Type filter selection (#453)
7. Fix dienst detail page layout to match applicatie template (#448)

### Medium Priority
8. Fix koppeling heading display — use `naam` field for h1, not constructed arrow (#186, #312)
9. Translate delete dialog to Dutch with type-specific text (#403)
10. Fix stale org UUID issue for leverancier persona (investigate frontend org caching)
11. Fix gebruik/applicatie CSV export 500 error (#15)
12. Fix GEMMA download CORS error — route through frontend proxy (#148)

### Before Next Test Run
13. Run `CLEANUP_DUPLICATES=1 bash test-setup.sh` to clean up test data
14. Restart Apache after org UUID changes: `docker exec nextcloud apache2ctl graceful`
15. Increase PostgreSQL shared_buffers to prevent OOM errors
