// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation test for the auto-configuration actions of the settings
 * page and its version section: auto-configure, force update and reset, for
 * a successful, a failed and an empty result and for a store that throws —
 * the toasts shown, the result kept and the busy flag.
 *
 * @spec openspec/specs/fe-settings-ui/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const toasts = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))

vi.mock('@nextcloud/dialogs', () => ({
	...toasts,
	showWarning: vi.fn(),
	showInfo: vi.fn(),
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnAdminSettingsShell: {},
	CnDataTable: {},
	CnStatusBadge: {},
}))
vi.mock('@nextcloud/initial-state', () => ({ loadState: () => ({}) }))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('../../src/store/store.js', () => ({ settingsStore: {} }))

const { default: VersionInformation } =
	await import('../../src/views/settings/sections/VersionInformation.vue')
const { default: StackiqSettings } =
	await import('../../src/views/settings/StackiqSettings.vue')

/**
 * What one action does with a store answer.
 *
 * @param {object} component The component.
 * @param {string} action The method name.
 * @param {Function} answer The store method's implementation.
 * @return {Promise<object>} The toasts, the component state and the store calls.
 */
async function run(component, action, answer) {
	toasts.showError.mockReset()
	toasts.showSuccess.mockReset()
	const store = {
		consolidatedAutoConfigure: vi.fn(answer),
		forceUpdate: vi.fn(answer),
		resetAutoConfig: vi.fn(answer),
		loadVersionInfo: vi.fn(async () => {}),
	}
	const vm = {
		store,
		autoConfiguring: false,
		consolidatedResult: 'old',
		resetAutoConfigResult: 'old',
	}
	const busy = []
	store.loadVersionInfo.mockImplementation(async () => {
		busy.push(vm.autoConfiguring)
	})
	await component.methods[action].call(vm)
	return {
		success: toasts.showSuccess.mock.calls,
		error: toasts.showError.mock.calls,
		consolidatedResult: vm.consolidatedResult,
		resetAutoConfigResult: vm.resetAutoConfigResult,
		autoConfiguring: vm.autoConfiguring,
		versionReloads: store.loadVersionInfo.mock.calls.length,
		busy,
	}
}

const ANSWERS = {
	success: async () => ({ success: true, message: 'Done here' }),
	'success without message': async () => ({ success: true }),
	failure: async () => ({ success: false, message: 'Broke' }),
	'failure without message': async () => ({ success: false }),
	empty: async () => null,
	throws: async () => {
		throw new Error('offline')
	},
}

const ACTIONS = [
	'consolidatedAutoConfigure',
	'handleForceUpdate',
	'handleResetAutoConfig',
]

beforeEach(() => {
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

describe('settings auto-configuration actions', () => {
	for (const [name, component] of [
		['VersionInformation', VersionInformation],
		['StackiqSettings', StackiqSettings],
	]) {
		for (const action of ACTIONS) {
			it(`${name} ${action}`, async () => {
				const out = {}
				for (const [answerName, answer] of Object.entries(ANSWERS)) {
					try {
						out[answerName] = await run(component, action, answer)
					} catch (error) {
						out[answerName] = { rejected: error.message }
					}
				}
				expect(out).toMatchSnapshot()
			})
		}
	}
})
