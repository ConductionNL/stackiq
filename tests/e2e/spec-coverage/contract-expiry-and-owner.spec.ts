// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Behavioural e2e coverage for contracts-expiry-and-owner.
 *
 * Runs against the demo data (`lib/Settings/stackiq_mock_register.json`), which
 * holds one Expiring contract with a responsible user ("Voorbeeld Contractnumber
 * 6", responsible `admin`) and one Expired contract ("Voorbeeld Contractnumber
 * 2"). The quick filters are driven through the real Contracten index; the
 * responsible user is picked in the real contract edit form and read back on
 * the detail page and in the index column.
 *
 * The status job, the notice window and the expiry recipients are server-side
 * and covered by ContractStatusServiceTest and ContractLifecycleDeclarationTest;
 * the free-text refusal by tests/vitest/contractResponsibleUser.spec.js.
 *
 * @spec openspec/changes/contracts-expiry-and-owner/specs/contract-expiry-and-owner/spec.md
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	APP_MAIN,
	collectAppErrors,
	expectNoAppErrors,
	gotoAppRoute,
} from './_helpers.ts'

/** The demo data's Expiring contract, with a responsible user. */
const EXPIRING = 'Voorbeeld Contractnumber 6'

/** The demo data's Expired contract. */
const EXPIRED = 'Voorbeeld Contractnumber 2'

/** The user the edit form names as responsible. */
const RESPONSIBLE = process.env.NC_ADMIN_USER || process.env.NC_USER || 'admin'

/**
 * Open the Contracten index and choose one quick filter.
 *
 * @param page The page.
 * @param label The quick filter's label.
 */
async function openWithQuickFilter(page: Page, label: string): Promise<void> {
	await gotoAppRoute(page, '/contracten')
	const main = page.locator(APP_MAIN).first()
	const filter = main.getByRole('tab', { name: label, exact: true }).or(main.getByRole('button', { name: label, exact: true }))
	await filter.first().waitFor({ state: 'visible', timeout: 30000 })
	await filter.first().click()
}

/**
 * The table row of one contract on the index.
 *
 * @param page The page.
 * @param contractNumber The contract number.
 * @return The row locator.
 */
function rowOf(page: Page, contractNumber: string) {
	return page.locator(APP_MAIN).first().locator('tr').filter({ hasText: contractNumber })
}

// @e2e contract-expiry-and-owner::an-information-manager-lists-the-contracts-about-to-expire
test('the Expiring quick filter lists the expiring contract and not the expired one', async ({ page }) => {
	const bag = collectAppErrors(page)

	await openWithQuickFilter(page, 'Expiring')
	await expect(rowOf(page, EXPIRING).first(), 'the Expiring filter must list the expiring contract').toBeVisible({ timeout: 30000 })
	await expect(rowOf(page, EXPIRED), 'the Expiring filter must not list the expired contract').toHaveCount(0)

	await openWithQuickFilter(page, 'Expired')
	await expect(rowOf(page, EXPIRED).first(), 'the Expired filter must list the expired contract').toBeVisible({ timeout: 30000 })
	await expect(rowOf(page, EXPIRING), 'the Expired filter must not list the expiring contract').toHaveCount(0)

	expectNoAppErrors(bag)
})

// @e2e contract-expiry-and-owner::the-status-column-shows-the-expiring-state
test('the status column reads Expiring and the Responsible user column names the user', async ({ page }) => {
	const bag = collectAppErrors(page)

	await gotoAppRoute(page, '/contracten')
	const main = page.locator(APP_MAIN).first()
	await expect(main.getByRole('columnheader', { name: /Responsible user/i }).first()).toBeVisible({ timeout: 30000 })

	const row = rowOf(page, EXPIRING).first()
	await expect(row).toBeVisible({ timeout: 30000 })
	await expect(row).toContainText('Expiring')
	await expect(row).toContainText('admin')

	expectNoAppErrors(bag)
})

// @e2e contract-expiry-and-owner::an-application-owner-names-the-responsible-person
test('picking a responsible user in the edit form shows on the detail page and in the index', async ({ page }) => {
	test.setTimeout(120000)
	const bag = collectAppErrors(page)

	await gotoAppRoute(page, '/contracten')
	const row = rowOf(page, EXPIRED).first()
	await expect(row).toBeVisible({ timeout: 30000 })
	await row.click()
	await expect(page).toHaveURL(/\/contracten\/[^/]+/, { timeout: 30000 })

	const main = page.locator(APP_MAIN).first()
	await main.getByRole('button', { name: /^Edit/i }).first().click()
	const dialog = page.locator('[role="dialog"], .modal-container').first()
	await expect(dialog).toBeVisible({ timeout: 30000 })

	// The field is a user picker (referenceType nextcloud-user): type, then
	// choose an existing user from the options it offers.
	const picker = dialog.getByLabel('Responsible user').first()
	await picker.click()
	await picker.fill(RESPONSIBLE)
	await page.getByRole('option', { name: new RegExp(RESPONSIBLE, 'i') }).first().click({ timeout: 30000 })
	await dialog.getByRole('button', { name: /^Save/i }).first().click()
	await expect(dialog).toBeHidden({ timeout: 30000 })

	await expect(main.getByText(/Responsible user/i).first()).toBeVisible({ timeout: 30000 })
	await expect(main).toContainText(RESPONSIBLE)

	await gotoAppRoute(page, '/contracten')
	await expect(rowOf(page, EXPIRED).first()).toContainText(RESPONSIBLE, { timeout: 30000 })

	expectNoAppErrors(bag)
})
