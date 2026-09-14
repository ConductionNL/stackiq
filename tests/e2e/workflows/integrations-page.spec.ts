/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Integrations page over integriq's connection registry
 * (adopt-connection-registry, hydra connection-registry D8 and D9).
 *
 * WHERE THE ROWS COME FROM. The rows are integriq's `app_connection` objects,
 * synced from stackiq's `lib/Settings/connections.json`, with `app` equal to
 * `stackiq`. Stackiq writes no row: a settings save asks integriq to resolve
 * again, a pull or a sync run reports what it met, and integriq decides the
 * status. So this spec needs integriq installed and synced, and reads the rows
 * from `/apps/openregister/api/objects/integriq/app_connection?app=stackiq`.
 *
 * `app` is a BARE filter key. The objects endpoint reads `filter[app]` as a
 * filter on nothing and answers the empty set without an error.
 *
 * NOT THE CATALOGUE'S `connection` SCHEMA. Stackiq's own register has a schema
 * with the slug `connection`. Every read here names integriq's register and
 * `app_connection` explicitly.
 *
 * WHAT A RED HERE USUALLY MEANS. An empty list in the first test means
 * integriq has not synced the declaration, or refused it whole.
 *
 * Locale: nothing forces the E2E language, so statuses are read from the API
 * and rows are found by their declared titles, which are not translated.
 *
 * @e2e openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#the-page-lists-only-the-rows-of-stackiq
 * @e2e openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#add-integration-goes-to-integriq
 * @e2e openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#the-null-transport-reads-simulated-and-an-empty-one-does-not
 */
import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { APP_PATH } from '../base-url.ts'

/** Integriq's objects endpoint for stackiq's connection rows. */
const CONNECTIONS_API = '/index.php/apps/openregister/api/objects/integriq/app_connection?app=stackiq&_limit=50'

/** Stackiq's email settings endpoint, admin only. */
const EMAIL_SETTINGS_API = `${APP_PATH}/api/settings/email`

/** The declared keys and titles, in declared order. */
const DECLARED = [
	{ key: 'email', title: 'Email' },
	{ key: 'federation', title: 'Catalog federation' },
	{ key: 'eol-feed', title: 'End-of-life feed' },
]

/** Headers for the JSON API calls. */
const JSON_HEADERS = { 'OCS-APIRequest': 'true', Accept: 'application/json' }

/**
 * Stackiq's connection rows, keyed by connection key.
 *
 * @param request An admin request context.
 * @return The rows by key.
 */
async function rowsByKey(request: APIRequestContext): Promise<Record<string, Record<string, unknown>>> {
	const res = await request.get(CONNECTIONS_API, { headers: JSON_HEADERS })
	expect(res.ok(), `list integriq/app_connection -> ${res.status()}`).toBeTruthy()
	const body = await res.json()
	const byKey: Record<string, Record<string, unknown>> = {}
	for (const row of (body.results ?? []) as Record<string, unknown>[]) {
		// A row from another app here means the bare filter was dropped.
		expect(String(row.app), 'a connection row from another app').toBe('stackiq')
		byKey[String(row.key)] = row
	}
	return byKey
}

/**
 * Open the Integrations page the way its menu entry does, with the preset.
 *
 * @param page The Playwright page.
 */
async function openIntegrations(page: Page): Promise<void> {
	await page.goto(`${APP_PATH}/settings/integrations?app=stackiq`, { timeout: 60_000 })
	await expect(page.locator('.cn-index-page')).toBeVisible({ timeout: 30_000 })
}

test.describe('Integrations over the connection registry', () => {
	test('lists the three declared connections, all of them stackiq\'s', async ({ page }) => {
		const byKey = await rowsByKey(page.request)
		expect(Object.keys(byKey).sort()).toEqual(DECLARED.map((d) => d.key).sort())

		// Every row links to a section of stackiq's own admin page.
		for (const { key } of DECLARED) {
			expect(String(byKey[key]?.settingsUrl ?? ''), key).toMatch(/^\/settings\/admin\/stackiq#section-/)
		}

		await openIntegrations(page)
		for (const { title } of DECLARED) {
			await expect(page.getByRole('row', { name: new RegExp(`^${title}\\b`, 'i') })).toHaveCount(1)
		}
	})

	test('reads Simulated once the null transport is saved, and not before', async ({ page }) => {
		const before = await page.request.get(EMAIL_SETTINGS_API, { headers: JSON_HEADERS })
		expect(before.ok(), `email settings read -> ${before.status()}`).toBeTruthy()
		const previous = String((await before.json())?.emailSettings?.transportType ?? 'smtp')
		test.skip(previous === 'null', 'This instance already runs the null transport, so there is no change to observe.')

		/**
		 * The email row's status, read without asserting: a throw inside
		 * `expect.poll` ends the poll instead of retrying it.
		 *
		 * @return The status, or '' when the row is missing.
		 */
		const emailStatus = async (): Promise<string> => {
			const list = await page.request.get(CONNECTIONS_API, { headers: JSON_HEADERS })
			const rows = list.ok() ? ((await list.json()).results ?? []) : []
			const row = rows.find((r: Record<string, unknown>) => r.key === 'email' && r.app === 'stackiq')
			return String(row?.status ?? '')
		}

		expect(await emailStatus()).not.toBe('simulated')

		try {
			// The save sends ConnectionRefreshRequestedEvent, and integriq's rule 3
			// reads `null` from email_transport_type.
			const res = await page.request.post(EMAIL_SETTINGS_API, {
				headers: JSON_HEADERS,
				data: { emailSettings: { transportType: 'null' } },
			})
			expect(res.ok(), `email settings save -> ${res.status()}`).toBeTruthy()

			await expect.poll(emailStatus, { timeout: 15_000 }).toBe('simulated')
		} finally {
			// Put the VALUE back. The restore is a save too, so it refreshes the row again.
			await page.request.post(EMAIL_SETTINGS_API, {
				headers: JSON_HEADERS,
				data: { emailSettings: { transportType: previous } },
			})
		}

		await expect.poll(emailStatus, { timeout: 15_000 }).not.toBe('simulated')
	})

	test('sends Add integration to integriq instead of offering a form', async ({ page }) => {
		await openIntegrations(page)

		// No generic Add button: a row nothing declared has nothing to check.
		await expect(page.locator('[data-testid="cn-cta-primary"]')).toHaveCount(0)

		// The action lives in the overflow menu. English and Dutch are the two
		// catalogues this change ships, and nothing forces the E2E locale.
		await page.locator('[data-testid="cn-actions"] button').first().click()
		await Promise.all([
			page.waitForURL(/\/apps\/integriq\/connections\?app=stackiq&link=1$/, { timeout: 30_000 }),
			page.getByRole('menuitem', { name: /Add integration|Integratie toevoegen/i }).click(),
		])
	})
})
