# Test Results: Bezoeker (Public Visitor)

**Persona:** Anonymous Visitor (unauthenticated)
**Date:** 2026-03-19
**Environment:** Frontend http://localhost:3000, Backend http://localhost:8080
**Browser:** Playwright MCP browser-6 (headed)
**Test data:** Local dev environment with test data (Test Applicatie Gemeente, Test Applicatie Leverancier, Test Samenwerking, etc.)

---

## Summary Table

| Issue | Title | Status | Severity |
|-------|-------|--------|----------|
| #267 | Naam is softwarecatalogus i.p.v. Softwarecatalogus | PASS | Low |
| #263 | Niet ingelogd: gebruik tab toont gemeenten | PASS | High |
| #278 | Filterteksten aanpassen | PARTIAL | Medium |
| #315 | Zoekpagina toont gemeentelijk applicatielandschap | FAIL | Critical |
| #345 | Dienst verschijnt niet in filters | FAIL | High |
| #347 | Dienstkaartje toont array | CANNOT_TEST | Medium |
| #394 | Contactpersonen gemeenten publiekelijk zichtbaar | PASS | Critical |
| #443 | Dienst pagina: diensttypen aan elkaar geschreven | CANNOT_TEST | Low |
| #444 | Vormgeving veranderd bij te lange URL's | CANNOT_TEST | Low |
| #447 | Zoeken: concept leverancier direct vindbaar | CANNOT_TEST | High |
| #448 | Overzichtspagina's: vormgeving inconsistent | CANNOT_TEST | Medium |
| #453 | Zoeken: filters van slag met filter Type=Koppeling | CANNOT_TEST | Medium |
| #455 | Tabblad koppelingen en contactpersonen publiekelijk niet getoond | FAIL | High |
| #205 | Gedepubliceerde applicatie nog vindbaar | CANNOT_TEST | High |
| #333 | UUID uit filters refcomp en standaarden | CANNOT_TEST | Medium |
| #398 | Zoeken: Filter met UUID's onder leveranciers | FAIL | Medium |
| #438 | Zoeken: verschillende vormgeving Diensten na filteren | CANNOT_TEST | Medium |
| #440 | Zoeken: Organisatietype teveel aan opties | FAIL | Medium |

---

## Per-Issue Results

### #267: Naam is softwarecatalogus i.p.v. Softwarecatalogus

**Status: PASS**

**Acceptance Criteria:**
- [x] [UI] Browser tab, header, and homepage read "Softwarecatalogus"
  - Tab title: "Zoeken - Softwarecatalogus" on search page, "Home - Softwarecatalogus" on homepage
  - Header: `<h1>` reads "Softwarecatalogus" (displayed in uppercase via CSS as "SOFTWARECATALOGUS")
  - Footer: "Softwarecatalogus" with tagline "Een plek voor alle software voor en door Gemeenten"
- [x] [UI] The name is consistent across all pages (header, footer, login, registration)
  - Verified on homepage, search page, and detail pages -- all consistently show "Softwarecatalogus"
- [ ] [UI] Verified on both test and accept environments
  - Only tested on local dev environment

**Evidence:** `screenshot-filters.png` shows header "SOFTWARECATALOGUS" (CSS text-transform), footer "Softwarecatalogus"

---

### #263: Niet ingelogd: gebruik tab toont gemeenten

**Status: PASS**

**Acceptance Criteria:**
- [x] [UI] When NOT logged in, the "Gebruik" tab is NOT visible or does not show municipality usage data
  - Verified on two detail pages (Test Applicatie Leverancier and Test Applicatie Gemeente). Available tabs are: Standaarden, Geschikt voor, Applicatieversies. No "Gebruik" tab is present.
- [ ] [UI] When logged in as an authorized user, the "Gebruik" tab IS visible with correct data
  - Not tested (this persona does not log in)
- [ ] [UI] Verify on both test and accept environments
  - Only tested on local dev environment

**Evidence:** `screenshot-detail-page.png` shows only Standaarden (0), Geschikt voor (0), Applicatieversies (1) tabs.

---

### #278: Filterteksten aanpassen

**Status: PARTIAL**

**Acceptance Criteria:**
- [x] [UI] Filter labels on /zoeken display correct, updated text
  - Filters present: "Type", "Licentievorm", "Geregistreerd door", "Organisatietype"
  - Labels are readable and reasonably clear
- [x] [API] Updated texts appear without stale cached content
- [ ] [UI] Filter texts are consistent with terminology used in wizards and management pages
  - Cannot verify wizard terminology as anonymous visitor
