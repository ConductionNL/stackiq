# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: manifest-pages.spec.ts >> manifest index contactpersonen: list page renders
- Location: tests/e2e/manifest-pages.spec.ts:116:6

# Error details

```
Error: expect(locator).toBeVisible() failed

Locator:  getByText('Contacts').first()
Expected: visible
Received: hidden
Timeout:  30000ms

Call log:
  - Expect "toBeVisible" with timeout 30000ms
  - waiting for getByText('Contacts').first()
    62 × locator resolved to <label data-v-ab8d7671="" class="input-field__label" for="contactsmenu__menu__search"> Search contacts </label>
       - unexpected value "hidden"

```

```yaml
- text: Keyboard navigation help
- button "Skip to app navigation"
- button "Skip to main content"
- banner:
  - link "Go to Dashboard":
    - /url: /
  - navigation "Applications menu":
    - list "Apps":
      - listitem:
        - link "Dashboard":
          - /url: /apps/dashboard/
      - listitem:
        - link "LaunchPad":
          - /url: /apps/launchpad/
      - listitem:
        - link "Files":
          - /url: /apps/files/
      - listitem:
        - link "Photos":
          - /url: /apps/photos/
      - listitem:
        - link "Activity":
          - /url: /apps/activity/
      - listitem:
        - link "Procest":
          - /url: /apps/procest
      - listitem:
        - link "Pipelinq":
          - /url: /apps/pipelinq
      - listitem:
        - link "PetStore":
          - /url: /apps/petstore/
      - listitem:
        - link "Register":
          - /url: /apps/openregister/
      - listitem:
        - link "Catalogi":
          - /url: /apps/opencatalogi
      - listitem:
        - link "Larping":
          - /url: /apps/larpingapp/
      - listitem:
        - link "Doriath":
          - /url: /apps/doriath/
      - listitem:
        - link "DocuDesk":
          - /url: /apps/docudesk/
      - listitem:
        - link "Decidesk":
          - /url: /apps/decidesk/
      - listitem:
        - link "Software Catalogs":
          - /url: /apps/softwarecatalog
      - listitem:
        - link "Zaak Afhandel App":
          - /url: /apps/zaakafhandelapp/
      - listitem:
        - link "OpenBuild":
          - /url: /apps/openbuild/
  - button "Unified search"
  - button "Notifications":
    - img
  - button "Search contacts"
  - navigation "Settings menu":
    - button "Settings menu"
    - text: Avatar of admin
- navigation:
  - list:
    - listitem:
      - link "Dashboard":
        - /url: /apps/softwarecatalog/
    - listitem:
      - link "Organisations":
        - /url: /apps/softwarecatalog/organisaties
    - listitem:
      - link "Contacts":
        - /url: /apps/softwarecatalog/contactpersonen
    - listitem:
      - link "Contracts":
        - /url: /apps/softwarecatalog/contracten
    - listitem:
      - link "Standards":
        - /url: /apps/softwarecatalog/standaarden
    - listitem:
      - link "Reviews":
        - /url: /apps/softwarecatalog/reviews
    - listitem:
      - link "Compliance":
        - /url: /apps/softwarecatalog/komplianties
    - listitem:
      - link "Module versions":
        - /url: /apps/softwarecatalog/moduleversies
  - list:
    - listitem:
      - link "Documentation":
        - /url: "#"
    - listitem:
      - link "Settings":
        - /url: /apps/softwarecatalog/settings
- button "Close navigation" [expanded]
- main:
  - radio "Cards"
  - text: Cards
  - radio "Table" [checked]
  - text: Table
  - button "Add Contactpersoon"
  - button "Actions"
  - note "No items found"
  - button "Actions"
- complementary:
  - heading "Details" [level=2]
  - button "Close sidebar"
  - tablist:
    - tab "Files" [selected]
    - tab "Notes"
    - tab "Tags"
    - tab "Tasks"
    - tab "Audit trail"
  - tabpanel "Files":
    - heading "Files" [level=3]
    - text: Drop files here or click to browse No files attached
- complementary:
  - heading "Contactpersoon" [level=2]
  - paragraph: Contactgegevens van een persoon
  - button "Close sidebar"
  - tablist:
    - tab "Search" [selected]
    - tab "Columns"
  - tabpanel "Search":
    - heading "Search" [level=3]
    - heading "Search" [level=3]
    - textbox "Search":
      - /placeholder: Type to search...
    - text: Search
- button "Open AI chat"
```

