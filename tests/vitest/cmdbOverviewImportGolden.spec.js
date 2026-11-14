// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for how the CMDB overview sends an ITSM exchange file: the
 * guard, the request, and the outcome shown for a started, refused, failed
 * or unreadable reply and for a network failure. Recorded with
 * RECORD_GOLDEN=1.
 *
 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-006-a-file-feeds-the-same-import
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { afterEach, describe, expect, it, vi } from 'vitest'

const trace = vi.hoisted(() => [])

vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: () => ({ uid: 'admin' }),
	getRequestToken: () => 'token-1',
}))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, params) =>
		'[' + text + (params ? ' ' + JSON.stringify(params) : '') + ']',
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/nc' + url }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {},
	NcLoadingIcon: {},
	NcNoteCard: {},
}))
vi.mock('vue-material-design-icons/Upload.vue', () => ({ default: {} }))

const { default: CmdbOverview } =
	await import('../../src/views/cmdb/CmdbOverview.vue')

const GOLDEN = path.resolve(import.meta.dirname, 'golden/cmdbOverviewImport.json')

const REPLIES = {
	'no file': { noFile: true },
	started: { ok: true, body: { started: true, rows: 12 } },
	'started as text': { ok: true, body: { started: 'true', rows: 12 } },
	'refused with message': {
		ok: true,
		body: { started: false, message: 'Set up the exchange first.' },
	},
	'refused without message': { ok: true, body: { started: false } },
	'server error with body': {
		ok: false,
		body: { started: true, message: 'Boom' },
	},
	'server error page': { ok: false, badJson: true },
	'network down': { networkDown: true },
}

afterEach(() => {
	delete globalThis.fetch
})

describe('CmdbOverview importFile golden', () => {
	it('matches the recorded outcomes', async () => {
		const actual = {}
		const lines = []
		const methods = CmdbOverview.methods || CmdbOverview.options?.methods
		for (const [name, reply] of Object.entries(REPLIES)) {
			trace.length = 0
			globalThis.fetch = async (url, options) => {
				trace.push([
					'fetch',
					url,
					options.method,
					options.headers,
					options.body.get('file').name,
				])
				if (reply.networkDown) throw new Error('offline')
				return {
					ok: reply.ok,
					json: async () => {
						if (reply.badJson) throw new Error('not json')
						return reply.body
					},
				}
			}
			const file = new File(['x'], 'export.csv')
			const vm = {
				importing: 'unchanged',
				importOk: 'unchanged',
				importMessage: 'unchanged',
				$refs: { file: { files: reply.noFile ? [] : [file] } },
			}
			for (const [method, fn] of Object.entries(methods)) {
				vm[method] = fn.bind(vm)
			}
			await vm.importFile()
			actual[name] = {
				trace: [...trace],
				importing: vm.importing,
				importOk: vm.importOk,
				importMessage: vm.importMessage,
			}
			lines.push(JSON.stringify(name) + ': ' + JSON.stringify(actual[name]))
		}
		if (process.env.RECORD_GOLDEN === '1') {
			writeFileSync(GOLDEN, '{\n' + lines.join(',\n') + '\n}\n')
		}
		expect(actual).toEqual(JSON.parse(readFileSync(GOLDEN, 'utf8')))
	})
})
