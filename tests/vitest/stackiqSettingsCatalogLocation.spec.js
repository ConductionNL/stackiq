// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin settings page's catalog location, and the toasts its
 * auto-configuration actions show for a result.
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
vi.mock('@nextcloud/initial-state', () => ({ loadState: (app, key, fallback) => fallback }))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('../../src/store/store.js', () => ({ settingsStore: {} }))

const { default: StackiqSettings } = await import('../../src/views/settings/StackiqSettings.vue')
const {
	FORCE_UPDATE_TEXTS,
	reportActionResult,
	reportAutoConfigResult,
} = await import('../../src/views/settings/autoConfigFeedback.js')

/**
 * The page over a store, without mounting.
 *
 * @param {object} store The settings store double.
 * @return {object} The instance.
 */
function page(store) {
	const vm = { ...StackiqSettings.data.call({}), store }
	for (const [name, fn] of Object.entries(StackiqSettings.methods)) {
		vm[name] = fn.bind(vm)
	}
	for (const [name, fn] of Object.entries(StackiqSettings.computed)) {
		Object.defineProperty(vm, name, { get: () => fn.call(vm), configurable: true })
	}
	return vm
}

beforeEach(() => {
	toasts.showError.mockReset()
	toasts.showSuccess.mockReset()
	vi.spyOn(console, 'error').mockImplementation(() => {})
	vi.spyOn(console, 'info').mockImplementation(() => {})
})

describe('StackiqSettings catalog location', () => {
	it('starts with the version from the page state, or Unknown', () => {
		expect(page({}).appVersion).toBe('Unknown')
	})

	it('reads the stored location on creation and compares edits against it', async () => {
		const store = { settings: { catalogLocation: 'https://a' }, loadSettings: vi.fn(async () => {}) }
		const vm = page(store)

		await StackiqSettings.created.call(vm)
		expect(store.loadSettings).toHaveBeenCalledTimes(1)
		expect(vm.catalogLocation).toBe('https://a')
		expect(vm.catalogLocationChanged).toBe(false)

		vm.onCatalogLocationChange('https://b')
		expect(vm.catalogLocationChanged).toBe(true)

		store.settings.catalogLocation = undefined
		vm.catalogLocation = ''
		expect(vm.catalogLocationChanged).toBe(false)
	})

	it('saving puts the location in the store before saving the configuration', async () => {
		const order = []
		const store = {
			settings: {},
			updateCatalogLocation: vi.fn(async (value) => order.push(['update', value])),
			saveConfiguration: vi.fn(async () => order.push(['save'])),
		}
		const vm = page(store)
		vm.catalogLocation = 'https://c'

		await vm.saveGeneralSettings()

		expect(order).toEqual([['update', 'https://c'], ['save']])
		expect(vm.savingCatalogLocation).toBe(false)
	})

	it('a failed save is logged and clears the busy flag', async () => {
		const store = {
			settings: {},
			updateCatalogLocation: vi.fn(async () => {
				throw new Error('denied')
			}),
			saveConfiguration: vi.fn(),
		}
		const vm = page(store)

		await vm.saveGeneralSettings()

		expect(store.saveConfiguration).not.toHaveBeenCalled()
		expect(console.error).toHaveBeenCalled()
		expect(vm.savingCatalogLocation).toBe(false)
	})

	it('refreshing resets the edit to the store, and a failed refresh keeps it', async () => {
		const store = { settings: { catalogLocation: 'https://stored' }, loadGeneralConfig: vi.fn(async () => {}) }
		const vm = page(store)
		vm.catalogLocation = 'https://edited'

		await vm.refreshGeneralSettings()
		expect(vm.catalogLocation).toBe('https://stored')

		store.loadGeneralConfig.mockRejectedValue(new Error('offline'))
		vm.catalogLocation = 'https://edited'
		await vm.refreshGeneralSettings()
		expect(vm.catalogLocation).toBe('https://edited')
	})

	it('the store watchers follow the location, and only a finished load resets it', () => {
		const store = { settings: { catalogLocation: 'https://after' } }
		const vm = page(store)
		const follow = StackiqSettings.watch['store.settings.catalogLocation'].handler.bind(vm)
		const loaded = StackiqSettings.watch['store.loadingGeneralSettings'].handler.bind(vm)

		follow('https://new')
		expect(vm.catalogLocation).toBe('https://new')
		follow(null)
		follow(undefined)
		expect(vm.catalogLocation).toBe('https://new')

		loaded(true, false)
		expect(vm.catalogLocation).toBe('https://new')
		loaded(false, undefined)
		expect(vm.catalogLocation).toBe('https://new')
		loaded(false, true)
		expect(vm.catalogLocation).toBe('https://after')
	})

	it('the version card reads the store version info, or nothing', () => {
		expect(page({}).versionInfo).toEqual({})
		expect(page({ versionInfo: { version: '1.2' } }).versionInfo).toEqual({ version: '1.2' })
	})
})

describe('auto-configuration feedback', () => {
	it('an action result shows its message or the default text', () => {
		reportActionResult({ success: true, message: 'All good' }, FORCE_UPDATE_TEXTS)
		reportActionResult({ success: true }, FORCE_UPDATE_TEXTS)
		reportActionResult({ success: false }, FORCE_UPDATE_TEXTS)
		reportActionResult({ success: false, message: 'Broke' }, FORCE_UPDATE_TEXTS)
		reportActionResult(null, FORCE_UPDATE_TEXTS)

		expect(toasts.showSuccess.mock.calls).toEqual([['All good'], ['Force update completed successfully']])
		expect(toasts.showError.mock.calls).toEqual([['Force update failed'], ['Broke']])
	})

	it('an auto-configuration shows a fixed success, a failure only with a message', () => {
		reportAutoConfigResult({ success: true, message: 'ignored' })
		reportAutoConfigResult({ success: false })
		reportAutoConfigResult({ success: false, message: 'No register' })
		reportAutoConfigResult(null)

		expect(toasts.showSuccess.mock.calls).toEqual([['Auto configuration completed successfully']])
		expect(toasts.showError.mock.calls).toEqual([['Auto configuration failed: No register']])
	})
})
