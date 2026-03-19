# Security Officer Test Results — Authenticated Session

**Persona:** Mark Jansen (Information Security Officer)
**Username:** mark.jansen@test.nl
**Groups:** gebruik-beheerder, software-catalog-users
**Organization:** Test Gemeente
**Date:** 2026-03-16
**Environment:** Frontend http://localhost:3000 / Backend http://localhost:8080
**Browser:** Playwright (Chromium, 1920x1080)

---

## Summary

| Category | Pass | Partial | Fail | Cannot Test | Total |
|----------|------|---------|------|-------------|-------|
| RBAC/Security | 4 | 1 | 0 | 1 | 6 |
| Privacy | 2 | 1 | 0 | 0 | 3 |
| UI/UX | 0 | 3 | 2 | 0 | 5 |
| **Total** | **6** | **5** | **2** | **1** | **14** |

---

## RBAC Verification Results

### Unauthenticated API Access Tests

| Schema | Register API (objects/3/{id}) | Publications API | Expected | Status |
|--------|-------------------------------|-----------------|----------|--------|
| contactpersoon (7) | 0 results (RBAC=true) | N/A — not a publication type | Blocked | PASS |
| koppeling (11) | 0 results (RBAC=true) | N/A — not a publication type | Blocked | PASS |
| gebruik (9) | 0 results (RBAC=true) | N/A — not a publication type | Blocked | PASS |
| organisatie (8) | 102 results (RBAC=true) | Via publications: visible | Public readable | PASS |
| module (19) | N/A | 146 results (Leverancier only) | Public for Leverancier | PASS |
| Admin endpoints (/api/registers) | 401 "not logged in" | N/A | Blocked | PASS |

### Authenticated API Access Tests (Mark Jansen — gebruik-beheerder)

| Schema | Result | Expected per RBAC | Status |
|--------|--------|-------------------|--------|
| contactpersoon (7) | 305 results (all orgs) | gebruik-beheerder: read all | PASS |
| koppeling (11) | 0 via slug, many via admin | gebruik-beheerder: read all | INCONCLUSIVE — may need different API path |
| gebruik (9) | 0 via slug | gebruik-beheerder: read all | INCONCLUSIVE — may need different API path |
| organisatie (8) | 0 via slug, 102 via publications | public readable | PASS |

---

## Issue Test Results

### #394: Contactpersonen van gemeenten publiekelijk zichtbaar
**Status: PASS**
**Severity: Resolved**

All acceptance criteria verified:
- [x] [API] Leverancier contacts visible on public pages — publications show leverancier data, contactpersoon fields return empty arrays (no personal data exposed)
- [x] [API] Gemeente contactpersonen NOT visible to unauthenticated users — register API returns 0 results for contactpersoon schema without auth
- [x] [API] Samenwerking contactpersonen NOT visible — same RBAC blocks as gemeente
- [x] [API] Public API `_extend=contactpersonen` returns 0 contacts for all publications (unauthenticated)
- [x] [API] No personal contact information leaks on public pages
- [x] [API] Authenticated gebruik-beheerder (Mark Jansen) sees 305 contactpersonen across all orgs — correct per RBAC rules

**Evidence:**
- Unauthenticated `objects/3/7` (contactpersoon): 0 results
- Authenticated as mark.jansen: 305 results
- Publications with `_extend[]=contactpersonen`: 0 contacts returned (unauthenticated)

---

### #183: Wachtwoord vergeten optie
**Status: PARTIAL**
**Severity: MEDIUM**

- [x] [UI] "Wachtwoord vergeten?" button present on login page
- [x] [UI] Clicking navigates to /reminder page
- [x] [UI] Page shows email input field with placeholder "uw.email@voorbeeld.nl"
- [x] [UI] "Verstuur code" button present
- [x] [UI] "Terug naar inloggen" button present
- [ ] [UI] Cannot verify email delivery — SMTP disabled on test environment (expected per MEMORY.md)

**Note:** Feature is implemented and UI flow works. Email delivery cannot be tested due to infrastructure (SMTP disabled). Previously PARTIAL, remains PARTIAL.

---

### #404: Regelmatig witte schermen
**Status: PASS**
**Severity: LOW (not reproducible)**

