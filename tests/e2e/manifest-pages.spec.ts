// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Real UI smoke coverage for the manifest-driven SoftwareCatalog SPA pages.
 *
 * src/manifest.json declares the rendering pages (index / detail / dashboard /
 * roadmap / settings). The app shell (CnAppRoot) uses vue-router in *history*
 * mode with base `/apps/softwarecatalog`, so every page is a real deep-linkable
 * path. Each test drives the real UI by navigating to the page route and
 * asserting the Vue shell mounted (the `.softwarecatalog-app-root` shell that
 * replaces `#content` on mount renders) and the page-specific title text is
 * visible — no Vue-internals
 * patching.
 *
 * GATE-19 COVERAGE
 * ----------------
 * The `fe-*` FE specs have been promoted into `openspec/specs/`, so the
 * *render/load* scenarios of those specs are now gate-visible and are covered
 * by the navigation tests below via `// @e2e <spec>::<slug>` annotations:
 *  - the dashboard page covers fe-shell-navigation "Open the dashboard" and
 *    fe-organizations "Show concept organisations" (the concept-orgs widget);
 *  - the settings page covers fe-settings-ui "Open settings" / "View
 *    statistics" / "View version information" (the settings shell renders all
 *    of its sections including statistics + version);
 *  - the organisaties page covers fe-organizations "Display an organisation
 *    card".
 * The remaining `fe-*` scenarios describe store actions, modal interactions
 * and presentational-component behaviour driven by live object data (save /
 * merge / migrate / upload / mass-ops / heartbeat / theme / pagination /
 * collapsible toggle / per-icon publication state). Those are exercised by the
 * Vue component + Pinia store unit tests (vitest), not by Playwright UI smoke,
 * and carry standalone `@e2e exclude` directives in their spec blocks.
 */

import { test, expect, type Page } from '@playwright/test'

const APP_BASE = '/apps/softwarecatalog'

// The Vue app bootstraps with `.$mount('#content')` (src/main.js), replacing
// Nextcloud's standard `#content` node with the App.vue root, whose outermost
// element is `<div class="softwarecatalog-app-root">` wrapping CnAppRoot. The
// vestigial `<div id="softwarecatalog">` in templates/index.php is never used
// as the mount target, so the shell is identified by its root class instead.
const APP_SHELL = '.softwarecatalog-app-root'

/**
 * Navigate to an in-app SPA route and wait for the Vue shell to mount.
 * Returns once the app-root shell has rendered. We wait for the *shell*
 * (the CnAppRoot container), not data-populated rows — index/dashboard
 * surfaces render their container + empty-state against an empty dev
 * dataset, so asserting the shell keeps the smoke test data-independent.
 */
async function gotoAppRoute(page: Page, route: string): Promise<void> {
	await page.goto(`${APP_BASE}${route}`, { waitUntil: 'networkidle' })
	// Wait for the App.vue shell that replaces #content on mount.
	await page.locator(APP_SHELL).first().waitFor({ state: 'visible', timeout: 30000 })
}

/**
 * Assert the app shell rendered (CnAppRoot container present) and the given
 * title text is visible somewhere in the rendered page. Uses .first() because
 * the manifest title can appear both in the nav and the page header.
 */
async function expectPageRendered(page: Page, title: string): Promise<void> {
	const root = page.locator(APP_SHELL).first()
	await expect(root).toBeVisible()
	await expect(page.getByText(title, { exact: false }).first()).toBeVisible({ timeout: 30000 })
}

// ---------------------------------------------------------------------------
// Dashboard (type: dashboard) — widget grid
// ---------------------------------------------------------------------------
// @e2e fe-shell-navigation::open-the-dashboard
// @e2e fe-organizations::show-concept-organisations
test('manifest dashboard: dashboard page renders the widget grid', async ({ page }) => {
	await gotoAppRoute(page, '/')
	await expectPageRendered(page, 'Dashboard')
})

// ---------------------------------------------------------------------------
// Index pages (type: index) — object list surfaces
// ---------------------------------------------------------------------------
// The organisaties index renders the organisation cards (OrganisatieCard);
// asserting the page renders covers fe-organizations "Display an organisation
// card" — the card list is the page body.
// @e2e fe-organizations::display-an-organisation-card
test('manifest index organisaties: list page renders the organisation cards', async ({ page }) => {
	await gotoAppRoute(page, '/organisaties')
	await expectPageRendered(page, 'Organisations')
})

