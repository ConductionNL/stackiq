// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * ArchiMate round-trip check: an admin checks a small model file before
 * importing it in the ArchiMate section of the app's admin settings
 * (src/views/settings/sections/ArchiMateRoundTripCheck.vue), and reads a
 * row per category with the summary. The check writes nothing, so nothing is
 * seeded or cleaned up.
 *
 * The report rules are covered by tests/Unit/Service/ArchiMateModelComparatorTest.php,
 * tests/Unit/Service/ArchiMateRoundTripServiceTest.php,
 * tests/Unit/Controller/SettingsControllerRoundTripCheckTest.php,
 * tests/vitest/archiMateRoundTrip.spec.js and tests/vitest/archiMateRoundTripCheck.spec.js.
 *
 * Written from the change's scenarios; not yet run against a live instance.
 *
 * @spec openspec/changes/archive/2026-11-25-architecture-round-trip-check/specs/archimate-round-trip-check/spec.md#requirement-req-art-004-the-admin-settings-page-shall-show-the-round-trip-report
 * @spec openspec/changes/archive/2026-11-25-architecture-round-trip-check/specs/archimate-round-trip-check/spec.md#requirement-req-art-003-only-an-admin-shall-run-the-check-and-the-old-round-trip-endpoint-shall-be-removed
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { resolve } from 'node:path'
import { newApiContext } from './_fixtures.ts'
import { dismissSupportDialog } from './_ui.ts'

// The suite loads as CommonJS (no import.meta), as the other specs do with __dirname.
const FIXTURE = resolve(__dirname, '../../data/archimate_golden.xml')

let apiCtx: APIRequestContext

test.beforeAll(async () => {
	apiCtx = await newApiContext()
})

test.afterAll(async () => {
	await apiCtx.dispose()
})

test('an admin checks a small model before importing it', async ({ page }) => {
	await page.goto('/settings/admin/stackiq', { waitUntil: 'domcontentloaded' })
	await dismissSupportDialog(page)
	await expect(page.getByRole('heading', { name: 'Check a model file' })).toBeVisible({ timeout: 30000 })

	await page.getByLabel('Model file to check').setInputFiles(FIXTURE)
	await expect(page.getByRole('radio', { name: 'Before import (nothing is written)' })).toBeChecked()
	await page.getByRole('button', { name: 'Check', exact: true }).click()

	const result = page.getByTestId('round-trip-result')
	await expect(result).toBeVisible({ timeout: 60000 })
	for (const category of ['Elements', 'Relationships', 'Views']) {
		await expect(result.getByRole('rowheader', { name: category })).toBeVisible()
	}
	await expect(result.getByText(/^(No losses found|Losses in: .+)$/)).toBeVisible()
})

test('the old round-trip endpoint is gone', async () => {
	const res = await apiCtx.post('/index.php/apps/stackiq/api/archimate/test-round-trip')

	expect(res.status()).toBe(404)
})
