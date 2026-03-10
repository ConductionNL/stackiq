# Security Officer Test Results — Authenticated Session

**Persona:** Mark Jansen (Information Security Officer)
**Username:** mark.jansen@test.nl
**Organization:** Test Gemeente
**Groups:** gebruik-beheerder, software-catalog-users
**Date:** 2026-03-10 (Retest #9)
**Previous Test Dates:** 2026-02-23, 2026-02-24, 2026-02-24, 2026-02-25, 2026-02-25, 2026-02-26, 2026-03-01, 2026-03-02, 2026-03-10
**Browser:** Playwright (Chromium), 1920x1080 viewport
**Environment:** Frontend http://localhost:3000, Backend http://localhost:8080

---

## Executive Summary

| Category | Pass | Partial | Fail | Cannot Test |
|----------|------|---------|------|-------------|
| RBAC / Privacy | 3 | 1 | 1 | 0 |
| UI / UX Issues | 3 | 1 | 0 | 0 |
| API Security | 2 | 0 | 0 | 0 |
| **Total** | **8** | **2** | **1** | **0** |

### Critical Findings

1. **#447 (FAIL):** 18 "Concept" status organisations visible to unauthenticated users via API. Security risk: unmoderated content publicly searchable.
2. **#455 (PARTIAL):** Koppelingen and Contactpersonen tabs not shown on application detail pages for unauthenticated users. RBAC config says leverancier koppelingen SHOULD be public, but the API returns 0 for unauthenticated requests. Possible RBAC enforcement mismatch.
3. **Search intermittent 500 errors:** The search page intermittently returns HTTP 500 on the main publications query, causing ghost cards ("Geen titel" with `/publicatie/undefined` links). Related to #404.

---

## Issue Test Results

### #394: Contactpersonen van gemeenten publiekelijk zichtbaar
**Status: PASS**
**Severity: N/A (resolved)**

**Test Method:**
- Unauthenticated API: `GET /publications?_schema=organisatie&_extend[]=contactpersonen` -- returned 2,073 orgs WITHOUT contactpersonen field
- Authenticated API (admin): Same query returned 13,109 results WITH contactpersonen data (names, email, phone)
- Unauthenticated objects API: `GET /objects?register=3&schema=14` (contactpersoon) -- returned 0 results
- Authenticated objects API: Same query returned 2,068 contactpersonen

**Acceptance Criteria Results:**
- [x] Leverancier contacts visible on public pages (via organisation detail page -- Email and Phone shown for leverancier orgs like 360Geo)
- [x] Gemeente contacts NOT visible to unauthenticated users (API returns no contactpersonen for unauthenticated extend)
- [x] Samenwerking contacts NOT visible unauthenticated
- [x] Public API correctly distinguishes leverancier vs gemeente contacts
- [x] No personal gemeente contact info on public pages
- [x] Authenticated gebruik-beheerder (Mark Jansen) can see all contactpersonen

**Evidence:** Screenshots sec-01, sec-02. API curl tests confirm RBAC enforcement.

---

### #183: Wachtwoord vergeten optie
**Status: PASS**
**Severity: N/A**

**Test Method:**
- Navigated to /login
- "Wachtwoord vergeten?" button present below login form
- Clicking navigates to /reminder page
- Page shows: "Wachtwoord vergeten" heading, email input field, "Verstuur code" button, "Terug naar inloggen" button
- Note: Cannot test actual email delivery (SMTP disabled on test env)

**Acceptance Criteria Results:**
- [x] "Wachtwoord vergeten?" link/button present on login page
- [x] Navigates to password reset page (/reminder)
- [x] Email input field present with placeholder "uw.email@voorbeeld.nl"
- [x] "Verstuur code" action button present
- [x] "Terug naar inloggen" navigation back to login
- [ ] Email delivery not testable (SMTP disabled -- expected per environment config)

**Evidence:** Screenshots sec-04-login-page.png, sec-09-wachtwoord-vergeten.png

---

### #404: Regelmatig witte schermen
**Status: PASS (with note)**
**Severity: N/A**

**Test Method:**
1. Rapid navigation: Navigated quickly between /beheer/applicaties, /beheer/diensten, /beheer/koppelingen -- no white screens
2. Direct URL access: Navigated directly to /beheer/applicaties, /beheer/diensten, /zoeken -- all loaded correctly
3. F5 refresh: Pressed F5 on /beheer/applicaties, /beheer/diensten -- pages reloaded correctly
4. Tested on /zoeken -- page loaded (though with intermittent 500 errors on search API)

**Acceptance Criteria Results:**
- [x] No white screens during navigation through major pages
- [x] F5 refresh does not produce white screens
- [x] Pages load correctly after navigation
- [x] No critical JS errors causing blank rendering

**Note:** White screen not reproducible in automated testing on 2026-03-10. However, the search page does show intermittent HTTP 500 errors on the main publications query, which causes "0 resultaten" with 15 ghost placeholder cards ("Geen titel" linking to /publicatie/undefined). This is not a white screen per se, but a degraded state that could be perceived as broken. On retry, the search loaded correctly with 13,111 results.

**Console errors observed:** 15 x 404 errors for `/api/names/{uuid}` (name resolution failures for some UUIDs in facet labels). These are non-critical but produce console error noise.

---

### #395: Menu linkerkant verdwijnt
**Status: PASS**
**Severity: N/A**

**Test Method:**
1. Resized browser to 1920x1080
2. Navigated to /beheer/applicaties -- sidebar visible with all 9 menu items (Dashboard, Mijn Account, Mijn Organisatie, Diensten, Contactpersonen, Applicaties, Gebruik, Koppelingen, View)
3. Pressed F5 -- sidebar remained visible after refresh
4. Navigated to /beheer/diensten -- sidebar visible, pressed F5 -- still visible
5. Navigated to /beheer/koppelingen -- sidebar visible
6. Direct URL navigation (not SPA) to all pages -- sidebar present

**Acceptance Criteria Results:**
- [x] Sidebar visible on /beheer/applicaties
- [x] Sidebar persists after F5 refresh
- [x] Sidebar visible on /beheer/diensten after refresh
- [x] Sidebar visible when directly navigating to URL
- [x] Sidebar present on all beheer pages tested (applicaties, diensten, koppelingen)

**Evidence:** Screenshots sec-06-applicaties-before-f5.png, sec-07-applicaties-after-f5.png

---

### #409: Footer anders: inlog of uitgelogd
**Status: PASS**
**Severity: N/A**

**Test Method:**
- Extracted footer links programmatically from authenticated (Mark Jansen logged in) and unauthenticated states
- Compared link text and href values

**Authenticated footer links:**
- GEMMA Online (https://www.gemmaonline.nl/)
- NORA Online (https://www.noraonline.nl/)
- VNG (https://vng.nl/)
- Commonground (https://commonground.nl/)
- Privacy (/privacyverklaring)
- Algemene voorwaarden (/algemene-voorwaarden)
- Disclaimer (/disclaimer)
- FAQ (/faq)

**Unauthenticated footer links:** Identical to authenticated.

**Acceptance Criteria Results:**
- [x] Footer links identical in logged-in and logged-out states
- [x] "Privacyverklaring" (Privacy) link points to same URL (/privacyverklaring)
- [x] "Algemene voorwaarden" link points to same URL (/algemene-voorwaarden)
- [x] Footer styling consistent (visual comparison of screenshots)
- [x] Single definitive set of footer links applied to both states

---

### #406: SiteImprove verwijderen
**Status: PASS**
**Severity: N/A**

**Test Method:**
- Evaluated `document.documentElement.outerHTML.includes('siteimproveanalytics')` on both authenticated and unauthenticated pages
- Result: `false` on both states

**Acceptance Criteria Results:**
- [x] HTML source does NOT contain `siteimproveanalytics.com` script tag
- [x] No references to "siteimprove" in page source
- [x] Verified on public pages (unauthenticated) and authenticated pages

---

### #85: (VNGR) Publieke API toegang tot aanbodinformatie
**Status: PASS**
**Severity: N/A**

**Test Method:**
- Tested OAS endpoint: `GET /index.php/apps/openregister/api/registers/3/oas`
- Response: HTTP 200, valid OpenAPI 3.1.0 document
- Title: "Voorzieningen API", 26 paths
- Tested unauthenticated publications API: returns 2,073 results (organisations)
- Tested unauthenticated objects API for module (schema 25): returns 1,063 leverancier applications

**Acceptance Criteria Results:**
- [x] Public API accessible and returns data
- [x] OAS documentation accessible at `/api/registers/3/oas`
- [x] API returns aanbiedende organisaties (2,073 organisations)
- [x] API returns aangeboden softwarepakketten (1,063 leverancier applications)
- [x] API supports standard query parameters for filtering and pagination
- [x] OAS documentation auto-generated per register

---

### #315: Hoge prioriteit: Zoekpagina toont deel gemeentelijk applicatielandschap
**Status: PASS**
**Severity: N/A (closed 2026-03-04)**

**Test Method:**
- Unauthenticated objects API for module (schema 25): returned 1,063 results, ALL with `geregistreerdDoor=Leverancier`
- No gemeente applications visible unauthenticated
- Authenticated API returns full dataset (includes non-public data)
- RBAC config confirms: module schema read = `public` only where `geregistreerdDoor=Leverancier`

**Acceptance Criteria Results:**
- [x] Only leverancier applications visible unauthenticated (verified all 1,063 results)
- [x] No municipality application landscape data publicly visible
- [x] RBAC-based filtering correctly enforced
- [x] Search result cards show actual supplier data

---

### #447: Zoeken: concept leverancier zonder VNG triage direct vindbaar
**Status: FAIL**
**Severity: HIGH**

**Test Method:**
- Unauthenticated API: `GET /publications?_schema=organisatie&status=Concept&_limit=18`
- Result: 18 concept organisations returned, all visible to unauthenticated users

**Concept organisations found publicly:**
- Test Org (type=Gemeente) x2
- Test Org X (type=Gemeente) x3
- Test Org 2 (type=Gemeente)
- Test Org Direct (type=Gemeente) x8
- NewOrg, NewOrg2, NewOrg3 (type=Gemeente)
- Newman Test Org (type=Gemeente)

**Note:** These appear to be test data from previous test runs, but the security issue remains: organisations with "Concept" status are visible in the public API without VNG triage/approval.

**Acceptance Criteria Results:**
- [ ] FAIL: Concept status organisations ARE visible in public search results (18 found)
- [ ] FAIL: Search API does NOT exclude concept status from unauthenticated results
- [ ] FAIL: Search API does NOT exclude concept status from other authenticated users' results
- [ ] Not tested: VNG admin approval workflow
- [ ] Not tested: VNG admin concept management view

**Recommendation:** Add RBAC rule or status filter to exclude `status=Concept` organisations from public read access. Only VNG admins / functioneel-beheerder should see concept objects.

---

### #455: Tabblad koppelingen en contactpersonen publiekelijk niet getoond
**Status: PARTIAL**
**Severity: MEDIUM**

**Test Method:**
1. Navigated to application detail page (Normity, leverancier app) unauthenticated
2. Tabs shown: "Standaarden (4)", "Geschikt voor (1)"
3. Tabs NOT shown: "Koppelingen", "Contactpersonen"
4. API test: `GET /objects?register=3&schema=18` (koppelingen) unauthenticated = 0 results
5. API test: `GET /objects?register=3&schema=14` (contactpersoon) unauthenticated = 0 results
6. Authenticated admin: schema 18 returns 3,453 koppelingen
7. RBAC config for koppeling: `public` read only where `geregistreerdDoor=Leverancier`
8. RBAC config for contactpersoon: NO public read access at all

**Analysis:**
The RBAC configuration for koppeling schema includes a conditional public read rule matching `geregistreerdDoor=Leverancier`. However, the unauthenticated objects API returns 0 koppelingen. This suggests either:
- The conditional RBAC match is not being enforced correctly for the objects API
- Koppelingen don't have a `geregistreerdDoor` field populated with "Leverancier"
- The frontend is not requesting koppelingen data for the unauthenticated detail page

For contactpersonen, the RBAC correctly has NO public access, so the tab absence is expected and correct.

**Acceptance Criteria Results:**
- [ ] FAIL: "Koppelingen" tab NOT visible on app detail pages when unauthenticated
- [x] EXPECTED: "Contactpersonen" tab NOT visible (correct per RBAC -- contactpersoon has no public read)
- [ ] FAIL: Public API requests for application koppelingen return 0 data (RBAC config suggests leverancier koppelingen should be public)
- [x] EXPECTED: Public API requests for contactpersonen return 0 data (correct per RBAC)

**Note:** Whether this is a bug depends on business intent. If leverancier koppelingen should be publicly visible (as the RBAC config suggests with the conditional `geregistreerdDoor=Leverancier` rule), the RBAC enforcement or data needs to be fixed. If they should not be public, the RBAC config should be updated to remove the conditional public rule.

**Evidence:** Screenshot sec-10-app-detail-unauth-no-tabs.png

---

## RBAC Verification Summary

| Check | Result | Notes |
|-------|--------|-------|
| Unauthenticated: gemeente contactpersonen hidden | PASS | API returns 0 contactpersonen unauthenticated |
| Unauthenticated: admin endpoints blocked | PASS | Objects API respects RBAC per schema |
| Unauthenticated: gebruik data hidden | PASS | Schema 16 returns 0 results unauthenticated |
| Unauthenticated: leverancier apps visible | PASS | 1,063 leverancier apps returned |
| Unauthenticated: gemeente apps hidden | PASS | 0 gemeente applications returned via objects API |
| Unauthenticated: concept orgs visible | FAIL | 18 concept orgs visible (should be hidden) |
| Unauthenticated: koppelingen hidden | PARTIAL | 0 returned, but RBAC says leverancier koppelingen should be public |
| Authenticated: gebruik-beheerder sees all data | PASS | Mark Jansen sees 13,111 results in search |
| Session management: logout works | PASS | /logout redirects to home, session cleared |

## Console Error Summary

| Page | Error Count | Type | Severity |
|------|-------------|------|----------|
| /zoeken (unauth) | 15 | 404 on /api/names/{uuid} | LOW (name resolution failures) |
| /zoeken (unauth) | 1 | HTTP 500 on publications query | HIGH (intermittent) |
| /zoeken (auth) | 15 | 404 on /api/names/{uuid} | LOW |
| /beheer/* | 0 | None | N/A |
| /publicatie/* | 0 | None | N/A |

## Performance Notes

- Search page initial load with facet resolution: ~5-6 seconds (resolving 2,765 UUIDs for facet labels)
- Backend cache warmup after login: ~3 seconds (loading 19 schemas across 2 registers)
- Intermittent 500 errors on search publications query observed once during testing
- "Slow network detected" console warnings on some page loads (font loading)

## Screenshots

| File | Description |
|------|-------------|
| sec-01-homepage-unauth.png | Unauthenticated homepage |
| sec-02-search-unauth-ghost-cards.png | Search page with ghost cards (first load, data still loading) |
| sec-03-search-unauth-500-error.png | Search page after 500 error (0 results + filters visible) |
| sec-04-login-page.png | Login page with "Wachtwoord vergeten?" button |
| sec-05-dashboard-authenticated.png | Authenticated dashboard as Mark Jansen |
| sec-06-applicaties-before-f5.png | Applicaties page with sidebar before F5 |
| sec-07-applicaties-after-f5.png | Applicaties page with sidebar after F5 |
| sec-08-search-authenticated.png | Authenticated search results (13,111) |
| sec-09-wachtwoord-vergeten.png | Password reset page |
| sec-10-app-detail-unauth-no-tabs.png | Application detail page without Koppelingen/Contactpersonen tabs |
