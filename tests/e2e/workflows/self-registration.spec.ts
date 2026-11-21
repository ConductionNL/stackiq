// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Self-registration: a visitor who is not signed in signs up an organisation
 * through the public sign-up form, and the registration lands in the admin's
 * moderation queue as pending (REQ in open-data-publishing, task 12).
 *
 * Task 12 is not built: stackiq serves no public page for the form yet, and
 * where the form lives is an open decision. The scenario is written against
 * the intake endpoint the form posts to (`POST /api/intake/register`) and is
 * marked fixme until the form exists. The second scenario of the requirement
 * (a generic create cannot skip the intake) is a server rule, covered by
 * PHPUnit, not here.
 *
 * Written from the change's scenario; not yet run against a live instance.
 *
 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/open-data-publishing/spec.md
 */
import { expect, request as playwrightRequest, test } from '@playwright/test'
import { BASE_URL, deleteObject, findAll, newApiContext, resolveConfig, RUN_ID } from './_fixtures.ts'

const supplier = `${RUN_ID} Leverancier B.V.`

// @e2e open-data-publishing::a-supplier-signs-up-without-an-account
test.fixme('a supplier signs up without an account and appears as pending in the moderation queue', async ({ browser }) => {
	// A visitor with no session at all.
	const context = await browser.newContext({ baseURL: BASE_URL, storageState: { cookies: [], origins: [] } })
	const page = await context.newPage()
	await page.goto('/index.php/apps/stackiq/sign-up', { waitUntil: 'domcontentloaded' })
	await page.getByLabel('Organisation name').fill(supplier)
	await page.getByRole('button', { name: 'Send' }).click()
	await expect(page.getByText('Registration received')).toBeVisible({ timeout: 30000 })
	await context.close()

	// The admin finds it pending.
	const admin = await newApiContext()
	const cfg = await resolveConfig(admin)
	const found = (await findAll(admin, cfg.register, cfg.organisatie_schema, RUN_ID)).find((org) => org.name === supplier)
	expect(found, 'the registration is stored').toBeTruthy()
	expect(found?.registrationStatus).toBe('pending')

	// An anonymous caller cannot read it back.
	const anonymous = await playwrightRequest.newContext({ baseURL: BASE_URL, storageState: { cookies: [], origins: [] } })
	const listed = await anonymous.get(`/index.php/apps/openregister/api/objects/${cfg.register}/${cfg.organisatie_schema}?_search=${encodeURIComponent(RUN_ID)}`)
	const rows = listed.ok() ? ((await listed.json())?.results ?? []) : []
	expect((rows as Array<{ name?: string }>).map((row) => row.name)).not.toContain(supplier)
	await anonymous.dispose()

	const id = String((found as { id?: string } | undefined)?.id ?? '')
	if (id) await deleteObject(admin, cfg.register, cfg.organisatie_schema, id)
	await admin.dispose()
})
