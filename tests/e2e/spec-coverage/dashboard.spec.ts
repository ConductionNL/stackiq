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
import { test, expect } from '@playwright/test'
import {
	gotoAppRoute,
	navClickTo,
	collectAppErrors,
	expectNoAppErrors,
	APP_MAIN,
} from './_helpers'

test('dashboard: renders the overview surface (stat tiles and the object statistics panel)', async ({
	page,
}) => {
	const bag = collectAppErrors(page)
	await gotoAppRoute(page, '/')
	const main = page.locator(APP_MAIN).first()

	// The dashboard is four stat tiles plus the catalog-panels widget. It used
	// to be an info box with an intro sentence and a "Beheer van Organisaties"
	// heading; that surface was replaced when the KPI tiles landed, and this
	// spec kept asserting the old one.
	//
	// Labels are asserted in DUTCH because the e2e instance runs Dutch, and
	// they are NOT all identical to the manifest source: the manifest says
	// "Services" and nl.json maps it to "Diensten". Asserting the English
	// source here would pass only on an English instance.
	for (const label of ['Organisaties', 'Modules', 'Diensten', 'Contracten']) {
		await expect(
			main.getByText(label, { exact: false }).first(),
			`the ${label} stat tile must render`,
		).toBeVisible({ timeout: 30000 })
	}

	await expect(
		main.getByText('Object statistieken', { exact: false }).first(),
		'the object statistics panel must render',
	).toBeVisible({ timeout: 30000 })

	expectNoAppErrors(bag)
})

test('dashboard: the refresh action re-runs the data load without error', async ({
	page,
}) => {
	const bag = collectAppErrors(page)
	await gotoAppRoute(page, '/')
	const main = page.locator(APP_MAIN).first()

	// Refresh is NOT a button on the surface. CnDashboardPage puts it in the
	// page actions menu, where showRefresh defaults to true, so it is reached
	// by opening that menu and clicking the item.
	//
	// The label is matched loosely on purpose: it comes from the nextcloud-vue
	// catalogue rather than this app's, so pinning one spelling would couple
	// this spec to the library's translations.
	const actions = main
		.getByRole('button', { name: /Acties|Actions|Meer|More/i })
		.first()
	await expect(actions, 'the page actions menu must be offered').toBeVisible({
		timeout: 30000,
	})
	await actions.click()

	const refresh = page
		.locator('.v-popper__popper--shown')
		.last()
		.getByRole('menuitem', { name: /Vernieuwen|Refresh/i })
		.first()
	await expect(refresh, 'the actions menu must offer Refresh').toBeVisible({
		timeout: 15000,
	})
	await refresh.click()

	// The surface is still intact after the reload.
	await expect(
		main.getByText('Organisaties', { exact: false }).first(),
	).toBeVisible({ timeout: 30000 })

	expectNoAppErrors(bag)
})

// The "Ga naar Organisaties" quick-nav test is deliberately gone rather than
// retargeted. It asserted a button inside the old info box, and that whole
// surface was replaced by the KPI tiles. Its own comment already recorded that
// the button was a no-op in the shared shell and that "the user's working path
// is the Organisations nav entry, covered separately" — which is the test
// immediately below. Keeping a rewritten version would re-test that same path
// twice while pretending to cover a control that no longer exists.

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
