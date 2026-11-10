// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for running a migration from the object migration dialog: the
 * guard, the mapping refresh, the request, the list refresh, the result shown
 * on success, a refusal and a network failure, and the state afterwards.
 * Recorded with RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { afterEach, describe, expect, it, vi } from 'vitest'

const trace = []

vi.mock('../../src/store/store.js', () => ({
	navigationStore: {},
	objectStore: { refreshObjectList: () => trace.push(['refreshObjectList']) },
	registerStore: {},
	schemaStore: {},
}))

const { default: MigrationObject } =
	await import('../../src/modals/object/MigrationObject.vue')

const GOLDEN = path.resolve(import.meta.dirname, 'golden/migrationObject.json')

const ANSWERS = {
	'cannot migrate': null,
	succeeds: { ok: true, status: 200, body: { success: true, migrated: 2 } },
	refused: { ok: false, status: 409, body: {} },
	'network failure': 'reject',
	'failure without a message': 'reject-empty',
}

describe('migration dialog golden', () => {
	afterEach(() => {
		vi.unstubAllGlobals()
		vi.restoreAllMocks()
	})

	it('runs the same migration', async () => {
		const out = {}
		for (const [name, answer] of Object.entries(ANSWERS)) {
			trace.length = 0
			vi.stubGlobal('fetch', async (url, init) => {
				trace.push(['fetch', url, init])
				if (answer === 'reject') throw new TypeError('Failed to fetch')
				if (answer === 'reject-empty') throw new Error('')
				return {
					ok: answer.ok,
					status: answer.status,
					json: async () => structuredClone(answer.body),
				}
			})
			vi.spyOn(console, 'error').mockImplementation((...args) =>
				trace.push(['console.error', args[0], String(args[1])]),
			)
			const vm = {
				canMigrate: answer !== null,
				loading: 'before',
				step: 3,
				migrationResult: null,
				sourceRegister: { id: 1 },
				sourceSchema: { id: 2 },
				targetRegister: { id: 3 },
				targetSchema: { id: 4 },
				selectedObjects: [{ id: 'o1' }, { id: 'o2' }],
				mapping: { title: 'name' },
			}
			for (const [method, fn] of Object.entries(MigrationObject.methods)) {
				vm[method] = fn.bind(vm)
			}
			vm.convertUIToMapping = () => trace.push(['convertUIToMapping'])
			await vm.performMigration()
			out[name] = {
				trace: structuredClone(trace),
				loading: vm.loading,
				step: vm.step,
				migrationResult: vm.migrationResult,
			}
		}
		if (process.env.RECORD_GOLDEN === '1') {
			writeFileSync(GOLDEN, JSON.stringify(out, null, '\t') + '\n')
		}
		expect(out).toEqual(JSON.parse(readFileSync(GOLDEN, 'utf8')))
	})
})
