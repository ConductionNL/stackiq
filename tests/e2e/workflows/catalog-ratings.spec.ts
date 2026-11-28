// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Catalog ratings: a user rates an application on its page, and the review
 * is saved with that rating and the status pending (REQ-CLR-009).
 *
 * Seeds one application carrying this run's RUN_ID through the OpenRegister
 * API, opens its page, writes a review with rating 8 through the rendered
 * "Write a review" dialog, and reads the saved review back through the API.
 * Afterwards the review and the application are deleted.
 *
 * Written from the change's scenario; not yet run against a live instance.
 *
 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/catalog-ratings/spec.md#requirement-a-review-carries-a-rating-from-1-to-10-req-clr-009
 */
import type { APIRequestContext } from '@playwright/test'
import type { VoorzieningenConfig } from './_fixtures.ts'

import { expect, test } from '@playwright/test'
import { createObject, deleteObject, findAll, newApiContext, resolveConfig, RUN_ID } from './_fixtures.ts'
import { dismissSupportDialog, gotoAppRoute } from './_ui.ts'

const REVIEW_SCHEMA = 'software-review'
const appName = `${RUN_ID} rated application`
const reviewTitle = `${RUN_ID} review`

let apiCtx: APIRequestContext
let cfg: VoorzieningenConfig
let moduleId = ''

test.describe.configure({ mode: 'serial' })

test.beforeAll(async () => {
	apiCtx = await newApiContext()
	cfg = await resolveConfig(apiCtx)
	moduleId = await createObject(apiCtx, cfg.register, cfg.module_schema, {
		name: appName,
		shortDescription: 'E2E application to rate',
	})
})

test.afterAll(async () => {
	if (!apiCtx) return
	for (const review of await findAll(apiCtx, cfg.register, REVIEW_SCHEMA, RUN_ID)) {
		const id = String((review as { id?: string }).id ?? (review as { '@self'?: { id?: string } })['@self']?.id ?? '')
		if (id) await deleteObject(apiCtx, cfg.register, REVIEW_SCHEMA, id)
	}
	if (moduleId) await deleteObject(apiCtx, cfg.register, cfg.module_schema, moduleId)
	await apiCtx.dispose()
})

// @e2e catalog-ratings::a-user-rates-an-application-they-use
test('a user rates an application on its page and the review is saved with rating 8, pending', async ({ page }) => {
	await gotoAppRoute(page, `/modules/${moduleId}`)
	await dismissSupportDialog(page)
	await expect(page.getByText(appName).first()).toBeVisible({ timeout: 30000 })

	await page.getByRole('button', { name: 'Write a review' }).first().click()
	const dialog = page.getByRole('dialog', { name: 'Write a review' })
	await expect(dialog).toBeVisible()
	await dialog.getByLabel('Title').fill(reviewTitle)
	await dialog.getByLabel('Rating (1-10)').click()
	await page.getByRole('option', { name: '8', exact: true }).click()
	await dialog.getByLabel('Testimonial').fill('Works well for us.')
	await dialog.getByRole('button', { name: 'Submit review' }).click()
	await expect(dialog).toBeHidden({ timeout: 30000 })

	const saved = (await findAll(apiCtx, cfg.register, REVIEW_SCHEMA, RUN_ID)).find((review) => review.name === reviewTitle)
	expect(saved, 'the review is saved').toBeTruthy()
	expect(saved?.rating).toBe(8)
	expect(saved?.status).toBe('pending')
})
