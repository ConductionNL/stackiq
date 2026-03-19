# GEMMA Softwarecatalogus — Test Results Summary

**Date:** 2026-03-19
**Environment:** http://localhost:3000 (Frontend), http://localhost:8080 (Backend)
**Method:** Combined API tests (Newman) + Browser tests (7 persona agents)

---

## Overall Results

| Status | Count | Percentage |
|--------|-------|------------|
| **PASS** | 62 | 41% |
| **PARTIAL** | 23 | 15% |
| **FAIL** | 11 | 7% |
| **CANNOT_TEST** | 32 | 21% |
| **CLOSED/OTHER** | 24 | 16% |
| **Total evaluated** | 152 | — |

**API Tests (Newman):** 456/456 assertions passed (100%)

---

## FAIL Issues (Requires Attention)

| Issue | Title | Severity | Agent | Summary |
|-------|-------|----------|-------|---------|
| #345 | Dienst verschijnt niet in filters | HIGH | Bezoeker | Diensten not published as OpenCatalogi publications — missing from search entirely |
| #455 | Tabblad koppelingen/contactpersonen niet getoond | MEDIUM | Security, Bezoeker | Public detail pages hide koppelingen/contactpersonen tabs — RBAC design decision needed |
| #440 | Organisatietype teveel aan opties | MEDIUM | Bezoeker | Filter shows only 2 of 4 expected organisatietype options |
| #403 | Delete dialog toont UUID | MEDIUM | Func. Beheerder | Delete dialog shows raw UUID instead of object name |
| #412 | Niet alle AMEF views hebben documentatie | MEDIUM | Architectuur | All 4 checked views lack documentation field |
| #316 | Wizard tekst applicatie toevoegen | LOW | Gemeente | Wizard text mismatches vs PowerPoint reference |
| #317 | Wizard tekst dienst toevoegen | LOW | Gemeente | Wizard text mismatches vs PowerPoint reference |
| #319 | Wizard tekst koppeling toevoegen | LOW | Gemeente | Wizard text mismatches vs PowerPoint reference |
| #349 | Standaardversies tonen UUIDs | MEDIUM | Gemeente | Standaardversies display as UUIDs instead of names |

---

## CANNOT_TEST Issues (Blocked)

| Issue | Agent | Reason |
|-------|-------|--------|
| #347, #443, #444, #448, #453, #205, #398, #438 | Bezoeker | Diensten/koppelingen not published as catalog publications |
| #160 | Architectuur | View rendering broken in frontend (`/beheer/views` shows empty) |
| #447 | Security | No concept-status test data available |
| 14 issues | Func. Beheerder | Beheer pages blocked by org UUID mapping (Default Organisation has no register object) |
| 5 issues | Gemeente | Schema loading bug (wrong URL path `/api/openregister/` vs `/api/apps/openregister/`) |
| Various | Leverancier | Frontend switched mid-test (another agent rebuilt it) |

---

## Results by Agent

### 1. Leverancier — Jan Pietersen (75 issues)
| PASS | PARTIAL | FAIL | CANNOT_TEST | CLOSED |
|------|---------|------|-------------|--------|
| 22 | 4 | 3 | 25 | 39+ |

Key findings:
- Applicatie wizard creates object but returns 500 (named parameter bug — **already fixed this session**)
- Koppeling wizard "Volgende" stays disabled despite filled fields
- RBAC scoping now correctly shows only own-org data
- Frontend switched to "Gemeente" theme mid-test

### 2. Gemeente — Maria van der Berg (27 issues)
| PASS | PARTIAL | FAIL | CANNOT_TEST |
|------|---------|------|-------------|
| 10 | 8 | 4 | 5 |