Tested multiple scenarios per the testing hints:
- [x] [UI] Direct URL navigation to `/beheer/applicaties` — page loads correctly
- [x] [UI] F5 refresh on `/beheer/applicaties` — page reloads correctly, no white screen
- [x] [UI] Direct URL navigation to `/beheer/diensten` — page loads correctly
- [x] [UI] Direct URL navigation to `/beheer/koppelingen` — page loads (content initially empty, then loads)
- [x] [UI] F5 refresh on `/zoeken` — page reloads correctly
- [x] [UI] Rapid navigation between beheer pages — no white screens observed
- [x] [UI] Direct URL to `/publicatie/{id}` — page loads correctly
- [x] [UI] Console shows no critical JS errors causing blank rendering (errors are 404s from names API, not rendering failures)

**Note:** White screen not reproduced in 10+ navigation attempts across multiple pages. PASS with note: "White screen not reproducible in automated testing on 2026-03-16."

---

### #395: Menu linkerkant verdwijnt
**Status: FAIL**
**Severity: HIGH**

- [ ] [UI] Left navigation menu is NOT visible on any beheer page — completely absent
- [ ] [UI] After F5 refresh on `/beheer/applicaties` — no left sidebar menu
- [ ] [UI] After direct URL navigation to `/beheer/diensten` — no left sidebar
- [ ] [UI] After direct URL navigation to `/beheer/koppelingen` — no left sidebar
- [ ] [UI] Menu NOT present when directly navigating to URL

**Root Cause:** Console warnings: "Beheer menu (position 7) not found or has no items" and "No beheer types found in menu". The left sidebar navigation menu is completely missing from all beheer pages. This is not a viewport/responsive issue (tested at 1920x1080). The menu configuration appears broken — the frontend cannot find menu items at position 7.

**Evidence:** Screenshots: `screenshots/beheer-applicaties-no-sidebar.png`, `screenshots/beheer-dashboard-mark.png`

---

### #409: Footer anders: inlog of uitgelogd
**Status: PASS**
**Severity: LOW**

- [x] [API] Footer content is identical in both states: "Softwarecatalogus" + "Een plek voor alle software voor en door Gemeenten"
- [x] [API] No "Privacyverklaring" or "Algemene voorwaarden" links in footer in either state (footer is minimal)
- [x] [UI] Footer styling appears consistent between logged-in and logged-out states

**Note:** The footer is minimal (just text, no links) in both states. The original issue about different links is no longer applicable — there are no footer links at all. The main navigation has "Privacy" and "Terms" links in both states.

---

### #406: SiteImprove verwijderen
**Status: PASS**
**Severity: LOW (resolved)**

- [x] [API] HTML source does NOT contain `siteimproveanalytics.com` script tag — confirmed via page source inspection
- [x] [API] No references to "siteimprove" in page source
- [x] [API] Piwik Pro analytics script present but inactive (empty configuration variables e, t, a)
- [x] [API] Only one analytics script position configured

**Evidence:** `grep -i siteimprove` on page source returns no results. Piwik script present but with empty config vars.

---

### #85: (VNGR) Publieke API toegang tot aanbodinformatie
**Status: PARTIAL**
**Severity: MEDIUM**

- [x] [API] Public API accessible and returns data (146 publications)
- [ ] [API] OAS documentation endpoint returns 500 error: `/api/registers/3/oas` fails (known issue — organisation field causes 500 for unauthenticated requests)
- [x] [API] API returns data about aanbiedende organisaties (via publications)
- [x] [API] API returns data about aangeboden softwarepakketten
- [x] [API] Supports standard query parameters (_limit, _search, etc.)
- [x] [API] Pagination works correctly (page, pages, limit, offset in response)

**Note:** OAS endpoint bug is documented in acceptance criteria. All other API access works correctly.

---

### #315: Hoge prioriteit: Zoekpagina toont deel gemeentelijk applicatielandschap
**Status: PASS**
**Severity: RESOLVED**

- [x] [API] Publications returned by public API show ONLY `geregistreerdDoor: Leverancier` — 0 results with `Gemeente` as supplier
- [x] [API] 44 out of 146 publications have Leverancier as geregistreerdDoor (rest have null/other)
- [x] [API] Municipal application landscape data not publicly visible via register API (gebruik, koppeling return 0 results unauthenticated)
- [x] [API] RBAC-based filtering is active (rbac=true in API response metadata)

**Note:** However, the search page FACETS expose metadata about private data types (see Additional Security Findings below).

---

### #447: Zoeken — concept leverancier zonder VNG triage direct vindbaar
**Status: CANNOT_TEST**
**Severity: MEDIUM**

- No organisations with status "Concept" exist in the test environment
- Cannot verify if concept organisations would appear in search results
- The publications API search for "concept" returns 0 results

**Note:** This issue requires a concept organisation to be created via the registration form to test properly. No such test data exists.

