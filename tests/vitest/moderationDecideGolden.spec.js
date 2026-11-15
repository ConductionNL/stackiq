// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for approving and rejecting an entry in the moderation queue:
 * the identifier guard, the request, the busy state while it runs, the
 * messages and the refresh. Recorded with RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/open-data-publishing/spec.md
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'

const trace = vi.hoisted(() => [])
const api = vi.hoisted(() => ({ fail: false, vm: null }))

vi.mock('@nextcloud/dialogs', () => ({
	showError: (message) => trace.push(['showError', message]),
	showSuccess: (message) => trace.push(['showSuccess', message]),
}))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, params) =>
		'[' + text + (params ? ' ' + JSON.stringify(params) : '') + ']',
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {},
	NcEmptyContent: {},
	NcLoadingIcon: {},
}))
vi.mock('vue-material-design-icons/Check.vue', () => ({ default: {} }))
vi.mock('vue-material-design-icons/CheckCircle.vue', () => ({ default: {} }))
vi.mock('vue-material-design-icons/Close.vue', () => ({ default: {} }))
vi.mock('../../src/components/AlwaysVisibleSection.vue', () => ({ default: {} }))
vi.mock('../../src/utils/adminApi.js', () => ({
	apiRequest: async (route, options) => {
		trace.push([
			'apiRequest',
			route,
			options,
			'busy',
			api.vm.busyId,
			api.vm.busyAction,
		])
		if (api.fail) throw new Error('conflict')
		return {}
	},
}))

const { default: ModerationQueue } =
	await import('../../src/views/settings/sections/ModerationQueue.vue')

const GOLDEN = path.resolve(import.meta.dirname, 'golden/moderationDecide.json')

const CASES = {
	'approve by id': { method: 'approve', item: { id: 'a/1', uuid: 'ignored' } },
	'approve by uuid': { method: 'approve', item: { uuid: 'u 2' } },
	'reject by id': { method: 'reject', item: { id: 'r-3' } },
	'approve without id': { method: 'approve', item: { name: 'x' } },
	'reject without id': { method: 'reject', item: { id: '' } },
	'approve fails': { method: 'approve', item: { id: 'f-1' }, fail: true },
	'reject fails': { method: 'reject', item: { id: 'f-2' }, fail: true },
	'empty label': { method: 'reject', item: { id: 'e-1' }, label: '' },
}

describe('ModerationQueue decide golden', () => {
	it('matches the recorded outcomes', async () => {
		const actual = {}
		const methods = ModerationQueue.methods || ModerationQueue.options?.methods
		for (const [name, setup] of Object.entries(CASES)) {
			trace.length = 0
			api.fail = Boolean(setup.fail)
			const vm = {
				type: 'review',
				entityLabel: setup.label === undefined ? 'review' : setup.label,
				busyId: 'unchanged',
				busyAction: 'unchanged',
				loadPending: async () => trace.push(['loadPending']),
			}
			api.vm = vm
			for (const [method, fn] of Object.entries(methods)) {
				if (method !== 'loadPending') {
					vm[method] = fn.bind(vm)
				}
			}
			await vm[setup.method](setup.item)
			actual[name] = {
				trace: [...trace],
				busyId: vm.busyId,
				busyAction: vm.busyAction,
			}
		}
		if (process.env.RECORD_GOLDEN === '1') {
			writeFileSync(GOLDEN, JSON.stringify(actual, null, '\t') + '\n')
		}
		expect(actual).toEqual(JSON.parse(readFileSync(GOLDEN, 'utf8')))
	})
})
