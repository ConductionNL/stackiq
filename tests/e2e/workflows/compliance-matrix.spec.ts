/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/module-compliance-assessment/spec.md
 * (REQ-CLR-022, a claim reads verified only after a reviewer outside the supplier checks it).
 *
 * The admin context seeds a supplier organisation, its application and an
 * evidenced claim, and removes them afterwards. The reviewer is the admin
 * acting from its own active organisation, which is never the throwaway
 * supplier. The supplier's own write is a separate user with an empty storage
 * state.
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request as playwrightRequest, test } from '@playwright/test'
import { BASE_URL, createObject, deleteObject, newApiContext, resolveConfig, RUN_ID } from './_fixtures.ts'

const SUPPLIER_USER = `${RUN_ID}-supplier`.slice(0, 60)
const PASSWORD = `E2e-${RUN_ID}-Pw!9`

/**
 * An API context acting as one user, with no session of anyone else.
 *
 * @param userId The user id.
 * @return The context; the caller disposes it.
 */
async function userApi(userId: string): Promise<APIRequestContext> {
	return await playwrightRequest.newContext({
		baseURL: BASE_URL,
		storageState: { cookies: [], origins: [] },
		httpCredentials: { username: userId, password: PASSWORD, send: 'always' },
		extraHTTPHeaders: { 'OCS-APIREQUEST': 'true' },
	})
}

test.describe('compliance claim verification', () => {
	let admin: APIRequestContext
	let register = ''
	let supplier = ''
	let moduleId = ''
	let claimId = ''

	test.beforeAll(async () => {
		admin = await newApiContext()
		register = (await resolveConfig(admin)).register
		supplier = await createObject(admin, register, 'organization', { name: `Supplier ${RUN_ID}`, type: 'Supplier' })
		moduleId = await createObject(admin, register, 'module', { name: `Application ${RUN_ID}`, provider: supplier })
		claimId = await createObject(admin, register, 'compliancy', { module: moduleId, standardGemma: 'ZGW API', url: 'https://example.org/test-report.pdf' })
		const created = await admin.post('/ocs/v2.php/cloud/users?format=json', {
			form: { userid: SUPPLIER_USER, password: PASSWORD, 'groups[]': 'aanbod-beheerder' },
		})
		expect(created.ok(), await created.text()).toBe(true)
	})

	test.afterAll(async () => {
		for (const [schema, id] of [['compliancy', claimId], ['module', moduleId], ['organization', supplier]]) {
			if (id !== '') {
				await deleteObject(admin, register, schema, id)
			}
		}
		await admin.delete(`/ocs/v2.php/cloud/users/${SUPPLIER_USER}?format=json`)
		await admin.dispose()
	})

	// @e2e openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/module-compliance-assessment/spec.md#scenario-a-supplier-s-own-evidence-stays-claimed-until-a-reviewer-verifies-it
	test('evidence alone stays claimed; a reviewer outside the supplier verifies it; new evidence resets it', async () => {
		const claim = async (): Promise<Record<string, unknown>> => {
			const res = await admin.get(`/index.php/apps/openregister/api/objects/${register}/compliancy/${claimId}`)
			expect(res.status()).toBe(200)
			return await res.json()
		}

		expect((await claim()).verifiedBy ?? null).toBeNull()

		// Marking it verified through the object API is refused, for anyone.
		const supplierApi = await userApi(SUPPLIER_USER)
		const forged = await supplierApi.patch(`/index.php/apps/openregister/api/objects/${register}/compliancy/${claimId}`, { data: { verifiedBy: 'Supplier' } })
		expect(forged.ok()).toBe(false)
		// And the verify action refuses a user without review rights.
		const refused = await supplierApi.post(`/index.php/apps/stackiq/api/compliancy/${claimId}/verify`)
		expect(refused.status()).toBe(403)
		await supplierApi.dispose()

		const verified = await admin.post(`/index.php/apps/stackiq/api/compliancy/${claimId}/verify`)
		expect(verified.status(), await verified.text()).toBe(200)
		const stamped = await claim()
		expect(String(stamped.verifiedBy ?? '')).not.toBe('')
		expect(String(stamped.verifiedAt ?? '')).toMatch(/^\d{4}-\d\d-\d\d/)

		const changed = await admin.patch(`/index.php/apps/openregister/api/objects/${register}/compliancy/${claimId}`, { data: { url: 'https://example.org/new-report.pdf' } })
		expect(changed.ok(), await changed.text()).toBe(true)
		expect((await claim()).verifiedBy ?? null).toBeNull()
	})
})
