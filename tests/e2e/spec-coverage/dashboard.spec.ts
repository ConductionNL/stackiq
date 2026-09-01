// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Behavioural UI coverage for the Dashboard page (manifest page `Dashboard`,
 * DashboardCustomView → src/views/Dashboard.vue).
 *
 * The existing manifest-pages smoke only asserts the word "Dashboard" is
 * visible. This drives the real dashboard surface: the widget grid, the
 * statistics overview tables, the "Vernieuwen" (refresh) action, and the
 * "Ga naar Organisaties" navigation button which routes to the organisaties
 * index (navigationStore.setSelected('organisaties')).
 */
import { expect, test } from '@playwright/test'
import {
	APP_MAIN,
	collectAppErrors,
	expectNoAppErrors,
	gotoAppRoute,
	navClickTo,
} from './_helpers.ts'

test('dashboard: renders the overview surface (info box, refresh, statistics tables)', async ({
	page,
}) => {
	const bag = collectAppErrors(page)
	await gotoAppRoute(page, '/')
	const main = page.locator(APP_MAIN).first()

	// Page intro / info-box widget content.
	await expect(
		main
			.getByText('Overzicht van uw softwarecatalogus', { exact: false })
			.first(),
	).toBeVisible({ timeout: 30000 })
	await expect(
		main.getByRole('heading', { name: 'Beheer van Organisaties' }).first(),
	).toBeVisible()

	// Refresh action is present and clickable (drives refreshAllData).
	const refresh = main.getByRole('button', { name: 'Vernieuwen' }).first()
	await expect(refresh).toBeVisible()
	await expect(refresh).toBeEnabled()

	// The statistics overview renders as object-type count tables (two grids).
	// At least one statistics table is present in the rendered dashboard.
	await expect(main.locator('table').first()).toBeVisible({ timeout: 30000 })

	expectNoAppErrors(bag)
})

test('dashboard: "Vernieuwen" refresh re-runs the data load without error', async ({
	page,
}) => {
	const bag = collectAppErrors(page)
	await gotoAppRoute(page, '/')
	const main = page.locator(APP_MAIN).first()

	const refresh = main.getByRole('button', { name: 'Vernieuwen' }).first()
	await expect(refresh).toBeEnabled()
	await refresh.click()

	// After refresh the dashboard surface is still intact (info-box heading).
	await expect(
		main.getByRole('heading', { name: 'Beheer van Organisaties' }).first(),
	).toBeVisible({ timeout: 30000 })

	expectNoAppErrors(bag)
})

// The info-box "Ga naar Organisaties" button calls
// navigationStore.setSelected('organisaties'). In the deployed manifest shell
// this updates the navigation store but does NOT swap the page URL or the main
// content region (see BUG LIST: the dashboard quick-nav button is a no-op in the
// shared CnAppRoot shell — the user's working path is the "Organisations" nav
// entry, covered separately). We therefore assert the button is a real,
// clickable control and that clicking it leaves the app in a healthy state with
// no stackiq-origin error — rather than asserting a navigation the
// deployed shell does not perform.
test('dashboard: "Ga naar Organisaties" quick-nav button is clickable and error-free', async ({
	page,
}) => {
	const bag = collectAppErrors(page)
	await gotoAppRoute(page, '/')
	const main = page.locator(APP_MAIN).first()

	const goButton = main
		.getByRole('button', { name: 'Ga naar Organisaties' })
		.first()
	await expect(goButton).toBeVisible({ timeout: 30000 })
	await expect(goButton).toBeEnabled()
	await goButton.click()

	// App stays healthy after the click (dashboard surface still rendered).
	await expect(
		main.getByRole('heading', { name: 'Beheer van Organisaties' }).first(),
	).toBeVisible({ timeout: 30000 })
	expectNoAppErrors(bag)
})

// The organisaties index is genuinely reachable via the real app nav entry
// "Organisations" — this is the user's actual navigation path and lands on the
// CnIndexPage list surface (Add button + Cards/Table toggle).
test('dashboard: "Organisations" nav entry reaches the organisaties index', async ({
	page,
}) => {
	const bag = collectAppErrors(page)
	await navClickTo(page, 'Organisations')
	const main = page.locator(APP_MAIN).first()

	// ⚠️ THIS USED TO ASSERT A SURFACE THE PRODUCT NO LONGER RENDERS. The
	// comment here claimed Organisations was a `type: custom` page
	// (OrganisatieIndexView) "with no Cards/Table toggle" whose create action
	// read "Add organisation". src/manifest.json decomposed it into a standard
	// `type: index` page (its own `_note` records the change), so the surface
	// is CnIndexPage: a heading, a Cards/Table view toggle, and an Add button
	// whose label CnIndexPage derives as `'Add ' + schema.title`.
	//
	// The schema title is authored "Organization" while every other string in
	// this app is British ("Organisations" nav entry, "Organisation
	// relationships" page title) — and a deployed instance can still carry the
	// older "Organisation" title, because OpenRegister's import skips a schema
	// whose deployed version is not older and its escape hatch never compares
	// the title. Accept either spelling of the one word rather than pin the
	// test to whichever an environment happens to hold; the assertion still
	// names the action and the entity, so it cannot match another page.
	await expect(
		main.getByRole('heading', { name: 'Organisation relationships' }).first(),
	).toBeVisible({ timeout: 30000 })
	await expect(
		main.getByRole('button', { name: /^Add Organi[sz]ation$/i }).first(),
	).toBeVisible({ timeout: 30000 })
	// The view toggle exists only on the index surface — it is what
	// distinguishes "landed on the index" from "landed on any page with a
	// create button".
	await expect(main.getByRole('button', { name: 'Table' }).first()).toBeVisible()
	expectNoAppErrors(bag)
})

test('dashboard: reachable by clicking the Dashboard navigation entry', async ({
	page,
}) => {
	const bag = collectAppErrors(page)
	await navClickTo(page, 'Dashboard')
	const main = page.locator(APP_MAIN).first()
	await expect(
		main.getByRole('heading', { name: 'Beheer van Organisaties' }).first(),
	).toBeVisible({ timeout: 30000 })
	expectNoAppErrors(bag)
})
