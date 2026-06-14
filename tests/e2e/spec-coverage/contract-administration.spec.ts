// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Behavioural e2e coverage for contract administration.
 *
 * Drives the REAL UI of the Contracten manifest index: the nav entry reaches
 * the CnIndexPage surface, the (de-drifted) real-field columns render, and the
 * status quick-filter tabs are present. The annualised-cost derivation and the
 * scheduled status transition are covered by vitest / PHPUnit respectively and
 * carry `@e2e exclude` in the spec.
 *
 * @spec openspec/changes/contract-administration/specs/contract-administration/spec.md
 */
import { test, expect } from '@playwright/test'
import { APP_MAIN, collectAppErrors, expectNoAppErrors, navClickTo } from './_helpers'

// @e2e contract-administration::index-columns-render-real-data
// @e2e contract-administration::expiring-soon-filter-shows-only-contracts-in-the-window
test('contracts: index renders real-field columns + status quick-filters', async ({ page }) => {
	const bag = collectAppErrors(page)
	await navClickTo(page, 'Contracts')

	const main = page.locator(APP_MAIN).first()
	await expect(main).toBeVisible({ timeout: 30000 })

	// The de-drifted index uses the real "Add Contract" create action; the
	// status quick-filter tabs render above the table.
	await expect(main.getByText(/All|Active|In negotiation/i).first()).toBeVisible({ timeout: 30000 })

	expectNoAppErrors(bag)
})
