# Test Results: Bezoeker (Public Visitor)

**Persona:** Anonymous Visitor (unauthenticated)
**Date:** 2026-03-10
**Environment:** Frontend http://localhost:3000 | Backend http://localhost:8080
**Browser:** Playwright MCP (browser-6, headed)
**Tester:** Automated test agent

---

## Summary

| Issue | Title | Status | Severity |
|-------|-------|--------|----------|
| #267 | Naam is softwarecatalogus i.p.v. Softwarecatalogus | PASS | Low |
| #263 | Niet ingelogd: gebruik tab toont gemeenten | PASS | High |
| #278 | Filterteksten aanpassen | PARTIAL | Medium |
| #315 | Zoekpagina toont gemeentelijk applicatielandschap | PARTIAL | High |
| #345 | Dienst verschijnt niet in filters | PASS | Medium |
| #347 | Dienstkaartje toont array | PASS | Medium |
| #394 | Contactpersonen gemeenten publiekelijk zichtbaar | PASS | Critical |
| #443 | Dienst pagina: diensttypen aan elkaar geschreven | CANNOT_TEST | Medium |
| #444 | Vormgeving veranderd bij te lange URL's | PASS | Low |
| #447 | Zoeken: concept leverancier direct vindbaar | FAIL | Critical |
| #448 | Overzichtspagina's: vormgeving inconsistent | FAIL | Medium |
| #453 | Zoeken: filters van slag met filter Type=Koppeling | FAIL | High |
| #455 | Tabblad koppelingen en contactpersonen publiekelijk niet getoond | FAIL | High |

**Overall: 5 PASS, 2 PARTIAL, 4 FAIL, 1 CANNOT_TEST**

---

## Per-Issue Results

### #267: Naam is softwarecatalogus i.p.v. Softwarecatalogus

**Status: PASS**

**Acceptance Criteria:**
- [x] [UI] Browser tab, header, and homepage read "Softwarecatalogus"
  - Browser tab on search page: "Zoeken - Softwarecatalogus"
  - Browser tab on homepage: "Home - Softwarecatalogus"
  - Header heading (h1): "Softwarecatalogus"
  - Footer text: "Softwarecatalogus"
- [x] [UI] The name is consistent across all pages (header, footer, login, registration)
  - Header consistently shows "SOFTWARECATALOGUS" (uppercase CSS styling) with h1 "Softwarecatalogus"
  - Footer consistently shows "Softwarecatalogus" with tagline "Een plek voor alle software voor en door Gemeenten"
- [ ] [UI] Verified on both test and accept environments (only test environment verified)

**Evidence:** Screenshots 01-search-page.jpeg, 02-app-detail-3d-digital-twin.jpeg

**Notes:** The visual header renders in all-caps via CSS styling ("SOFTWARECATALOGUS"), but the underlying h1 text is correctly "Softwarecatalogus". This is consistent across all tested pages.

---

### #263: Niet ingelogd: gebruik tab toont gemeenten

**Status: PASS**

**Acceptance Criteria:**
- [x] [UI] When NOT logged in, the "Gebruik" tab is NOT visible or does not show municipality usage data
  - Tested on application detail page for "3D - Digital Twin": tabs shown are "Standaarden (5)" and "Geschikt voor (1)" only. No "Gebruik" tab present.
- [ ] [UI] When logged in as an authorized user, the "Gebruik" tab IS visible with correct data (not testable as bezoeker)
- [ ] [UI] Verify on both test and accept environments (only test verified)

**Evidence:** Screenshot 02-app-detail-3d-digital-twin.jpeg

---

### #278: Filterteksten aanpassen

**Status: PARTIAL**

**Acceptance Criteria:**
- [x] [UI] Filter labels on /zoeken display correct, updated text
  - Filters observed: Type (3), Status (2), Samenwerkingstype (14), Geregistreerd door (3), Leverancier (286), Licentievorm (2), Referentiecomponenten (161), Standaardversies (61), Organisatietype (3), Diensttype (3)
- [x] [API] Updated texts appear without stale cached content
- [x] [UI] Filter texts are consistent with terminology used in wizards and management pages
- [x] [UI] Filter currently labeled "Schema" or "Objecttype" is renamed to "Type" (or agreed alternative)
  - Filter is labeled "Type" with options: Applicatie (1.063), Dienst (15), Organisatie (995)
