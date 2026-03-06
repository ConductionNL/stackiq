# GEMMA Softwarecatalogus — Test Results Summary

**Date:** 2026-03-02 (Run #8)
**Previous Runs:** 2026-02-23 through 2026-03-01 (Runs #1-7)
**Environment:** http://localhost:3000 (Frontend), http://localhost:8080 (Backend)
**Method:** Combined API tests (Newman/Postman) + Browser tests (6 parallel persona agents via Playwright MCP)
**Nextcloud Version:** 32.0.5

---

## Overall Results

### API Tests (Newman)

| Metric | Value |
|--------|-------|
| Requests | 134 |
| Assertions | 150 |
| Passed | 122 (81.3%) |
| Failed | 28 (18.7%) |
| Duration | 29.8s |
| Avg Response Time | 202ms |

### Browser Tests (6 Persona Agents)

| Status | Count | Percentage |
|--------|-------|------------|
| **PASS** | 85 | 73% |
| **PARTIAL** | 12 | 10% |
| **FAIL** | 3 | 3% |
| **CANNOT_TEST** | 13 | 11% |
| **BLOCKED** | 2 | — |
| **MOVED** | 8 | — |
| **SKIPPED** | 1 | — |
| **NOT_VERIFIED** | 1 | — |
| **Total tested** | 125 | — |

*Note: Some issues are tested by multiple agents. Counts reflect per-agent test instances. BLOCKED/MOVED/SKIPPED/NOT_VERIFIED excluded from percentages.*

### Combined Coverage

| Method | Unique Issues | PASS | PARTIAL | FAIL | CANNOT_TEST |
|--------|---------------|------|---------|------|-------------|
| API (Newman) | ~35 | 22 | 0 | 13 | 0 |
| Browser (6 agents) | ~75 | 85 | 12 | 3 | 13 |
| **Combined** | **~100** | — | — | — | — |

---

## FAIL Issues (Requires Attention)

### API FAIL Issues (13 unique)

| Issue | Title | Severity | Summary |
|-------|-------|----------|---------|
| #144 | Search functionality | HIGH | `/api/publications?_search=Test` returns 404 |
| #315 | geregistreerdDoor field | MEDIUM | Field missing from applicatie response |
| #105 | RBAC org scoping | HIGH | API results not scoped to user's org |
| #300 | Org-scoped listing | HIGH | Objects from other orgs returned |
| #307 | Diensten org filter | HIGH | Cross-org diensten visible via API |
| #394 | Public applicatie access | MEDIUM | Public access returns error |
| #6 | Standards compliance | MEDIUM | Cannot set standaardversies |
| #65 | Contactpersonen org scope | MEDIUM | Cross-org contacts visible via API |
| #382 | URL field | MEDIUM | URL not saved correctly |
| #400 | Koppeling CRUD | HIGH | All koppeling API operations fail |
| #437 | Leverancier koppeling | HIGH | Leverancier cannot create koppeling via API |
| #148, #160, #413 | Views endpoint | HIGH | Views returns 500 error via API |
| #419 | Standaardversies field | MEDIUM | Field not accessible |

### Browser FAIL Issues (3 unique)

| Issue | Title | Severity | Agent | Summary |
|-------|-------|----------|-------|---------|
| **#312** | Koppeling heeft verplicht een naam | HIGH | Leverancier | Koppelingen from Applicatie wizard use UUID-based names. Dedicated Koppeling wizard works correctly. |
| **#342** | Zoeken: referentiecomponenten op kaartjes | MEDIUM | Gemeente | Cards show "Geschikt voor:" but no "+N meer" count for multiple referentiecomponenten. |
| **#392** | Geimporteerde gebruiker error | HIGH | Func. Beheerder | SQL not-null violation on "afnemer" column when creating contactpersoon for imported org. |

---

## CANNOT_TEST Issues

| Issue | Title | Agent | Reason |
|-------|-------|-------|--------|
| #294 | Applicatie publiceren: uitlijning | Leverancier | Referentiecomponenten step blocked by 500 error |
| #300 | Beheer: overzicht applicaties | Leverancier | /beheer/applicaties blocked by `_extend[]=moduleVersies` 500 |
| #302 | Beheer: applicatie bewerken | Leverancier | Applicaties beheer table not loading |
| #370 | Applicatie: teveel kolommen | Leverancier | Applicaties table not loading |
| #376 | Applicaties: labels wizard/tabel | Leverancier | Cannot compare (table doesn't load) |
| #377 | Applicaties: tabel toont diensten niet | Leverancier | Applicaties table not loading |
| #378 | Applicatie: standaarden na wijzigen | Leverancier | Cannot access edit flow |
| #399 | Versies: andere leverancier foutmelding | Leverancier | Second vendor app not navigated |
| #365 | Contactpersonen: error bij opslaan | Leverancier | Not tested to avoid modifying data |
| #366 | Contactpersonen: veld Rollen | Leverancier | Rollen column not visible |
| #105 | Aanbieders applicatielandschappen | Leverancier | Route not available for aanbod-beheerder |
| Import dialog | Register import workflow | Func. Beheerder | Not exercised in browser session |
| #286 | Wachtwoord wijzigen via UI | Gemeente | Admin-level test, moved to func. beheerder |

---

## Results by Agent

### 1. Leverancier — Jan Pietersen (Aanbod-beheerder)

| PASS | PARTIAL | FAIL | CANNOT_TEST | BLOCKED | MOVED | SKIPPED |
|------|---------|------|-------------|---------|-------|---------|
| 40 | 1 | 1 | 10 | 2 | 4 | 1 |

**Key findings:**
- 3 of 4 wizard flows completed (Applicatie, Dienst, Koppeling). Wizard 4 (Gebruik) blocked by `_extend[]=moduleVersies` 500 error
- **Critical**: `_extend[]=moduleVersies` causes 500 — blocks /beheer/applicaties table AND wizard 4
- **Critical**: Referentiecomponenten API with `_extend` returns 500 (infinite retry loop in wizard step 4)
- #375 FIXED: SaaS applications get default version 1.0.0 automatically
- #373 FIXED: Diensten visible on applicatie detail page
- #352 FIXED: /account page works with full user data
- #312 FAIL: Applicatie wizard creates koppelingen with UUID names

### 2. Gemeente — Maria van der Berg (Gebruik-beheerder)

| PASS | PARTIAL | FAIL | CANNOT_TEST | MOVED | NOT_VERIFIED |
|------|---------|------|-------------|-------|--------------|
| 17 | 3 | 1 | 2 | 3 | 1 |

**Key findings:**
- All 3 mandatory wizard flows completed — all objects verified in beheer tables
- #328 PASS: "Ik kan de gewenste applicatie niet vinden" sub-step works (was CANNOT_TEST)
- CSV and Excel export verified with dual-column format (UUIDs + readable names)
- RBAC working: 0 municipality data visible to unauthenticated users
- Koppeling detail pages show `[object Object]` for Applicatie B (rendering bug)
- Municipality names (Amersfoort) appear in Leverancier filter (data quality)

### 3. Security Officer — Mark Jansen

| PASS | PARTIAL | FAIL | CANNOT_TEST | MOVED |
|------|---------|------|-------------|-------|
| 8 | 0 | 0 | 0 | 1 |

**Key findings:**
- All 8 testable security issues PASS
- RBAC verified: 0 contactpersonen unauthenticated vs 1,616 authenticated
- Unauthenticated search: 1,872 results; Authenticated: 12,692 (RBAC tightened)
- No koppelingen or gebruik visible to unauthenticated users
- SiteImprove removed, Piwik Pro present
- #315 upgraded from PARTIAL to PASS (municipal landscape correctly hidden)

### 4. Functioneel Beheerder — Peter van Dijk (Admin)

| PASS | PARTIAL | FAIL | CANNOT_TEST |
|------|---------|------|-------------|
| 19 | 4 | 1 | 1 |

**Key findings:**
- #403 UPGRADED to PASS: Frontend delete dialog shows type-specific text with in-use check
- #225 UPGRADED to PASS: No "+" button on other organizations' pages
- #169 UPGRADED to PARTIAL: /beheer/my-account works with name, email, org, functie
- #141 PARTIAL: Merge dialog fully functional (2-step with property comparison), not executed
- #392 FAIL: SQL not-null violation on "afnemer" when creating contactpersoon for imported org
- All exports (CSV/Excel) work with human-readable columns

### 5. Samenwerking — Linda Bakker

| PASS | PARTIAL | FAIL |
|------|---------|------|
| 0 | 2 | 0 |

**Key findings:**
- **Major improvement**: Wizard buttons now visible on dashboard (was "Geen wizards beschikbaar")
- TypeError crash on org switch confirmed FIXED
- Applicatie wizard broken by schema slug mismatch ("applicatie" vs correct "module")
- Organisation action buttons all disabled (org UUID mismatch)
- Member municipality delegation not implemented
- Koppeling type filter returns 0 results despite 3,427 koppelingen in API

### 6. Architectuur Expert — Dr. Sarah de Vries (VNG-raadpleger)

| PASS | PARTIAL | FAIL |
|------|---------|------|
| 1 | 2 | 0 |

**Key findings:**
- **#160 PASS**: 35x performance improvement! Benchmark view: 346ms (was 12.19s, target: 11s)
- API response: 141ms (was 512ms) — 3.6x faster
- OAS endpoint for register 4 FIXED (was 500, now 200)
- Empty properties on elements FIXED (was 80+ nulls, now only populated fields)
- Views listing page empty despite 249 views in API (UI bug)
- model-id filter not implemented; "Gemma downloaden" button missing

---

## Critical Findings

### 1. `_extend[]=moduleVersies` Causes 500 (NEW — Critical)
**Impact**: Blocks /beheer/applicaties table (shows "Geen data gevonden") and Wizard 4 (Applicatiegebruik). Any API call with `_extend[]=moduleVersies` fails. This is the most impactful regression — leveranciers cannot see or manage their applications.

### 2. RBAC Not Enforced on API Layer
API tests show RBAC not scoped correctly (#105, #300, #307). Leverancier user sees objects from ALL organizations via direct API calls. **However**, the publication/search layer (tested by Security Officer) correctly enforces RBAC — unauthenticated users see only 1,872 of 12,692 total records.

### 3. Views Endpoint Returns 500 via API
All three view-related issues (#148, #160, #413) return 500 via Newman API tests. **However**, individual view pages work correctly in the browser (Architectuur Expert confirmed 346ms load time). The views listing page is empty despite 249 views existing.

### 4. Koppeling CRUD Broken via API (#400, #437)
All koppeling creation/save/list operations fail via Newman API tests. **However**, the Koppeling wizard in the browser creates koppelingen successfully (Leverancier confirmed).

### 5. SQL Not-Null Violation on Contact Person Creation (#392)
Creating a contactpersoon for an imported organisation triggers `SQLSTATE[23502]: Not null violation` on the "afnemer" column. The system does not auto-populate this field.

---

## Improvements Since Run #7 (2026-03-01)

| Issue | Title | Previous | Current | Agent |
|-------|-------|----------|---------|-------|
| #160 | Performance plotten views | PARTIAL (12.19s) | **PASS** (346ms) | Architectuur |
| #315 | Gemeentelijk applicatielandschap | PARTIAL | **PASS** | Security |
| #352 | Mijn account contactpersoon | FAIL | **PASS** | Leverancier |
| #57 | Pakketten samenwerkingsverband | PARTIAL (no wizards) | **PARTIAL** (wizards visible) | Samenwerking |
| #148 | GEMMA API (empty properties) | PARTIAL (80+ nulls) | **PARTIAL** (fixed) | Architectuur |
| #135/89 | Toegankelijkheid | PARTIAL | **PASS** | Architectuur |
| #328 | Nieuwe applicatie opvoeren (stap 1.1) | CANNOT_TEST | **PASS** | Gemeente |
| #392 | Geimporteerde gebruiker error | SKIPPED | **FAIL** (now tested) | Func. Beheerder |

---

## Regressions

### Primary Regression: `_extend[]=moduleVersies` 500 Error
This new backend error causes 6 cascading CANNOT_TEST results for the Leverancier agent:
- #300, #302, #370, #376, #377, #378 — all depend on the applicaties beheer table loading
- Additionally blocks Wizard 4 (Applicatiegebruik melden) completely
- Endpoint: `GET /api/objects/voorzieningen/module?_extend[]=moduleVersies`

---

## Bugs Found in This Session

| # | Bug | Severity | Agent(s) | Location |
|---|-----|----------|----------|----------|
| BUG-1 | `_extend[]=moduleVersies` causes 500 | CRITICAL | Leverancier | /api/objects/voorzieningen/module |
| BUG-2 | Referentiecomponenten API with `_extend` returns 500 | HIGH | Leverancier | /api/objects/vng-gemma/element |
| BUG-3 | Koppeling detail shows `[object Object]` for Applicatie B | MEDIUM | Gemeente | /publicatie/{koppeling-uuid} |
| BUG-4 | Koppeling h1 title shows UUID instead of name | MEDIUM | Samenwerking, Gemeente | /publicatie/{koppeling-uuid} |
| BUG-5 | "null" literal text in koppeling visual display | MEDIUM | Samenwerking | /publicatie/{koppeling-uuid} |
| BUG-6 | Standaardversies on search cards show raw UUIDs | MEDIUM | Gemeente, Samenwerking | /zoeken — /api/names/ returns 404 |
| BUG-7 | Municipality names in Leverancier filter | HIGH | Gemeente, Security | /zoeken — import data quality |
| BUG-8 | Organisation object 404 on every page load | LOW | All agents | Console — org UUID mismatch |
| BUG-9 | Applicatie wizard uses wrong schema slug "applicatie" vs "module" | HIGH | Samenwerking | /forms/gebruik/applicatie |
| BUG-10 | Koppeling type filter returns 0 results | HIGH | Samenwerking | /zoeken?type=koppeling |
| BUG-11 | Views listing page empty despite 249 views | MEDIUM | Architectuur | /beheer/views |
| BUG-12 | SQL not-null violation on "afnemer" column | HIGH | Func. Beheerder | POST /api/objects/3/16 |
| BUG-13 | Organisation action buttons all disabled for samenwerking | MEDIUM | Samenwerking | /beheer/my-organisation |

---

## Console Errors Overview

| Agent | Pages Checked | Pages with Errors | Most Frequent Error |
|-------|--------------|-------------------|---------------------|
| Leverancier | ~20 | ~8 | 500 for `_extend[]=moduleVersies` |
| Gemeente | ~15 | ~8 | 404 for organisation object, /api/names/ |
| Security Officer | ~10 | ~5 | 404 for organisation object |
| Func. Beheerder | ~15 | ~3 | @nextcloud/vue warnings |
| Samenwerking | ~10 | ~8 | 404 for organisation object, schema not found |
| Architectuur | ~10 | ~2 | 404 for CMS page, schema endpoint |

**Most frequent**: Organisation object 404 on every authenticated page (org UUID mismatch between Nextcloud and register).

---

## Performance Overview

All agents reported acceptable performance. The most notable finding is the **35x improvement** on ArchiMate view rendering.

| Metric | Run #7 (2026-03-01) | Run #8 (2026-03-02) | Status |
|--------|---------------------|---------------------|--------|
| Benchmark view (388 nodes) | 12.19s | **346ms** | PASS (target: 11s) |
| View API response | 512ms | **141ms** | PASS (target: 500ms) |
| Search page (12,699 results) | ~5s | ~5s | OK |
| Wizard steps | < 2s | < 2s | OK |
| Export (CSV/Excel) | < 5s | < 2s | OK |
| Detail pages | < 3s | < 3s | OK |
| Newman avg response | N/A | 202ms | OK |

---

## Environment Limitations

1. **Local dev only**: Tests run against localhost, not production/acceptance environments
2. **Single browser engine**: Playwright/Chromium only — Edge/Firefox differences (#402) untestable
3. **WSL2 overhead**: Performance may differ from native target machine
4. **No email delivery**: Password reset OTP codes cannot be verified end-to-end
5. **No Archi client**: ArchiMate XML import roundtrip not verifiable
6. **Piwik Pro unconfigured**: Empty srcUrl/dataLayerName/id on localhost (expected)

---

## Recommendations

### Immediate (Critical)
1. **Fix `_extend[]=moduleVersies` 500** — This blocks the applicaties beheer table and Wizard 4. Likely a `stripEmptyValues()` receiving ObjectEntity instead of array.
2. **Fix #392 SQL not-null violation** — Populate "afnemer" column automatically when creating contactpersoon for imported org.
3. **Fix Referentiecomponenten API 500** — `_extend[]=aanbevolenStandaarden` on vng-gemma/element causes infinite retry in wizard step 4.

### High Priority
4. **Fix #312 koppeling names** — Populate `naam` field when creating koppelingen via Applicatie wizard (same logic as Koppeling wizard).
5. **Fix koppeling rendering bugs** — `[object Object]` for Applicatie B (#BUG-3), "null" literal (#BUG-5), h1 UUID (#BUG-4).
6. **Fix schema slug mismatch** — Samenwerking applicatie wizard queries "applicatie" but correct slug is "module" (#BUG-9).
7. **Fix koppeling type filter** — Search with `?type=koppeling` returns 0 results despite 3,427 koppelingen (#BUG-10).
8. **Fix views listing page** — /beheer/views shows empty despite 249 views in API (#BUG-11).

### Before Next Test Run
9. **Fix /api/names/ endpoint** — Returns 404 for standaardversie UUIDs, causing raw UUIDs on search cards.
10. **Clean Leverancier filter data** — Remove municipality names and raw UUIDs from the Leverancier facet (import data quality).
11. **Fix org UUID mapping** — Resolve Nextcloud org UUID vs register object UUID mismatch (persistent 404 on every page).
12. **Configure Piwik Pro** — Set srcUrl, dataLayerName, id for production environments.
13. **Enable organisation actions for samenwerking** — Fix disabled Bewerk/Deelnames buttons on Mijn Organisatie.

---

## Detailed Results

Full per-agent results with acceptance criteria, screenshots, and evidence:

| Agent | Results File |
|-------|-------------|
| API (Newman) | [api/results.md](api/results.md) |
| Leverancier | [leverancier/results-authenticated.md](leverancier/results-authenticated.md) |
| Gemeente | [gemeente/results-authenticated.md](gemeente/results-authenticated.md) |
| Security Officer | [security-officer/results-authenticated.md](security-officer/results-authenticated.md) |
| Functioneel Beheerder | [functioneel-beheerder/results-authenticated.md](functioneel-beheerder/results-authenticated.md) |
| Samenwerking | [samenwerking/results-authenticated.md](samenwerking/results-authenticated.md) |
| Architectuur Expert | [architectuur-expert/results-authenticated.md](architectuur-expert/results-authenticated.md) |
