// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Business process mapping: a process recorded with three ordered steps, the
 * steps an application in use supports listed on its usage page, a step
 * marked as not compliant, the steps drawn as a read-only flow, and a process
 * taken into use through its lifecycle.
 *
 * Seeds an organisation, an application, a usage, two processes and four
 * steps carrying this run's RUN_ID through the objects API (the call the Add
 * forms make), and removes exactly those rows and the process and steps the
 * record test creates afterwards. The flow mapping and the hidden-usage count
 * are covered by tests/vitest/processStepFlow.spec.js and
 * tests/vitest/processStepUsages.spec.js, the schema rules by
 * tests/Unit/Settings/ProcessMappingRegisterShapeTest.php.
 *
 * Written from the change's scenarios; not yet run against a live instance.
 *
 * @spec openspec/changes/architecture-process-mapping/specs/business-process-mapping/spec.md#requirement-req-bpm-001-a-municipal-information-manager-shall-record-a-business-process-with-ordered-steps
 * @spec openspec/changes/architecture-process-mapping/specs/business-process-mapping/spec.md#requirement-req-bpm-002-a-step-shall-name-the-applications-in-use-that-support-it
 * @spec openspec/changes/architecture-process-mapping/specs/business-process-mapping/spec.md#requirement-req-bpm-003-a-step-shall-carry-a-risk-level-and-a-compliance-check
 * @spec openspec/changes/architecture-process-mapping/specs/business-process-mapping/spec.md#requirement-req-bpm-004-the-process-page-shall-show-the-steps-as-a-read-only-flow
 * @spec openspec/changes/architecture-process-mapping/specs/business-process-mapping/spec.md#requirement-req-bpm-005-a-process-shall-move-through-a-declared-lifecycle-and-may-follow-a-gemma-reference-process
 */
import type { APIRequestContext, Locator, Page } from '@playwright/test'
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
import { dismissSupportDialog, gotoAppRoute, openCreateDialog } from './_ui.ts'

const OR = '/index.php/apps/openregister/api/objects'

let apiCtx: APIRequestContext
let cfg: VoorzieningenConfig
const seeded: Array<[string, string]> = []
const ids: Record<string, string> = {}
const organisation = `${RUN_ID} municipality`
const application = `${RUN_ID} service desk application`
const permitProcess = `${RUN_ID} handling an application`
const draftProcess = `${RUN_ID} handling a request`
const intake = `${RUN_ID} intake`
const review = `${RUN_ID} check requirements`
const decide = `${RUN_ID} decide`
const archive = `${RUN_ID} archive the file`

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
 * The id of an object, whether given as a row or as an object.
 *
 * @param row The row.
 * @return The id, or an empty string.
 */
function idOf(row: Record<string, unknown>): string {
	return String(row.id ?? (row as { '@self'?: { id?: string } })['@self']?.id ?? '')
}

/**
 * Pick an option in a select of an open form, named by its label.
 *
 * @param page The page.
 * @param dialog The form dialog.
 * @param label The field label.
 * @param option The option text.
 * @return Resolves once picked.
 */
async function pick(page: Page, dialog: Locator, label: string, option: RegExp): Promise<void> {
	await dialog.getByLabel(label, { exact: true }).first().click()
	await page.getByRole('option', { name: option }).first().click()
}

/**
 * The open form dialog.
 *
 * @param page The page.
 * @return The dialog, once visible.
 */
async function openDialog(page: Page): Promise<Locator> {
	const dialog = page.locator('[role="dialog"], .modal-container').first()
	await dialog.waitFor({ state: 'visible', timeout: 15000 })
	return dialog
}

