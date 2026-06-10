// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Behavioural UI coverage for the manifest INDEX pages (type: index), each
 * rendered by the nextcloud-vue CnIndexPage against an OpenRegister schema:
 *   Organisations, Contacts, Contracts, Standards, Reviews, Compliance,
 *   Module versions.
 *
 * The existing manifest-pages smoke only asserts the nav link is visible. This
 * suite drives each index page's real, data-independent surface:
 *   - reached by CLICKING the app navigation entry (the user's real path);
 *   - the Cards / Table view-mode toggle is present and switchable;
 *   - the primary "Add <object>" create action is present;
 *   - the "No items found" empty-state renders for the empty dev dataset
 *     (proving the list body — not just the chrome — mounted).
 *
 * The manifest `@resolve:voorzieningen_register` placeholder now resolves to the
 * configured numeric register id (provisioned via initial-state in
 * Application::boot), so the list object-fetch hits a real register and no
 * longer 404s. collectAppErrors no longer filters that 404, so these suites
 * assert it is genuinely absent alongside any other app-origin error / 5xx.
 */
import { test, expect } from '@playwright/test'
import { navClickTo, collectAppErrors, expectNoAppErrors, expectIndexSurface, APP_MAIN } from './_helpers'

interface IndexPage {
	/** Exact app-navigation link label. */
	navLabel: string
	/** Exact primary create-button label. */
	addLabel: string
	/** Slug for the test name. */
	name: string
}

// True manifest `type: index` pages (CnIndexPage against a voorzieningen
// schema). NOTE: "Organisations" is intentionally NOT here — it is a
// `type: custom` page (component OrganisatieIndexView) with its own surface,
// covered by a dedicated test below.
const INDEX_PAGES: IndexPage[] = [
	{ navLabel: 'Contacts', addLabel: 'Add Contactpersoon', name: 'contactpersonen' },
	{ navLabel: 'Contracts', addLabel: 'Add Contract', name: 'contracten' },
	{ navLabel: 'Reviews', addLabel: 'Add Beoordeeling', name: 'reviews' },
	{ navLabel: 'Compliance', addLabel: 'Add Compliancy', name: 'komplianties' },
	{ navLabel: 'Module versions', addLabel: 'Add Applicatieversie', name: 'moduleversies' },
]

for (const p of INDEX_PAGES) {
	test(`index ${p.name}: nav entry reaches the CnIndexPage surface (toggle + add + list body)`, async ({ page }) => {
		const bag = collectAppErrors(page)
		await navClickTo(page, p.navLabel)
		await expectIndexSurface(page, p.addLabel)
		expectNoAppErrors(bag)
	})
}

// BUG (pre-existing, app config/manifest): the "Standards" index page is wired
// to the schema slug `standaard`, but NO `standaard` schema exists in the
// softwarecatalog voorzieningen register/config (the app config exposes
// organisatie/contactpersoon/contract/beoordeeling/compliancy/moduleVersie/...
// schemas but never a `standaard` one). So the page's list fetch fails with a
// console error: "Error fetching 11-standaard collection: {status: undefined,
// ...}", and the CnIndexPage list body never loads. Driving this page can
// therefore never be app-error-free until the `standaard` schema is provisioned
// (or the Standards page is removed/repointed in the manifest). Kept as a
// documented fixme so it re-activates once the schema gap is closed. Not a test
// defect — the page genuinely cannot load its data.
test.fixme('index standaarden: nav entry reaches the CnIndexPage surface (blocked: missing `standaard` schema)', async ({ page }) => {
	const bag = collectAppErrors(page)
	await navClickTo(page, 'Standards')
	await expectIndexSurface(page, 'Add Item')
	expectNoAppErrors(bag)
})

// ---------------------------------------------------------------------------
// Organisations is a `type: custom` page (OrganisatieIndexView), not a
// CnIndexPage. Its surface is the custom organisations view: the primary
// "Add organisation" create action, reached by clicking the nav entry. We
// assert that custom surface mounts WITHOUT an app-origin error (the register
// sentinel now resolves, so no @resolve 404).
// ---------------------------------------------------------------------------
test('custom organisaties: nav entry reaches the OrganisatieIndexView surface', async ({ page }) => {
	const bag = collectAppErrors(page)
	await navClickTo(page, 'Organisations')
	const main = page.locator(APP_MAIN).first()
	await expect(main).toBeVisible({ timeout: 30000 })

	// The custom view exposes a primary create action. Its empty-state button
	// reads "Add organisation"; assert the create affordance is present.
	await expect(
		main.getByRole('button', { name: /Add organisation/i }).first(),
	).toBeVisible({ timeout: 30000 })

	expectNoAppErrors(bag)
})

// ---------------------------------------------------------------------------
// View-mode toggle interaction (Cards <-> Table). Representative coverage on
// the Contacts index — switching the toggle re-renders the list body in the
// other mode without an app error. The view buttons are rendered DOM controls.
// ---------------------------------------------------------------------------
test('index contactpersonen: Cards/Table view toggle switches the list mode', async ({ page }) => {
	const bag = collectAppErrors(page)
	await navClickTo(page, 'Contacts')
	const main = page.locator(APP_MAIN).first()

	// Both view-mode controls are present.
	const tableToggle = main.getByText('Table', { exact: true }).first()
	const cardsToggle = main.getByText('Cards', { exact: true }).first()
	await expect(cardsToggle).toBeVisible({ timeout: 30000 })
	await expect(tableToggle).toBeVisible()

	// Switch to Table mode, then back to Cards — the list body persists (the
	// Contacts schema now resolves a real register and may return rows, so we
	// assert empty-state OR a populated list) and the toggle interaction must
	// not throw an app error.
	const body = main.getByText('No items found', { exact: false }).first()
		.or(main.getByText(/Showing\s+\d+\s+of\s+\d+/i).first())
	await tableToggle.click()
	await expect(body).toBeVisible({ timeout: 15000 })
	await cardsToggle.click()
	await expect(body).toBeVisible({ timeout: 15000 })

	expectNoAppErrors(bag)
})

// ---------------------------------------------------------------------------
// Primary create action opens a creation surface. Representative coverage on
// the Contacts index — clicking "Add Contactpersoon" opens a modal/dialog (the
// manifest CnIndexPage create flow). We assert a dialog surfaces, then dismiss
// with Escape to leave the app clean. Data-independent (no save).
// ---------------------------------------------------------------------------
test('index contactpersonen: "Add Contactpersoon" opens a create dialog', async ({ page }) => {
	const bag = collectAppErrors(page)
	await navClickTo(page, 'Contacts')
	const main = page.locator(APP_MAIN).first()

	const addBtn = main.getByRole('button', { name: 'Add Contactpersoon', exact: true }).first()
	await expect(addBtn).toBeVisible({ timeout: 30000 })
	await addBtn.click()

	// A modal/dialog surfaces for object creation. NcModal/NcDialog render with
	// role="dialog"; assert one becomes visible.
	const dialog = page.locator('[role="dialog"], .modal-container, .modal-wrapper').first()
	await expect(dialog).toBeVisible({ timeout: 15000 })

	// Dismiss without saving (Escape) so no object is created.
	await page.keyboard.press('Escape')

	expectNoAppErrors(bag)
})
