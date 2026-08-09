// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Behavioural e2e coverage for four manifest page surfaces that had no
 * browser-level proof at all, and for the EOL-sync settings section.
 *
 * Every test below drives the REAL UI: it clicks the app's own navigation the
 * way a user does (or opens the Nextcloud admin settings section), then asserts
 * the page's own content region rendered and that the app logged no console
 * error and returned no 5xx. Asserting the shell alone would pass on a blank
 * page, so each test also asserts something the page itself puts on screen.
 *
 * Components proved reachable here:
 *   - src/views/FacetedCatalogIndexView.vue   (Applications and Services)
 *   - src/views/suites/SuitesIndexView.vue    (Suites)
 *   - src/views/organisaties/PortfolioReport.vue (Portfolio rationalization)
 *   - src/views/settings/sections/EolSyncSettings.vue (admin settings section)
 *
 * LIVE-RUN NOTE: authored against the built app and run in CI's Playwright job
 * against a freshly deployed instance; deploying a worktree to the shared dev
 * instance is disallowed by policy, so these were not run by hand locally.
 *
 * @spec openspec/specs/gemma-faceted-search/spec.md
 * @spec openspec/specs/suite-wizard/spec.md
 * @spec openspec/specs/portfolio-rationalization-time/spec.md
 * @spec openspec/specs/eol-feed-integration/spec.md
 */
import { test, expect } from '@playwright/test'
import { APP_MAIN, collectAppErrors, expectNoAppErrors, navClickTo } from './_helpers'

test('applications index: FacetedCatalogIndexView renders its list surface', async ({ page }) => {
	const bag = collectAppErrors(page)
	await navClickTo(page, 'Applications')

	const main = page.locator(APP_MAIN).first()
	await expect(main).toBeVisible({ timeout: 30000 })
	// The faceted index renders either rows or its own empty state — both are
	// the page; a blank main region is not.
	await expect(main.getByText(/Applications/i).first()).toBeVisible({ timeout: 30000 })

	expectNoAppErrors(bag)
})

test('services index: FacetedCatalogIndexView renders for the dienst subject too', async ({ page }) => {
	const bag = collectAppErrors(page)
	await navClickTo(page, 'Services')

	const main = page.locator(APP_MAIN).first()
	await expect(main).toBeVisible({ timeout: 30000 })
	await expect(main.getByText(/Services/i).first()).toBeVisible({ timeout: 30000 })

	expectNoAppErrors(bag)
})

test('suites index: SuitesIndexView renders its list surface', async ({ page }) => {
	const bag = collectAppErrors(page)
	await navClickTo(page, 'Suites')

	const main = page.locator(APP_MAIN).first()
	await expect(main).toBeVisible({ timeout: 30000 })
	await expect(main.getByText(/Suites/i).first()).toBeVisible({ timeout: 30000 })

	expectNoAppErrors(bag)
})

test('portfolio rationalization: PortfolioReport renders its quadrant report', async ({ page }) => {
	const bag = collectAppErrors(page)
	await navClickTo(page, 'Portfolio rationalization')

	const main = page.locator(APP_MAIN).first()
	await expect(main).toBeVisible({ timeout: 30000 })
	// The TIME report always renders its quadrant table header, with or without
	// rows behind it.
	await expect(main.getByText(/Quadrant|Portfolio rationalization/i).first())
		.toBeVisible({ timeout: 30000 })

	expectNoAppErrors(bag)
})

test('admin settings: EolSyncSettings section renders inside the settings shell', async ({ page }) => {
	const bag = collectAppErrors(page)
	// `domcontentloaded`, not `networkidle`: Nextcloud keeps long-lived polls
	// open so the network never goes idle (ADR-074 rule 4).
	await page.goto('/settings/admin/softwarecatalog', { waitUntil: 'domcontentloaded' })

	const host = page.locator('#softwarecatalog-settings')
	await expect(host).toBeVisible({ timeout: 30000 })
	await expect(host.getByText(/End.of.life|EOL/i).first()).toBeVisible({ timeout: 30000 })

	expectNoAppErrors(bag)
})
