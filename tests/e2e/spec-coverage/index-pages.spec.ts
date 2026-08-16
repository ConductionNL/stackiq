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
import {
	navClickTo,
	gotoAppRoute,
	collectAppErrors,
	expectNoAppErrors,
	expectIndexSurface,
	APP_MAIN,
} from './_helpers'

interface IndexPage {
	/** Exact app-navigation link label. */
	navLabel: string
	/** Exact primary create-button label. */
	addLabel: string
	/** Slug for the test name. */
	name: string
}

// True manifest `type: index` pages (CnIndexPage against a voorzieningen
// schema). NOTE: "Organisations" IS one of these now (it was decomposed from a
// bespoke `type: custom` view), but its create label spells one word
// differently depending on which schema title an environment carries, and this
// list matches labels exactly — so it keeps a dedicated test below.
// ⚠️ `addLabel` is not free text. nc-vue's CnIndexPage derives its primary
// create action as `'Add ' + schema.title` (CnIndexPage.vue), and the
// softwarecatalog schema titles were rewritten from Dutch to English on
// 2026-07-26 (commit 13215dd). The Dutch spellings these entries used to carry
// — "Add Beoordeeling", "Add Applicatieversie", "Add Contactpersoon" — have not
// existed in the rendered UI since. "Add Contract" and "Add Compliancy" happen
// to be spelled identically in both, which is why only three of the five broke.
//
// `Contacts` is likewise absent: the nav entry was deliberately removed when
// contact/organisation identity moved to the Nextcloud addressbook, so
// `/contactpersonen` is reached by route below rather than by a nav click.
const INDEX_PAGES: IndexPage[] = [
	{ navLabel: 'Contracts', addLabel: 'Add Contract', name: 'contracten' },
	{ navLabel: 'Reviews', addLabel: 'Add Assessment', name: 'reviews' },
	{ navLabel: 'Compliance', addLabel: 'Add Compliancy', name: 'komplianties' },
	{
		navLabel: 'Module versions',
		addLabel: 'Add Application version',
		name: 'moduleversies',
	},
]

for (const p of INDEX_PAGES) {
	test(`index ${p.name}: nav entry reaches the CnIndexPage surface (toggle + add + list body)`, async ({
		page,
	}) => {
		const bag = collectAppErrors(page)
		await navClickTo(page, p.navLabel)
		await expectIndexSurface(page, p.addLabel)
		expectNoAppErrors(bag)
	})
}

// The contactpersonen index is a routable page with NO menu entry (see above),
// so the user's real path to it is the route, not a nav click. Everything else
// about the surface contract is unchanged.
test('index contactpersonen: the route reaches the CnIndexPage surface (toggle + add + list body)', async ({
	page,
}) => {
	const bag = collectAppErrors(page)
	await gotoAppRoute(page, '/contactpersonen')
	await expectIndexSurface(page, 'Add Contact person')
	expectNoAppErrors(bag)
})

// ⚠️ THIS TEST'S SKIP REASON WAS NO LONGER TRUE, so it was an invisible pass.
// It was a `test.fixme` "blocked: missing `standaard` schema", on the stated
// grounds that the Standards page is wired to the schema slug `standaard` and
// no such schema exists. It is not: `src/manifest.json` binds the Standaarden
// page to `"schema": "element"`, and `element` IS provisioned — the CI seed
// enumerates it among the 36 schemas present on the instance. Verified on a
// running instance as well: /standaarden renders the index chrome and its
// create action resolves to "Add Element" (not the "Add Item" fallback the
// skipped body asserted), with zero app-origin console errors.
//
// A skip whose reason has stopped being true reads exactly like a passing
// test, so the reason is not repaired here — the test is put back to work.
test('index standards: nav entry reaches the CnIndexPage surface (toggle + add + list body)', async ({
	page,
}) => {
	const bag = collectAppErrors(page)
	await navClickTo(page, 'Standards')
	await expectIndexSurface(page, 'Add Element')
	expectNoAppErrors(bag)
})

