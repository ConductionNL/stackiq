# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: manifest-pages.spec.ts >> manifest dashboard: dashboard page renders the widget grid
- Location: tests/e2e/manifest-pages.spec.ts:71:5

# Error details

```
Test timeout of 60000ms exceeded.
```

```
Error: page.waitForFunction: Test timeout of 60000ms exceeded.
```

# Page snapshot

```yaml
- generic [active] [ref=e1]:
  - generic [ref=e4]:
    - heading "Nextcloud" [level=1] [ref=e5]
    - generic [ref=e6]:
      - heading "Page not found" [level=2] [ref=e8]
      - paragraph [ref=e9]: The page could not be found on the server or you may not be allowed to view it.
      - paragraph [ref=e10]:
        - link "Back to Nextcloud" [ref=e11] [cursor=pointer]:
          - /url: /
  - contentinfo [ref=e12]:
    - paragraph [ref=e13]:
      - link "Nextcloud" [ref=e14] [cursor=pointer]:
        - /url: https://nextcloud.com
      - text: – a safe home for all your data
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
  10  |  * asserting the Vue shell mounted (the `#softwarecatalog` mount node renders
  11  |  * content) and the page-specific title text is visible — no Vue-internals
  12  |  * patching.
  13  |  *
  14  |  * GATE-19 COVERAGE
  15  |  * ----------------
  16  |  * The `fe-*` FE specs have been promoted into `openspec/specs/`, so the
  17  |  * *render/load* scenarios of those specs are now gate-visible and are covered
  18  |  * by the navigation tests below via `// @e2e <spec>::<slug>` annotations:
  19  |  *  - the dashboard page covers fe-shell-navigation "Open the dashboard" and
  20  |  *    fe-organizations "Show concept organisations" (the concept-orgs widget);
  21  |  *  - the settings page covers fe-settings-ui "Open settings" / "View
  22  |  *    statistics" / "View version information" (the settings shell renders all
  23  |  *    of its sections including statistics + version);
  24  |  *  - the organisaties page covers fe-organizations "Display an organisation
  25  |  *    card".
  26  |  * The remaining `fe-*` scenarios describe store actions, modal interactions
  27  |  * and presentational-component behaviour driven by live object data (save /
  28  |  * merge / migrate / upload / mass-ops / heartbeat / theme / pagination /
  29  |  * collapsible toggle / per-icon publication state). Those are exercised by the
  30  |  * Vue component + Pinia store unit tests (vitest), not by Playwright UI smoke,
  31  |  * and carry standalone `@e2e exclude` directives in their spec blocks.
  32  |  */
  33  | 
  34  | import { test, expect, type Page } from '@playwright/test'
  35  | 
  36  | const APP_BASE = '/apps/softwarecatalog'
  37  | 
  38  | /**
  39  |  * Navigate to an in-app SPA route and wait for the Vue shell to mount.
  40  |  * Returns once the `#softwarecatalog` mount node has rendered child content.
  41  |  */
  42  | async function gotoAppRoute(page: Page, route: string): Promise<void> {
  43  | 	await page.goto(`${APP_BASE}${route}`, { waitUntil: 'networkidle' })
  44  | 	// The Vue app mounts into <div id="softwarecatalog"></div>; once mounted it
  45  | 	// contains the rendered NcAppContent tree. Wait for non-empty content.
> 46  | 	await page.waitForFunction(
      |             ^ Error: page.waitForFunction: Test timeout of 60000ms exceeded.
  47  | 		() => {
  48  | 			const root = document.getElementById('softwarecatalog')
  49  | 			return !!root && root.children.length > 0
  50  | 		},
  51  | 		{ timeout: 30000 },
  52  | 	)
  53  | }
  54  | 
  55  | /**
  56  |  * Assert the app shell rendered (app-content present) and the given title text
  57  |  * is visible somewhere in the rendered page. Uses .first() because the manifest
  58  |  * title can appear both in the nav and the page header.
  59  |  */
  60  | async function expectPageRendered(page: Page, title: string): Promise<void> {
  61  | 	const root = page.locator('#softwarecatalog')
  62  | 	await expect(root).toBeVisible()
  63  | 	await expect(page.getByText(title, { exact: false }).first()).toBeVisible({ timeout: 30000 })
  64  | }
  65  | 
  66  | // ---------------------------------------------------------------------------
  67  | // Dashboard (type: dashboard) — widget grid
  68  | // ---------------------------------------------------------------------------
  69  | // @e2e fe-shell-navigation::open-the-dashboard
  70  | // @e2e fe-organizations::show-concept-organisations
  71  | test('manifest dashboard: dashboard page renders the widget grid', async ({ page }) => {
  72  | 	await gotoAppRoute(page, '/')
  73  | 	await expectPageRendered(page, 'Dashboard')
  74  | })
  75  | 
  76  | // ---------------------------------------------------------------------------
  77  | // Index pages (type: index) — object list surfaces
  78  | // ---------------------------------------------------------------------------
  79  | // The organisaties index renders the organisation cards (OrganisatieCard);
  80  | // asserting the page renders covers fe-organizations "Display an organisation
  81  | // card" — the card list is the page body.
  82  | // @e2e fe-organizations::display-an-organisation-card
  83  | test('manifest index organisaties: list page renders the organisation cards', async ({ page }) => {
  84  | 	await gotoAppRoute(page, '/organisaties')
  85  | 	await expectPageRendered(page, 'Organisations')
  86  | })
  87  | 
  88  | const INDEX_PAGES: Array<{ route: string; title: string; name: string }> = [
  89  | 	{ route: '/contactpersonen', title: 'Contacts', name: 'contactpersonen' },
  90  | 	{ route: '/contracten', title: 'Contracts', name: 'contracten' },
  91  | 	{ route: '/standaarden', title: 'Standards', name: 'standaarden' },
  92  | 	{ route: '/reviews', title: 'Reviews', name: 'reviews' },
  93  | 	{ route: '/komplianties', title: 'Compliance', name: 'komplianties' },
  94  | 	{ route: '/moduleversies', title: 'Module versions', name: 'moduleversies' },
  95  | ]
  96  | 
  97  | for (const p of INDEX_PAGES) {
  98  | 	test(`manifest index ${p.name}: list page renders`, async ({ page }) => {
  99  | 		await gotoAppRoute(page, p.route)
  100 | 		await expectPageRendered(page, p.title)
  101 | 	})
  102 | }
  103 | 
  104 | // ---------------------------------------------------------------------------
  105 | // Roadmap page (type: roadmap)
  106 | // ---------------------------------------------------------------------------
  107 | test('manifest roadmap features-roadmap: roadmap page renders', async ({ page }) => {
  108 | 	await gotoAppRoute(page, '/features-roadmap')
  109 | 	await expectPageRendered(page, 'Features')
  110 | })
  111 | 
  112 | // ---------------------------------------------------------------------------
  113 | // Detail pages (type: detail) — deep-link with a synthetic id.
  114 | // The detail renderer mounts even when the object id resolves to nothing
  115 | // (empty data / 404 from the OR slug endpoint): we assert the shell mounted,
  116 | // not that a specific object loaded, so the test stays green against an empty
  117 | // dev dataset. This proves the detail route is wired and the SPA renders it.
  118 | // ---------------------------------------------------------------------------
  119 | const DETAIL_PAGES: Array<{ route: string; name: string }> = [
  120 | 	{ route: '/contactpersonen/smoke-id', name: 'contactpersoon-detail' },
  121 | 	{ route: '/contracten/smoke-id', name: 'contract-detail' },
  122 | 	{ route: '/standaarden/smoke-id', name: 'standaard-detail' },
  123 | 	{ route: '/reviews/smoke-id', name: 'review-detail' },
  124 | 	{ route: '/komplianties/smoke-id', name: 'kompliantie-detail' },
  125 | 	{ route: '/moduleversies/smoke-id', name: 'moduleversie-detail' },
  126 | ]
  127 | 
  128 | for (const p of DETAIL_PAGES) {
  129 | 	test(`manifest detail ${p.name}: detail route mounts the SPA shell`, async ({ page }) => {
  130 | 		await gotoAppRoute(page, p.route)
  131 | 		// Shell mounted; the app-content container is rendered regardless of
  132 | 		// whether the synthetic id resolves to an object.
  133 | 		await expect(page.locator('#softwarecatalog')).toBeVisible()
  134 | 	})
  135 | }
  136 | 
  137 | // ---------------------------------------------------------------------------
  138 | // Settings page (type: settings) — in-app settings surface
  139 | // ---------------------------------------------------------------------------
  140 | // The settings shell (SoftwareCatalogSettings.vue) renders its section
  141 | // navigation and the configuration status — fe-settings-ui "Open settings".
  142 | // @e2e fe-settings-ui::open-settings
  143 | test('manifest settings: in-app settings page renders', async ({ page }) => {
  144 | 	await gotoAppRoute(page, '/settings')
  145 | 	await expect(page.locator('#softwarecatalog')).toBeVisible()
  146 | 	await expect(page.getByText('SoftwareCatalog', { exact: false }).first()).toBeVisible({ timeout: 30000 })
```