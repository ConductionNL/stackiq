/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/module-vulnerability-tracking/spec.md
 * (REQ-CLR-005 and REQ-CLR-018: a vulnerability opens from the list on its own page, with who is exposed).
 *
 * The admin API context seeds a municipality running an application in
 * production and a vulnerability against that application, and removes them.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { createObject, deleteObject, newApiContext, resolveConfig, RUN_ID } from './_fixtures.ts'

const CVE = `CVE-2026-${RUN_ID.slice(-5)}`

test.describe('vulnerability page', () => {
	let admin: APIRequestContext
	let register = ''
	const seeded: Array<[string, string]> = []
	const ids: Record<string, string> = {}

	test.beforeAll(async () => {
		admin = await newApiContext()
		register = (await resolveConfig(admin)).register
		const seed = async (schema: string, data: Record<string, unknown>): Promise<string> => {
			const id = await createObject(admin, register, schema, data)
			seeded.push([schema, id])
			return id
		}
		ids.org = await seed('organization', { name: `Gemeente ${RUN_ID}`, type: 'Municipality' })
		ids.module = await seed('module', { name: `Zaaksysteem ${RUN_ID}` })
		ids.usage = await seed('usage', { consumer: ids.org, module: ids.module, status: 'In production' })
		ids.vulnerability = await seed('vulnerability', { name: CVE, cveCode: CVE, cvssScore: 9.1, modules: [ids.module] })
	})

	test.afterAll(async () => {
		for (const [schema, id] of seeded.reverse()) {
			await deleteObject(admin, register, schema, id)
		}
		await admin.dispose()
	})

	// @e2e openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/module-vulnerability-tracking/spec.md#scenario-a-security-officer-opens-a-vulnerability-from-the-list
	// @e2e openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/module-vulnerability-tracking/spec.md#scenario-a-security-officer-sees-who-is-exposed
	test('a row opens the vulnerability page, which shows the affected application and who is exposed', async ({ page }) => {
		await page.goto('/index.php/apps/stackiq/kwetsbaarheden', { waitUntil: 'domcontentloaded' })
		const row = page.locator('[data-testid="vuln-row"]', { hasText: CVE })
		await expect(row).toBeVisible({ timeout: 30_000 })
		await row.click()

		await expect(page).toHaveURL(new RegExp(`/kwetsbaarheden/${ids.vulnerability}`), { timeout: 30_000 })
		await expect(page.getByText(`Zaaksysteem ${RUN_ID}`).first()).toBeVisible({ timeout: 30_000 })

		await page.getByRole('tab', { name: /exposure|blootstelling/i }).click()
		await expect(page.getByText(`Gemeente ${RUN_ID}`).first()).toBeVisible({ timeout: 30_000 })
	})
})
