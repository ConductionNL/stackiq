// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * The Data model (architecture-data-model-and-ggm): finding an own data
 * entity, the relations drawn around it, linking an application in use to it,
 * and adding a new entity with a key attribute.
 *
 * Seeds two own data entities (AMEF `element`, type BusinessObject, origin
 * drawn) with a relation between them, and an organisation, an application and
 * a usage in the voorzieningen register, all carrying this run's RUN_ID, through
 * the objects API (the call the forms make). Removes exactly those rows and the
 * entity the "new entity" test creates afterwards. Setup and teardown use the
 * API; every assertion is made on the rendered page.
 *
 * The scenarios marked `@e2e exclude` in the change's spec are covered by
 * tests/validate-manifest.js, tests/vitest/dataEntityRelations.spec.js,
 * tests/vitest/dataEntityAttributes.spec.js and
 * tests/Unit/Settings/DataModelRegisterShapeTest.php.
 *
 * @spec openspec/changes/architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md
 */
import type { APIRequestContext } from '@playwright/test'
import type { VoorzieningenConfig } from './_fixtures.ts'

import { expect, test } from '@playwright/test'
import {
	createObject,
	deleteObject,
	findAll,
	newApiContext,
	resolveConfig,
	RUN_ID,
} from './_fixtures.ts'
import { dismissSupportDialog, gotoAppRoute, navClickTo, openCreateDialog } from './_ui.ts'

interface AmefConfig {
	register: string
	element_schema: string
	relation_schema: string
}

let apiCtx: APIRequestContext
let cfg: VoorzieningenConfig
let amef: AmefConfig
const seeded: Array<[string, string, string]> = []
const ids: Record<string, string> = {}
const melding = `${RUN_ID} Melding openbare ruimte`
const melder = `${RUN_ID} Melder`
const vergunning = `${RUN_ID} Vergunning`
const application = `${RUN_ID} meldingen application`

/**
 * Create a row and remember it for cleanup.
 *
 * @param register The register id.
 * @param schema The schema id or slug.
 * @param data The object.
 * @return The new id.
 */
async function seed(register: string, schema: string, data: Record<string, unknown>): Promise<string> {
	const id = await createObject(apiCtx, register, schema, data)
	seeded.push([register, schema, id])
	return id
}

/**
 * Read the AMEF register mapping the `@resolve:amef_register` pages use.
 *
 * @param ctx An admin API context.
 * @return The register and the element and relation schemas.
 */
async function resolveAmefConfig(ctx: APIRequestContext): Promise<AmefConfig> {
	const res = await ctx.get('/index.php/apps/stackiq/api/amef/config')
	expect(res.ok(), `amef/config returned ${res.status()}`).toBe(true)
	const config = (await res.json())?.config ?? {}
	const register = String(config.register ?? config.register_id ?? '')
	expect(register, 'stackiq has no AMEF register mapping').not.toBe('')
	return {
		register,
		element_schema: String(config.element_schema || 'element'),
		relation_schema: String(config.relation_schema || 'relation'),
	}
}

test.describe.configure({ mode: 'serial' })

test.beforeAll(async () => {
	apiCtx = await newApiContext()
	cfg = await resolveConfig(apiCtx)
	amef = await resolveAmefConfig(apiCtx)

	ids.melding = await seed(amef.register, amef.element_schema, {
		identifier: `id-${RUN_ID}-melding`,
		type: 'BusinessObject',
		name: melding,
		documentation: 'Een melding over de openbare ruimte, zoals een losliggende stoeptegel.',
		origin: 'drawn',
		attributes: [
			{ name: 'meldingnummer', dataType: 'text', isKey: true },
			{ name: 'datum melding', dataType: 'date', isKey: false },
		],
	})
	ids.melder = await seed(amef.register, amef.element_schema, {
		identifier: `id-${RUN_ID}-melder`,
		type: 'BusinessObject',
		name: melder,
		documentation: 'De persoon die een melding doet.',
		origin: 'drawn',
		attributes: [{ name: 'e-mailadres', dataType: 'text', isKey: true }],
	})
	ids.relation = await seed(amef.register, amef.relation_schema, {
		identifier: `id-${RUN_ID}-rel-melding-melder`,
		type: 'Association',
		name: 'gedaan door',
		source: `id-${RUN_ID}-melding`,
		target: `id-${RUN_ID}-melder`,
		sourceCardinality: '0..*',
		targetCardinality: '1',
		origin: 'drawn',
	})

	ids.org = await seed(cfg.register, 'organization', { name: `${RUN_ID} municipality` })
	ids.module = await seed(cfg.register, 'module', { name: application })
	ids.usage = await seed(cfg.register, 'usage', {
		consumer: ids.org,
		module: ids.module,
		status: 'In production',
	})
})

test.afterAll(async () => {
	if (!apiCtx) return
	// The entity the "new entity" test made through the dialog.
	for (const row of await findAll(apiCtx, amef.register, amef.element_schema, vergunning)) {
		const id = String((row as { id?: string }).id ?? (row as { '@self'?: { id?: string } })['@self']?.id ?? '')
		if (id && JSON.stringify(row).includes(RUN_ID)) {
			await deleteObject(apiCtx, amef.register, amef.element_schema, id)
		}
	}
	for (const [register, schema, id] of seeded.reverse()) {
		await deleteObject(apiCtx, register, schema, id)
	}
	await apiCtx.dispose()
})

