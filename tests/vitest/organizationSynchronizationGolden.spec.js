// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the synchronisation settings section's requests: loading
 * the sync status, a manual sync and an organisation sync, across good
 * replies, refused syncs, HTTP errors and network failures. Recorded with
 * RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/fe-settings-ui/spec.md
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { afterEach, describe, expect, it, vi } from 'vitest'

const trace = vi.hoisted(() => [])

vi.mock('@nextcloud/dialogs', () => ({
	showError: (message) => trace.push(['showError', message]),
	showSuccess: (message) => trace.push(['showSuccess', message]),
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {},
	NcLoadingIcon: {},
	NcNoteCard: {},
	NcSelect: {},
}))
vi.mock('../../src/components/AlwaysVisibleSection.vue', () => ({ default: {} }))
vi.mock('../../src/store/store.js', () => ({ settingsStore: {} }))
vi.mock('../../src/utils/heartbeat.js', () => ({
	withHeartbeat: async (operation, interval) => {
		trace.push(['withHeartbeat', interval])
		return operation()
	},
}))

const { default: OrganizationSynchronization } =
	await import('../../src/views/settings/sections/OrganizationSynchronization.vue')

const GOLDEN = path.resolve(
	import.meta.dirname,
	'golden/organizationSynchronization.json',
)

const REPLIES = {
	success: {
		ok: true,
		status: 200,
		statusText: 'OK',
		json: { success: true, synced: 3 },
	},
	'refused with message': {
		ok: true,
		status: 200,
		statusText: 'OK',
		json: { success: false, message: 'Busy' },
	},
	'refused without message': {
		ok: true,
		status: 200,
		statusText: 'OK',
		json: { success: false },
	},
	'http error': {
		ok: false,
		status: 503,
		statusText: 'Service Unavailable',
		json: {},
	},
	'bad json': { ok: true, status: 200, statusText: 'OK', badJson: true },
	'network down': { networkDown: true },
}

/**
 * Build a bound component instance with a fetch that answers with the reply.
 *
 * @param {object} reply The reply.
 * @param {object} state Extra component state.
 * @return {object} The instance.
 */
function instance(reply, state) {
	globalThis.fetch = async (url, options) => {
		trace.push(['fetch', url, options ?? null])
		if (reply.networkDown) throw new Error('network down')
		return {
			ok: reply.ok,
			status: reply.status,
			statusText: reply.statusText,
			json: async () => {
				if (reply.badJson) throw new Error('bad json')
				return structuredClone(reply.json)
			},
		}
	}
	const vm = { ...OrganizationSynchronization.data.call({}), ...state }
	for (const [name, fn] of Object.entries(OrganizationSynchronization.methods)) {
		vm[name] = fn.bind(vm)
	}
	return vm
}

const RUNS = {
	loadSyncStatus: {
		state: { selectedTimeWindow: { value: 30 } },
		fields: ['syncStatus', 'loadingSyncStatus'],
	},
	'loadSyncStatus default window': {
		method: 'loadSyncStatus',
		state: { selectedTimeWindow: null },
		fields: ['syncStatus', 'loadingSyncStatus'],
	},
	performManualSync: {
		state: { selectedTimeWindow: { value: 60 } },
		fields: ['syncResult', 'performingSync', 'syncStatus'],
	},
	performOrganisationSync: {
		state: { orgSyncOptions: { dryRun: false, batchSize: 50 } },
		fields: ['orgSyncResult', 'performingOrgSync'],
	},
	'performOrganisationSync dry run': {
		method: 'performOrganisationSync',
		state: { orgSyncOptions: { dryRun: true, batchSize: 10 } },
		fields: ['orgSyncResult', 'performingOrgSync'],
	},
}

afterEach(() => {
	delete globalThis.fetch
})

describe('OrganizationSynchronization requests golden', () => {
	it('matches the recorded outcomes', async () => {
		vi.spyOn(console, 'error').mockImplementation((message, error) =>
			trace.push(['console.error', message, String(error)]),
		)
		const actual = {}
		const lines = []
		for (const [run, setup] of Object.entries(RUNS)) {
			for (const [replyName, reply] of Object.entries(REPLIES)) {
				trace.length = 0
				const vm = instance(reply, setup.state)
				await vm[setup.method || run]()
				const fields = Object.fromEntries(
					setup.fields.map((field) => [field, vm[field] ?? null]),
				)
				const key = run + ' / ' + replyName
				actual[key] = { trace: [...trace], ...fields }
				lines.push(JSON.stringify(key) + ': ' + JSON.stringify(actual[key]))
			}
		}
		if (process.env.RECORD_GOLDEN === '1') {
			writeFileSync(GOLDEN, '{\n' + lines.join(',\n') + '\n}\n')
		}
		expect(actual).toEqual(JSON.parse(readFileSync(GOLDEN, 'utf8')))
	})
})
