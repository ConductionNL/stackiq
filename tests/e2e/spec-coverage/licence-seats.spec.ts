// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Licence seats: a licence contract records its metric and its counts, the
 * contract page sets licences in use against licences bought, and the License
 * posture page lists the contracts over their licence first.
 *
 * Seeds two contracts through the OpenRegister objects API (the same call the
 * contract form makes) and removes them afterwards. The seat maths and the
 * uncounted metrics are covered by tests/vitest/licenceSeats.spec.js.
 *
 * @spec openspec/specs/licence-seats/spec.md
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { collectAppErrors, expectNoAppErrors, gotoAppRoute } from './_helpers.ts'

const OBJECTS = '/index.php/apps/openregister/api/objects/stackiq/catalogContract'

/**
 * The request token of the loaded app page, which every write needs.
 *
 * @param page The page.
 * @return The token.
 */
async function requestToken(page: Page): Promise<string> {
	return page.evaluate(
		() =>
			(window as unknown as { OC?: { requestToken?: string } }).OC
				?.requestToken ?? '',
	)
}

/**
 * Create one licence contract and return its id.
 *
 * @param page The page.
 * @param fields The contract fields.
 * @return The new contract id.
 */
async function createContract(
	page: Page,
	fields: Record<string, unknown>,
): Promise<string> {
	const res = await page.request.post(OBJECTS, {
		headers: { requesttoken: await requestToken(page) },
		data: { contractType: 'Licence', status: 'Active', ...fields },
	})
	expect(res.ok(), await res.text()).toBe(true)
	const body = await res.json()
	return String(body.id ?? body['@self']?.id)
}

test.describe('licence seats', () => {
	const created: string[] = []

	test.afterEach(async ({ page }) => {
		const token = await requestToken(page).catch(() => '')
		for (const id of created.splice(0)) {
			await page.request
				.delete(`${OBJECTS}/${id}`, { headers: { requesttoken: token } })
				.catch(() => {})
		}
	})

	// @e2e licence-seats::an-application-owner-records-a-user-licence
	// @e2e licence-seats::use-over-the-licence-is-flagged
	test('the contract page reads Over licence by 60 with the metric and both counts', async ({
		page,
	}) => {
		const bag = collectAppErrors(page)
		await gotoAppRoute(page, '/contracten')
		const id = await createContract(page, {
			contractNumber: 'e2e-seats-over',
			licenceMetric: 'Per named user',
			licencesBought: 400,
			licencesInUse: 460,
		})
		created.push(id)

		await gotoAppRoute(page, `/contracten/${id}`)
		const panel = page.getByTestId('contract-seats-panel')
		await expect(panel).toBeVisible({ timeout: 30000 })
		await expect(page.getByTestId('contract-seats-state')).toHaveText(
			/Over licence by 60/,
		)
		await expect(panel).toContainText('Per named user')
		await expect(panel).toContainText('400')
		await expect(panel).toContainText('460')
		await expect(panel.locator('.cn-progress-bar')).toBeVisible()
		expectNoAppErrors(bag)
	})

	// @e2e licence-seats::an-information-manager-finds-the-contracts-over-their-licence
	test('the Seats section lists the over-licence contract first', async ({
		page,
	}) => {
		await gotoAppRoute(page, '/contracten')
		created.push(
			await createContract(page, {
				contractNumber: 'e2e-seats-within',
				licenceMetric: 'Per device',
				licencesBought: 100,
				licencesInUse: 50,
			}),
		)
		created.push(
			await createContract(page, {
				contractNumber: 'e2e-seats-over',
				licenceMetric: 'Per named user',
				licencesBought: 400,
				licencesInUse: 460,
			}),
		)

		await gotoAppRoute(page, '/license-posture')
		const rows = page
			.getByTestId('posture-seats')
			.getByTestId('posture-seat-row')
		await expect(rows.first()).toContainText('Over licence by 60', {
			timeout: 30000,
		})
		await expect(
			rows.filter({ hasText: 'Within licence' }).first(),
		).toBeVisible()
	})
})