// @e2e data-model-and-ggm::an-information-manager-finds-a-data-entity
test('the Data model page lists an own entity under Own and opens its page', async ({ page }) => {
	await navClickTo(page, 'Data model')
	await dismissSupportDialog(page)
	await page.getByRole('tab', { name: 'Own', exact: true }).click()
	const search = page.getByRole('main').getByRole('textbox', { name: /search/i }).first()
	if (await search.count()) {
		await search.fill(melding)
	}
	const row = page.getByRole('row').filter({ hasText: melding })
	await expect(row).toBeVisible({ timeout: 30000 })
	await expect(row).toContainText('drawn')

	await row.getByText(melding).first().click()
	await expect(page).toHaveURL(new RegExp(`/gegevensmodel/${ids.melding}`), { timeout: 30000 })
	await expect(page.getByText(melding).first()).toBeVisible({ timeout: 30000 })
	await expect(page.getByText('Een melding over de openbare ruimte').first()).toBeVisible()
})

// @e2e data-model-and-ggm::an-information-manager-sees-what-a-melding-relates-to
test('the entity page draws the relation to Melder and lists it as text', async ({ page }) => {
	await gotoAppRoute(page, `/gegevensmodel/${ids.melding}`)
	await dismissSupportDialog(page)
	const relations = page.getByTestId('data-entity-relations')
	await expect(relations).toBeVisible({ timeout: 30000 })
	const graph = relations.getByRole('img', { name: `Relations of ${melding}` })
	await expect(graph).toBeVisible({ timeout: 30000 })
	await expect(graph.getByText(melder).first()).toBeAttached()
	await expect(graph.getByText('gedaan door, 0..* to 1').first()).toBeAttached()

	const row = page.getByTestId('data-entity-relations-table').getByRole('row').filter({ hasText: 'gedaan door' })
	await expect(row).toContainText(melder)
	await expect(row).toContainText('0..*')
	await expect(row).toContainText('1')
})

// @e2e data-model-and-ggm::an-application-owner-links-their-application-to-a-data-entity
test('a usage linked to the entity shows it, and the entity lists the usage', async ({ page }) => {
	await gotoAppRoute(page, `/gebruik/${ids.usage}`)
	await dismissSupportDialog(page)
	await page.getByRole('button', { name: 'Edit', exact: true }).first().click()
	const dialog = page.locator('[role="dialog"], .modal-container').first()
	await dialog.waitFor({ state: 'visible', timeout: 15000 })
	const picker = dialog.getByLabel('Data entities').first()
	await picker.click()
	await picker.fill(melding)
	await page.getByRole('option', { name: new RegExp(melding) }).first().click()
	await dialog.getByRole('button', { name: /save/i }).last().click()
	await expect(dialog).toBeHidden({ timeout: 30000 })

	await expect(page.getByText('Data entities').first()).toBeVisible({ timeout: 30000 })
	await expect(page.getByText(melding).first()).toBeVisible({ timeout: 30000 })

	await gotoAppRoute(page, `/gegevensmodel/${ids.melding}`)
	await expect(page.getByText('Applications that hold this data').first()).toBeVisible({ timeout: 30000 })
	await expect(page.getByText(application).first()).toBeVisible({ timeout: 30000 })
})

// @e2e data-model-and-ggm::an-information-manager-adds-an-entity-with-a-key
test('a new data entity with a text key is listed under Own with the key first', async ({ page }) => {
	await gotoAppRoute(page, '/gegevensmodel')
	await dismissSupportDialog(page)
	const dialog = await openCreateDialog(page, 'New data entity')
	await dialog.getByLabel('Name').first().fill(vergunning)
	await dialog.getByRole('button', { name: /save|create/i }).last().click()
	await expect(dialog).toBeHidden({ timeout: 30000 })

	await page.getByRole('tab', { name: 'Own', exact: true }).click()
	const row = page.getByRole('row').filter({ hasText: vergunning })
	await expect(row).toBeVisible({ timeout: 30000 })
	await row.getByText(vergunning).first().click()
	await expect(page).toHaveURL(/\/gegevensmodel\/[^/]+$/, { timeout: 30000 })

	const attributes = page.getByTestId('data-entity-attributes')
	await attributes.getByTestId('data-entity-attributes-edit').click()
	// The non-key attribute is entered first, so "key first" is the page's ordering, not the entry order.
	await attributes.getByRole('button', { name: 'Add attribute' }).click()
	await attributes.getByTestId('data-entity-attribute-row').last().getByLabel('Name').fill('omschrijving')
	await attributes.getByRole('button', { name: 'Add attribute' }).click()
	await attributes.getByTestId('data-entity-attribute-row').last().getByLabel('Name').fill('zaaknummer')
	await attributes.getByTestId('data-entity-attribute-row').last().getByText('Part of the key').click()
	await attributes.getByRole('button', { name: 'Save attributes' }).click()

	const first = attributes.getByTestId('data-entity-attribute').first()
	await expect(first).toContainText('zaaknummer', { timeout: 30000 })
	await expect(first).toContainText('key')
	await expect(attributes.getByTestId('data-entity-attribute').nth(1)).not.toContainText('key')
})
