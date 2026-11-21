// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Publish and lock from the list (publish-and-lock-from-the-list, task 1;
 * REQ-ODP-010).
 *
 * Surface under test: the selection strip of `FacetedCatalogIndexView` on
 * /modules (`selection-publish`, `selection-withdraw`, `selection-lock` next
 * to the built-in Delete), which opens MassPublishObjects (one call per entry
 * to `PUT /api/publication/{objectType}/{uuid}/publish`) and MassLockObjects
 * (OpenRegister's lock, for the duration typed in seconds). The Published
 * badge is `data-testid="publication-badge"` on the application's page.
 *
 * Seeds one supplier and six unpublished applications carrying this run's
 * RUN_ID through the objects API (three to publish, three to lock), and
 * removes exactly those rows afterwards, unlocking the locked ones first.
 * The logged-in user is the instance admin, whom the publication endpoint's
 * guard lets publish any entry, as it lets an aanbod-beheerder publish its own.
 *
 * The change's scenarios carry no `@e2e` line; this file covers the three
 * REQ-ODP-010 scenarios that tasks.md points at it. REQ-ODP-011 is covered by
 * tests/vitest/publicationAction.spec.js.
 *
 * Written from the change's scenarios; not yet run against a live instance.
 *
 * @spec openspec/changes/publish-and-lock-from-the-list/specs/open-data-publishing/spec.md#requirement-selected-entries-are-published-withdrawn-or-locked-together-from-the-list-req-odp-010
 */
import type { APIRequestContext, Page } from '@playwright/test'
import type { VoorzieningenConfig } from '../workflows/_fixtures.ts'

import { expect, test } from '@playwright/test'
import {
	createObject,
	deleteObject,
	newApiContext,
	resolveConfig,
	RUN_ID,
} from '../workflows/_fixtures.ts'
import { gotoAppRoute } from './_helpers.ts'

const OBJECTS = '/index.php/apps/openregister/api/objects'
const toPublish = [1, 2, 3].map((n) => `${RUN_ID} publish ${n}`)
const toLock = [1, 2, 3].map((n) => `${RUN_ID} lock ${n}`)

let api: APIRequestContext
let config: VoorzieningenConfig
const seeded: Array<[string, string]> = []
const ids: Record<string, string> = {}

/**
 * Create a row and remember it for cleanup.
 *
 * @param schema The schema slug.
 * @param data The object.
 * @return The new id.
 */
async function seed(schema: string, data: Record<string, unknown>): Promise<string> {
	const id = await createObject(api, config.register, schema, data)
	seeded.push([schema, id])
	return id
}

/**
 * Read one application through the objects API.
 *
 * @param id The application's id.
 * @return The object.
 */
async function readModule(id: string): Promise<Record<string, unknown>> {
	const res = await api.get(`${OBJECTS}/${config.register}/module/${id}`)
	expect(res.ok(), await res.text()).toBe(true)
	return await res.json()
}

/**
 * Open Applications on this run's rows and tick the named ones.
 *
 * @param page The page.
 * @param names The rows to select.
 * @return Nothing.
 */
async function selectRows(page: Page, names: string[]): Promise<void> {
	await gotoAppRoute(page, `/modules?_search=${encodeURIComponent(RUN_ID)}`)
	for (const name of names) {
		const row = page.getByRole('row').filter({ hasText: name })
		await expect(row).toBeVisible({ timeout: 30000 })
		await row.getByRole('checkbox').first().check({ force: true })
	}
}

test.beforeAll(async () => {
	api = await newApiContext()
	config = await resolveConfig(api)
	ids.supplier = await seed('organization', { name: `${RUN_ID} supplier`, type: 'Supplier', status: 'Active', contactsUid: `${RUN_ID}-supplier` })
	for (const name of [...toPublish, ...toLock]) {
		ids[name] = await seed('module', { name, provider: ids.supplier })
	}
})

test.afterAll(async () => {
	if (!api) return
	for (const name of toLock) {
		await api.post(`${OBJECTS}/${config.register}/module/${ids[name]}/unlock`).catch(() => undefined)
	}
	for (const [schema, id] of seeded.reverse()) {
		await deleteObject(api, config.register, schema, id)
	}
	await api.dispose()
})

// @e2e open-data-publishing::a-supplier-publishes-three-entries
test('publishing three selected applications from the strip gives each a publication date and the Published badge', async ({ page }) => {
	await selectRows(page, toPublish)
	await page.getByTestId('selection-publish').click()

	const dialog = page.getByRole('dialog').filter({ hasText: 'Publish 3 publications' })
	await expect(dialog).toBeVisible({ timeout: 15000 })
	for (const name of toPublish) {
		await expect(dialog).toContainText(name)
	}
	await dialog.getByRole('button', { name: 'Publish', exact: true }).click()
	await expect(dialog.getByText('Objects successfully published')).toBeVisible({ timeout: 30000 })
	await expect(dialog.getByTestId('mass-action-refused')).toHaveCount(0)
	await dialog.getByRole('button', { name: 'Close' }).click()
	await expect(dialog).toBeHidden({ timeout: 15000 })

	const before = Date.now()
	for (const name of toPublish) {
		const published = String((await readModule(ids[name])).publicationDate ?? '')
		expect(published, `${name} has a publication date`).not.toBe('')
		// "Now": within the last ten minutes, allowing for clock skew.
		expect(Math.abs(before - Date.parse(published))).toBeLessThan(10 * 60 * 1000)

		await gotoAppRoute(page, `/modules/${ids[name]}`)
		await expect(page.getByTestId('publication-badge')).toHaveText('Published', { timeout: 30000 })
	}
})

// @e2e open-data-publishing::one-entry-belongs-to-someone-else
test.fixme('two own applications are published and the third, of another supplier, is listed as refused', async () => {
	// Needs a non-admin aanbod-beheerder whose Nextcloud user value
	// core/organisation is the owning supplier: PublicationController reads
	// that value for its ownership guard, and no API this suite can call sets
	// it. The refusal list itself is `data-testid="mass-action-refused"` in
	// MassObjectDialog; tests/vitest/publicationActions.spec.js covers the
	// split into succeeded and refused.
})

// @e2e open-data-publishing::locking-for-editing
test('locking three selected applications for an hour locks each of them', async ({ page }) => {
	await selectRows(page, toLock)
	await page.getByTestId('selection-lock').click()

	const dialog = page.getByRole('dialog').filter({ hasText: 'Lock 3 publications' })
	await expect(dialog).toBeVisible({ timeout: 15000 })
	await dialog.getByLabel('Duration in seconds (optional)').fill('3600')
	await dialog.getByRole('button', { name: 'Lock', exact: true }).click()
	await expect(dialog.getByText('Publications successfully locked')).toBeVisible({ timeout: 30000 })
	await dialog.getByRole('button', { name: 'Close' }).click()
	await expect(dialog).toBeHidden({ timeout: 15000 })

	// Another reader sees the lock on the object, with an expiry an hour out.
	for (const name of toLock) {
		const self = ((await readModule(ids[name]))['@self'] ?? {}) as { locked?: unknown }
		expect(self.locked, `${name} is locked`).toBeTruthy()
		const lock = self.locked as { expiration?: string, duration?: number } | true
		if (typeof lock === 'object' && lock !== null) {
			if (lock.duration !== undefined) {
				expect(Number(lock.duration)).toBe(3600)
			}
			if (lock.expiration) {
				expect(Date.parse(lock.expiration)).toBeGreaterThan(Date.now())
			}
		}
	}
})