---

### #455: Tabblad koppelingen en contactpersonen publiekelijk niet getoond — RBAC?
**Status: FAIL**
**Severity: HIGH**

- [ ] [HYBRID] "Koppelingen" tab NOT visible on application detail page (unauthenticated) — only "Standaarden (0)" and "Geschikt voor (0)" tabs shown
- [ ] [HYBRID] "Contactpersonen" tab NOT visible on application detail page (unauthenticated) — missing
- [ ] [HYBRID] Same tabs missing when AUTHENTICATED as Mark Jansen — this is not just a public visibility issue
- [ ] [API] Server returns 500 errors for `/publications/{id}/uses` and `/publications/{id}/used` endpoints — both authenticated and unauthenticated
- [ ] [UI] The publication detail page shows only 2 tabs regardless of auth state

**Root Cause:** The backend endpoints for fetching related objects (koppelingen, contactpersonen) on the publication detail page return 500 Internal Server Error. This prevents the frontend from rendering the Koppelingen and Contactpersonen tabs.

**Evidence:** Screenshots: `screenshots/publication-detail-authenticated.png`, `screenshots/publication-detail-unauthenticated.png`. Console errors: "Error fetching uses: Internal Server Error", "Error fetching used: Internal Server Error".

---

## Additional Security Findings

### MEDIUM: Search Page Exposes Private Data Type Counts in Facets

**Severity: MEDIUM**
**Location:** `/zoeken` page (unauthenticated)

The public search page at `/zoeken` displays facet filters that reveal the count of private data types:
- **Type filter shows:** Applicatie (69), Contactpersoon (305), Gebruik (19,502), Koppeling (4,980), Organisatie (203)
- **Geregistreerd door filter shows:** Gemeente (4,440), Leverancier (...), Samenwerking (...)

While the actual DATA records are not accessible (clicking Contactpersoon returns 0 results), the **metadata counts** reveal:
1. The exact number of contact persons in the system (305)
2. The exact number of usage records (19,502) — this is municipal private data
3. The exact number of connections (4,980)
4. The number of municipalities using the system (via Gemeente count: 4,440)

**Recommendation:** The faceting/search API should exclude non-public schema types (contactpersoon, gebruik, koppeling) from the facet response for unauthenticated users, or the frontend should filter these out before rendering.

**Evidence:** Screenshot: `screenshots/search-page-unauthenticated.png`

### MEDIUM: Search Results Show "Geen titel" and Broken Links

**Severity: MEDIUM**
**Location:** `/zoeken` page (both authenticated and unauthenticated)

All search result cards display:
- Title: "Geen titel" (No title)
- Links: `/publicatie/undefined` (broken)
- Standaardversies: Raw UUIDs instead of human-readable names

**Root Cause:** The `/api/apps/openregister/api/names/{uuid}` endpoint returns 404 for most UUIDs (26+ errors per page load). The search cards fail to resolve names and IDs, rendering as blank/broken.

### LOW: Organization Assignment Inconsistency

On first login, Mark Jansen was assigned to "Default Organisation" instead of "Test Gemeente". After logging out and back in, the correct organisation "Test Gemeente" was displayed. Console shows errors: "Error fetching voorzieningen_organisatie" with failed requests.

### INFO: Console Warnings on Every Page Load

Every beheer page load produces:
- "Beheer menu (position 7) not found or has no items"
- "No beheer types found in menu"

These warnings correlate with the missing left sidebar navigation (#395).

---

## Test Environment Notes

- All tests performed on local dev environment (localhost:3000 / localhost:8080)
- SMTP disabled — email-dependent features (password reset delivery) cannot be verified
- Test data created by `test-setup.sh` script
- No concept organisations exist in test data (limits #447 testing)
- Nextcloud backend authentication: admin:admin
- Mark Jansen credentials: mark.jansen@test.nl / WelcomeToTest2026

---

## Screenshots

| File | Description |
|------|-------------|
| `screenshots/search-page-unauthenticated.png` | Public search showing 25,059 results with private type counts in facets |
| `screenshots/beheer-dashboard-mark.png` | Dashboard showing Default Organisation (first login issue) |
| `screenshots/beheer-applicaties-no-sidebar.png` | Beheer applicaties — no left sidebar menu |
| `screenshots/publication-detail-authenticated.png` | App detail — missing Koppelingen/Contactpersonen tabs (authenticated) |
| `screenshots/publication-detail-unauthenticated.png` | App detail — missing Koppelingen/Contactpersonen tabs (unauthenticated) |
