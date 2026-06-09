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
 * Data-fetch for these schemas currently returns the empty-state because the
 * manifest `@resolve:voorzieningen_register` register placeholder is forwarded
 * literally to the OpenRegister objects endpoint (HTTP 404 — see BUG LIST). The
 * render path under test is unaffected and those 404s are filtered as known
 * noise in collectAppErrors; we still assert no OTHER app-origin error / 5xx.
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

const INDEX_PAGES: IndexPage[] = [
	{ navLabel: 'Organisations', addLabel: 'Add Organisatie', name: 'organisaties' },
	{ navLabel: 'Contacts', addLabel: 'Add Contactpersoon', name: 'contactpersonen' },
	{ navLabel: 'Contracts', addLabel: 'Add Contract', name: 'contracten' },
	{ navLabel: 'Standards', addLabel: 'Add Item', name: 'standaarden' },
	{ navLabel: 'Reviews', addLabel: 'Add Beoordeeling', name: 'reviews' },
	{ navLabel: 'Compliance', addLabel: 'Add Compliancy', name: 'komplianties' },
	{ navLabel: 'Module versions', addLabel: 'Add Applicatieversie', name: 'moduleversies' },
]

for (const p of INDEX_PAGES) {
	test(`index ${p.name}: nav entry reaches the CnIndexPage surface (toggle + add + empty-state)`, async ({ page }) => {
		const bag = collectAppErrors(page)
		await navClickTo(page, p.navLabel)
		await expectIndexSurface(page, p.addLabel)
		expectNoAppErrors(bag)
	})
}

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

	// Switch to Table mode, then back to Cards — the empty-state persists (no
	// data) but the toggle interaction must not throw an app error.
	await tableToggle.click()
	await expect(main.getByText('No items found', { exact: false }).first()).toBeVisible({ timeout: 15000 })
	await cardsToggle.click()
	await expect(main.getByText('No items found', { exact: false }).first()).toBeVisible({ timeout: 15000 })

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
