// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * API documentation: a developer signed in to stackiq opens the page from the
 * footer menu and reads both OpenAPI documents in the app, each downloadable.
 *
 * Read-only: nothing is seeded. The stackiq endpoints document is the bundled
 * openapi.json; the catalogue objects document is OpenRegister's document of
 * the stackiq register.
 *
 * @spec openspec/changes/archive/2026-11-25-sharing-generated-api-docs/specs/generated-api-docs/spec.md#requirement-req-gad-002-a-developer-reads-both-documents-on-one-page-in-stackiq
 */
import { expect, test } from '@playwright/test'
import { readFileSync } from 'node:fs'
import { APP_MAIN, dismissSupportDialog, navClickTo } from './_ui.ts'

test.describe('API documentation', () => {
	test.beforeEach(async ({ page }) => {
		await navClickTo(page, 'API documentation')
		await dismissSupportDialog(page)
		await expect(
			page
				.locator(APP_MAIN)
				.getByRole('heading', { name: 'API documentation' }).first(),
		).toBeVisible()
	})

	test("a supplier's developer reads the offered usage endpoint", async ({
		page,
	}) => {
		const main = page.locator(APP_MAIN)
		await main.getByRole('tab', { name: 'Stackiq endpoints' }).click()
		const reference = main.locator('[data-testid="cn-api-reference"]:visible')
		await expect(reference).toBeVisible()
		await reference
			.getByTestId('cn-api-reference-filter')
			.locator('input')
			.fill('koppelingen-gebruik')
		const operation = reference.getByRole('button', {
			name: /GET\s+\/koppelingen-gebruik\/\{uuid\}/,
		})
		await expect(operation).toBeVisible()
		await operation.click()
		await expect(
			reference.getByText('uuid', { exact: true }).first(),
		).toBeVisible()
		await expect(reference.getByText('200').first()).toBeVisible()
	})

	test('downloading the catalogue objects document', async ({ page }) => {
		const main = page.locator(APP_MAIN)
		await main.getByRole('tab', { name: 'Catalogue objects' }).click()
		const reference = main.locator('[data-testid="cn-api-reference"]:visible')
		await expect(reference).toBeVisible()
		const [download] = await Promise.all([
			page.waitForEvent('download'),
			reference.getByTestId('cn-api-reference-download').click(),
		])
		expect(download.suggestedFilename()).toMatch(/\.json$/)
		const body = JSON.parse(readFileSync(await download.path(), 'utf8'))
		expect(typeof body.openapi).toBe('string')
		expect(Object.keys(body.paths ?? {}).length).toBeGreaterThan(0)
	})

	test('the page loads no third-party viewer', async ({ page }) => {
		const origin = new URL(page.url()).origin
		const foreign: string[] = []
		page.on('request', (request) => {
			if (
				!request.url().startsWith(origin)
				&& !request.url().startsWith('data:')
			) {
				foreign.push(request.url())
			}
		})
		await page
			.locator(APP_MAIN)
			.getByRole('tab', { name: 'Catalogue objects' })
			.click()
		await page
			.locator(APP_MAIN)
			.getByRole('tab', { name: 'Stackiq endpoints' })
			.click()
		await page.waitForTimeout(500)
		expect(foreign).toEqual([])
	})
})
