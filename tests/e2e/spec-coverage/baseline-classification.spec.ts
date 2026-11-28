// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * The BIO baseline classification of an application in use
 * (security-baseline-classification): classifying a usage on availability,
 * integrity and confidentiality, the suggestion from GEMMA's reference
 * components, and the anonymous summary of how organisations classify an
 * application.
 *
 * Seeds, through the objects API, a reference component (AMEF `element`) with
 * GEMMA scores availability 1, integrity 2 and confidentiality 2, an
 * application that names it, four organisations and a usage of the application
 * for each, three of them classified (two at BBN2 overall, one at BBN3). Every
 * name carries this run's RUN_ID and exactly those rows are removed afterwards.
 * Setup and teardown use the API; the assertions are made on the rendered page.
 *
 * The scenarios marked `@e2e exclude` in the change's spec are covered by
 * tests/Unit/Settings/BaselineClassificationDeclarationTest.php,
 * tests/Unit/EventListener/UsageClassificationSubscriberTest.php,
 * tests/Unit/Service/UsageClassificationServiceTest.php and
 * tests/Unit/Controller/UsageClassificationControllerTest.php.
 *
 * @spec openspec/changes/archive/2026-11-24-security-baseline-classification/specs/baseline-classification/spec.md
 */
import type { APIRequestContext } from '@playwright/test'
import type { VoorzieningenConfig } from '../workflows/_fixtures.ts'

import { expect, test } from '@playwright/test'
import {
	createObject,
	deleteObject,
	NC_ADMIN_USER,
	newApiContext,
	resolveConfig,
	RUN_ID,
} from '../workflows/_fixtures.ts'
import { dismissSupportDialog, gotoAppRoute } from './_helpers.ts'

let apiCtx: APIRequestContext
let cfg: VoorzieningenConfig
let amefRegister = ''
let elementSchema = ''
const seeded: Array<[string, string, string]> = []
const ids: Record<string, string> = {}
const application = `${RUN_ID} zaaksysteem`
const organisations = ['Gemeente A', 'Gemeente B', 'Gemeente C', 'Gemeente Voorbeeld'].map((name) => `${RUN_ID} ${name}`)

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
 * Today as the panel's stamp shows it is not pinned to a format, so the test
 * reads the stored stamp back and checks it is today's date.
 *
 * @return Today as YYYY-MM-DD in UTC.
 */
function today(): string {
	return new Date().toISOString().slice(0, 10)
}

test.describe.configure({ mode: 'serial' })

test.beforeAll(async () => {
	apiCtx = await newApiContext()
	cfg = await resolveConfig(apiCtx)
	const amef = await apiCtx.get('/index.php/apps/stackiq/api/amef/config')
	expect(amef.ok(), `amef/config returned ${amef.status()}`).toBe(true)
	const amefConfig = (await amef.json())?.config ?? {}
	amefRegister = String(amefConfig.register ?? amefConfig.register_id ?? '')
	elementSchema = String(amefConfig.element_schema || 'element')
	expect(amefRegister, 'stackiq has no AMEF register mapping').not.toBe('')

	ids.component = await seed(amefRegister, elementSchema, {
		identifier: `id-${RUN_ID}-zaakafhandelcomponent`,
		type: 'ApplicationComponent',
		name: `${RUN_ID} Zaakafhandelcomponent`,
		availability: 1,
		integrity: 2,
		confidentiality: 2,
	})
	ids.module = await seed(cfg.register, 'module', { name: application, referenceComponents: [ids.component] })

	const levels: Array<Record<string, string>> = [
		{ availabilityLevel: 'BBN1', integrityLevel: 'BBN2', confidentialityLevel: 'BBN2' },
		{ availabilityLevel: 'BBN2', integrityLevel: 'BBN1', confidentialityLevel: 'BBN2' },
		{ availabilityLevel: 'BBN2', integrityLevel: 'BBN3', confidentialityLevel: 'BBN2' },
	]
	for (const [index, name] of organisations.entries()) {
		const org = await seed(cfg.register, 'organization', { name, type: 'Municipality' })
		const usage = await seed(cfg.register, 'usage', {
			consumer: org,
			module: ids.module,
			status: 'In production',
			...(levels[index] ?? {}),
		})
		ids[`usage${index}`] = usage
	}
	// The fourth organisation's usage is the one the functional administrator classifies.
	ids.own = ids.usage3
})

test.afterAll(async () => {
	if (!apiCtx) return
	for (const [register, schema, id] of seeded.reverse()) {
		await deleteObject(apiCtx, register, schema, id)
	}
	await apiCtx.dispose()
})

