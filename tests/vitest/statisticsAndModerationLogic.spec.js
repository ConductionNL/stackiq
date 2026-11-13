// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Two settings sections run against plain instances. StatisticsOverview:
 * what it reads from the settings store, the compliance count it hands the
 * bulk sync dialog, its number and timestamp formatting, and the refresh on
 * mount. ModerationQueue: its translated prop defaults and loading the
 * pending queue, with the empty and failure answers.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({ answer: null, calls: [] }))
const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))

vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/{(\w+)}/g, (match, key) => (key in vars ? vars[key] : match)),
}))
vi.mock('../../src/utils/adminApi.js', () => ({
	apiRequest: async (route, init) => {
		api.calls.push(init === undefined ? [route] : [route, init])
		if (api.answer instanceof Error) {
			throw api.answer
		}
		return api.answer
	},
}))
vi.mock('../../src/store/modules/settings.js', () => ({ useSettingsStore: () => ({ from: 'pinia' }) }))
vi.mock('../../src/modals/BulkSyncDialog.vue', () => ({ default: { name: 'BulkSyncDialog', render: () => null } }))

const { default: StatisticsOverview } = await import('../../src/views/settings/sections/StatisticsOverview.vue')
const { default: ModerationQueue } = await import('../../src/views/settings/sections/ModerationQueue.vue')

/**
 * A plain instance: data, bound methods and computed getters.
 *
 * @param {object} Component The component.
 * @param {object} overrides Props, doubles or data to set.
 * @return {object} The instance.
 */
function instance(Component, overrides = {}) {
	const self = { ...overrides }
	Object.assign(self, Component.data.call(self), overrides)
	for (const [name, method] of Object.entries(Component.methods)) {
		self[name] = typeof method === 'function' ? method.bind(self) : method
	}
	for (const [name, getter] of Object.entries(Component.computed ?? {})) {
		Object.defineProperty(self, name, { get: () => getter.call(self), configurable: true })
	}
	return self
}

beforeEach(() => {
	api.answer = null
	api.calls = []
	dialogs.showError.mockReset()
})

describe('StatisticsOverview', () => {
	/**
	 * A settings store double.
	 *
	 * @param {object} state The store state.
	 * @return {object} The store.
	 */
	const settingsStore = (state = {}) => ({
		statistics: { total: 3 },
		formattedStatistics: [],
		loadingStatistics: false,
		error: null,
		loadStatistics: vi.fn(async () => {}),
		...state,
	})

	it('takes the settings store from setup', () => {
		expect(StatisticsOverview.setup()).toEqual({ settingsStore: { from: 'pinia' } })
	})

	it('reads statistics, loading state and error from the store', () => {
		const vm = instance(StatisticsOverview, { settingsStore: settingsStore({ loadingStatistics: true, error: 'Forbidden' }) })

		expect([vm.statistics, vm.loadingStats, vm.error]).toEqual([{ total: 3 }, true, 'Forbidden'])
		expect([vm.showSyncDialog, vm.bulkSyncLoading]).toEqual([false, false])
	})

	it('hands the dialog the compliance count, or zero without one', () => {
		const vm = instance(StatisticsOverview, {
			settingsStore: settingsStore({ formattedStatistics: [{ type: 'Module', count: 40 }, { type: 'Compliancy', count: 7 }] }),
		})
		expect(vm.complianceCount).toBe(7)

		vm.settingsStore.formattedStatistics = [{ type: 'Module', count: 40 }]
		expect(vm.complianceCount).toBe(0)
	})

	it('formats numbers and timestamps', () => {
		const vm = instance(StatisticsOverview, { settingsStore: settingsStore() })

		expect(vm.formatNumber(0)).toBe('0')
		expect(vm.formatNumber(1234567)).toBe((1234567).toLocaleString())
		expect(vm.formatTimestamp(0)).toBe('Unknown')
		expect(vm.formatTimestamp(null)).toBe('Unknown')
		expect(vm.formatTimestamp(1700000000)).toBe(new Date(1700000000 * 1000).toLocaleString())
	})

	it('refreshes on mount and opens the bulk sync dialog', async () => {
		const store = settingsStore()
		const vm = instance(StatisticsOverview, { settingsStore: store })

		await StatisticsOverview.mounted.call(vm)
		vm.showBulkSyncDialog()

		expect(store.loadStatistics).toHaveBeenCalledTimes(1)
		expect(vm.showSyncDialog).toBe(true)
	})
})

describe('ModerationQueue', () => {
	it('defaults to organisation registrations with translated texts', () => {
		const props = ModerationQueue.props

		expect(props.type.default).toBe('organization')
		expect(props.entityLabel.default).toBe('registration')
		expect(props.name.default()).toBe('Registration moderation')
		expect(props.description.default()).toContain('Approving an entry publishes it')
		expect(props.loadingText.default()).toBe('Loading pending registrations…')
		expect(props.emptyDescription.default()).toBe('There are no pending registrations right now.')
	})

	it('loads the pending items of its type on creation', async () => {
		api.answer = { items: [{ id: 'r1' }, { id: 'r2' }] }
		const vm = instance(ModerationQueue, { type: 'contact person' })

		await ModerationQueue.created.call(vm)

		expect(api.calls).toEqual([['moderation/pending?type=contact%20person']])
		expect(vm.items).toEqual([{ id: 'r1' }, { id: 'r2' }])
		expect(vm.loading).toBe(false)
	})

	it('shows an empty queue for an answer without a list', async () => {
		api.answer = { items: 'none' }
		const vm = instance(ModerationQueue, { type: 'organization', items: [{ id: 'stale' }] })

		await vm.loadPending()

		expect(vm.items).toEqual([])
	})

	it('reports a failed load and keeps the previous items', async () => {
		api.answer = new Error('Forbidden')
		const vm = instance(ModerationQueue, { type: 'organization', items: [{ id: 'kept' }] })

		await vm.loadPending()

		expect(dialogs.showError).toHaveBeenCalledWith('Could not load the moderation queue: Forbidden')
		expect(vm.items).toEqual([{ id: 'kept' }])
		expect(vm.loading).toBe(false)
	})
})
