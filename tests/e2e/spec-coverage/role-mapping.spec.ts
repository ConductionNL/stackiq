// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Role mapping in stackiq's admin settings (change
 * organisations-role-mapping-and-access-review, REQ-RMA-001): a Nextcloud
 * admin maps Gebruik-beheerder onto a group of their own, saves, and the
 * group's member is in `gebruik-beheerder` afterwards.
 *
 * Seeds a group "<RUN_ID> Inkoop" with one member, maps it through the
 * Role mapping table, and restores the mapping and removes the group and
 * account afterwards. The non-admin refusal is PHPUnit's
 * (SettingsControllerUserGroupsTest); seeing one's own organisation's usages
 * follows from the group, which the catalogue's access rules already cover.
 *
 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { randomBytes } from 'node:crypto'
import { newApiContext, RUN_ID } from '../workflows/_fixtures.ts'

const OCS = '/ocs/v2.php/cloud'
const CONFIG = '/index.php/apps/stackiq/api/user-groups/config'
const group = `${RUN_ID}-inkoop`.slice(0, 60)
const member = `${RUN_ID}-inkoper`.slice(0, 60)
const password = `Sq-${randomBytes(12).toString('hex')}-Pw1!`

let apiCtx: APIRequestContext
let savedConfig: unknown = null

/**
 * The groups of the member.
 */
async function memberGroups(): Promise<string[]> {
	const res = await apiCtx.get(`${OCS}/users/${member}/groups?format=json`)
	return (await res.json())?.ocs?.data?.groups ?? []
}

test.beforeAll(async () => {
	apiCtx = await newApiContext()
	const current = await apiCtx.get(CONFIG)
	if (current.ok()) {
		savedConfig = await current.json()
	}
	const madeGroup = await apiCtx.post(`${OCS}/groups?format=json`, { data: { groupid: group } })
	expect(madeGroup.ok(), await madeGroup.text()).toBe(true)
	const madeUser = await apiCtx.post(`${OCS}/users?format=json`, { data: { userid: member, password, groups: [group] } })
	expect(madeUser.ok(), await madeUser.text()).toBe(true)
	expect(await memberGroups()).not.toContain('gebruik-beheerder')
})

test.afterAll(async () => {
	if (!apiCtx) return
	// Only the role mapping is written back: the endpoint leaves keys it is not sent alone.
	const roleMapping = (savedConfig as { roleMapping?: unknown } | null)?.roleMapping
	if (roleMapping !== undefined) {
		await apiCtx.post(CONFIG, { data: { roleMapping } }).catch(() => undefined)
	}
	await apiCtx.delete(`${OCS}/users/${member}?format=json`).catch(() => undefined)
	await apiCtx.delete(`${OCS}/groups/${group}?format=json`).catch(() => undefined)
	await apiCtx.dispose()
})

// @e2e role-mapping-and-access-review::an-admin-makes-the-inkoop-group-buyers
test('mapping Gebruik-beheerder onto the Inkoop group puts its member in gebruik-beheerder', async ({ page }) => {
	await page.goto('/settings/admin/stackiq', { waitUntil: 'domcontentloaded' })
	// The settings section renders its tabs as buttons (no tab role); the table sits in a hidden panel until chosen.
	const tab = page.getByRole('button', { name: 'Role mapping', exact: true })
	await expect(tab).toBeVisible({ timeout: 30000 })
	await tab.click()
	const table = page.getByTestId('role-mapping-table')
	await expect(table).toBeVisible({ timeout: 30000 })

	const row = page.getByTestId('role-mapping-row-Gebruik-beheerder')
	await row.locator('input').fill(group)
	// The picker highlights the typed text, which splits the option's accessible name: match on its text.
	await page.getByRole('option').filter({ hasText: group }).first().click()
	await page.keyboard.press('Escape')

	await page.getByRole('button', { name: 'Save User Groups' }).click()

	await expect.poll(memberGroups, { timeout: 30000 }).toContain('gebruik-beheerder')

	const stored = await apiCtx.get(CONFIG)
	expect(JSON.stringify(await stored.json())).toContain(group)
})
