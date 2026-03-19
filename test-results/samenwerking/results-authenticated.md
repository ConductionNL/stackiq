# Test Results: Samenwerking (Authenticated)

**Persona:** Linda Bakker (linda.bakker@test.nl)
**Role:** Gebruik-beheerder
**Organization:** Test Samenwerking
**Date:** 2026-03-19
**Browser:** Playwright MCP (browser-5, headless)
**Environment:** Frontend http://localhost:3000, Backend http://localhost:8080

---

## Issue #57: Pakketten opvoeren voor samenwerkingsverband

**Previous Status:** PARTIAL
**Current Status:** PARTIAL

### Acceptance Criteria

| # | Criterion | Status | Notes |
|---|-----------|--------|-------|
| 1 | [HYBRID] Samenwerking user can log in and see the dashboard without crash | PASS | Login successful, dashboard renders with "Mijn softwarecatalogus" heading, org switcher, and action buttons. No crash. |
| 2 | [UI] Dashboard shows organization name ("Test Samenwerking") | PASS | Organization name visible in the dropdown/combobox after switching from "Default Organisation". Note: initial login defaults to "Default Organisation" -- user must manually switch via dropdown. |
| 3 | [HYBRID] No `TypeError: Cannot read properties of undefined` in console | PASS | Zero console errors on dashboard, my-account, my-organisation, and beheer/koppelingen pages. The only error observed was "AangebodenGebruik API not available or no organization ID" after org switch, which is unrelated to the userGroups TypeError. |
| 4 | [UI] Welcome section renders correctly for gebruik-beheerder role | PASS | Welcome section shows correctly with explanations for "Dienst registreren", "Gebruik registreren", "Koppeling registreren" and links to Mijn Account / Mijn Organisatie. |
| 5 | [UI] Wizards are available for samenwerking organizations (requires org type configuration) | PASS | Three wizard buttons available: "Applicatie toevoegen", "Koppeling toevoegen", "Dienst toevoegen". Applicatie wizard opens with multi-step form (Applicatie > Gebruik configuratie > Controleren) including a "Deelnemers" sub-step. Koppeling wizard also opens successfully with "Deelnemers toevoegen" sub-step. |
| 6 | [UI] Samenwerking user can register packages on behalf of member municipalities (feature not yet implemented) | CANNOT_TEST | The wizards have a "Deelnemers" step that could serve this purpose, but no specific UI for selecting member municipalities was observed. The feature appears to be partially scaffolded but not fully implemented for samenwerking-specific multi-municipality registration. |

### Summary

The original crash bug (TypeError on userGroups) is fully resolved. The dashboard, My Account, and My Organisation pages all render without errors for the samenwerking user. Wizards are available and functional. The remaining gap is the samenwerking-specific feature of registering packages on behalf of member municipalities, which is acknowledged as a feature gap in the issue description.

### Observations

- Linda's account defaults to "Default Organisation" on initial login; the org switcher must be used to select "Test Samenwerking". This is expected behavior for multi-org users.
- The "Mijn Organisatie" page correctly shows "Test Samenwerking" after org switch.
- 7 duplicate contact person entries for Linda Bakker visible on org page (from repeated test-setup runs).

### Evidence

- `screenshot-dashboard.png` - Initial dashboard after login
- `screenshot-dashboard-samenwerking.png` - Dashboard with Test Samenwerking selected
- `screenshot-my-account.png` - Mijn Account page showing Linda Bakker
- `screenshot-my-organisation.png` - Mijn Organisatie showing Test Samenwerking
- `screenshot-applicatie-wizard.png` - Applicatie toevoegen wizard
- `screenshot-koppeling-wizard.png` - Koppeling toevoegen wizard

---

## Issue #186: Koppelingen

**Previous Status:** Not previously tested (for this persona)
**Current Status:** PARTIAL

### Acceptance Criteria

| # | Criterion | Status | Notes |
|---|-----------|--------|-------|
| 1 | [API] Koppelingen display in a table format with readable titles (not blank or UUID-only) | PASS | Beheer koppelingen overview at /beheer/koppelingen renders a proper table with columns: Naam, Status, Korte beschrijving, Applicatie A, Applicatie B, Buitengemeentelijke Voorziening, Acties. Table is empty for Test Samenwerking (no koppelingen owned by this org), but table structure is correct. API returns koppelingen with readable names (e.g., "adf39389... <-> LV-BAG - Basisregistratie Adressen en Gebouwen"). |
| 2 | [API] Koppelingen linked to "buitengemeentelijke voorzieningen" correctly display the referenced external service | PASS | Verified on detail page at /publicatie/0cb77e1d-efb9-4e3c-8c60-0606dc9884c1: "Buitengemeentelijke voorziening: LV-BAG - Basisregistratie Adressen en Gebouwen" is displayed correctly with a readable name. |
| 3 | [API] Koppelingen do not reference non-existent applications (graceful handling) | PASS | When Applicatie A references a non-existent UUID (adf39389-9986-50d9-a421-cc39e32f8404), the UUID is displayed as-is rather than crashing. A 404 error is logged in console for the name lookup, but the page renders gracefully. This is expected behavior per the testing note in issues.md (bad client data). |
| 4 | [UI] Detail page shows all relevant fields: name, type, transport protocol, linked applications, external service | PASS | Detail page at /publicatie/0cb77e1d-efb9-4e3c-8c60-0606dc9884c1 shows: Naam (in title), Applicatie A (UUID), Buitengemeentelijke voorziening (readable), Richting (bi-directioneel), Transportprotocol (extern), Status (In gebruik). All relevant fields are present. |
| 5 | [API] Koppeling detail page at /publicatie/{uuid} renders correctly | PASS | Page renders with correct title, breadcrumb shows "Koppeling", all fields display properly. A "Koppeling aanbieden" action button is available. |

### Additional Findings

- **Public search for koppelingen is broken**: Navigating to /zoeken?type=koppeling shows results with "Geen titel" (No title) and links to `/publicatie/undefined`. The heading shows "0 resultaten" despite cards being displayed. This appears to be an OpenCatalogi publication/listing issue where koppelingen are not properly published, not a bug in the koppeling data itself.
- **Beheer koppelingen empty for samenwerking**: The /beheer/koppelingen page shows "Geen data gevonden" for Test Samenwerking, which is expected since this test organization has no koppelingen assigned.
- **Koppeling wizard available**: The "Koppeling toevoegen" wizard is accessible from the dashboard and properly renders a multi-step form (Een koppeling zoeken > Gebruiksinformatie > Deelnemers toevoegen > Controleren).

### Evidence

- `screenshot-beheer-koppelingen-empty.png` - Beheer koppelingen overview (empty for samenwerking)
- `screenshot-search-koppelingen.png` - Public search showing broken koppeling cards
- `screenshot-koppeling-detail.png` - Koppeling detail page with all fields
- `screenshot-koppeling-wizard.png` - Koppeling toevoegen wizard

---

## Summary

| Issue | Title | Status | Key Finding |
|-------|-------|--------|-------------|
| #57 | Pakketten opvoeren voor samenwerkingsverband | PARTIAL | Crash fix confirmed (5/6 criteria pass). Remaining gap: no samenwerking-specific member municipality registration feature. |
| #186 | Koppelingen | PARTIAL | All 5 API/UI criteria pass for koppeling data and detail pages. However, public search for koppelingen is broken (cards show "Geen titel" with undefined links), which is a separate publication/listing issue. |
