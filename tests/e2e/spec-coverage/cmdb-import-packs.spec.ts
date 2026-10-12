// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * E2e coverage for openspec/changes/cmdb-import-mapping-view: the CMDB
 * import packs stored in OpenRegister's migration-pack store, the mapping
 * endpoint that reports them and the import that runs them.
 *
 * Every scenario the delta spec tags
 * `@e2e tests/e2e/spec-coverage/cmdb-import-packs.spec.ts` is driven here
 * through the HTTP API, as an admin with basic auth: OpenRegister's
 * `/api/migration-packs` to read, edit and delete a stored pack, stackiq's
 * mapping endpoint and its import endpoint.
 *
 * The packs are instance-wide, so every test that edits one puts back the
 * exact definition it read (marker included, so the pack counts as unedited
 * again) in a `finally`, and the tests run one after the other. The import
 * writes for a municipality created per run (`Gemeente Packstad <RUN_ID>`);
 * the cleanup removes it and what the import wrote for it.
 *
 * Needs an OpenRegister with the hardened migration-pack store (its pack
 * writes take `{definition}` and lock a built-in pack's slug); on an older
 * one stackiq keeps the shipped files and the first test fails on
 * `openRegisterPacks`.
 */

import type { APIRequestContext } from '@playwright/test'
import type { VoorzieningenConfig } from '../workflows/_fixtures.ts'

import { expect, request as playwrightRequest, test } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'
import {
	BASE_URL,
	createObject,
	deleteObject,
	findAll,
	newApiContext,
	resolveConfig,
	RUN_ID,
} from '../workflows/_fixtures.ts'

const MAPPING_PATH = '/index.php/apps/stackiq/api/settings/cmdb-import/mapping'
const IMPORT_PATH = '/index.php/apps/stackiq/api/cmdb-import'
const PACKS_PATH = '/index.php/apps/openregister/api/migration-packs'
const EXPORT_FIXTURE = path.resolve(
	__dirname,
	'../../fixtures/cmdb/topdesk-export-anonymised.xlsx',
)
const TARGETS = ['module', 'manufacturer', 'municipality', 'usage', 'businessOwner']
const SHIPPED_SHEETS = ['Onbeh Applicaties CMDB', 'Beheerde Applicaties CMDB']
const STORED_SLUGS = [
	'stackiq-topdesk-module',
	'stackiq-topdesk-manufacturer',
	'stackiq-topdesk-usage',
	'stackiq-topdesk-business-owner',
]
const MUNICIPALITY_NAME = `Gemeente Packstad ${RUN_ID}`

interface StoredPack {
	id: number
	packSlug: string
	builtin: boolean
	definition: Record<string, unknown> & {
		fieldMappings: Array<Record<string, unknown>>
	}
}

interface MappingPack {
	target: string
	source: string
	packId: number | null
	editable: boolean
	edited: boolean | null
	editUrl: string | null
	fieldMappings: Array<{
		source: string
		target: string
		transform: { type: string; map?: Record<string, string> }
	}>
}

let config: VoorzieningenConfig
let municipalityUuid = ''

/**
 * The stackiq packs OpenRegister holds, by slug.
 *
 * @param ctx The API context
 */
async function storedPacks(
	ctx: APIRequestContext,
): Promise<Record<string, StoredPack>> {
	const res = await ctx.get(PACKS_PATH)
	expect(res.status(), await res.text()).toBe(200)
	const bySlug: Record<string, StoredPack> = {}
	for (const pack of (await res.json()).results as StoredPack[]) {
		if (pack.packSlug.startsWith('stackiq-topdesk-')) {
			bySlug[pack.packSlug] = pack
		}
	}
	return bySlug
}

/**
 * Replace a stored pack's definition through OpenRegister's API.
 *
 * @param ctx The API context
 * @param id The pack's row id
 * @param definition The definition
 */
async function putDefinition(
	ctx: APIRequestContext,
	id: number,
	definition: Record<string, unknown>,
): Promise<void> {
	const res = await ctx.put(`${PACKS_PATH}/${id}`, { data: { definition } })
	expect(res.status(), await res.text()).toBe(200)
}

/**
 * Edit a stored pack for the length of a callback, then put back the definition it had.
 *
 * @param ctx The API context
 * @param slug The pack slug
 * @param edit Changes a copy of the stored definition
 * @param run What to do while the edit is stored; gets the row id
 */
async function withEditedPack(
	ctx: APIRequestContext,
	slug: string,
	edit: (definition: StoredPack['definition']) => void,
	run: (id: number) => Promise<void>,
): Promise<void> {
	const stored = (await storedPacks(ctx))[slug]
	expect(stored, `${slug} is stored in OpenRegister`).toBeDefined()
	const original = structuredClone(stored.definition)
	const edited = structuredClone(stored.definition)
	edit(edited)
	await putDefinition(ctx, stored.id, edited)
	try {
		await run(stored.id)
	} finally {
		await putDefinition(ctx, stored.id, original)
	}
}

/**
 * Upload the anonymised export for the run's municipality.
 *
 * @param ctx The API context
 */
async function importExport(ctx: APIRequestContext) {
	expect(
		fs.existsSync(EXPORT_FIXTURE),
		'tests/fixtures/cmdb/topdesk-export-anonymised.xlsx is committed',
	).toBe(true)
	return await ctx.post(IMPORT_PATH, {
		multipart: {
			cmdbFile: {
				name: 'topdesk-export-anonymised.xlsx',
				mimeType:
					'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
				buffer: fs.readFileSync(EXPORT_FIXTURE),
			},
			municipalityUuid,
		},
		timeout: 120000,
	})
}

/**
 * The usages the import wrote for the run's municipality.
 *
 * @param ctx The API context
 */
async function usagesOfMunicipality(
	ctx: APIRequestContext,
): Promise<Array<Record<string, unknown>>> {
	return (await findAll(ctx, config.register, config.gebruik_schema)).filter(
		(usage) => JSON.stringify(usage.consumer ?? '').includes(municipalityUuid),
	)
}

/**
 * The mapping endpoint's answer.
 *
 * @param ctx The API context
 */
async function readMapping(ctx: APIRequestContext) {
	return await ctx.get(MAPPING_PATH)
}

test.describe.serial('CMDB import packs in OpenRegister', () => {
	test.beforeAll(async () => {
		const ctx = await newApiContext()
		try {
			config = await resolveConfig(ctx)
			municipalityUuid = await createObject(
				ctx,
				config.register,
				config.organisatie_schema,
				{ name: MUNICIPALITY_NAME, type: 'Municipality', status: 'Active' },
			)
		} finally {
			await ctx.dispose()
		}
	})

	test.afterAll(async () => {
		if (!municipalityUuid) {
			return
		}
		const ctx = await newApiContext()
		try {
			for (const schema of [
				config.gebruik_schema,
				config.contactpersoon_schema,
				config.module_schema,
			]) {
				for (const row of await findAll(ctx, config.register, schema)) {
					if (!JSON.stringify(row).includes(municipalityUuid)) {
						continue
					}
					const id = String(
						row.id ?? (row['@self'] as { id?: string } | undefined)?.id ?? '',
					)
					if (id !== '') {
						await deleteObject(ctx, config.register, schema, id)
					}
				}
			}
			await deleteObject(
				ctx,
				config.register,
				config.organisatie_schema,
				municipalityUuid,
			)
		} finally {
			await ctx.dispose()
		}
	})

	// @e2e cmdb-export-import::the-four-sheet-packs-are-stored-in-openregister
	test('the four sheet packs are stored in OpenRegister as built-in packs', async () => {
		const ctx = await newApiContext()
		try {
			// The mapping endpoint seeds a pack that is still missing.
			expect((await readMapping(ctx)).status()).toBe(200)

			const packs = await storedPacks(ctx)
			expect(Object.keys(packs).sort()).toEqual([...STORED_SLUGS].sort())
			for (const slug of STORED_SLUGS) {
				expect(packs[slug].builtin, slug).toBe(true)
				const marker = packs[slug].definition['x-stackiq'] as Record<
					string,
					unknown
				>
				expect(marker, slug).toBeDefined()
				expect(marker.shippedVersion, slug).toBeTruthy()
				expect(String(marker.shippedHash), slug).toMatch(/^[0-9a-f]{64}$/)
			}
			expect(packs['stackiq-topdesk-municipality']).toBeUndefined()
		} finally {
			await ctx.dispose()
		}
	})

	// @e2e cmdb-export-import::an-admin-reads-the-mapping-the-import-uses
	test('an admin reads the mapping the import uses, with where each pack comes from', async () => {
		const ctx = await newApiContext()
		try {
			const res = await readMapping(ctx)
			expect(res.status(), await res.text()).toBe(200)
			const body = await res.json()

			expect(Object.keys(body).sort()).toEqual([
				'openRegisterPacks',
				'packs',
				'profile',
			])
			expect(body.openRegisterPacks).toBe(true)
			expect(
				body.profile.sheets.map((sheet: { name: string }) => sheet.name),
			).toEqual(SHIPPED_SHEETS)
			expect(body.profile.keyColumn).toBe('APPID')

			const packs = body.packs as MappingPack[]
			expect(packs.map((pack) => pack.target)).toEqual(TARGETS)
			for (const pack of packs) {
				if (pack.target === 'municipality') {
					expect(pack.source).toBe('file')
					expect(pack.editable).toBe(false)
					expect(pack.editUrl).toBeNull()
					continue
				}
				expect(pack.source, pack.target).toBe('openregister')
				expect(typeof pack.packId, pack.target).toBe('number')
				expect(pack.editable, pack.target).toBe(true)
				expect(pack.editUrl, pack.target).toMatch(
					new RegExp(`/apps/openregister/migration-packs/${pack.packId}$`),
				)
			}

			const usage = packs.find((pack) => pack.target === 'usage') as MappingPack
			const status = usage.fieldMappings.find(
				(mapping) => mapping.source === 'Applicatie Status',
			)
			expect(status?.target).toBe('status')
			expect(status?.transform.type).toBe('lookup')
			expect(status?.transform.map?.['In productie']).toBe('In production')
		} finally {
			await ctx.dispose()
		}
	})

	// @e2e cmdb-export-import::a-broken-stored-pack-is-reported-with-its-name
	// @e2e cmdb-export-import::a-stored-pack-that-writes-a-field-the-import-manages-is-refused
	test('a stored pack that maps a field the import sets is refused, by the mapping and by the import', async () => {
		const ctx = await newApiContext()
		try {
			await withEditedPack(
				ctx,
				'stackiq-topdesk-usage',
				(definition) => {
					const status = definition.fieldMappings.find(
						(mapping) => mapping.source === 'Applicatie Status',
					) as Record<string, unknown>
					status.target = 'provider'
				},
				async (id) => {
					const mapping = await readMapping(ctx)
					expect(mapping.status()).toBe(503)
					const refused = await mapping.json()
					expect(refused.error).toBe('MAPPING_UNAVAILABLE')
					expect(refused.details.pack).toBe('stackiq-topdesk-usage')
					expect(refused.details.packId).toBe(id)
					expect(refused.details.editUrl).toMatch(
						new RegExp(`/apps/openregister/migration-packs/${id}$`),
					)
					expect(refused.details.reason).toContain(
						`OpenRegister migration pack stackiq-topdesk-usage (id ${id})`,
					)
					expect(refused.details.reason).toContain(
						'"provider" is set by the import and cannot be mapped',
					)
					expect(refused.details.reason.length).toBeLessThanOrEqual(500)
					expect(refused.details.reason).not.toContain('\n')

					const answer = await importExport(ctx)
					expect(answer.status()).toBe(503)
					const body = await answer.json()
					expect(body.error).toBe('MAPPING_UNAVAILABLE')
					expect(body.details.pack).toBe('stackiq-topdesk-usage')
					expect(body.details.packId).toBe(id)
					expect(await usagesOfMunicipality(ctx)).toHaveLength(0)
				},
			)
			expect((await readMapping(ctx)).status()).toBe(200)
		} finally {
			await ctx.dispose()
		}
	})

	// @e2e cmdb-export-import::a-stored-module-pack-without-the-required-name-mapping-is-refused
	test('a stored module pack whose name mapping is no longer required is refused', async () => {
		const ctx = await newApiContext()
		try {
			await withEditedPack(
				ctx,
				'stackiq-topdesk-module',
				(definition) => {
					const name = definition.fieldMappings.find(
						(mapping) => mapping.target === 'name',
					) as Record<string, unknown>
					name.required = false
				},
				async (id) => {
					const answer = await importExport(ctx)
					expect(answer.status()).toBe(503)
					const body = await answer.json()
					expect(body.error).toBe('MAPPING_UNAVAILABLE')
					expect(body.details.pack).toBe('stackiq-topdesk-module')
					expect(body.details.packId).toBe(id)

					const mapping = await (await readMapping(ctx)).json()
					expect(mapping.details.reason).toContain('stackiq-topdesk-module')
					expect(mapping.details.reason).toContain(
						'"name" must be mapped as required',
					)
				},
			)
		} finally {
			await ctx.dispose()
		}
	})

	// @e2e cmdb-export-import::an-edit-in-openregister-is-what-the-import-runs
	test('an edit of the usage pack in OpenRegister is what the next import runs', async () => {
		const ctx = await newApiContext()
		try {
			await withEditedPack(
				ctx,
				'stackiq-topdesk-usage',
				(definition) => {
					const status = definition.fieldMappings.find(
						(mapping) => mapping.source === 'Applicatie Status',
					) as { transform: { map: Record<string, string> } }
					status.transform.map['In productie'] = 'Planned'
				},
				async () => {
					const mapping = await (await readMapping(ctx)).json()
					const usage = (mapping.packs as MappingPack[]).find(
						(pack) => pack.target === 'usage',
					) as MappingPack
					expect(usage.edited).toBe(true)

					const answer = await importExport(ctx)
					expect(answer.status(), await answer.text()).toBe(200)
					const report = await answer.json()
					expect(report.importWarnings).toEqual([])

					const usages = await usagesOfMunicipality(ctx)
					expect(usages).toHaveLength(2)
					// The "Beheerde" row is "In productie" in the export.
					for (const written of usages) {
						expect(written.status).toBe('Planned')
					}
				},
			)

			const restored = await (await readMapping(ctx)).json()
			const usage = (restored.packs as MappingPack[]).find(
				(pack) => pack.target === 'usage',
			) as MappingPack
			expect(usage.edited, 'the definition put back counts as unedited').toBe(
				false,
			)
		} finally {
			await ctx.dispose()
		}
	})

	// @e2e cmdb-export-import::a-deleted-pack-is-seeded-again
	test('a pack deleted in OpenRegister is seeded again', async () => {
		const ctx = await newApiContext()
		try {
			const before = (await storedPacks(ctx))['stackiq-topdesk-business-owner']
			expect(before).toBeDefined()
			const deleted = await ctx.delete(`${PACKS_PATH}/${before.id}`)
			expect(deleted.status(), await deleted.text()).toBe(204)

			const res = await readMapping(ctx)
			expect(res.status(), await res.text()).toBe(200)
			const owner = ((await res.json()).packs as MappingPack[]).find(
				(pack) => pack.target === 'businessOwner',
			) as MappingPack
			expect(owner.source).toBe('openregister')
			expect(owner.packId).not.toBe(before.id)
			expect(owner.edited).toBe(false)

			const after = (await storedPacks(ctx))['stackiq-topdesk-business-owner']
			expect(after.id).toBe(owner.packId)
			expect(after.builtin).toBe(true)
		} finally {
			await ctx.dispose()
		}
	})

	// @e2e cmdb-export-import::a-user-who-is-not-a-nextcloud-admin-cannot-read-the-mapping
	test('a signed-in user who is not a Nextcloud admin is refused', async () => {
		const ctx = await newApiContext()
		const userId = `cmdbpacks-${RUN_ID}`
		const password = `Cmdbpacks-${RUN_ID}-Pw!9`
		const created = await ctx.post('/ocs/v2.php/cloud/users?format=json', {
			form: { userid: userId, password },
		})
		expect(created.ok(), await created.text()).toBe(true)
		const user = await playwrightRequest.newContext({
			baseURL: BASE_URL,
			storageState: { cookies: [], origins: [] },
			httpCredentials: { username: userId, password, send: 'always' },
			extraHTTPHeaders: { 'OCS-APIREQUEST': 'true' },
		})
		try {
			// Prove the context is that user, so a 403 is the rule and not a failed login.
			const whoami = await user.get('/ocs/v2.php/cloud/user?format=json')
			expect(whoami.status()).toBe(200)
			expect((await whoami.json()).ocs.data.id).toBe(userId)

			expect((await user.get(MAPPING_PATH)).status()).toBe(403)
		} finally {
			await user.dispose()
			await ctx.delete(`/ocs/v2.php/cloud/users/${userId}?format=json`)
			await ctx.dispose()
		}
	})
})