test.beforeAll(async () => {
	apiCtx = await newApiContext()
	cfg = await resolveConfig(apiCtx)
	ids.org = await seed('organization', { name: organisation, type: 'Municipality' })
	ids.module = await seed('module', { name: application })
	ids.usage = await seed('usage', {
		consumer: ids.org,
		module: ids.module,
		status: 'In production',
		startDateInProduction: '2022-01-01',
	})
	ids.permit = await seed('process', { name: permitProcess, status: 'active', tags: [RUN_ID] })
	ids.intake = await seed('processStep', {
		process: ids.permit,
		name: intake,
		stepType: 'event',
		position: 1,
		usages: [ids.usage],
		riskLevel: 'low',
		complianceCheck: 'compliant',
	})
	ids.review = await seed('processStep', {
		process: ids.permit,
		name: review,
		stepType: 'task',
		position: 2,
		usages: [],
		riskLevel: 'medium',
		complianceCheck: 'not checked',
	})
	ids.decide = await seed('processStep', {
		process: ids.permit,
		name: decide,
		stepType: 'decision',
		position: 3,
		usages: [],
		riskLevel: 'high',
		complianceCheck: 'not compliant',
		complianceNote: `${RUN_ID} the decision is not archived on time.`,
	})
	ids.draft = await seed('process', { name: draftProcess, status: 'draft' })
	ids.archive = await seed('processStep', {
		process: ids.draft,
		name: archive,
		stepType: 'task',
		position: 1,
		riskLevel: 'not assessed',
		complianceCheck: 'not checked',
	})
})

test.afterAll(async () => {
	if (!apiCtx) return
	for (const schema of ['processStep', 'process']) {
		for (const row of await findAll(apiCtx, cfg.register, schema, RUN_ID)) {
			const id = idOf(row)
			if (id && !seeded.some(([, seededId]) => seededId === id)) {
				await deleteObject(apiCtx, cfg.register, schema, id)
			}
		}
	}
	for (const [schema, id] of seeded.reverse()) {
		await deleteObject(apiCtx, cfg.register, schema, id)
	}
	await apiCtx.dispose()
})