// @e2e baseline-classification::the-suggestion-follows-the-reference-components
test('Suggest from GEMMA fills BBN1, BBN2 and BBN2 and saves nothing', async ({ page }) => {
	await gotoAppRoute(page, `/gebruik/${ids.own}`)
	await dismissSupportDialog(page)
	const panel = page.getByTestId('usage-classification')
	await expect(panel.getByTestId('classification-suggest')).toBeVisible({ timeout: 30000 })

	await panel.getByTestId('classification-suggest').click()
	await expect(panel.getByTestId('classification-availability')).toHaveValue('BBN1', { timeout: 30000 })
	await expect(panel.getByTestId('classification-integrity')).toHaveValue('BBN2')
	await expect(panel.getByTestId('classification-confidentiality')).toHaveValue('BBN2')
	await expect(panel.getByTestId('classification-suggestion-note')).toContainText('1 reference component')
	await expect(panel.getByTestId('classification-save')).toBeEnabled()

	// Unsaved: a fresh page shows the usage still unclassified.
	await page.reload()
	await dismissSupportDialog(page)
	await expect(panel.getByTestId('classification-availability')).toHaveValue('', { timeout: 30000 })
	await expect(panel.getByTestId('classification-overall')).toContainText('Not classified')
})

// @e2e baseline-classification::a-ciso-classifies-an-application-in-use
test('classifying a usage shows the three levels, BBN2 overall and the stamp', async ({ page }) => {
	await gotoAppRoute(page, `/gebruik/${ids.own}`)
	await dismissSupportDialog(page)
	const panel = page.getByTestId('usage-classification')
	await expect(panel.getByTestId('classification-availability')).toBeVisible({ timeout: 30000 })

	await panel.getByTestId('classification-availability').selectOption('BBN1')
	await panel.getByTestId('classification-availability-reason').locator('input').fill('Een dag uitval is te overbruggen')
	await panel.getByTestId('classification-integrity').selectOption('BBN2')
	await panel.getByTestId('classification-integrity-reason').locator('input').fill('Besluiten steunen op deze gegevens')
	await panel.getByTestId('classification-confidentiality').selectOption('BBN2')
	await panel.getByTestId('classification-confidentiality-reason').locator('input').fill('Persoonsgegevens van burgers')
	await panel.getByTestId('classification-save').click()

	await expect(panel.getByTestId('classification-overall')).toContainText('BBN2', { timeout: 30000 })
	await expect(panel.getByText(/Classified on .+ by /)).toContainText(NC_ADMIN_USER, { timeout: 30000 })

	// The stored record carries the levels, the derived overall level and today's stamp.
	await page.reload()
	await dismissSupportDialog(page)
	await expect(panel.getByTestId('classification-availability')).toHaveValue('BBN1', { timeout: 30000 })
	await expect(panel.getByTestId('classification-integrity')).toHaveValue('BBN2')
	await expect(panel.getByTestId('classification-confidentiality')).toHaveValue('BBN2')
	await expect(panel.getByTestId('classification-overall')).toContainText('BBN2')
	const stored = await apiCtx.get(`/index.php/apps/openregister/api/objects/${cfg.register}/usage/${ids.own}`)
	expect(stored.ok()).toBe(true)
	const body = await stored.json()
	expect(body.bbnLevel).toBe('BBN2')
	expect(String(body.classifiedAt ?? '')).toContain(today())
	expect(body.classifiedBy).toBe(NC_ADMIN_USER)
})

// @e2e baseline-classification::an-information-manager-learns-how-others-classify-an-application
test('the application page counts the classifying organisations per level and names none', async ({ page }) => {
	// Back to three classifying organisations: the usage the previous test classified is removed.
	const removed = await apiCtx.delete(`/index.php/apps/openregister/api/objects/${cfg.register}/usage/${ids.own}`)
	expect(removed.ok(), `removing the fourth usage returned ${removed.status()}`).toBe(true)

	await gotoAppRoute(page, `/modules/${ids.module}`)
	await dismissSupportDialog(page)
	const summary = page.getByTestId('module-classification-summary')
	await expect(summary.getByTestId('module-classification-count')).toContainText('3 organisations classified this application', { timeout: 30000 })

	const overall = summary.getByTestId('module-classification-overall')
	const cells = overall.locator('td')
	await expect(cells).toHaveCount(3)
	await expect(cells.nth(0)).toHaveText('0')
	await expect(cells.nth(1)).toHaveText('2')
	await expect(cells.nth(2)).toHaveText('1')

	for (const name of organisations) {
		await expect(summary.getByText(name)).toHaveCount(0)
	}
})
