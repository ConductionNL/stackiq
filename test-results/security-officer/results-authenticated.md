# Security Officer Test Results (Authenticated)

**Tester**: Mark Jansen (Information Security Officer)
**Account**: mark.jansen@test.nl (gebruik-beheerder, Test Gemeente)
**Date**: 2026-03-19
**Browser**: browser-3 (Playwright MCP, 1920x1080)
**Environment**: Frontend http://localhost:3000, Backend http://localhost:8080

---

## Summary

| Issue | Title | Status | Severity |
|-------|-------|--------|----------|
| #394 | Contactpersonen van gemeenten publiekelijk zichtbaar | **PARTIAL** | CRITICAL |
| #183 | Wachtwoord vergeten optie | **PASS** | - |
| #404 | Regelmatig witte schermen | **PASS** | - |
| #395 | Menu linkerkant verdwijnt | **PASS** | - |
| #409 | Footer anders: inlog of uitgelogd | **PASS** | - |
| #406 | SiteImprove verwijderen | **PASS** | - |
| #85 | Publieke API toegang tot aanbodinformatie | **PASS** | - |
| #315 | Zoekpagina toont deel gemeentelijk applicatielandschap | **FAIL** | CRITICAL |
| #447 | Concept leverancier zonder VNG triage direct vindbaar | **CANNOT_TEST** | MEDIUM |
| #455 | Tabblad koppelingen en contactpersonen publiekelijk niet getoond | **FAIL** | HIGH |
| #414 | Mogen deelnemers gebruiksobjecten lezen | **PASS** | - |

**Overall security posture**: CRITICAL issues remain. The publications/search API exposes 25,238 objects (including contactpersonen, gebruik, and koppelingen) to both authenticated and unauthenticated users, while the direct OpenRegister object API properly enforces RBAC (returning 0 results for unauthenticated requests). This indicates a bypass in the publications layer.

---

## Detailed Results

### #394: Contactpersonen van gemeenten publiekelijk zichtbaar

**Status: PARTIAL**
**Severity: CRITICAL**

The direct OpenRegister object API correctly blocks unauthenticated access to contactpersonen (returns 0 results). However, the publications/search API exposes them.

**Acceptance Criteria:**
- [x] [API] Contact persons of leveranciers ARE visible on public pages (correct behavior)
- [x] [API] Direct object API (`/api/objects/3/7`) blocks unauthenticated access: returns 0 results
- [ ] [API] Publications/search API still exposes 394 contactpersonen in search filters to unauthenticated users
- [x] [API] Public API (`_extend=contactpersonen`) does not leak contacts in direct publication calls
- [x] [API] No personal contact info visible in direct publication API responses
- [x] [API] Authenticated gebruik-beheerder can see all contactpersonen via direct API (2 results for Test Gemeente)

**Evidence:**
- `curl 'http://localhost:8080/.../api/objects/3/7'` (unauthenticated): `{"results":[],"total":0}` -- PASS
- `curl -u mark.jansen@test.nl 'http://localhost:8080/.../api/objects/3/7'` (authenticated): Returns 2 contact persons -- PASS
- Search page (unauthenticated) at `/zoeken`: Shows "Contactpersoon (394)" in Type filter -- FAIL
- Screenshot: `screenshots/07-zoeken-unauthenticated.png`

**Note:** The direct object API RBAC is working correctly. The issue is that the publications API (`/api/publications`) returns 25,238 results when authenticated (vs 205 unauthenticated), and the search frontend renders all of them including contactpersonen. Even the unauthenticated search page shows 25,238 results with "Contactpersoon (394)" in filters, suggesting the frontend proxy passes auth cookies or the publications endpoint has different RBAC rules.

---

### #183: Wachtwoord vergeten optie

**Status: PASS**

**Acceptance Criteria:**
- [x] [UI] "Wachtwoord vergeten?" button is visible on the login page
- [x] [UI] Clicking it navigates to `/reminder` page
- [x] [UI] Page shows "Wachtwoord vergeten" heading with instructions
- [x] [UI] Email input field with placeholder "uw.email@voorbeeld.nl"
- [x] [UI] "Verstuur code" button to send a one-time login code
- [x] [UI] "Terug naar inloggen" button to return to login

**Evidence:** Screenshot: `screenshots/06-wachtwoord-vergeten.png`

**Note:** Cannot test actual email delivery (SMTP disabled on test env, expected). The UI flow is complete and functional.

---

### #404: Regelmatig witte schermen

**Status: PASS**

White screen not reproducible in automated testing on 2026-03-19.

**Testing performed:**
- [x] Direct URL navigation to `/beheer/applicaties` -- rendered correctly
- [x] Direct URL navigation to `/beheer/diensten` -- rendered correctly
- [x] F5 refresh on `/beheer/applicaties` -- page reloaded correctly
- [x] Rapid navigation between `/beheer/applicaties` and `/beheer/diensten` -- no white screens
- [x] Console: 0 JS errors on beheer pages (26 errors on /zoeken related to 404 name lookups, not white screen)

