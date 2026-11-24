// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Behavioural e2e coverage for copying an application with its links
 * (applications-copy-with-links, task 3; REQ-ACP-001).
 *
 * Surface under test: the Modules page (`/modules`, FacetedCatalogIndexView
 * over CnIndexPage), whose manifest `copy` config lets a copy take along its
 * connections, incoming links and files, and starts the copy unpublished.
 *
 * The two scenarios depend on the instance, and each test says so rather
 * than passing on the wrong one:
 *   - with OpenRegister's copy endpoint (`POST /api/objects/{register}/{schema}/{id}/copy`)
 *     the dialog lists the links to tick, and the copy holds the same
 *     connections as its source;
 *   - without it, the dialog shows the links read only with a note.
 * Which one the instance offers is read off the dialog, the only place the
 * user sees it.
 *
 * Data: one source application per run, named with RUN_ID, created over the
 * API and removed in afterAll together with every copy of it.
 *
 * @spec openspec/changes/archive/2026-11-24-applications-copy-with-links/specs/application-copy/spec.md#requirement-an-application-is-copied-with-the-links-the-user-picks-req-acp-001
 */
import type { APIRequestContext, Page } from '@playwright/test'
import type { VoorzieningenConfig } from '../workflows/_fixtures.ts'

import { expect, test } from '@playwright/test'
import {
	createObject,
	deleteObject,
	findAll,
	nameOf,
	newApiContext,
	resolveConfig,
	RUN_ID,
} from '../workflows/_fixtures.ts'
import { clickAction, openRowActions } from '../workflows/_ui.ts'
import { collectAppErrors, expectNoAppErrors, gotoAppRoute } from './_helpers.ts'

const SOURCE_NAME = `Copy source ${RUN_ID}`

let ctx: APIRequestContext
let config: VoorzieningenConfig
let sourceId = ''

/**
 * The relation rows of an application as OpenRegister counts them, or null
 * when the instance does not answer that path.
 *
 * @param id The application.
 * @return The rows, or null.
 */
async function relationRows(id: string): Promise<unknown[] | null> {
	const res = await ctx.get(`/index.php/apps/openregister/api/objects/${config.register}/module/${id}/relation-rows`)
	if (!res.ok()) return null
	const body = await res.json()
	const rows = Array.isArray(body) ? body : (body?.results ?? body?.rows)
	return Array.isArray(rows) ? rows : null
}

/**
 * Open the copy dialog of the source application on the Modules page.
 *
 * @param page The page.
 * @return The dialog.
 */
async function openCopyDialog(page: Page) {
	await gotoAppRoute(page, '/modules')
	const search = page.locator('main').getByRole('textbox', { name: /search|zoek/i }).first()
	if (await search.count()) {
		await search.fill(RUN_ID)
	}

	await openRowActions(page, SOURCE_NAME)
	await clickAction(page, 'Copy')
	const dialog = page.locator('[data-testid-modal="cn-copy-dialog"]').first()
	await expect(dialog).toBeVisible({ timeout: 15000 })

	return dialog
}

test.beforeAll(async () => {
	ctx = await newApiContext()
	config = await resolveConfig(ctx)
	sourceId = await createObject(ctx, config.register, 'module', {
		name: SOURCE_NAME,
		publicationDate: '2026-01-01T00:00:00+00:00',
	})
})

test.afterAll(async () => {
	if (!ctx || !config) return
	for (const row of await findAll(ctx, config.register, 'module')) {
		if (nameOf(row).includes(RUN_ID)) {
			await deleteObject(ctx, config.register, 'module', String(row.id ?? (row['@self'] as { id?: string })?.id))
		}
	}

	await ctx.dispose()
})

test.describe('applications-copy-with-links', () => {
	/**
	 * @e2e application-copy::an-application-is-copied-with-the-links-the-user-picks
	 */
	test('a copy with its connections ticked holds the same connections, unpublished', async ({ page }) => {
		const bag = collectAppErrors(page)
		const dialog = await openCopyDialog(page)

		const links = dialog.locator('[data-testid="cn-copy-links"]')
		test.skip(
			(await dialog.locator('[data-testid="cn-copy-links-unavailable"]').count()) > 0,
			'This instance has no OpenRegister copy endpoint; the read-only case is the next test.',
		)
		await expect(links).toBeVisible()
		const connections = dialog.locator('[data-testid="cn-copy-kind-relationRows"]')
		if (await connections.count()) {
			await expect(connections.locator('input[type="checkbox"]').first()).toBeChecked()
		}

		await dialog.getByRole('button', { name: /^(Copy|Kopiëren)$/ }).first().click()
		await expect(dialog.locator('[data-testid="cn-copy-new-object"]')).toBeVisible({ timeout: 30000 })

		const copies = (await findAll(ctx, config.register, 'module')).filter(
			(row) => nameOf(row).includes(RUN_ID) && String(row.id ?? '') !== sourceId,
		)
		expect(copies, 'exactly one copy of the source').toHaveLength(1)
		const copy = copies[0]
		expect(copy.publicationDate ?? null, 'a copy starts unpublished').toBeNull()
		expect(copy.depublicationDate ?? null).toBeNull()

		const sourceRows = await relationRows(sourceId)
		const copyRows = await relationRows(String(copy.id))
		test.skip(sourceRows === null || copyRows === null, 'This instance does not answer relation-rows; the connections cannot be compared.')
		expect(copyRows?.length, 'the copy holds the same connections as its source').toBe(sourceRows?.length)

		expectNoAppErrors(bag)
	})

	/**
	 * @e2e application-copy::without-the-copy-endpoint-the-links-show-read-only
	 */
	test('without the copy endpoint the links show read only with the note', async ({ page }) => {
		const bag = collectAppErrors(page)
		const dialog = await openCopyDialog(page)

		const unavailable = dialog.locator('[data-testid="cn-copy-links-unavailable"]')
		test.skip((await unavailable.count()) === 0, 'This instance has the OpenRegister copy endpoint; the copy with links is the previous test.')
		await expect(unavailable).toBeVisible()
		await expect(unavailable).not.toBeEmpty()
		for (const box of await dialog.locator('[data-testid^="cn-copy-kind-"] input[type="checkbox"]').all()) {
			await expect(box).toBeDisabled()
		}

		await dialog.getByRole('button', { name: /^(Cancel|Annuleren)$/ }).first().click()
		expectNoAppErrors(bag)
	})
})