# Test source

```ts
  1   | // SPDX-License-Identifier: EUPL-1.2
  2   | // SPDX-FileCopyrightText: 2026 Conduction B.V.
  3   | /**
  4   |  * Real UI smoke coverage for the manifest-driven SoftwareCatalog SPA pages.
  5   |  *
  6   |  * src/manifest.json declares the rendering pages (index / detail / dashboard /
  7   |  * roadmap / settings). The app shell (CnAppRoot) uses vue-router in *history*
  8   |  * mode with base `/apps/softwarecatalog`, so every page is a real deep-linkable
  9   |  * path. Each test drives the real UI by navigating to the page route and
  10  |  * asserting the Vue shell mounted (the `.softwarecatalog-app-root` shell that
  11  |  * replaces `#content` on mount renders) and the page-specific title text is
  12  |  * visible — no Vue-internals
  13  |  * patching.
  14  |  *
  15  |  * GATE-19 COVERAGE
  16  |  * ----------------
  17  |  * The `fe-*` FE specs have been promoted into `openspec/specs/`, so the
  18  |  * *render/load* scenarios of those specs are now gate-visible and are covered
  19  |  * by the navigation tests below via `// @e2e <spec>::<slug>` annotations:
  20  |  *  - the dashboard page covers fe-shell-navigation "Open the dashboard" and
  21  |  *    fe-organizations "Show concept organisations" (the concept-orgs widget);
  22  |  *  - the settings page covers fe-settings-ui "Open settings" / "View
  23  |  *    statistics" / "View version information" (the settings shell renders all
  24  |  *    of its sections including statistics + version);
  25  |  *  - the organisaties page covers fe-organizations "Display an organisation
  26  |  *    card".
  27  |  * The remaining `fe-*` scenarios describe store actions, modal interactions
  28  |  * and presentational-component behaviour driven by live object data (save /
  29  |  * merge / migrate / upload / mass-ops / heartbeat / theme / pagination /
  30  |  * collapsible toggle / per-icon publication state). Those are exercised by the
  31  |  * Vue component + Pinia store unit tests (vitest), not by Playwright UI smoke,
  32  |  * and carry standalone `@e2e exclude` directives in their spec blocks.
  33  |  */
  34  | 
  35  | import { test, expect, type Page } from '@playwright/test'
  36  | 
  37  | const APP_BASE = '/apps/softwarecatalog'
  38  | 
  39  | // The Vue app bootstraps with `.$mount('#content')` (src/main.js), replacing
  40  | // Nextcloud's standard `#content` node with the App.vue root, whose outermost
  41  | // element is `<div class="softwarecatalog-app-root">` wrapping CnAppRoot. The
  42  | // vestigial `<div id="softwarecatalog">` in templates/index.php is never used
  43  | // as the mount target, so the shell is identified by its root class instead.
  44  | //
  45  | // The `.softwarecatalog-app-root` wrapper itself carries no geometry (the
  46  | // CnAppRoot/NcContent layout positions its children), so Playwright reports
  47  | // the wrapper as "hidden" even when the page is fully rendered. We therefore
  48  | // wait for the wrapper to be *attached* (Vue mounted) and assert visibility on
  49  | // the real content region — the NcAppContent `<main>` — and on page text.
  50  | const APP_SHELL = '.softwarecatalog-app-root'
  51  | const APP_MAIN = 'main'
  52  | 
  53  | /**
  54  |  * Navigate to an in-app SPA route and wait for the Vue shell to mount.
  55  |  * Returns once the app-root shell has mounted and the main content region is
  56  |  * visible. We wait for the *shell* (the CnAppRoot container + its NcAppContent
  57  |  * main), not data-populated rows — index/dashboard surfaces render their
  58  |  * container + empty-state against an empty dev dataset, so asserting the shell
  59  |  * keeps the smoke test data-independent.
  60  |  */
  61  | async function gotoAppRoute(page: Page, route: string): Promise<void> {
  62  | 	// The dashboard lives at the app root. NC serves it at `/apps/softwarecatalog`
  63  | 	// (no trailing slash) — the trailing-slash form `/apps/softwarecatalog/` 404s
  64  | 	// because the bare `/` page route does not match a trailing slash. So for the
  65  | 	// root route navigate to APP_BASE directly; deep routes keep their path.
  66  | 	const url = route === '/' ? APP_BASE : `${APP_BASE}${route}`
  67  | 	await page.goto(url, { waitUntil: 'networkidle' })
  68  | 	// App.vue shell mounted (replaces #content) — the wrapper has no geometry,
  69  | 	// so check it is attached, then wait for the visible main content region.
  70  | 	await page.locator(APP_SHELL).first().waitFor({ state: 'attached', timeout: 30000 })
  71  | 	await page.locator(APP_MAIN).first().waitFor({ state: 'visible', timeout: 30000 })
  72  | }
  73  | 
  74  | /**
  75  |  * Assert the app shell rendered (NcAppContent main region present) and the
  76  |  * given title text is visible somewhere in the rendered page. Uses .first()
  77  |  * because the manifest title can appear both in the nav and the page header.
  78  |  */
  79  | async function expectPageRendered(page: Page, title: string): Promise<void> {
  80  | 	await expect(page.locator(APP_MAIN).first()).toBeVisible()
> 81  | 	await expect(page.getByText(title, { exact: false }).first()).toBeVisible({ timeout: 30000 })
      |                                                                ^ Error: expect(locator).toBeVisible() failed
  82  | }
  83  | 
  84  | // ---------------------------------------------------------------------------
  85  | // Dashboard (type: dashboard) — widget grid
  86  | // ---------------------------------------------------------------------------
  87  | // @e2e fe-shell-navigation::open-the-dashboard
  88  | // @e2e fe-organizations::show-concept-organisations
  89  | test('manifest dashboard: dashboard page renders the widget grid', async ({ page }) => {
  90  | 	await gotoAppRoute(page, '/')
  91  | 	await expectPageRendered(page, 'Dashboard')
  92  | })
  93  | 
  94  | // ---------------------------------------------------------------------------
  95  | // Index pages (type: index) — object list surfaces
  96  | // ---------------------------------------------------------------------------
  97  | // The organisaties index renders the organisation cards (OrganisatieCard);
  98  | // asserting the page renders covers fe-organizations "Display an organisation
  99  | // card" — the card list is the page body.
  100 | // @e2e fe-organizations::display-an-organisation-card
  101 | test('manifest index organisaties: list page renders the organisation cards', async ({ page }) => {
  102 | 	await gotoAppRoute(page, '/organisaties')
  103 | 	await expectPageRendered(page, 'Organisations')
  104 | })
  105 | 
  106 | const INDEX_PAGES: Array<{ route: string; title: string; name: string }> = [
  107 | 	{ route: '/contactpersonen', title: 'Contacts', name: 'contactpersonen' },
  108 | 	{ route: '/contracten', title: 'Contracts', name: 'contracten' },
  109 | 	{ route: '/standaarden', title: 'Standards', name: 'standaarden' },
  110 | 	{ route: '/reviews', title: 'Reviews', name: 'reviews' },
  111 | 	{ route: '/komplianties', title: 'Compliance', name: 'komplianties' },
  112 | 	{ route: '/moduleversies', title: 'Module versions', name: 'moduleversies' },
  113 | ]
  114 | 
  115 | for (const p of INDEX_PAGES) {
  116 | 	test(`manifest index ${p.name}: list page renders`, async ({ page }) => {
  117 | 		await gotoAppRoute(page, p.route)
  118 | 		await expectPageRendered(page, p.title)
  119 | 	})
  120 | }
  121 | 
  122 | // ---------------------------------------------------------------------------
  123 | // Roadmap page (type: roadmap)
  124 | // ---------------------------------------------------------------------------
  125 | test('manifest roadmap features-roadmap: roadmap page renders', async ({ page }) => {
  126 | 	await gotoAppRoute(page, '/features-roadmap')
  127 | 	await expectPageRendered(page, 'Features')
  128 | })
  129 | 
  130 | // ---------------------------------------------------------------------------
  131 | // Detail pages (type: detail) — deep-link with a synthetic id.
  132 | // The detail renderer mounts even when the object id resolves to nothing
  133 | // (empty data / 404 from the OR slug endpoint): we assert the shell mounted,
  134 | // not that a specific object loaded, so the test stays green against an empty
  135 | // dev dataset. This proves the detail route is wired and the SPA renders it.
  136 | // ---------------------------------------------------------------------------
  137 | const DETAIL_PAGES: Array<{ route: string; name: string }> = [
  138 | 	{ route: '/contactpersonen/smoke-id', name: 'contactpersoon-detail' },
  139 | 	{ route: '/contracten/smoke-id', name: 'contract-detail' },
  140 | 	{ route: '/standaarden/smoke-id', name: 'standaard-detail' },
  141 | 	{ route: '/reviews/smoke-id', name: 'review-detail' },
  142 | 	{ route: '/komplianties/smoke-id', name: 'kompliantie-detail' },
  143 | 	{ route: '/moduleversies/smoke-id', name: 'moduleversie-detail' },
  144 | ]
  145 | 
  146 | for (const p of DETAIL_PAGES) {
  147 | 	test(`manifest detail ${p.name}: detail route mounts the SPA shell`, async ({ page }) => {
  148 | 		await gotoAppRoute(page, p.route)
  149 | 		// Shell mounted; the app-content container is rendered regardless of
  150 | 		// whether the synthetic id resolves to an object.
  151 | 		await expect(page.locator(APP_MAIN).first()).toBeVisible()
  152 | 	})
  153 | }
  154 | 
  155 | // ---------------------------------------------------------------------------
  156 | // Settings page (type: settings) — in-app settings surface
  157 | // ---------------------------------------------------------------------------
  158 | // The settings shell (SoftwareCatalogSettings.vue) renders its section
  159 | // navigation and the configuration status — fe-settings-ui "Open settings".
  160 | // @e2e fe-settings-ui::open-settings
  161 | test('manifest settings: in-app settings page renders', async ({ page }) => {
  162 | 	await gotoAppRoute(page, '/settings')
  163 | 	await expect(page.locator(APP_MAIN).first()).toBeVisible()
  164 | 	await expect(page.getByText('SoftwareCatalog', { exact: false }).first()).toBeVisible({ timeout: 30000 })
  165 | })
  166 | 
  167 | // The settings shell renders the Statistics overview section (StatisticsOverview.vue),
  168 | // which loads and displays aggregate object counts — fe-settings-ui "View statistics".
  169 | // @e2e fe-settings-ui::view-statistics
  170 | test('manifest settings: statistics section renders', async ({ page }) => {
  171 | 	await gotoAppRoute(page, '/settings')
  172 | 	await expect(page.locator(APP_MAIN).first()).toBeVisible()
  173 | 	await expect(page.getByText('Statistics', { exact: false }).first()).toBeVisible({ timeout: 30000 })
  174 | })
  175 | 
  176 | // The settings shell renders the Version information section (VersionInformation.vue),
  177 | // which loads and displays the app version — fe-settings-ui "View version information".
  178 | // @e2e fe-settings-ui::view-version-information
  179 | test('manifest settings: version information section renders', async ({ page }) => {
  180 | 	await gotoAppRoute(page, '/settings')
  181 | 	await expect(page.locator(APP_MAIN).first()).toBeVisible()
```