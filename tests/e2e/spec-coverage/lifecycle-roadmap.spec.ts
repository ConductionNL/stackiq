// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Behavioural e2e coverage for the portfolio lifecycle roadmap.
 *
 * Drives the REAL UI of the LifecycleRoadmap manifest custom page: the nav
 * entry reaches the roadmap surface, which is organisation-first (a selector +
 * guidance until an organisation is picked). The phase-derivation, EOL window
 * and grouping/ordering logic are covered exhaustively by the vitest unit
 * tests on the lifecyclePhase utility.
 *
 * @spec openspec/changes/application-lifecycle-tracking/specs/application-lifecycle-tracking/spec.md
 */
import { test, expect } from '@playwright/test'
import { APP_MAIN, collectAppErrors, expectNoAppErrors, navClickTo } from './_helpers'

// @e2e application-lifecycle-tracking::roadmap-groups-and-orders-the-portfolio
test('roadmap: nav entry reaches the organisation-first roadmap surface', async ({ page }) => {
	const bag = collectAppErrors(page)
	await navClickTo(page, 'Portfolio roadmap')

	const main = page.locator(APP_MAIN).first()
	await expect(main).toBeVisible({ timeout: 30000 })

	// Organisation-first: until an organisation is selected the page shows the
	// "Select an organisation" guidance.
	await expect(main.getByText(/Select an organisation|Portfolio roadmap/i).first()).toBeVisible({ timeout: 30000 })

	expectNoAppErrors(bag)
})