**Evidence:** Screenshot: `screenshots/04-applicaties-after-f5.png` (page rendered after F5)

---

### #395: Menu linkerkant verdwijnt

**Status: PASS**

**Acceptance Criteria:**
- [x] [UI] Navigate to "Applicaties" overview while logged in -- left menu visible
- [x] [UI] Press F5 to refresh -- left menu remains visible after refresh
- [x] [UI] Left menu remains visible after refresh (tested at 1920x1080)
- [x] [UI] Menu present when directly navigating to URL (`/beheer/diensten` via address bar)
- [x] [UI] Menu persists across refreshes on Diensten page

**Evidence:**
- Screenshot: `screenshots/04-applicaties-after-f5.png` (left menu visible after F5 on /beheer/applicaties)
- Left menu items confirmed present: Dashboard, Mijn Account, Mijn Organisatie, Diensten, Contactpersonen, Applicaties, Gebruik, Koppelingen, View

---

### #409: Footer anders: inlog of uitgelogd

**Status: PASS**

**Acceptance Criteria:**
- [x] [API] Footer content is identical in logged-in and logged-out states
- [x] [API] Both states show: "Softwarecatalogus" and "Een plek voor alle software voor en door Gemeenten"
- [x] [UI] Footer styling consistent between states (same dark blue background, same layout)
- [x] [API] Nav bar shows "Privacy" and "Terms" links in both states

**Evidence:**
- Authenticated footer: "Softwarecatalogus" / "Een plek voor alle software voor en door Gemeenten"
- Unauthenticated footer: identical content
- Screenshots: `screenshots/02-publication-authenticated.png`, `screenshots/03-publication-unauthenticated.png`

---

### #406: SiteImprove verwijderen

**Status: PASS**

**Acceptance Criteria:**
- [x] [API] HTML source does NOT contain `siteimproveanalytics.com` script tag
- [x] [API] No references to "siteimprove" in page source
- [x] [API] Piwik Pro analytics script is present (conditional, requires config)
- [x] [API] Only ONE configurable position for tracking scripts (Piwik Pro block in HTML body)

**Evidence:** `curl http://localhost:3000/ | grep -i siteimprove` returns no results. Piwik Pro script block found in HTML with conditional loading.

---

### #85: Publieke API toegang tot aanbodinformatie

**Status: PASS**

**Acceptance Criteria:**
- [x] [API] Public API for the Softwarecatalogus register is accessible and returns data (205 module publications)
- [x] [API] Auto-generated OAS documentation accessible at `/index.php/apps/openregister/api/registers/3/oas` (returns valid OpenAPI 3.1.0 spec)
- [x] [API] API returns data about aanbiedende organisaties
- [x] [API] API returns data about aangeboden softwarepakketten
- [x] [API] API supports standard query parameters for filtering and pagination
- [x] [API] OAS documentation includes all expected schemas (Sector, Suite, Applicatie, Dienst, etc.)

**Evidence:** OAS endpoint returns complete OpenAPI spec with title "Voorzieningen API" version 2.0.3.

---

### #315: Zoekpagina toont deel van gemeentelijk applicatielandschap

**Status: FAIL**
**Severity: CRITICAL**

**Acceptance Criteria:**
- [x] [API] Direct publications API (unauthenticated) returns only Leverancier-registered items (205 results, all `geregistreerdDoor: Leverancier`)
- [ ] [UI] Search page shows 25,238 results including: Contactpersoon (394), Gebruik (19,505), Koppeling (4,971), Organisatie (257) -- these should NOT be in public search
- [ ] [UI] "Geregistreerd door" filter shows "Gemeente (4,444)" -- municipalities visible as category
- [ ] [UI] Search result cards show "Onbekend -> Onbekend" with raw UUIDs for standaardversies
- [x] [API] Direct publications API correctly filters (no municipalities as suppliers)

**Evidence:**
- `curl 'http://localhost:8080/.../api/publications?_limit=50'`: All 205 results have `geregistreerdDoor: Leverancier` -- PASS
- Authenticated search page: 25,239 results with Gemeente (4,444) visible -- FAIL
- Unauthenticated search page: 25,238 results with same data exposed -- CRITICAL FAIL
- Screenshots: `screenshots/05-zoeken-geen-titel.png`, `screenshots/07-zoeken-unauthenticated.png`

**Root cause analysis:** The publications API returns 205 results unauthenticated and 25,238 authenticated. However, the unauthenticated search page also shows 25,238 results, suggesting the frontend proxy at `localhost:3000` may be forwarding authentication headers or the search uses a different API path that bypasses RBAC.

---

### #447: Concept leverancier zonder VNG triage direct vindbaar

**Status: CANNOT_TEST**
**Severity: MEDIUM**