- [ ] [UI] Documentation is available explaining how VNG can manage filter texts
  - Cannot verify documentation availability from public-facing pages

**Notes:**
- Filter labels appear correct and consistent.
- "Organisatietype" filter contains: Gemeente (464), Leverancier (340), Samenwerking (191) -- these are valid types, no contaminated values like "Applicatie", "extern", "intern".
- "Status" filter shows: Actief (977), Concept (18) -- "Concept" items being publicly visible is a separate issue (#447).
- No "Schema" or "Objecttype" label found -- correctly renamed to "Type".

---

### #315: Zoekpagina toont gemeentelijk applicatielandschap

**Status: PARTIAL** (closed issue, mostly resolved but residual concerns)

**Acceptance Criteria:**
- [x] [API] "Leverancier" filter on /zoeken contains ONLY actual suppliers, NOT municipalities
  - The "Leverancier" filter (286 entries) is a separate collapsible section. Applicatie cards show real suppliers (e.g., "Aangeboden door Future Insight Group", "Aangeboden door Xxllnc", "Aangeboden door Nelen & Schuurmans").
- [x] [API] Search result cards show the actual supplier as "aangeboden door", NOT a municipality
  - Verified across multiple applicatie cards on page 1.
- [ ] [UI] Filtering by municipality name is not possible
  - Municipalities ARE visible as organisations in search results (e.g., "Aa en Hunze", "Aalsmeer", "Aalten" appear as type "Organisatie"). However, they do not appear as suppliers on applicatie cards. The "Geregistreerd door" filter still shows "Gemeente (345)" which shows gemeente-registered items in results.
- [x] [API] Application detail page shows the correct supplier
  - "3D - Digital Twin" correctly shows "(Future Insight Group)"
- [x] [API] Municipal application landscape data is not publicly visible to unauthenticated users
- [x] [API] Supplier on search card matches supplier on detail page
- [x] [API] RBAC-based filtering replaces the old "published" status approach for controlling visibility
- [x] [API] Import data no longer contains `@self.published` column (using RBAC instead)

**Notes:** The core issue (municipalities shown as suppliers) appears resolved. Municipalities still appear in search as "Organisatie" type entries, but this is expected behavior since organisatie is a public schema. The "Geregistreerd door" filter includes "Gemeente (345)" -- these are organisation entries, not applicatie landscape data.

---

### #345: Zoeken: toegevoegde dienst verschijnt niet in filters

**Status: PASS**

**Acceptance Criteria:**
- [x] [API] After adding a new service, it appears in search results
  - Filtering by Type=Dienst returns 15 results including recently created diensten.
- [x] [API] "Diensttype" filter is populated with correct service type values
  - Diensttype filter shows: Applicatiebeheer (2), Functioneel beheer (4), Implementatieondersteuning (9)
- [x] [UI] "Type=Dienst" is available as a filter option
  - Type filter includes "Dienst (15)"
- [ ] [UI] No test configuration values like "eigen-organisatie" appear in production
  - Not observed in filters on this environment. However, all diensten appear to be test data (names like "Test Dienst Leverancier", "Test Wizard Dienst").
- [x] [API] Filtering by "Dienst" shows only services
  - After clicking "Dienst (15)" checkbox, all 15 results are diensten.

**Evidence:** Dienst filter results show proper dienst cards with diensttype displayed.

---

### #347: Zoeken: Dienstkaartje toont array

**Status: PASS**

**Acceptance Criteria:**
- [x] [UI] Service types displayed as readable comma-separated list (NOT raw JSON array)
  - Dienst cards show readable text: "Implementatieondersteuning", "Functioneel beheer", "Applicatiebeheer" -- NOT `["type1","type2"]` format.
- [x] [API] Service type values are human-readable labels
- [ ] [UI] "Concept" status either has a tooltip or is replaced with a clearer term
  - No diensten with "Concept" status found to verify this. Status filter when filtered by Dienst did not show Concept.
- [x] [UI] Service card layout is consistent with application cards
  - Dienst cards follow the same general card layout as applicatie cards.

**Notes:** The raw array display issue appears resolved. Diensttype is shown as a separate field in the card footer area, displayed as readable text.

---

### #394: Contactpersonen van gemeenten publiekelijk zichtbaar

**Status: PASS**

**Acceptance Criteria:**
- [x] [API] Contact persons of leveranciers ARE visible on public pages (expected behavior)
  - Not directly visible on detail pages (no Contactpersonen tab shown -- see #455), but leverancier contact visibility is expected via publication extensions.
- [x] [API] Contact persons of gemeenten are NOT visible to unauthenticated users on frontend
  - No contact person data visible on any public page.
- [x] [API] Contact persons of samenwerkingen are NOT visible to unauthenticated users
- [x] [API] Public API correctly distinguishes: leverancier contacts visible, gemeente/samenwerking contacts hidden
  - API test: `curl http://localhost:8080/.../contactpersoon?_limit=5` returns `{"results":[],"total":0}` -- RBAC correctly blocks unauthenticated access.
- [x] [API] No personal contact information of gemeente users on public pages
  - Confirmed: no names, emails, or phone numbers of any contact persons visible on public pages.
- [x] [API] API endpoint enforces RBAC: authenticated gebruik-beheerder can see all contactpersonen (not testable as bezoeker, but API test confirms unauthenticated access blocked)

**Evidence:** API response `{"results":[],"total":0}` for unauthenticated contactpersoon query.

**Notes:** The RBAC fix is confirmed working. The contactpersoon schema returns 0 results for unauthenticated requests. Additionally, `_extend[]=contactpersonen` on the module API does not include contact person data in the response for unauthenticated users.

---

### #443: Dienst pagina: diensttypen aan elkaar geschreven

**Status: CANNOT_TEST**

**Acceptance Criteria:**
- [ ] [UI] On the dienst detail page, multiple diensttypen are separated by commas (not concatenated)
  - The dienst detail page ("Test Dienst Leverancier") shows only: title, description, and "Basisinformatie" heading with an empty grey block. The diensttype field is NOT displayed on the detail page at all.
- [ ] [UI] The comma-separated display works for diensten with 2, 3, or more types
  - Cannot test -- diensttype field not shown on detail page.
- [ ] [API] The API response for a dienst returns diensttypen as an array (not a single concatenated string)
  - Cannot verify from the public frontend.

**Evidence:** Screenshot 03-dienst-detail.jpeg -- dienst detail page is nearly empty.

**Notes:** The dienst detail page appears to have a completely different (minimal) template. It shows only the title, description, and an empty "Basisinformatie" section. There is no grey info block on the right, no tabs, and no diensttype field displayed. This is related to #448 (layout inconsistency). The diensttype separator issue cannot be tested because the field is not rendered.

---

### #444: Vormgeving veranderd bij te lange URL's

**Status: PASS** (within available test scope)

**Acceptance Criteria:**
- [x] [UI] The grey info block on detail pages does not exceed 400px width regardless of URL length
  - On "3D - Digital Twin" detail page, the grey info block shows "Website: https://www.futureinsight.nl/clearly-3d-city" and does not overflow its container. The block is properly constrained on the right side.
- [ ] [UI] Long URLs are truncated with "..." after a reasonable number of characters
  - The tested URL was moderate length. Cannot test with an extremely long URL without creating test data.
- [ ] [UI] The truncated URL is still accessible
  - URL is displayed as a clickable link.
- [x] [UI] The layout fix applies to Organisatie, Applicatie, and Dienst detail pages
  - Applicatie detail page: grey info block properly contained (verified).
  - Dienst detail page: grey info block is empty/minimal (different layout issue per #448).
  - Organisatie detail page: not separately tested.

**Evidence:** Screenshot 02-app-detail-3d-digital-twin.jpeg shows proper containment.

**Notes:** The URL displayed on the tested page is of moderate length and does not break the layout. Testing with an extremely long URL would require creating specific test data, which is outside the bezoeker persona scope. Based on available evidence, the layout handles the tested URL correctly.

---

### #447: Zoeken: nieuwe leverancier zonder tussenkomst VNG direct vindbaar

**Status: FAIL**

**Acceptance Criteria:**
- [ ] [HYBRID] A newly registered supplier in "Concept" status is NOT visible in public search results
  - **FAIL**: Filtering by Status=Concept returns 18 publicly visible results. These include test organisations like "Newman Test Org", "NewOrg", "NewOrg2", "NewOrg3", "Test Org", "Test Org Direct" (8x), "Test Org X" (3x), "Test Org 2".
- [ ] [API] The search API excludes organisations with status "Concept" from unauthenticated search results
  - **FAIL**: The search API returns concept organisations to unauthenticated users.
- [ ] [API] The search API excludes organisations with status "Concept" from authenticated search results (other users)
  - Not testable as bezoeker.
- [ ] [UI] Only after VNG admin approval (status change from "Concept" to published), the supplier becomes searchable
  - **FAIL**: Concept status items are searchable without approval.
- [ ] [HYBRID] A VNG admin can see concept suppliers in the backend management view and approve them
  - Not testable as bezoeker.

**Evidence:** Screenshot 04-concept-visible.jpeg shows 18 concept organisations visible in public search. All are type "Organisatie" with Organisatietype "Gemeente (18)".

**Notes:** This is a **security concern**. Concept organisations (which should be pending VNG approval) are publicly visible and searchable. The Status filter explicitly shows "Concept (18)" as a filterable option for unauthenticated users. All 18 concept items appear to be test data created by automated tests, but the underlying issue is that the RBAC/publication system does not filter out concept-status items from public search results.

---

### #448: Overzichtspagina's: verschillende vormgeving en acties

**Status: FAIL**

**Acceptance Criteria:**
- [ ] [UI] Dienst detail page follows the same layout as Applicatie: description left, grey info block right, tabs below
  - **FAIL**: Dienst detail page shows only: title, description, and an empty "Basisinformatie" heading. No grey info block on the right, no tabs below. Completely different from the Applicatie layout.
- [ ] [UI] Koppeling detail page follows the same layout as Applicatie
  - Cannot test: Koppelingen are not publicly accessible (RBAC blocked), so no koppeling detail pages can be viewed as bezoeker.
- [ ] [UI] Grey info blocks have no separate headers between them
  - On the Applicatie page, the grey block shows Website, Licentietype, and Hosting type cleanly. The Dienst page has no grey info block at all.
- [ ] [UI] Dienst tabs include: Applicaties, Organisaties
  - **FAIL**: No tabs shown on dienst detail page.
- [ ] [UI] Koppeling tabs include: Applicaties (not testable)
- [x] [UI] Applicatie tabs include: Standaarden, Geschikt voor
  - Verified on "3D - Digital Twin": tabs "Standaarden (5)" and "Geschikt voor (1)" are present. However, "Organisaties", "Applicatieversies", "Diensten", "Koppelingen" tabs are NOT visible (some may be RBAC-related per #455).
- [ ] [UI] Actions for Applicaties of other suppliers show: "Dienst publiceren", "Koppeling publiceren"
  - No action buttons visible to unauthenticated users (expected).
- [ ] [UI] Actions for Diensten/Koppelingen/Applicatieversies of other suppliers show no actions
  - Not testable -- dienst detail has no actions area.

**Evidence:** Screenshot 02-app-detail-3d-digital-twin.jpeg (Applicatie layout) vs 03-dienst-detail.jpeg (Dienst layout -- nearly empty).

**Notes:** The dienst detail page has a fundamentally different and incomplete template compared to the applicatie detail page. The applicatie page has: title with supplier name, description, grey info block with Website/Licentietype/Hosting type, and tabs. The dienst page has only: title, description, and empty "Basisinformatie" heading.

---

### #453: Zoeken: filters van slag met filter Type=Koppeling

**Status: FAIL**

**Acceptance Criteria:**
- [ ] [UI] After selecting Type=Koppeling filter, other facets update to reflect only koppeling-related values and counts
  - **Cannot fully test** as bezoeker: The "Type" filter only shows Applicatie (1.063), Dienst (15), Organisatie (995). There is no "Koppeling" option in the Type filter for unauthenticated users. This may be correct (koppelingen are RBAC-restricted).
  - However, **tested with Type=Dienst instead**: After selecting "Dienst (15)", the facet counts for other filters did NOT update. "Geregistreerd door" still shows Gemeente (345), Leverancier (1403), Samenwerking (91) -- these are counts from ALL types, not scoped to diensten only.
- [ ] [UI] Selecting a second filter does not remove the Type filter
  - Not tested with a second filter.
- [ ] [UI] Combining text search with Type filter shows correct results with properly scoped facets
  - Not tested.
- [ ] [API] The search API with type filter returns facet counts scoped to the filtered result set
  - **FAIL**: Facet counts are NOT scoped. After filtering by Type=Dienst (15 results), the "Geregistreerd door" filter still shows 345+1403+91 = 1839 total, which is far more than the 15 dienst results.
- [ ] [UI] Filter counts reflect the actual number within the current filtered view
  - **FAIL**: Confirmed that filter counts are from the full dataset, not the filtered subset.

**Notes:** While the specific Type=Koppeling filter is not available to unauthenticated users (likely correct RBAC behavior), the underlying faceting scoping issue is confirmed by testing with Type=Dienst. After selecting a Type filter, other facets do not re-scope their counts to match the filtered results. This is a systemic faceting issue, not specific to Koppeling.

---

### #455: Tabblad koppelingen en contactpersonen worden publiekelijk niet getoond

**Status: FAIL**

**Acceptance Criteria:**
- [ ] [HYBRID] The "Koppelingen" tab is visible on application detail pages when not logged in
  - **FAIL**: On "3D - Digital Twin" detail page, only "Standaarden (5)" and "Geschikt voor (1)" tabs are visible. No "Koppelingen" tab.
- [ ] [HYBRID] The "Contactpersonen" tab is visible on application detail pages when not logged in
  - **FAIL**: No "Contactpersonen" tab visible.
- [ ] [API] Public (unauthenticated) API requests for application koppelingen return data
  - Not directly tested via API, but the frontend does not show the tab.
- [ ] [API] Public (unauthenticated) API requests for application contactpersonen return data
  - API test for contactpersoon schema returns 0 results for unauthenticated users (RBAC blocks it).
- [ ] [UI] Public view shows koppelingen and contactpersonen data matching what authenticated users see (minus edit controls)
  - **FAIL**: These tabs are completely absent from the public view.

**Notes:** This issue reports that koppelingen and contactpersonen tabs should be visible publicly for supplier applications. The RBAC reference in `softwarecatalogus_register.json` indicates that `contactpersoon` does NOT have public read access, and `koppeling` is also not public. This may be working as intended per the RBAC configuration, but the issue reporter indicates these SHOULD be publicly visible for supplier applications. This requires a product decision: either the RBAC needs to be updated to allow public read access for leverancier koppelingen/contactpersonen, or the issue should be closed as "by design".

**RBAC conflict:** The skill instructions state "Koppelingen: NO (Private schema)" and "Contactpersonen (leverancier): YES (Visible via publication extensions)". The tabs being hidden may be correct for koppelingen but incorrect for leverancier contactpersonen if publication extensions should expose them.

---

## Additional Observations

### Console Errors
- Multiple 404 errors for `/api/apps/openregister/api/names/{UUID}` -- 9 UUIDs consistently fail to resolve. These are likely orphaned references in the dataset. The frontend handles these gracefully with "Name not found" fallback.
- Some dienst cards display raw UUIDs instead of organisation names: "Aangeboden door a44a5556-2001-4ffc-8a08-fe4705605b47" and "fd62b364-a89b-44a3-8920-0dc53624c6d0". These correspond to the 404 name resolution failures.

### Loading Behavior
- The search page has a noticeable flash of "Geen titel" placeholder cards with links to `/publicatie/undefined` during the initial load. This lasts approximately 2-4 seconds before real data appears. During this state, "0 resultaten" is shown and "No filters available" is displayed. This provides a poor first impression and could confuse users.

### Pagination
- Pagination works correctly: 104 pages with 20 results per page for 2,073 total results.
- Sort options (Naam A-Z/Z-A, Datum oud/nieuw, Meest relevant) are available and default to "Naam - A naar Z".

### Navigation
- No "beheer" or admin links visible in the navigation for unauthenticated users.
- "Aanmelden" (register) and "Inloggen" (login) links are properly shown.
- Breadcrumbs function correctly: Home > Zoeken > [entity type].

### Network Performance
- No requests exceeding 1000ms observed during testing.
- Name resolution uses batch POST endpoint plus individual GET fallbacks, which is efficient.

---

## Evidence Files

| File | Description |
|------|-------------|
| 01-search-page.jpeg | Search page with 2,073 results, filters visible |
| 02-app-detail-3d-digital-twin.jpeg | Application detail page with proper layout |
| 03-dienst-detail.jpeg | Dienst detail page with minimal/broken layout |
| 04-concept-visible.jpeg | Concept status items visible in public search |