// ---------------------------------------------------------------------------
// Organisations. ⚠️ THIS TEST DESCRIBED A SURFACE THAT NO LONGER EXISTS: it
// asserted the bespoke `type: custom` OrganisatieIndexView and its
// "Add organisation" button. src/manifest.json decomposed that view into a
// standard `type: index` page (its own `_note` records the change), so the page
// is a CnIndexPage like every entry in INDEX_PAGES above — heading, Cards/Table
// toggle, create action, list body.
//
// It is kept as a dedicated test rather than folded into INDEX_PAGES for one
// reason: `expectIndexSurface` matches the create label EXACTLY, and this is
// the one page whose label spelling is not stable across environments.
// CnIndexPage derives it as `'Add ' + schema.title`; the repo authors that
// title "Organization" while the rest of the app is British, and a deployed
// instance can still serve the older "Organisation" because OpenRegister skips
// importing a schema whose deployed version is not older, and its
// schemaContentDiffers() escape hatch compares properties/required/
// authorization — never the title. Accept either spelling of that one word.
// ---------------------------------------------------------------------------
test('index organisaties: nav entry reaches the CnIndexPage surface (toggle + add + list body)', async ({
	page,
}) => {
	const bag = collectAppErrors(page)
	await navClickTo(page, 'Organisations')
	const main = page.locator(APP_MAIN).first()
	await expect(main).toBeVisible({ timeout: 30000 })

	// Index chrome — the view toggle is what separates "this is the index" from
	// "this is any page that happens to have a create button".
	await expect(main.getByText('Cards', { exact: true }).first()).toBeVisible({
		timeout: 30000,
	})
	await expect(main.getByText('Table', { exact: true }).first()).toBeVisible()

	// Primary create action.
	await expect(
		main.getByRole('button', { name: /^Add Organi[sz]ation$/i }).first(),
	).toBeVisible({ timeout: 30000 })

	// List body mounted — empty-state OR a populated list. Proves the data layer
	// ran (the `@resolve` register sentinel resolved), not just the chrome.
	const emptyState = main.getByText('No items found', { exact: false }).first()
	const populated = main.getByText(/Showing\s+\d+\s+of\s+\d+/i).first()
	await expect(emptyState.or(populated)).toBeVisible({ timeout: 30000 })

	expectNoAppErrors(bag)
})

// ---------------------------------------------------------------------------
// View-mode toggle interaction (Cards <-> Table). Representative coverage on
// the Contacts index — switching the toggle re-renders the list body in the
// other mode without an app error. The view buttons are rendered DOM controls.
// ---------------------------------------------------------------------------
test('index contactpersonen: Cards/Table view toggle switches the list mode', async ({
	page,
}) => {
	const bag = collectAppErrors(page)
	await gotoAppRoute(page, '/contactpersonen')
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
	const body = main
		.getByText('No items found', { exact: false })
		.first()
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
test('index contactpersonen: "Add Contact person" opens a create dialog', async ({
	page,
}) => {
	const bag = collectAppErrors(page)
	await gotoAppRoute(page, '/contactpersonen')
	const main = page.locator(APP_MAIN).first()

	const addBtn = main
		.getByRole('button', { name: 'Add Contact person', exact: true })
		.first()
	await expect(addBtn).toBeVisible({ timeout: 30000 })
	await addBtn.click()

	// A modal/dialog surfaces for object creation. NcModal/NcDialog render with
	// role="dialog"; assert one becomes visible.
	const dialog = page
		.locator('[role="dialog"], .modal-container, .modal-wrapper')
		.first()
	await expect(dialog).toBeVisible({ timeout: 15000 })

	// Dismiss without saving (Escape) so no object is created.
	await page.keyboard.press('Escape')

	expectNoAppErrors(bag)
})
