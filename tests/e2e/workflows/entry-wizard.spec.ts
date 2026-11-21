// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * The entry wizard (capability-requirements-from-the-matrix, task 1): a new
 * application and a new connection are added through details, related
 * records and a summary; a required field left empty keeps its step open and
 * names the field; after the save the record's page opens.
 *
 * Seeds a supplier and two applications carrying this run's RUN_ID for the
 * connection, and removes those rows and everything the wizard created with
 * the same RUN_ID afterwards.
 *
 * @spec openspec/changes/archive/2026-11-20-capability-requirements-from-the-matrix/tasks.md
 */
import type { APIRequestContext, Locator, Page } from '@playwright/test'
import type { VoorzieningenConfig } from './_fixtures.ts'

import { expect, test } from '@playwright/test'
import { randomUUID } from 'node:crypto'
import {
	createObject,
	deleteObject,
	findAll,
	nameOf,
	newApiContext,
	resolveConfig,
	RUN_ID,
} from './_fixtures.ts'
import { dismissSupportDialog, gotoAppRoute } from './_ui.ts'

let apiCtx: APIRequestContext
let cfg: VoorzieningenConfig
const seeded: Array<[string, string]> = []
const ids: Record<string, string> = {}
const appX = `${RUN_ID} wizard application X`
const appY = `${RUN_ID} wizard application Y`

/**
 * Create a row and remember it for cleanup.
 *
 * @param schema The schema slug.
 * @param data The object.
 * @return The new id.
 */
async function seed(schema: string, data: Record<string, unknown>): Promise<string> {
	const id = await createObject(apiCtx, cfg.register, schema, data)
	seeded.push([schema, id])
	return id
}

/**
 * Open the wizard from a list page's Add.
 *
 * @param page The page.
 * @param route The list route.
 * @return The wizard dialog.
 */
async function openWizard(page: Page, route: string): Promise<Locator> {
	await gotoAppRoute(page, route)
	await dismissSupportDialog(page)
	await page.getByTestId('entry-wizard-add').click()
	const dialog = page.getByRole('dialog')
	await expect(dialog.getByTestId('entry-wizard-details')).toBeVisible({
		timeout: 30000,
	})
	return dialog
}

/**
 * Pick an option in one of the wizard's record selects by typing its name.
 *
 * @param dialog The wizard dialog.
 * @param key The relation key.
 * @param name The record's name.
 */
async function pick(dialog: Locator, key: string, name: string): Promise<void> {
	const select = dialog.getByTestId(`entry-wizard-relation-${key}`)
	await select.locator('input').first().fill(name)
	await dialog.page().getByRole('option', { name }).first().click()
}

test.beforeAll(async () => {
	apiCtx = await newApiContext()
	cfg = await resolveConfig(apiCtx)
	ids.supplier = await seed('organization', {
		name: `${RUN_ID} wizard supplier`,
		type: 'Supplier',
		status: 'Active',
		contactsUid: randomUUID(),
	})
	ids.x = await seed('module', { name: appX, provider: ids.supplier })
	ids.y = await seed('module', { name: appY, provider: ids.supplier })
})

test.afterAll(async () => {
	if (!apiCtx) return
	// What the wizard created carries RUN_ID in its name.
	for (const schema of ['connection', 'module']) {
		for (const row of await findAll(apiCtx, cfg.register, schema, RUN_ID)) {
			const id = String(
				(row as { id?: string }).id
					?? (row as { '@self'?: { id?: string } })['@self']?.id
					?? '',
			)
			if (
				nameOf(row).includes(RUN_ID)
				&& id !== ''
				&& !seeded.some(([, s]) => s === id)
			) {
				await deleteObject(apiCtx, cfg.register, schema, id)
			}
		}
	}
	for (const [schema, id] of seeded.reverse()) {
		await deleteObject(apiCtx, cfg.register, schema, id)
	}
	await apiCtx.dispose()
})

test('a required field left empty keeps the step open and names it', async ({
	page,
}) => {
	const dialog = await openWizard(page, '/modules')

	await dialog.getByRole('button', { name: 'Next' }).click()

	await expect(dialog.getByTestId('entry-wizard-details')).toBeVisible()
	await expect(dialog).toContainText('Name')
	await expect(dialog.getByTestId('entry-wizard-summary')).toHaveCount(0)
})

test('a new application goes through details, related records and the summary, then opens', async ({
	page,
}) => {
	const name = `${RUN_ID} wizard new application`
	const dialog = await openWizard(page, '/modules')

	await dialog
		.getByTestId('entry-wizard-field-name')
		.locator('input')
		.first()
		.fill(name)
	await dialog.getByRole('button', { name: 'Next' }).click()
	await expect(dialog.getByTestId('entry-wizard-related')).toBeVisible()
	await dialog.getByRole('button', { name: 'Next' }).click()

	await expect(dialog.getByTestId('entry-wizard-summary')).toContainText(name)
	await dialog.getByRole('button', { name: 'Create application' }).click()

	await expect(page).toHaveURL(/\/modules\/[0-9a-f-]{36}/, { timeout: 30000 })
	const rows = await findAll(apiCtx, cfg.register, 'module', name)
	expect(rows.filter((row) => nameOf(row) === name)).toHaveLength(1)
})

test('a new connection links the two applications chosen in the wizard', async ({
	page,
}) => {
	const name = `${RUN_ID} wizard new connection`
	const dialog = await openWizard(page, '/koppelingen')

	await dialog
		.getByTestId('entry-wizard-field-name')
		.locator('input')
		.first()
		.fill(name)
	await dialog.getByRole('button', { name: 'Next' }).click()
	await expect(dialog.getByTestId('entry-wizard-related')).toBeVisible()
	await pick(dialog, 'moduleA', appX)
	await pick(dialog, 'moduleB', appY)
	await dialog.getByRole('button', { name: 'Next' }).click()

	const summary = dialog.getByTestId('entry-wizard-summary')
	await expect(summary).toContainText(name)
	await expect(summary).toContainText(appX)
	await expect(summary).toContainText(appY)
	await dialog.getByRole('button', { name: 'Create connection' }).click()

	await expect(page).toHaveURL(/\/koppelingen\/[0-9a-f-]{36}/, { timeout: 30000 })
	const [created] = (
		await findAll(apiCtx, cfg.register, 'connection', name)
	).filter((row) => nameOf(row) === name)
	expect(created).toBeTruthy()
	expect((created as Record<string, unknown>).moduleA).toBe(ids.x)
	expect((created as Record<string, unknown>).moduleB).toBe(ids.y)
})
