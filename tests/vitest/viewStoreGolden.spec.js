/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the view store fetches: every include flag combination
 * against a full answer, an empty answer and a failure, recording the request,
 * the console line and the store state. Recorded with RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/fe-stores/spec.md
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { createPinia, setActivePinia } from 'pinia'
import { describe, expect, it, vi } from 'vitest'

const trace = []
const answer = { current: null }

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: async (url, config) => {
			trace.push(['get', url, config])
			if (answer.current === 'reject') throw new Error('offline')
			if (answer.current === 'reject-no-message') throw {}
			return { data: answer.current }
		},
	},
}))

const { useViewStore } = await import('../../src/store/modules/view.js')

const GOLDEN = path.resolve(import.meta.dirname, 'golden/viewStoreGolden.json')
const ANSWERS = {
	full: { views: [{ id: 1 }], view: { id: 'v1' } },
	empty: {},
	failure: 'reject',
	'failure without message': 'reject-no-message',
}

describe('view store fetches', () => {
	it('answers the same for every case', async () => {
		vi.spyOn(console, 'error').mockImplementation((...args) =>
			trace.push(['console.error', String(args[0])]),
		)
		const recorded = {}
		for (let flags = 0; flags < 8; flags++) {
			for (const [name, body] of Object.entries(ANSWERS)) {
				for (const action of ['fetchViews', 'fetchView']) {
					trace.length = 0
					answer.current = body
					setActivePinia(createPinia())
					const store = useViewStore()
					store.includeGebruik = Boolean(flags & 1)
					store.includeDeelnamesGebruik = Boolean(flags & 2)
					store.includeProducts = Boolean(flags & 4)
					await store[action]('v 1/x')
					recorded[`${action} / ${flags} / ${name}`] = JSON.parse(
						JSON.stringify({ trace: [...trace], state: store.$state }),
					)
				}
			}
		}
		if (process.env.RECORD_GOLDEN) {
			writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
			return
		}
		expect(JSON.stringify(recorded)).toBe(
			JSON.stringify(JSON.parse(readFileSync(GOLDEN, 'utf8'))),
		)
	})
})
