// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for how the AI Act checklist loads its AI system and the
 * system's files: the guard, the two requests, the unwrapping of the
 * replies and the flags afterwards. Recorded with RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/ai-system-inventory/spec.md#requirement-req-ais-003-a-high-risk-ai-system-without-a-fundamental-rights-impact-assessment-is-flagged
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ replies: {}, fail: null, calls: [] }))

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: async (url) => {
			http.calls.push(['get', url])
			const kind = url.endsWith('/files') ? 'files' : 'object'
			if (http.fail === kind) throw new Error('down')
			return { data: structuredClone(http.replies[kind]) }
		},
	},
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params) =>
		url
			.replace('{register}', params.register)
			.replace('{schema}', params.schema)
			.replace('{id}', params.id),
}))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('@nextcloud/vue', () => ({ NcLoadingIcon: {}, NcNoteCard: {} }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnIcon: {}, CnWidgetWrapper: {} }))

const { default: AiActChecklist } =
	await import('../../src/components/ai/AiActChecklist.vue')

const GOLDEN = path.resolve(import.meta.dirname, 'golden/aiActChecklistLoad.json')

const SYSTEM = { name: 'Chatbot', riskLevel: 'high' }

const CASES = {
	'no object id': { objectId: '' },
	'object wrapper': {
		replies: { object: { object: SYSTEM }, files: { results: [{ id: 'f1' }] } },
	},
	'plain object': { replies: { object: SYSTEM, files: { results: [] } } },
	'empty object wrapper': {
		replies: { object: { object: null, name: 'outer' }, files: {} },
	},
	'null replies': { replies: { object: null, files: null } },
	'numeric id': {
		objectId: 9,
		replies: { object: SYSTEM, files: { results: [{ id: 'f2' }] } },
	},
	'object request fails': { fail: 'object', replies: { files: { results: [] } } },
	'files request fails': { fail: 'files', replies: { object: SYSTEM } },
}

describe('AiActChecklist load golden', () => {
	it('matches the recorded outcomes', async () => {
		const actual = {}
		for (const [name, setup] of Object.entries(CASES)) {
			http.replies = setup.replies || {}
			http.fail = setup.fail || null
			http.calls = []
			const vm = {
				...AiActChecklist.data.call({}),
				register: 'reg',
				schema: 'ai',
				objectId: setup.objectId === undefined ? 'uuid-1' : setup.objectId,
				system: 'unchanged',
				files: 'unchanged',
			}
			for (const [method, fn] of Object.entries(AiActChecklist.methods)) {
				vm[method] = fn.bind(vm)
			}
			await vm.load()
			actual[name] = {
				calls: http.calls,
				loading: vm.loading,
				error: vm.error,
				system: vm.system,
				files: vm.files,
			}
		}
		if (process.env.RECORD_GOLDEN === '1') {
			writeFileSync(GOLDEN, JSON.stringify(actual, null, '\t') + '\n')
		}
		expect(actual).toEqual(JSON.parse(readFileSync(GOLDEN, 'utf8')))
	})
})