Key findings:
- Critical schema loading bug: wrong URL path blocks gemeente wizards
- Dienst wizard works end-to-end
- Wizard text mismatches (#316, #317, #319) vs PowerPoint reference

### 3. Security Officer — Mark Jansen (11 issues)
| PASS | PARTIAL | FAIL | CANNOT_TEST |
|------|---------|------|-------------|
| 9 | 0 | 1 | 1 |

Key findings:
- RBAC correctly enforced — no data leakage
- Only #455 fails (public tabs hidden — design decision)
- All security-critical checks pass

### 4. Functioneel Beheerder — Peter van Dijk (40 issues)
| PASS | PARTIAL | FAIL | CANNOT_TEST |
|------|---------|------|-------------|
| 17 | 5 | 1 | 14 |

Key findings:
- Glossary validation bug: empty URL field blocks form submission
- Delete dialog shows UUID instead of name (#403)
- Merge feature not available (#141)
- 14 issues blocked by org UUID mapping

### 5. Samenwerking — Linda Bakker (2 issues)
| PASS | PARTIAL | FAIL | CANNOT_TEST |
|------|---------|------|-------------|
| 0 | 2 | 0 | 0 |

Key findings:
- Dashboard stable, no crashes
- Missing: register packages on behalf of member municipalities
- Koppeling titles show UUIDs (bad client data, not code bug)

### 6. Bezoeker — Anonymous Visitor (18 issues)
| PASS | PARTIAL | FAIL | CANNOT_TEST |
|------|---------|------|-------------|
| 4 | 3 | 3 | 8 |

Key findings:
- Diensten entirely missing from public search
- Facet configuration incomplete ("0 available facets" despite 13 in API)
- 8 issues untestable because diensten/koppelingen not published

### 7. Architectuur Expert — Dr. Sarah de Vries (5 issues)
| PASS | PARTIAL | FAIL | CANNOT_TEST |
|------|---------|------|-------------|
| 0 | 3 | 1 | 1 |

Key findings:
- All 9 API criteria pass (OAS, elements, relations, views, models)
- View rendering broken in frontend
- AMEF register not configured in app settings
- CMS pages for referentiearchitectuur return 404

---

## Bugs Fixed During This Session (4)

| Bug | File | Impact |
|-----|------|--------|
| Register Entity missing `languages` property | `openregister/lib/Db/Register.php` | ALL API endpoints returning 500 |
| Named parameter `rbac:` vs `_rbac:` | `openregister/lib/Service/Object/SaveObject.php` | ALL PATCH/PUT operations returning 500 |
| Deelnemers endpoint empty `if` block | `softwarecatalog/lib/Controller/AangebodenGebruikController.php` | Always returned 500 |
| Publications missing schema/register enrichment | `opencatalogi/lib/Controller/PublicationsController.php` | Blank search page ("Geen titel") |

---

## Critical Findings

1. **Schema loading bug blocks gemeente wizards** — Frontend fetches from `/api/openregister/api/schemas/gebruik` instead of `/api/apps/openregister/api/schemas/gebruik`. Blocks applicatie and koppeling wizards for gemeente users.

2. **Diensten not published to catalog** — Diensten exist in OpenRegister (schema 5) but aren't included in the OpenCatalogi publication catalog, blocking 8+ bezoeker tests and making diensten invisible in public search.

3. **View rendering broken** — `/beheer/views` shows "Geen weergaven beschikbaar" despite 248 views in the API. Blocks all architecture visualization features.

4. **Org UUID mapping broken for Default Organisation** — `c0ff4d70-14f0-4852-9c18-ce522996119c` has no matching register object in `voorzieningen/organisatie`, blocking 14+ functioneel-beheerder tests.

5. **Koppeling wizard disabled** — "Volgende" button stays disabled despite all fields filled. Confirmed across leverancier and gemeente agents.

---

## Recommendations

### Immediate
1. Fix schema loading URL path in frontend (gemeente wizard blocker)
2. Add diensten schema to the publications catalog configuration
3. Create register object for Default Organisation UUID

### High Priority
4. Fix koppeling wizard "Volgende" button logic
5. Fix view rendering on `/beheer/views`
6. Configure AMEF register in softwarecatalog app settings
7. Fix glossary empty URL validation
8. Fix delete dialog to show object name instead of UUID

### Before Next Test Run
9. Ensure only one frontend build runs at a time (agents rebuilt frontend mid-test)
10. Add concept-status test data for #447 testing

---

## Reports

- **API results:** [api/results.md](api/results.md)
- **API HTML report:** [api/report.html](api/report.html)
- **Leverancier:** [leverancier/results-authenticated.md](leverancier/results-authenticated.md)
- **Gemeente:** [gemeente/results-authenticated.md](gemeente/results-authenticated.md)
- **Security Officer:** [security-officer/results-authenticated.md](security-officer/results-authenticated.md)
- **Functioneel Beheerder:** [functioneel-beheerder/results-authenticated.md](functioneel-beheerder/results-authenticated.md)
- **Samenwerking:** [samenwerking/results-authenticated.md](samenwerking/results-authenticated.md)
- **Bezoeker:** [bezoeker/results-public.md](bezoeker/results-public.md)
- **Architectuur Expert:** [architectuur-expert/results-authenticated.md](architectuur-expert/results-authenticated.md)
