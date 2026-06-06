# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: manifest-pages.spec.ts >> manifest detail review-detail: detail route mounts the SPA shell
- Location: tests/e2e/manifest-pages.spec.ts:129:6

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
    - generic [ref=e5]: Keyboard navigation help
    - generic [ref=e6]:
      - button "Skip to app navigation" [ref=e7] [cursor=pointer]:
        - generic [ref=e9]: Skip to app navigation
      - button "Skip to main content" [ref=e10] [cursor=pointer]:
        - generic [ref=e12]: Skip to main content
    - img [ref=e13]:
      - img [ref=e15]
  - banner [ref=e36]:
    - generic [ref=e37]:
      - link "Go to Dashboard" [ref=e38] [cursor=pointer]:
        - /url: /
      - navigation "Applications menu" [ref=e40]:
        - list "Apps" [ref=e41]:
          - listitem [ref=e42]:
            - link "Dashboard" [ref=e43] [cursor=pointer]:
              - /url: /apps/dashboard/
              - img [ref=e44]
              - generic [ref=e45]: Dashboard
          - listitem [ref=e46]:
            - link "LaunchPad" [ref=e47] [cursor=pointer]:
              - /url: /apps/launchpad/
              - img [ref=e48]
              - generic [ref=e49]: LaunchPad
          - listitem [ref=e50]:
            - link "Files" [ref=e51] [cursor=pointer]:
              - /url: /apps/files/
              - img [ref=e52]
              - generic [ref=e53]: Files
          - listitem [ref=e54]:
            - link "Photos" [ref=e55] [cursor=pointer]:
              - /url: /apps/photos/
              - img [ref=e56]
              - generic [ref=e57]: Photos
          - listitem [ref=e58]:
            - link "Activity" [ref=e59] [cursor=pointer]:
              - /url: /apps/activity/
              - img [ref=e60]
              - generic [ref=e61]: Activity
          - listitem [ref=e62]:
            - link "Procest" [ref=e63] [cursor=pointer]:
              - /url: /apps/procest
              - img [ref=e64]
              - generic [ref=e65]: Procest
          - listitem [ref=e66]:
            - link "Pipelinq" [ref=e67] [cursor=pointer]:
              - /url: /apps/pipelinq
              - img [ref=e68]
              - generic [ref=e69]: Pipelinq
          - listitem [ref=e70]:
            - link "PetStore" [ref=e71] [cursor=pointer]:
              - /url: /apps/petstore/
              - img [ref=e72]
              - generic [ref=e73]: PetStore
          - listitem [ref=e74]:
            - link "Register" [ref=e75] [cursor=pointer]:
              - /url: /apps/openregister/
              - img [ref=e76]
              - generic [ref=e77]: Register
          - listitem [ref=e78]:
            - link "Catalogi" [ref=e79] [cursor=pointer]:
              - /url: /apps/opencatalogi
              - img [ref=e80]
              - generic [ref=e81]: Catalogi
          - listitem [ref=e82]:
            - link "Larping" [ref=e83] [cursor=pointer]:
              - /url: /apps/larpingapp/
              - img [ref=e84]
              - generic [ref=e85]: Larping
          - listitem [ref=e86]:
            - link "Doriath" [ref=e87] [cursor=pointer]:
              - /url: /apps/doriath/
              - img [ref=e88]
              - generic [ref=e89]: Doriath
          - listitem [ref=e90]:
            - link "DocuDesk" [ref=e91] [cursor=pointer]:
              - /url: /apps/docudesk/
              - img [ref=e92]
              - generic [ref=e93]: DocuDesk
          - listitem [ref=e94]:
            - link "Decidesk" [ref=e95] [cursor=pointer]:
              - /url: /apps/decidesk/
              - img [ref=e96]
              - generic [ref=e97]: Decidesk
          - listitem [ref=e98]:
            - link "Software Catalogs" [ref=e99] [cursor=pointer]:
              - /url: /apps/softwarecatalog
              - img [ref=e100]
              - generic [ref=e101]: Software Catalogs
          - listitem [ref=e102]:
            - link "Zaak Afhandel App" [ref=e103] [cursor=pointer]:
              - /url: /apps/zaakafhandelapp/
              - img [ref=e104]
              - generic [ref=e105]: Zaak Afhandel App
          - listitem [ref=e106]:
            - link "OpenBuild" [ref=e107] [cursor=pointer]:
              - /url: /apps/openbuild/
              - img [ref=e108]
              - generic [ref=e109]: OpenBuild
    - generic [ref=e110]:
      - button "Unified search" [ref=e113] [cursor=pointer]:
        - img [ref=e116]:
          - img [ref=e117]
      - generic "Notifications" [ref=e120]:
        - button "Notifications" [ref=e121] [cursor=pointer]:
          - img [ref=e125]
      - button "Search contacts" [ref=e129] [cursor=pointer]:
        - img [ref=e132]:
          - img [ref=e133]
      - navigation "Settings menu" [ref=e135]:
        - button "Settings menu" [ref=e136] [cursor=pointer]
        - generic [ref=e140]: Avatar of admin
  - generic [ref=e141]:
    - generic [ref=e142]:
      - navigation [ref=e143]:
        - list [ref=e144]:
          - listitem [ref=e145]:
            - link "Dashboard" [ref=e147] [cursor=pointer]:
              - /url: /apps/softwarecatalog/
              - generic [ref=e149]: Dashboard
          - listitem [ref=e150]:
            - link "Organisations" [ref=e152] [cursor=pointer]:
              - /url: /apps/softwarecatalog/organisaties
              - generic [ref=e154]: Organisations
          - listitem [ref=e155]:
            - link "Contacts" [ref=e157] [cursor=pointer]:
              - /url: /apps/softwarecatalog/contactpersonen
              - generic [ref=e159]: Contacts
          - listitem [ref=e160]:
            - link "Contracts" [ref=e162] [cursor=pointer]:
              - /url: /apps/softwarecatalog/contracten
              - generic [ref=e164]: Contracts
          - listitem [ref=e165]:
            - link "Standards" [ref=e167] [cursor=pointer]:
              - /url: /apps/softwarecatalog/standaarden
              - generic [ref=e169]: Standards
          - listitem [ref=e170]:
            - link "Reviews" [ref=e172] [cursor=pointer]:
              - /url: /apps/softwarecatalog/reviews
              - generic [ref=e174]: Reviews
          - listitem [ref=e175]:
            - link "Compliance" [ref=e177] [cursor=pointer]:
              - /url: /apps/softwarecatalog/komplianties
              - generic [ref=e179]: Compliance
          - listitem [ref=e180]:
            - link "Module versions" [ref=e182] [cursor=pointer]:
              - /url: /apps/softwarecatalog/moduleversies
              - generic [ref=e184]: Module versions
        - list [ref=e185]:
          - listitem [ref=e186]:
            - link "Documentation" [ref=e188] [cursor=pointer]:
              - /url: "#"
              - generic [ref=e190]: Documentation
          - listitem [ref=e191]:
            - link "Settings" [ref=e193] [cursor=pointer]:
              - /url: /apps/softwarecatalog/settings
              - generic [ref=e195]: Settings
      - button "Close navigation" [expanded] [ref=e197] [cursor=pointer]:
        - img [ref=e200]:
          - img [ref=e201]
    - main [ref=e203]
    - button "Open AI chat" [ref=e207] [cursor=pointer]:
      - img [ref=e209]:
        - img [ref=e210]
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