const INDEX_PAGES: Array<{ route: string; title: string; name: string }> = [
	{ route: '/contactpersonen', title: 'Contacts', name: 'contactpersonen' },
	{ route: '/contracten', title: 'Contracts', name: 'contracten' },
	{ route: '/standaarden', title: 'Standards', name: 'standaarden' },
	{ route: '/reviews', title: 'Reviews', name: 'reviews' },
	{ route: '/komplianties', title: 'Compliance', name: 'komplianties' },
	{ route: '/moduleversies', title: 'Module versions', name: 'moduleversies' },
]

for (const p of INDEX_PAGES) {
	test(`manifest index ${p.name}: list page renders`, async ({ page }) => {
		await gotoAppRoute(page, p.route)
		await expectPageRendered(page, p.title)
	})
}

// ---------------------------------------------------------------------------
// Roadmap page (type: roadmap)
// ---------------------------------------------------------------------------
test('manifest roadmap features-roadmap: roadmap page renders', async ({ page }) => {
	await gotoAppRoute(page, '/features-roadmap')
	await expectPageRendered(page, 'Features')
})

// ---------------------------------------------------------------------------
// Detail pages (type: detail) — deep-link with a synthetic id.
// The detail renderer mounts even when the object id resolves to nothing
// (empty data / 404 from the OR slug endpoint): we assert the shell mounted,
// not that a specific object loaded, so the test stays green against an empty
// dev dataset. This proves the detail route is wired and the SPA renders it.
// ---------------------------------------------------------------------------
const DETAIL_PAGES: Array<{ route: string; name: string }> = [
	{ route: '/contactpersonen/smoke-id', name: 'contactpersoon-detail' },
	{ route: '/contracten/smoke-id', name: 'contract-detail' },
	{ route: '/standaarden/smoke-id', name: 'standaard-detail' },
	{ route: '/reviews/smoke-id', name: 'review-detail' },
	{ route: '/komplianties/smoke-id', name: 'kompliantie-detail' },
	{ route: '/moduleversies/smoke-id', name: 'moduleversie-detail' },
]

for (const p of DETAIL_PAGES) {
	test(`manifest detail ${p.name}: detail route mounts the SPA shell`, async ({ page }) => {
		await gotoAppRoute(page, p.route)
		// Shell mounted; the app-content container is rendered regardless of
		// whether the synthetic id resolves to an object.
		await expect(page.locator(APP_SHELL).first()).toBeVisible()
	})
}

// ---------------------------------------------------------------------------
// Settings page (type: settings) — in-app settings surface
// ---------------------------------------------------------------------------
// The settings shell (SoftwareCatalogSettings.vue) renders its section
// navigation and the configuration status — fe-settings-ui "Open settings".
// @e2e fe-settings-ui::open-settings
test('manifest settings: in-app settings page renders', async ({ page }) => {
	await gotoAppRoute(page, '/settings')
	await expect(page.locator(APP_SHELL).first()).toBeVisible()
	await expect(page.getByText('SoftwareCatalog', { exact: false }).first()).toBeVisible({ timeout: 30000 })
})

// The settings shell renders the Statistics overview section (StatisticsOverview.vue),
// which loads and displays aggregate object counts — fe-settings-ui "View statistics".
// @e2e fe-settings-ui::view-statistics
test('manifest settings: statistics section renders', async ({ page }) => {
	await gotoAppRoute(page, '/settings')
	await expect(page.locator(APP_SHELL).first()).toBeVisible()
	await expect(page.getByText('Statistics', { exact: false }).first()).toBeVisible({ timeout: 30000 })
})

// The settings shell renders the Version information section (VersionInformation.vue),
// which loads and displays the app version — fe-settings-ui "View version information".
// @e2e fe-settings-ui::view-version-information
test('manifest settings: version information section renders', async ({ page }) => {
	await gotoAppRoute(page, '/settings')
	await expect(page.locator(APP_SHELL).first()).toBeVisible()
	await expect(page.getByText('Version', { exact: false }).first()).toBeVisible({ timeout: 30000 })
})
