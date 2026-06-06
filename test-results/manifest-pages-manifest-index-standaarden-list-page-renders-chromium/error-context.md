# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: manifest-pages.spec.ts >> manifest index standaarden: list page renders
- Location: tests/e2e/manifest-pages.spec.ts:116:6

# Error details

```
Test timeout of 60000ms exceeded.
```

```
Error: page.goto: Test timeout of 60000ms exceeded.
Call log:
  - navigating to "http://localhost:8080/apps/softwarecatalog/standaarden", waiting until "networkidle"

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
    - main [ref=e203]:
      - generic [ref=e205]:
        - generic [ref=e207]:
          - generic [ref=e208]:
            - generic [ref=e209]:
              - radio "Cards" [ref=e210] [cursor=pointer]
              - generic [ref=e213] [cursor=pointer]: Cards
            - generic [ref=e214]:
              - radio "Table" [checked] [ref=e215] [cursor=pointer]
              - generic [ref=e218] [cursor=pointer]: Table
          - button "Add Item" [ref=e219] [cursor=pointer]:
            - generic [ref=e220]:
              - img [ref=e222]:
                - img [ref=e223]
              - generic [ref=e225]: Add Item
          - button "Actions" [ref=e228] [cursor=pointer]:
            - generic [ref=e229]:
              - img [ref=e231]:
                - img [ref=e232]
              - generic [ref=e234]: Actions
        - generic [ref=e236]:
          - note "No items found" [ref=e238]:
            - img [ref=e240]:
              - img [ref=e241]
            - generic [ref=e243]: No items found
          - button "Actions" [ref=e246] [cursor=pointer]:
            - img [ref=e249]:
              - img [ref=e250]
    - complementary [ref=e252]:
      - generic [ref=e253]:
        - heading "Details" [level=2] [ref=e258]
        - button "Close sidebar" [ref=e259] [cursor=pointer]:
          - img [ref=e262]:
            - img [ref=e263]
      - generic [ref=e265]:
        - tablist [ref=e266]:
          - tab "Files" [selected] [ref=e267]:
            - generic [ref=e268] [cursor=pointer]:
              - img [ref=e270]:
                - img [ref=e271]
              - generic [ref=e275]: Files
          - tab "Notes" [ref=e276]:
            - generic [ref=e277] [cursor=pointer]:
              - img [ref=e279]:
                - img [ref=e280]
              - generic [ref=e284]: Notes
          - tab "Tags" [ref=e285]:
            - generic [ref=e286] [cursor=pointer]:
              - img [ref=e288]:
                - img [ref=e289]
              - generic [ref=e293]: Tags
          - tab "Tasks" [ref=e294]:
            - generic [ref=e295] [cursor=pointer]:
              - img [ref=e297]:
                - img [ref=e298]
              - generic [ref=e302]: Tasks
          - tab "Audit trail" [ref=e303]:
            - generic [ref=e304] [cursor=pointer]:
              - img [ref=e306]:
                - img [ref=e307]
              - generic [ref=e311]: Audit trail
        - tabpanel "Files" [ref=e313]:
          - heading "Files" [level=3] [ref=e314]
          - generic [ref=e315]:
            - generic [ref=e316] [cursor=pointer]:
              - img [ref=e317]:
                - img [ref=e318]
              - generic [ref=e320]: Drop files here or click to browse
            - generic [ref=e321]: No files attached
    - complementary [ref=e322]:
      - generic [ref=e323]:
        - heading "Search" [level=2] [ref=e328]
        - button "Close sidebar" [ref=e329] [cursor=pointer]:
          - img [ref=e332]:
            - img [ref=e333]
      - generic [ref=e335]:
        - tablist [ref=e336]:
          - tab "Search" [selected] [ref=e337]:
            - generic [ref=e338] [cursor=pointer]:
              - img [ref=e340]:
                - img [ref=e341]
              - generic [ref=e345]: Search
          - tab "Columns" [ref=e346]:
            - generic [ref=e347] [cursor=pointer]:
              - img [ref=e349]:
                - img [ref=e350]
              - generic [ref=e354]: Columns
        - tabpanel "Search" [ref=e356]:
          - heading "Search" [level=3] [ref=e357]
          - generic [ref=e359]:
            - heading "Search" [level=3] [ref=e360]
            - generic [ref=e362]:
              - textbox "Search" [ref=e363] [cursor=pointer]:
                - /placeholder: Type to search...
              - generic: Search
    - button "Open AI chat" [ref=e365] [cursor=pointer]:
      - img [ref=e367]:
        - img [ref=e368]
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
> 67  | 	await page.goto(url, { waitUntil: 'networkidle' })
      |             ^ Error: page.goto: Test timeout of 60000ms exceeded.
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
  81  | 	await expect(page.getByText(title, { exact: false }).first()).toBeVisible({ timeout: 30000 })
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
```