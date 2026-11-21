// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Future-state scenarios: the plan compared with today from the Portfolio
 * roadmap, a scenario that replaces a usage, and an adopted scenario applied
 * to the landscape.
 *
 * Seeds an organisation, three applications and two usages carrying this run's
 * RUN_ID through the objects API, and removes exactly those rows (and the
 * scenarios and usages the run creates) afterwards. The derivation is covered
 * by tests/Unit/Service/LandscapeAtDateDerivationTest.php and the apply by
 * tests/vitest/scenarioApply.spec.js.
 *
 * Written from the change's scenarios; not yet run against a live instance.
 *
 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-002-an-information-manager-shall-compare-todays-landscape-with-the-plan-on-a-date
 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-003-an-information-manager-shall-write-a-scenario-of-additions-phase-outs-and-replacements
 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-004-a-scenario-shall-move-through-a-declared-lifecycle-and-an-adopted-scenario-shall-be-applied-to-the-landscape
 */
import type { APIRequestContext, Page } from '@playwright/test'
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

let apiCtx: APIRequestContext
let cfg: VoorzieningenConfig
const seeded: Array<[string, string]> = []
const ids: Record<string, string> = {}
const organisation = `${RUN_ID} municipality`
const current = `${RUN_ID} current case system`
const successor = `${RUN_ID} successor case system`
const added = `${RUN_ID} new service desk`
const nextYear = `${new Date().getFullYear() + 1}-06-30`

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
 * The id of a related object, whether stored as a uuid or as an object.
 *
 * @param value The relation value.
 * @return The id, or an empty string.
 */
function idOf(value: unknown): string {
	if (value && typeof value === 'object') {
		const object = value as { '@self'?: { id?: string }, id?: string }
		return String(object['@self']?.id ?? object.id ?? '')
	}
	return value ? String(value) : ''
}

/**
 * Choose the run's organisation in the organisation picker on the page.
 *
 * @param page The page.
 * @return Resolves once the option is chosen.
 */
async function chooseOrganisation(page: Page): Promise<void> {
	await page.getByRole('combobox', { name: 'Organisation' }).fill(organisation)
	await page.locator('.vs__dropdown-option', { hasText: organisation }).first().click()
}

test.beforeAll(async () => {
	apiCtx = await newApiContext()
	cfg = await resolveConfig(apiCtx)
	ids.org = await seed('organization', { name: organisation, type: 'Municipality' })
	ids.current = await seed('module', { name: current })
	ids.successor = await seed('module', { name: successor })
	ids.added = await seed('module', { name: added })
	ids.replaced = await seed('usage', {
		consumer: ids.org,
		module: ids.current,
		status: 'In production',
		startDateInProduction: '2020-01-01',
		plannedReplacement: ids.successor,
		plannedReplacementDate: '2027-03-01',
	})
	ids.kept = await seed('usage', {
		consumer: ids.org,
		module: ids.successor,
		status: 'In production',
		startDateInProduction: '2021-01-01',
	})
})

test.afterAll(async () => {
	if (!apiCtx) return
	for (const schema of ['scenarioChange', 'scenario', 'usage']) {
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

// @e2e future-state-scenarios::an-information-manager-sees-what-the-plan-changes-by-next-summer
test('the roadmap opens the plan comparison, which shows the planned replacement', async ({ page }) => {
	await gotoAppRoute(page, '/portfolio-roadmap')
	await dismissSupportDialog(page)
	await chooseOrganisation(page)
	await page.getByRole('button', { name: 'Compare with the plan' }).click()

	await expect(page).toHaveURL(/landscape-comparison\?organisation=/)
	await page.getByLabel('Date').fill('2027-06-30')

	const applications = page.getByTestId('landscape-applications')
	const row = applications.getByRole('row', { name: new RegExp(current) })
	await expect(row).toContainText(`Replaced by ${successor}`, { timeout: 30000 })
	await expect(row).toContainText('Plan')
	await expect(page.getByTestId('landscape-today')).toContainText(current)
})

// @e2e future-state-scenarios::an-information-manager-tries-a-replacement
test('a scenario that replaces a usage lists the change and compares it with source scenario', async ({ page }) => {
	const name = `${RUN_ID} replacement scenario`
	await gotoAppRoute(page, '/scenarios')
	await dismissSupportDialog(page)

	const create = await openCreateDialog(page, /Add/)
	await create.getByLabel('Name').fill(name)
	await create.getByRole('combobox', { name: 'Organisation' }).fill(organisation)
	await page.locator('.vs__dropdown-option', { hasText: organisation }).first().click()
	await create.getByLabel('Target date').fill(nextYear)
	await create.getByRole('button', { name: /Save|Create/ }).click()

	await page.getByRole('row', { name: new RegExp(name) }).click()
	await expect(page).toHaveURL(/\/scenarios\//)

	await page.getByRole('button', { name: 'Add change' }).click()
	const change = page.locator('[role="dialog"], .modal-container').first()
	await change.getByRole('combobox', { name: 'Action' }).click()
	await page.locator('.vs__dropdown-option', { hasText: 'replace' }).first().click()
	await change.getByRole('combobox', { name: 'Usage' }).fill(RUN_ID)
	await page.locator('.vs__dropdown-option', { hasText: current }).first().click()
	await change.getByRole('combobox', { name: 'Application' }).fill(added)
	await page.locator('.vs__dropdown-option', { hasText: added }).first().click()
	await change.getByRole('button', { name: /Save|Create/ }).click()

	await expect(page.getByText('replace').first()).toBeVisible({ timeout: 15000 })
	const row = page.getByTestId('landscape-applications').getByRole('row', { name: new RegExp(current) })
	await expect(row).toContainText(`Replaced by ${added}`, { timeout: 30000 })
	await expect(row).toContainText('Scenario')
})

// @e2e future-state-scenarios::an-information-manager-applies-an-adopted-scenario
test('an adopted scenario is applied to the landscape once', async ({ page }) => {
	const scenarioId = await seed('scenario', {
		name: `${RUN_ID} apply scenario`,
		organisation: ids.org,
		targetDate: '2027-06-30',
		status: 'proposed',
	})
	await seed('scenarioChange', { scenario: scenarioId, action: 'add', module: ids.added, effectiveDate: '2027-03-01', note: `${RUN_ID} add` })
	await seed('scenarioChange', { scenario: scenarioId, action: 'phase out', usage: ids.kept, effectiveDate: '2027-06-30', note: `${RUN_ID} phase out` })

	await gotoAppRoute(page, `/scenarios/${scenarioId}`)
	await dismissSupportDialog(page)
	await page.getByRole('button', { name: /Adopt/ }).click()
	await page.getByTestId('apply-scenario').click()
	await expect(page.getByText('Applied 2 changes to the landscape.')).toBeVisible({ timeout: 30000 })
	await expect(page.getByTestId('apply-scenario')).toHaveCount(0)

	const usages = await findAll(apiCtx, cfg.register, 'usage')
	const created = usages.find((u) => idOf(u.module) === ids.added && idOf(u.consumer) === ids.org)
	expect(created).toMatchObject({ status: 'Planned', startDateInProduction: '2027-03-01' })
	const phasedOut = usages.find((u) => idOf(u) === ids.kept)
	expect(phasedOut?.startDateOutPhased).toBe('2027-06-30')
})
