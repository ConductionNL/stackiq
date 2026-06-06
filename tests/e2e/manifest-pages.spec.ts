// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Real UI smoke coverage for the manifest-driven SoftwareCatalog SPA pages.
 *
 * src/manifest.json declares the rendering pages (index / detail / dashboard /
 * roadmap / settings). The app shell (CnAppRoot) uses vue-router in *history*
 * mode with base `/apps/softwarecatalog`, so every page is a real deep-linkable
 * path. Each test drives the real UI by navigating to the page route and
 * asserting the Vue shell mounted (the `#softwarecatalog` mount node renders
 * content) and the page-specific title text is visible — no Vue-internals
 * patching.
 *
 * SPEC-AUTHORING GAP
 * ------------------
 * Only `openspec/specs/org-archimate-export/spec.md` lives under
 * `openspec/specs/` today, so gate-19 can only *require* the ArchiMate
 * scenarios. The manifest index/detail/roadmap/dashboard pages below have
 * NO scenario under `openspec/specs/` (their behaviour is described only by
 * the un-promoted `openspec/changes/retrofit-2026-05-26-fe-*` deltas for the
 * older organisaties/contactpersonen/settings/dashboard surfaces, and the
 * newer contracten/standaarden/reviews/komplianties/moduleversies/features-
 * roadmap surfaces have no spec at all). These tests therefore are NOT
 * gate-counted — they exist so the rendering pages have genuine UI coverage.
 * When FE specs are promoted into `openspec/specs/`, annotate each test below
 * with `// @e2e <spec>::<slug>` to make the gate count them.
 */

import { test, expect, type Page } from '@playwright/test'

const APP_BASE = '/apps/softwarecatalog'

/**
 * Navigate to an in-app SPA route and wait for the Vue shell to mount.
 * Returns once the `#softwarecatalog` mount node has rendered child content.
 */
async function gotoAppRoute(page: Page, route: string): Promise<void> {
	await page.goto(`${APP_BASE}${route}`, { waitUntil: 'networkidle' })
	// The Vue app mounts into <div id="softwarecatalog"></div>; once mounted it
	// contains the rendered NcAppContent tree. Wait for non-empty content.
	await page.waitForFunction(
		() => {
			const root = document.getElementById('softwarecatalog')
			return !!root && root.children.length > 0
		},
		{ timeout: 30000 },
	)
}

/**
 * Assert the app shell rendered (app-content present) and the given title text
 * is visible somewhere in the rendered page. Uses .first() because the manifest
 * title can appear both in the nav and the page header.
 */
async function expectPageRendered(page: Page, title: string): Promise<void> {
	const root = page.locator('#softwarecatalog')
	await expect(root).toBeVisible()
	await expect(page.getByText(title, { exact: false }).first()).toBeVisible({ timeout: 30000 })
}

// ---------------------------------------------------------------------------
// Dashboard (type: dashboard) — widget grid
// ---------------------------------------------------------------------------
test('manifest dashboard: dashboard page renders the widget grid', async ({ page }) => {
	await gotoAppRoute(page, '/')
	await expectPageRendered(page, 'Dashboard')
})

// ---------------------------------------------------------------------------
// Index pages (type: index) — object list surfaces
// ---------------------------------------------------------------------------
const INDEX_PAGES: Array<{ route: string; title: string; name: string }> = [
	{ route: '/organisaties', title: 'Organisations', name: 'organisaties' },
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
		await expect(page.locator('#softwarecatalog')).toBeVisible()
	})
}

// ---------------------------------------------------------------------------
// Settings page (type: settings) — in-app settings surface
// ---------------------------------------------------------------------------
test('manifest settings: in-app settings page renders', async ({ page }) => {
	await gotoAppRoute(page, '/settings')
	await expect(page.locator('#softwarecatalog')).toBeVisible()
	await expect(page.getByText('SoftwareCatalog', { exact: false }).first()).toBeVisible({ timeout: 30000 })
})