Cannot test this issue because it requires creating a new supplier registration and checking if it appears in search before VNG approval. The test environment does not have a newly registered "concept" supplier to verify against. The search page itself is broken (showing 25K results including non-module objects), making it impossible to isolate concept-supplier visibility.

---

### #455: Tabblad koppelingen en contactpersonen publiekelijk niet getoond

**Status: FAIL**
**Severity: HIGH**

**Acceptance Criteria:**
- [ ] [HYBRID] The "Koppelingen" tab is NOT visible on application detail pages when not logged in
- [ ] [HYBRID] The "Contactpersonen" tab is NOT visible on application detail pages when not logged in
- [ ] [API] Public (unauthenticated) API requests for application koppelingen return data
- [ ] [API] Public (unauthenticated) API requests for application contactpersonen return data
- [ ] [UI] Public view shows koppelingen and contactpersonen data matching what authenticated users see

**Evidence:**
- Authenticated view of `/publicatie/e27f06e9-...`: Shows tabs: Standaarden (0), Geschikt voor (0), Applicatieversies (1). NO Koppelingen or Contactpersonen tabs.
- Unauthenticated view of same publication: Same tabs shown, NO Koppelingen or Contactpersonen tabs.
- Screenshots: `screenshots/02-publication-authenticated.png`, `screenshots/03-publication-unauthenticated.png`

**Note:** The tabs are missing in BOTH authenticated and unauthenticated views. This is not purely an RBAC issue -- the tabs appear to not be rendered for this application at all, possibly because the application has no koppelingen or contactpersonen linked. However, per the issue description, these tabs should be visible publicly for supplier applications. The issue reports that when logged in as a different supplier, the Koppelingen tab IS shown -- suggesting this is a data/relationship issue with the test application rather than a pure RBAC tab-hiding issue.

---

### #414: Mogen deelnemers gebruiksobjecten lezen

**Status: PASS**

**Acceptance Criteria:**
- [x] [API] Gebruik-beheerder can read all gebruiksobjecten (19,505 objects accessible)
- [x] [API] Data scoped correctly via RBAC -- gebruik-beheerder role grants full read access

**Evidence:** `curl -u mark.jansen@test.nl 'http://localhost:8080/.../api/objects/3/9?_limit=3'` returns `total: 19505`.

---

## RBAC Verification Summary

| Check | Result | Notes |
|-------|--------|-------|
| Unauthenticated users cannot see gemeente contactpersonen via direct API | PASS | Returns 0 results |
| Unauthenticated users cannot access admin endpoints | PASS | /beheer redirects to login |
| Unauthenticated users cannot see contactpersonen via search | **FAIL** | Search shows 394 contactpersonen |
| Unauthenticated users cannot see gebruik data | **FAIL** | Search shows 19,505 gebruik records |
| Unauthenticated users cannot see koppeling data | **FAIL** | Search shows 4,971 koppelingen |
| Authenticated gebruik-beheerder can see all contactpersonen | PASS | 2 results via direct API |
| Authenticated gebruik-beheerder can see all gebruik | PASS | 19,505 results via direct API |
| Direct object API enforces RBAC | PASS | All schema-level RBAC rules applied correctly |
| Publications API enforces RBAC | **FAIL** | 25,238 results authenticated, search frontend exposes same count unauthenticated |

## Privacy Verification Summary

| Check | Result | Notes |
|-------|--------|-------|
| Gemeente contactpersonen NOT publicly visible (direct API) | PASS | RBAC blocks correctly |
| Gemeente contactpersonen NOT publicly visible (search) | **FAIL** | 394 contactpersonen in search filters |
| Usage data scoped to own organization (direct API) | PASS | RBAC scoping works |
| Usage data NOT visible publicly (search) | **FAIL** | 19,505 gebruik records in search |
| API endpoints enforce same rules as UI (direct) | PASS | Direct API RBAC consistent |
| API endpoints enforce same rules as UI (publications) | **FAIL** | Publications API has different behavior |

---

## Critical Security Finding

**The search/publications API path is a major data leak vector.** While the direct OpenRegister object API (`/api/objects/{register}/{schema}`) correctly enforces RBAC authorization rules (returning 0 results for unauthenticated requests to non-public schemas), the publications API (`/api/publications`) returns 25,238 results when authenticated and the frontend at `localhost:3000/zoeken` exposes this same data even in unauthenticated sessions.

**Affected data:**
- 394 contactpersonen (names, emails, phone numbers)
- 19,505 gebruik records (which organizations use which applications)
- 4,971 koppelingen (inter-system connections)
- 4,444 gemeente entries visible in "Geregistreerd door" filter

**Recommendation:** Investigate why the publications API returns 25,238 results versus the expected 205 module publications. The publications register appears to include ALL objects from the voorzieningen register, not just the module schema. The frontend proxy at localhost:3000 may also be forwarding session credentials to the backend, causing unauthenticated frontend visitors to receive authenticated API responses.
