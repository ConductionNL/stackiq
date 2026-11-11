// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation test for the SBOM panel's upload: the multipart request
 * (file and format, the module version in the URL), the success message and
 * reset, the refetch of the replaced set, and the error for a refused,
 * unsuccessful or failed upload; no upload without a file or a module
 * version.
 *
 * @spec openspec/specs/sbom-import/spec.md#requirement-re-import-replaces-the-previous-component-set-and-is-soft-delete-aware
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const store = vi.hoisted(() => ({ fetchCollection: null }))

vi.mock('../../src/store/store.js', () => ({ objectStore: store }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDataTable: {} }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params) => url + ' ' + JSON.stringify(params),
}))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		'[' + text + ']' + (vars ? JSON.stringify(vars) : ''),
}))

const { default: SbomComponentsPanel } =
	await import('../../src/components/sbom/SbomComponentsPanel.vue')

let calls

beforeEach(() => {
	calls = []
	store.fetchCollection = vi.fn(async (type, params) => {
		calls.push(['refetch', type, params])
	})
})

afterEach(() => {
	vi.unstubAllGlobals()
})

/**
 * Runs one import and records what happened.
 *
 * @param {Function} answer The fetch implementation.
 * @param {object} state The panel state.
 * @return {Promise<object>} The requests and the panel state afterwards.
 */
async function run(answer, state = {}) {
	vi.stubGlobal(
		'fetch',
		vi.fn(async (url, init) => {
			calls.push([
				'fetch',
				url,
				init.method,
				init.headers,
				[...init.body.entries()].map(([k, v]) => [
					k,
					typeof v === 'string' ? v : v.name,
				]),
			])
			return answer()
		}),
	)
	const fileInput = { value: 'C:/fake/sbom.json' }
	const vm = {
		...SbomComponentsPanel.data.call({}),
		objectId: 42,
		selectedFile: new File(['{}'], 'sbom.json'),
		$refs: { fileInput },
		...state,
	}
	for (const [name, fn] of Object.entries(SbomComponentsPanel.methods)) {
		if (typeof fn === 'function') {
			vm[name] = fn.bind(vm)
		}
	}
	await vm.importSbom()
	return {
		calls,
		uploading: vm.uploading,
		uploadError: vm.uploadError,
		uploadSuccessMessage: vm.uploadSuccessMessage,
		selectedFile: vm.selectedFile === null ? null : vm.selectedFile?.name,
		fileInputValue: fileInput.value,
	}
}

const ok = (body) => () => ({ ok: true, json: async () => body })

describe('SbomComponentsPanel importSbom', () => {
	it('uploads the file and refetches the replaced set', async () => {
		expect(await run(ok({ success: true, componentCount: 7 }))).toEqual({
			calls: [
				[
					'fetch',
					'/apps/stackiq/api/moduleversies/{moduleVersieUuid}/sbom {"moduleVersieUuid":"42"}',
					'POST',
					{ 'X-Requested-With': 'XMLHttpRequest' },
					[
						['sbomFile', 'sbom.json'],
						['format', 'cyclonedx-json'],
					],
				],
				['refetch', 'sbomComponent', { _limit: 1000 }],
				['refetch', 'moduleVersion', { _limit: 1000 }],
			],
			uploading: false,
			uploadError: null,
			uploadSuccessMessage: '[Imported {count} components.]{"count":7}',
			selectedFile: null,
			fileInputValue: '',
		})
	})

	it('counts zero components when the server gives no count, without a file input', async () => {
		const out = await run(ok({}), { $refs: {}, format: 'spdx-json' })
		expect(out.uploadSuccessMessage).toBe(
			'[Imported {count} components.]{"count":0}',
		)
		expect(out.calls[0][4]).toEqual([
			['sbomFile', 'sbom.json'],
			['format', 'spdx-json'],
		])
	})

	it('does not upload without a file or a module version', async () => {
		expect((await run(ok({}), { selectedFile: null })).calls).toEqual([])
		expect((await run(ok({}), { objectId: '' })).calls).toEqual([])
	})

	it('reports a refused, unsuccessful or failed upload', async () => {
		const refused = await run(() => ({
			ok: false,
			json: async () => ({ message: 'Too large' }),
		}))
		expect(refused).toMatchObject({
			uploading: false,
			uploadError: 'Too large',
			uploadSuccessMessage: null,
			selectedFile: 'sbom.json',
		})
		expect(refused.calls.filter((c) => c[0] === 'refetch')).toEqual([])
		calls = []
		expect((await run(ok({ success: false }))).uploadError).toBe(
			'[SBOM import failed]',
		)
		calls = []
		expect(
			(await run(() => ({ ok: false, json: async () => ({}) }))).uploadError,
		).toBe('[SBOM import failed]')
		calls = []
		expect(
			(
				await run(() => {
					throw new Error('offline')
				})
			).uploadError,
		).toBe('offline')
		calls = []
		expect(
			(
				await run(() => ({
					ok: true,
					json: async () => {
						throw new Error('')
					},
				}))
			).uploadError,
		).toBe('[SBOM import failed]')
	})
})
