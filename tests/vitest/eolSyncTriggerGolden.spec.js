// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for triggering the EOL sync from its settings section: the
 * request, the status merged afterwards and the message shown for a run,
 * a sync that could not run and a failed request. Recorded with
 * RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-eol-sync-runs-on-a-schedule-with-a-manual-trigger
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'

const trace = vi.hoisted(() => [])
const api = vi.hoisted(() => ({ reply: null, fail: false }))

vi.mock('@nextcloud/dialogs', () => ({
	showError: (message) => trace.push(['showError', message]),
	showSuccess: (message) => trace.push(['showSuccess', message]),
}))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, params) =>
		'[' + text + (params ? ' ' + JSON.stringify(params) : '') + ']',
}))
vi.mock('@nextcloud/vue', async () => {
	const names = [
		'NcButton',
		'NcCheckboxRadioSwitch',
		'NcLoadingIcon',
		'NcNoteCard',
		'NcSelect',
		'NcTextField',
	]
	return Object.fromEntries(names.map((name) => [name, {}]))
})
vi.mock('vue-material-design-icons/Sync.vue', () => ({ default: {} }))
vi.mock('../../src/components/AlwaysVisibleSection.vue', () => ({ default: {} }))
vi.mock('../../src/utils/adminApi.js', () => ({
	apiRequest: async (path, options) => {
		trace.push(['apiRequest', path, options])
		if (api.fail) throw new Error('timeout')
		return structuredClone(api.reply)
	},
}))

const { default: EolSyncSettings } =
	await import('../../src/views/settings/sections/EolSyncSettings.vue')

const GOLDEN = path.resolve(import.meta.dirname, 'golden/eolSyncTrigger.json')

const BEFORE = { available: true, matched: 1, skipped: 0, lastRun: 'yesterday' }

const CASES = {
	ran: { reply: { status: { matched: 12, skipped: 3 } } },
	'not available after run': {
		reply: { status: { available: false, matched: 0 } },
	},
	'reply without status': { reply: { ok: true } },
	'empty status': { reply: { status: null } },
	'null reply': { reply: null },
	'unavailable before, no status': { reply: {}, before: { available: false } },
	'request fails': { fail: true },
}

describe('EolSyncSettings triggerSync golden', () => {
	it('matches the recorded outcomes', async () => {
		const actual = {}
		const methods = EolSyncSettings.methods || EolSyncSettings.options?.methods
		for (const [name, setup] of Object.entries(CASES)) {
			trace.length = 0
			api.reply = setup.reply === undefined ? null : setup.reply
			api.fail = Boolean(setup.fail)
			const vm = {
				syncing: 'unchanged',
				status: { ...BEFORE, ...(setup.before || {}) },
				unavailableReasonLabel: 'No source configured',
			}
			for (const [method, fn] of Object.entries(methods)) {
				vm[method] = fn.bind(vm)
			}
			await vm.triggerSync()
			actual[name] = {
				trace: [...trace],
				syncing: vm.syncing,
				status: vm.status,
			}
		}
		if (process.env.RECORD_GOLDEN === '1') {
			writeFileSync(GOLDEN, JSON.stringify(actual, null, '\t') + '\n')
		}
		expect(actual).toEqual(JSON.parse(readFileSync(GOLDEN, 'utf8')))
	})
})