// @e2e business-process-mapping::an-information-manager-adds-a-process-with-three-steps
test('a new process with three steps lists them in position order and reads draft', async ({ page }) => {
	const name = `${RUN_ID} handling a report`
	const steps = [`${RUN_ID} register`, `${RUN_ID} assess`, `${RUN_ID} complete`]
	await gotoAppRoute(page, '/processen')
	await dismissSupportDialog(page)

	const create = await openCreateDialog(page, /Add/)
	await create.getByLabel('Name', { exact: true }).fill(name)
	await create.getByRole('button', { name: /Save|Create/ }).last().click()
	await expect(create).toBeHidden({ timeout: 30000 })

	await page.getByRole('row').filter({ hasText: name }).first().click()
	await expect(page).toHaveURL(/\/processen\//, { timeout: 30000 })

	for (const [index, step] of steps.entries()) {
		await page.getByRole('button', { name: 'Add step', exact: true }).first().click()
		const dialog = await openDialog(page)
		await dialog.getByLabel('Name', { exact: true }).fill(step)
		await dialog.getByLabel('Position', { exact: true }).fill(String(index + 1))
		await dialog.getByRole('button', { name: /Save|Create/ }).last().click()
		await expect(dialog).toBeHidden({ timeout: 30000 })
		await expect(page.getByRole('row').filter({ hasText: step })).toBeVisible({ timeout: 30000 })
	}

	const listed = (await page.getByRole('row').filter({ hasText: `${RUN_ID} ` }).allInnerTexts())
		.map((text) => steps.findIndex((step) => text.includes(step)))
		.filter((index) => index >= 0)
	expect(listed).toEqual([0, 1, 2])

	await gotoAppRoute(page, `/processen?_search=${encodeURIComponent(name)}`)
	const row = page.getByRole('row').filter({ hasText: name })
	await expect(row).toContainText(/draft/i, { timeout: 30000 })
})

// @e2e business-process-mapping::an-application-owner-sees-which-process-steps-their-application-supports
test('the usage page lists the step it supports with its process and opens the step page', async ({ page }) => {
	await gotoAppRoute(page, `/gebruik/${ids.usage}`)
	await dismissSupportDialog(page)
	await expect(page.getByText('Process steps this application supports').first()).toBeVisible({ timeout: 30000 })
	const row = page.getByRole('row').filter({ hasText: intake })
	await expect(row).toBeVisible({ timeout: 30000 })
	await expect(row).toContainText(permitProcess, { timeout: 30000 })
	await expect(page.getByRole('row').filter({ hasText: review })).toHaveCount(0)

	await row.click()
	await expect(page).toHaveURL(new RegExp(`/processtappen/${ids.intake}`), { timeout: 30000 })
	await expect(page.getByTestId('process-step-usages')).toContainText(application, { timeout: 30000 })
})

// @e2e business-process-mapping::an-information-manager-marks-a-step-as-not-compliant
test('a step edited to high risk and not compliant shows both on the process page', async ({ page }) => {
	const note = `${RUN_ID} the requirements are not checked against the regulation.`
	await gotoAppRoute(page, `/processtappen/${ids.review}`)
	await dismissSupportDialog(page)
	await expect(page.getByText(review).first()).toBeVisible({ timeout: 30000 })

	await page.getByRole('button', { name: 'Edit', exact: true }).first().click()
	const dialog = await openDialog(page)
	await pick(page, dialog, 'Risk level', /^high$/i)
	await pick(page, dialog, 'Compliance check', /^not compliant$/i)
	await dialog.getByLabel('Compliance note', { exact: true }).fill(note)
	await dialog.getByRole('button', { name: /save/i }).last().click()
	await expect(dialog).toBeHidden({ timeout: 30000 })
	await expect(page.getByText(note).first()).toBeVisible({ timeout: 30000 })

	const res = await apiCtx.get(`${OR}/${cfg.register}/processStep/${ids.review}`)
	expect(await res.json()).toMatchObject({ riskLevel: 'high', complianceCheck: 'not compliant', complianceNote: note })

	await gotoAppRoute(page, `/processen/${ids.permit}`)
	const row = page.getByRole('row').filter({ hasText: review })
	await expect(row).toContainText(/high/i, { timeout: 30000 })
	await expect(row).toContainText(/not compliant/i)
})

// @e2e business-process-mapping::a-reader-sees-the-flow-of-a-process
test('the process page draws three steps in position order and marks the decision step', async ({ page }) => {
	await gotoAppRoute(page, `/processen/${ids.permit}`)
	await dismissSupportDialog(page)
	const flow = page.getByTestId('process-step-flow')
	await expect(flow.locator('.vue-flow__node')).toHaveCount(3, { timeout: 30000 })
	await expect(flow.locator('.vue-flow__edge')).toHaveCount(2)

	const node = flow.locator('.vue-flow__node').filter({ hasText: decide })
	await expect(node).toContainText('Risk: High')
	await expect(node).toContainText('Compliance: Not compliant')
	await expect(node).toContainText('Needs attention')
	await expect(node.locator('.process-step-flow__node--attention')).toHaveCount(1)
	await expect(flow.locator('.vue-flow__node').filter({ hasText: intake })).not.toContainText('Needs attention')
})

// @e2e business-process-mapping::an-owner-activates-a-process
test('applying Activate makes the process active and the History tab shows it', async ({ page }) => {
	await gotoAppRoute(page, `/processen/${ids.draft}`)
	await dismissSupportDialog(page)
	await expect(page.getByText(draftProcess).first()).toBeVisible({ timeout: 30000 })

	await page.getByTestId('cn-lifecycle-action-activate').click()
	await expect(page.getByTestId('cn-lifecycle-actions-error')).toHaveCount(0)
	await expect(page.getByText('Active', { exact: true }).first()).toBeVisible({ timeout: 30000 })
	await expect(page.getByTestId('cn-lifecycle-action-activate')).toHaveCount(0)

	const res = await apiCtx.get(`${OR}/${cfg.register}/process/${ids.draft}`)
	expect((await res.json()).status).toBe('active')

	await page.getByRole('tab', { name: 'History' }).click()
	await expect(page.getByRole('tabpanel').filter({ hasText: /active/i }).first()).toBeVisible({ timeout: 30000 })
})