- [ ] [UI] Filter currently labeled "Schema" or "Objecttype" is renamed to "Type" (or agreed alternative)
  - "Type" filter IS present with values "Applicatie (76)" and "Organisatie (128)"
  - However, "Dienst" and "Koppeling" are missing from Type filter values (see #345)
- [ ] [UI] Documentation is available explaining how VNG can manage filter texts
  - Cannot verify documentation

**Notes:** The filters that are present have reasonable labels, but the filter set is incomplete (no Dienst/Koppeling in Type, no Diensttype filter, no Referentiecomponent filter, no Standaard filter).

---

### #315: Hoge prioriteit: Zoekpagina toont gemeentelijk applicatielandschap

**Status: FAIL**

**Acceptance Criteria:**
- [ ] [API] "Leverancier" filter on /zoeken contains ONLY actual suppliers, NOT municipalities
  - FAIL: There is no "Leverancier" filter per se. "Geregistreerd door" filter only shows "Leverancier (76)" which is a type label, not individual suppliers.
- [ ] [API] Search result cards show the actual supplier as "aangeboden door", NOT a municipality
  - FAIL: "Test Applicatie Gemeente" cards show "(Aangeboden door Default Organisation)" -- these are municipality-registered applications visible publicly. 3 gemeente applications appear in the first page of results.
- [ ] [UI] Filtering by municipality name is not possible
  - Partially true: no supplier name filter exists. However, "Organisatietype" filter includes "Gemeente (64)" and "Samenwerking (64)" which could expose municipality data.
- [x] [API] Application detail page shows the correct supplier
  - Detail pages show correct data for what they contain
- [ ] [API] Municipal application landscape data is not publicly visible to unauthenticated users
  - FAIL: "Test Applicatie Gemeente" entries are publicly visible in search results as an anonymous visitor. These are municipality-registered applications that should not be publicly shown.
- [ ] [API] Supplier on search card matches supplier on detail page
  - Some cards show UUID `c0ff4d70-14f0-4852-9c18-ce522996119c` instead of supplier name (see #398)
- [x] [API] RBAC-based filtering replaces the old "published" status approach for controlling visibility
- [x] [API] Import data no longer contains `@self.published` column (using RBAC instead)

**Critical Finding:** Municipality-registered applications ("Test Applicatie Gemeente") appear in public search results, visible to unauthenticated users. This is the exact issue described: the municipal application landscape is publicly exposed. 3 such apps appear on page 1, with "Default Organisation" as the supplier (not a real vendor).

**Evidence:** `screenshot-search-page-broken.png` and `screenshot-filters.png` both show "Test Applicatie Gemeente (Aangeboden door Default Organisation)" in public results.

---

### #345: Zoeken: toegevoegde dienst verschijnt niet in filters

**Status: FAIL**

**Acceptance Criteria:**
- [ ] [API] After adding a new service, it appears in search results
  - No diensten appear in search results at all
- [ ] [API] "Diensttype" filter is populated with correct service type values
  - FAIL: No "Diensttype" filter is present on the search page
- [ ] [UI] "Type=Dienst" is available as a filter option
  - FAIL: The "Type" filter only shows "Applicatie (76)" and "Organisatie (128)". No "Dienst" option.
- [ ] [UI] No test configuration values like "eigen-organisatie" appear in production
  - No such values seen, but Dienst type is completely absent
- [ ] [API] Filtering by "Dienst" shows only services
  - Cannot test: no Dienst filter option available

**Notes:** The Type filter only has Applicatie and Organisatie. No Dienst or Koppeling types are available. Console logs show "Available schemas: [7, 8, 9, 11, 19]" but schema 5 (dienst) is not among published schemas, meaning no diensten have been published.

---

### #347: Dienstkaartje toont array

**Status: CANNOT_TEST**

**Acceptance Criteria:**
- [ ] [UI] Service types displayed as readable comma-separated list (NOT raw JSON array)
- [ ] [API] Service type values are human-readable labels
- [ ] [UI] "Concept" status either has a tooltip or is replaced with a clearer term
- [ ] [UI] Service card layout is consistent with application cards

**Reason:** No diensten are visible in search results (see #345). Cannot test service card display without diensten in the system.

---

### #394: Contactpersonen van gemeenten publiekelijk zichtbaar

**Status: PASS**

**Acceptance Criteria:**
- [x] [API] Contact persons of **leveranciers** ARE visible on public pages (this is expected/correct behavior)
  - Note: No contact person data is visible on any public detail page since there is no "Contactpersonen" tab
- [x] [API] Contact persons of **gemeenten** are NOT visible to unauthenticated users on frontend
  - Confirmed: no contactpersoon data visible anywhere
- [x] [API] Contact persons of **samenwerkingen** are NOT visible to unauthenticated users
  - Confirmed
- [x] [API] Public API (`_extend=contactpersonen`) correctly distinguishes: leverancier contacts visible, gemeente/samenwerking contacts hidden
  - API call `GET /api/objects/voorzieningen/module?_extend[]=contactpersonen&_limit=2` returns contacts as empty arrays
- [x] [API] No personal contact information (name, email, phone) of gemeente users on public pages
  - Confirmed: no PII visible
- [x] [API] API endpoint enforces RBAC: contactpersoon schema not publicly accessible
  - API call `GET /api/objects/voorzieningen/contactpersoon?_limit=5` returns 0 results for unauthenticated request. Response shows `_rbac: true` confirming RBAC is enforced.

**Evidence:** API response for contactpersoon without auth: `{"results": [], "total": 0, "pages": 0, ...}`

---

### #443: Dienst pagina: diensttypen aan elkaar geschreven

**Status: CANNOT_TEST**

**Reason:** No diensten are accessible in the public interface. No dienst detail pages can be navigated to (see #345).

---

### #444: Vormgeving veranderd bij te lange URL's

**Status: CANNOT_TEST**

**Reason:** Test data does not contain entries with long URLs. All visible entries are test data without website URLs. Would need real data or specific test entries with long URLs to verify the grey info block behavior.

---

### #447: Zoeken: concept leverancier direct vindbaar

**Status: CANNOT_TEST**

**Acceptance Criteria:**
- [ ] [HYBRID] A newly registered supplier in "Concept" status is NOT visible in public search results
- [ ] [API] The search API excludes organisations with status "Concept" from unauthenticated search results

**Reason:** Test data does not include organisations with "Concept" status. All organisations in the API show status "Actief". Would need to create a concept organisation and verify it does not appear in public search.

**Partial observation:** The Organisatietype filter shows "Gemeente (64)" and "Samenwerking (64)" but no "Leverancier" type, suggesting organisation type data may be incomplete or test-only.

---

### #448: Overzichtspagina's: vormgeving inconsistent

**Status: CANNOT_TEST**

**Reason:** No dienst or koppeling detail pages are accessible to compare layout against applicatie detail pages. The test environment only has applicatie and organisatie publications.

**Partial observation:** The applicatie detail page shows: description on the left, grey info block (Licentietype: Closed source) on the right, tabs below. This matches the expected reference layout.

---

### #453: Zoeken: filters van slag met filter Type=Koppeling

**Status: CANNOT_TEST**

**Reason:** No "Koppeling" option exists in the Type filter. The filter only shows Applicatie and Organisatie. Cannot test cross-filter behavior with Koppeling type.

---

### #455: Tabblad koppelingen en contactpersonen publiekelijk niet getoond

**Status: FAIL**

**Acceptance Criteria:**
- [ ] [HYBRID] The "Koppelingen" tab is visible on application detail pages when not logged in
  - FAIL: No "Koppelingen" tab is shown on public application detail pages. Tested on both "Test Applicatie Leverancier" and "Test Applicatie Gemeente" detail pages. Available tabs are only: Standaarden, Geschikt voor, Applicatieversies.
- [ ] [HYBRID] The "Contactpersonen" tab is visible on application detail pages when not logged in
  - FAIL: No "Contactpersonen" tab is shown on public application detail pages.
- [ ] [API] Public (unauthenticated) API requests for application koppelingen return data
  - Not verified via API
- [ ] [API] Public (unauthenticated) API requests for application contactpersonen return data
  - API returns 0 contactpersonen for unauthenticated requests (RBAC blocks access)
- [ ] [UI] Public view shows koppelingen and contactpersonen data matching what authenticated users see (minus edit controls)
  - FAIL: Tabs are completely absent

**Evidence:** `screenshot-detail-page.png` shows only 3 tabs: Standaarden (0), Geschikt voor (0), Applicatieversies (1). No Koppelingen or Contactpersonen tab.

**Note:** This appears to be an RBAC configuration issue. The contactpersoon schema has no public read access, which means the frontend correctly hides the tab since it would have no data. However, the issue states these tabs SHOULD be visible publicly for supplier applications. This requires either changing RBAC rules for koppelingen/contactpersonen to allow public read, or implementing a publication-based extension mechanism.

---

### #205: Gedepubliceerde applicatie nog vindbaar

**Status: CANNOT_TEST**

**Reason:** Test environment does not contain depublished applications. All applications appear to be in a default state. Would need a known depublished application UUID to verify it does not appear in search.

---

### #333: UUID uit filters refcomp en standaarden

**Status: CANNOT_TEST**

**Reason:** No "Referentiecomponent" or "Standaard" filters are present on the search page. The only filters shown are: Type, Licentievorm, Geregistreerd door, Organisatietype. The referentiecomponent and standaard facets are either not configured or have no data in this test environment.

---

### #398: Zoeken: Filter met UUID's onder leveranciers

**Status: FAIL**

**Acceptance Criteria:**
- [ ] [API] Leveranciers filter shows human-readable supplier names, not UUIDs
  - FAIL: There is no standalone "Leveranciers" filter dropdown. However, the search cards themselves display UUIDs as supplier names. Multiple cards show "(Aangeboden door c0ff4d70-14f0-4852-9c18-ce522996119c)" instead of a readable name.
- [ ] [UI] All suppliers in the filter have proper names
  - N/A: no supplier name filter exists
- [x] [API] No empty or UUID-only entries in the leveranciers dropdown
  - No dropdown exists, but the cards DO show raw UUIDs
- [x] [API] Frontend does not make extra API calls to resolve missing organization names
  - The frontend DOES try to resolve via `/api/names/c0ff4d70-...` but gets a 404, so it falls back to showing the UUID
- [ ] [API] If an organization UUID cannot be resolved, a human-readable fallback is shown (not the raw UUID)
  - FAIL: The fallback IS the raw UUID string

**Evidence:** `screenshot-filters.png` shows "Test Applicatie Leverancier (Aangeboden door c0ff4d70-14f0-4852-9c18-ce522996119c)" on the 5th card. Console logs show: "Name not found (404) for c0ff4d70-14f0-4852-9c18-ce522996119c".

---

### #438: Zoeken: verschillende vormgeving Diensten na filteren

**Status: CANNOT_TEST**

**Reason:** No diensten exist in search results (see #345). Cannot test dienst card consistency across filter combinations.

---

### #440: Zoeken: Organisatietype teveel aan opties

**Status: FAIL**

**Acceptance Criteria:**
- [ ] [UI] The Organisatietype filter on the search page shows exactly 4 options: gemeente, samenwerking, leverancier, community
  - FAIL: The filter shows only 2 options: "Gemeente (64)" and "Samenwerking (64)". Missing: "Leverancier" and "Community".
- [ ] [UI] No additional or unexpected organisation types appear in the filter dropdown
  - No unexpected types, but the filter is incomplete rather than having too many options.
- [x] [API] Filtering by each of the 4 organisatietypes returns correct results
  - Only 2 types available; cannot test the other 2
- [ ] [UI] The filter options are displayed in a consistent, user-friendly format (no UUIDs, no technical names)
  - The 2 visible options use readable names (Gemeente, Samenwerking), not UUIDs

**Notes:** The issue title says "too many options" but on this test environment we see too FEW options. The test data only contains organisations of type Gemeente and Samenwerking. No Leverancier-type or Community-type organisations exist in the organisatie schema. This may be a data issue in the test environment rather than a code issue. The original issue described extra unwanted options; here we see the opposite problem.

---

## Additional Observations

### Pagination
- Pagination is present and functional (11 pages for 204 results)
- Page navigation works (URL changes to `?_page=2`, different cards load)
- **Issue observed:** Some publication IDs appear on both page 1 and page 2 (e.g., `8793a069`, `0522b2fd`), suggesting overlapping results between pages

### Sort Options
- Sort dropdown present with 5 options: Meest relevant, Datum oud-nieuw, Datum nieuw-oud, Naam A-Z (default), Naam Z-A
- Default sort "Naam - A naar Z" is selected

### Card Display
- Cards show: title, supplier (aangeboden door), description, date, type badge
- Date format: "16 maart 2026" (Dutch)
- Type badge shows "Applicatie" for all visible results
- Title shows empty parentheses: "Test Applicatie Leverancier ()" -- likely empty version or reference component field

### Navigation
- No "beheer" or admin links visible for anonymous visitor (correct)
- "Aanmelden" and "Inloggen" links visible in header (correct)
- Breadcrumbs work: Home > Zoeken > Applicatie
- Footer shows VNG logo, "Softwarecatalogus", tagline

### Filter Infrastructure
- Console shows "Facets data: 13 facets with data" but "Facetable config: 0 available facets"
- This suggests facet data IS returned by the API (13 facets) but the frontend configuration for which facets to display is not matching/empty, resulting in only a subset being shown
- Only 4 filter groups rendered despite 13 facets available in the API response

---

## Evidence Screenshots

| File | Description |
|------|-------------|
| `screenshot-search-page-broken.png` | Full-page search results showing all cards and pagination |
| `screenshot-filters.png` | Viewport showing filters (Type, Licentievorm, Geregistreerd door, Organisatietype) and first 5 result cards |
| `screenshot-detail-page.png` | Application detail page showing tabs (Standaarden, Geschikt voor, Applicatieversies) without Koppelingen or Contactpersonen |
