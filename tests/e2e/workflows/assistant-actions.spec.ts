// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Assistant actions (the assistant answers and action trail change, task 3): an
 * administrator opens Assistant actions, filters on one stackiq tool and reads
 * the row of an assistant's call with when, user, tool and the linked record,
 * and the record link opens the contract page.
 *
 * Seeds one organisation, one application, a usage of it and a contract on
 * that usage, all carrying this run's RUN_ID, through the objects API, and
 * removes exactly those rows afterwards.
 *
 * The audit row itself is answered in the browser (page.route on
 * OpenRegister's `GET /api/audit-trails`): an `mcp.` audit record is written
 * only when an assistant calls a stackiq tool over MCP, which a test cannot
 * start, and stackiq keeps no copy it could seed. The answered row points at
 * the seeded contract, so the schema lookup and the record link run against
 * the live instance. The test also checks the page asks OpenRegister for the
 * `mcp.` action prefix, the `stackiq.` tool prefix and the chosen tool, so the
 * filtering stays server side.
 *
 * The excluded scenario (a user sees only their own assistant's actions) is
 * OpenRegister's audit-trail rule; tests/vitest/assistantActions.spec.js
 * covers that stackiq adds no wider read.
 *
 * Written from the change's scenarios; not yet run against a live instance.
 *
 * @spec openspec/changes/archive/2026-11-24-insight-assistant-answers-and-action-trail/specs/assistant-insight/spec.md#requirement-req-ask-003-assistant-actions-on-catalogue-records-are-listed
 */
import type { APIRequestContext, Route } from '@playwright/test'
import type { VoorzieningenConfig } from './_fixtures.ts'

import { expect, test } from '@playwright/test'
import {
	createObject,
	deleteObject,
	NC_ADMIN_USER,
	newApiContext,
	resolveConfig,
	RUN_ID,
} from './_fixtures.ts'
import { dismissSupportDialog, navClickTo } from './_ui.ts'

const AUDIT_TRAILS = '/apps/openregister/api/audit-trails'
const TOOL = 'stackiq.submitContractApproval'
const OTHER_TOOL = 'stackiq.module.get'
const WHEN = '2026-10-06T10:15:00+00:00'

let apiCtx: APIRequestContext
let cfg: VoorzieningenConfig
const seeded: Array<[string, string]> = []
const ids: Record<string, string> = {}
let contractSchemaId = ''
let moduleSchemaId = ''
const contractNumber = `${RUN_ID}-2025-0042`

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
 * The numeric schema id OpenRegister stores on an object.
 *
 * @param schema The schema slug.
 * @param id The object's id.
 * @return The schema id.
 */
async function schemaIdOf(schema: string, id: string): Promise<string> {
	const res = await apiCtx.get(`/index.php/apps/openregister/api/objects/${cfg.register}/${schema}/${id}`)
	expect(res.ok(), await res.text()).toBe(true)
	const body = await res.json()
	return String(body?.['@self']?.schema ?? '')
}

/**
 * One audit row as OpenRegister's list returns it.
 *
 * @param id The row id.
 * @param toolId The tool.
 * @param schema The schema id of the record.
 * @param objectUuid The record.
 * @return The row.
 */
function auditRow(id: string, toolId: string, schema: string, objectUuid: string): Record<string, unknown> {
	return {
		id,
		created: WHEN,
		action: 'mcp.tools/call',
		userName: NC_ADMIN_USER,
		toolId,
		schema,
		objectUuid,
		resultSummary: { isError: false },
	}
}

test.beforeAll(async () => {
	apiCtx = await newApiContext()
	cfg = await resolveConfig(apiCtx)
	ids.org = await seed('organization', {
		name: `${RUN_ID} municipality`,
		type: 'Municipality',
		status: 'Active',
		contactsUid: `${RUN_ID}-municipality`,
	})
	ids.app = await seed('module', { name: `${RUN_ID} application` })
	ids.usage = await seed('usage', { module: ids.app, consumer: ids.org, status: 'In production' })
	ids.contract = await seed('catalogContract', {
		contractNumber,
		contractType: 'Licence',
		// Active is reached only through an approved decision; the audit rows do not depend on it.
		status: 'In negotiation',
		startDate: '2026-01-01',
		endDate: '2027-12-31',
		usage: ids.usage,
	})
	contractSchemaId = await schemaIdOf('catalogContract', ids.contract)
	moduleSchemaId = await schemaIdOf('module', ids.app)
})

test.afterAll(async () => {
	if (!apiCtx) return
	for (const [schema, id] of seeded.reverse()) {
		await deleteObject(apiCtx, cfg.register, schema, id)
	}
	await apiCtx.dispose()
})

// @e2e assistant-insight::an-administrator-reviews-what-an-assistant-changed
test('filtering on the submit tool leaves one row whose record link opens the contract', async ({ page }) => {
	const asked: URLSearchParams[] = []
	await page.route(`**${AUDIT_TRAILS}*`, async (route: Route) => {
		const params = new URL(route.request().url()).searchParams
		asked.push(params)
		const rows = [auditRow(`${RUN_ID}-1`, TOOL, contractSchemaId, ids.contract)]
		if (!params.has('tool')) {
			rows.push(auditRow(`${RUN_ID}-2`, OTHER_TOOL, moduleSchemaId, ids.app))
		}
		await route.fulfill({ json: { results: rows.filter((row) => !params.has('tool') || row.toolId === params.get('tool')), nextCursor: null } })
	})

	await navClickTo(page, 'Assistant actions')
	await dismissSupportDialog(page)
	await expect(page.getByRole('heading', { name: 'Assistant actions' }).first()).toBeVisible({ timeout: 30000 })
	await expect(page.getByTestId('aa-row')).toHaveCount(2, { timeout: 30000 })

	await page.getByLabel('Tool', { exact: true }).fill('submitContractApproval')
	await page.getByTestId('aa-apply').click()

	const rows = page.getByTestId('aa-row')
	await expect(rows).toHaveCount(1, { timeout: 30000 })
	const last = asked[asked.length - 1]
	expect(last.get('actionPrefix')).toBe('mcp.')
	expect(last.get('toolPrefix')).toBe('stackiq.')
	expect(last.get('tool')).toBe(TOOL)

	// The page prints the date in the browser's locale; read it the same way.
	const when = await page.evaluate((iso) => new Date(iso).toLocaleString(), WHEN)
	const row = rows.first()
	await expect(row).toContainText(when)
	await expect(row).toContainText('2026')
	await expect(row).toContainText(NC_ADMIN_USER)
	await expect(row).toContainText(TOOL)
	await expect(row).toContainText('Succeeded')

	const record = row.getByRole('link', { name: ids.contract })
	await expect(record).toBeVisible({ timeout: 30000 })
	await record.click()
	await expect(page).toHaveURL(new RegExp(`/contracten/${ids.contract}`))
	await expect(page.getByText(contractNumber).first()).toBeVisible({ timeout: 30000 })
})
