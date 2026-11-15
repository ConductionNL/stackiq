// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * LicensePostureView's own logic, run against a plain instance: what it
 * reads from the object store, how it names and labels the posture util's
 * rows (application, organisation and vendor names, percentages, money,
 * seat labels, vendor order), the per-organisation report, the loading of
 * the four collections and the currency fallback. The posture util itself
 * is stubbed; its arithmetic has its own specs.
 *
 * @spec openspec/specs/software-license-posture/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const store = vi.hoisted(() => ({ objectStore: { collections: {}, settings: null, fetchSettings: null } }))
const posture = vi.hoisted(() => ({
	portfolioPosture: vi.fn(),
	perVendorRollup: vi.fn(),
	perOrganisationPosture: vi.fn(),
	seatRows: vi.fn(),
}))
const fetched = vi.hoisted(() => ({ types: [], fail: null }))
const live = vi.hoisted(() => ({ calls: [] }))

vi.mock('../../src/store/store.js', () => store)
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/{(\w+)}/g, (match, key) => (key in vars ? vars[key] : match)),
}))
vi.mock('../../src/composables/useLiveCollections.js', () => ({
	useLiveCollections: (target, types) => live.calls.push([target, types]),
}))
vi.mock('../../src/utils/objectTypeCollection.js', () => ({
	fetchObjectTypeCollection: async (target, type) => {
		fetched.types.push(type)
		if (fetched.fail === type) {
			throw new Error('offline')
		}
	},
}))
vi.mock('../../src/utils/licensePosture.js', async (importOriginal) => ({
	...(await importOriginal()),
	...posture,
}))

const { default: View } = await import('../../src/views/LicensePostureView.vue')

/**
 * A plain instance: data, bound methods and computed getters.
 *
 * @param {object} overrides Data to set.
 * @return {object} The instance.
 */
function instance(overrides = {}) {
	const self = { ...View.data.call({}), ...overrides }
	for (const [name, method] of Object.entries(View.methods)) {
		self[name] = typeof method === 'function' ? method.bind(self) : method
	}
	for (const [name, getter] of Object.entries(View.computed)) {
		Object.defineProperty(self, name, { get: () => getter.call(self), configurable: true })
	}
	return self
}

/**
 * Fill the store's collections.
 *
 * @param {object} collections type -> results.
 */
function collections(collections) {
	store.objectStore.collections = Object.fromEntries(
		Object.entries(collections).map(([type, results]) => [type, { results }]),
	)
}

beforeEach(() => {
	store.objectStore.getCollection = (type) => store.objectStore.collections[type]
	store.objectStore.collections = {}
	store.objectStore.settings = null
	store.objectStore.fetchSettings = null
	fetched.types = []
	fetched.fail = null
	live.calls = []
	for (const fn of Object.values(posture)) {
		fn.mockReset()
	}
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	vi.restoreAllMocks()
})

describe('reading the store', () => {
	it('subscribes to the four collections it shows', () => {
		View.setup()

		expect(live.calls).toEqual([[store.objectStore, ['module', 'usage', 'organization', 'catalogContract']]])
	})

	it('reads empty lists when a collection is not loaded yet', () => {
		const vm = instance()

		expect([vm.modules, vm.usages, vm.contracts, vm.organisatieIndex]).toEqual([[], [], [], {}])
	})

	it('names organisations and modules by name, title or uuid', () => {
		collections({
			organization: [{ uuid: 'o1', object: { name: 'Gemeente A' } }, { id: 'o2', title: 'Gemeente B' }, { uuid: 'o3' }],
			module: [{ uuid: 'm1', name: 'Zaaksysteem' }],
		})
		const vm = instance()

		expect(vm.organisatieIndex).toEqual({ o1: 'Gemeente A', o2: 'Gemeente B', o3: 'o3' })
		expect(vm.moduleNameIndex).toEqual({ m1: 'Zaaksysteem' })
		expect(vm.organisationOptions).toEqual([
			{ uuid: 'o1', label: 'Gemeente A' },
			{ uuid: 'o2', label: 'Gemeente B' },
			{ uuid: 'o3', label: 'o3' },
		])
	})
})

describe('labelling the posture', () => {
	it('formats the open share as a rounded percentage, or a dash', () => {
		const vm = instance()

		posture.portfolioPosture.mockReturnValue({ openShare: 0.666 })
		expect(vm.openSharePct).toBe('67%')
		posture.portfolioPosture.mockReturnValue({ openShare: null })
		expect(vm.openSharePct).toBe('—')
	})

	it('names, labels and orders the vendor rows', () => {
		collections({ organization: [{ uuid: 'v1', name: 'Vendor One' }] })
		posture.perVendorRollup.mockReturnValue([
			{ vendorId: 'v1', deployments: 2, mix: { 'Open source': 2 }, annualCost: 1200 },
			{ vendorId: 'v9', deployments: 5, mix: { 'Closed source': 4, Unknown: 1 }, annualCost: null },
			{ vendorId: '', deployments: 1, mix: {}, annualCost: 0 },
		])
		const vm = instance()

		const rows = vm.vendorRows

		expect(rows.map((row) => [row.vendorName, row.deployments])).toEqual([['v9', 5], ['Vendor One', 2], ['Unknown vendor', 1]])
		expect(rows[0].mix).toEqual({ openCount: 0, closedCount: 4, unknownCount: 1 })
		expect(rows[0].costLabel).toBe('—')
		expect(rows[1].costLabel).toBe(vm.formatCurrency(1200))
	})

	it('names and labels the seat rows', () => {
		collections({ module: [{ uuid: 'm1', name: 'Zaaksysteem' }], organization: [{ uuid: 'o1', name: 'Gemeente A' }] })
		posture.seatRows.mockReturnValue([
			{ moduleId: 'm1', consumerId: 'o1', contractNumber: 'C-1', metric: 'Per device', bought: 1000, inUse: 1200, state: 'over', over: 200 },
			{ moduleId: 'mx', consumerId: 'ox', contractNumber: 'C-2', metric: 'Seats', bought: 10, inUse: null, state: 'unknown' },
			{ moduleId: 'mx', consumerId: 'ox', contractNumber: '', metric: null, bought: 5, inUse: 5, state: 'within' },
		])
		const vm = instance()

		const rows = vm.seatTableRows

		expect(posture.seatRows).toHaveBeenCalledWith([], [])
		expect(rows.map((row) => [row.applicationName, row.organisationName, row.metricLabel, row.stateLabel, row.isOver])).toEqual([
			['Zaaksysteem', 'Gemeente A', 'Per device', 'Over licence by 200', true],
			['C-2', '', 'Seats', 'Unknown', false],
			['Contract', '', '', 'Within licence', false],
		])
		expect(rows[0].boughtLabel).toBe((1000).toLocaleString())
		expect(rows[1].inUseLabel).toBe('')
		expect(rows[2].inUseLabel).toBe('5')
	})
})

describe('the per-organisation report', () => {
	it('is empty until an organisation is chosen', () => {
		const vm = instance()

		expect(vm.orgPosture).toEqual({ total: 0, open: 0, closed: 0, unknown: 0, openShare: null, closedContributors: [] })
		expect(vm.orgSharePct).toBe('—')
		expect(vm.orgClosedContributors).toEqual([])
		expect(posture.perOrganisationPosture).not.toHaveBeenCalled()
	})

	it('reports the chosen organisation with named closed contributors', () => {
		collections({ module: [{ uuid: 'm1', name: 'Zaaksysteem' }] })
		posture.perOrganisationPosture.mockReturnValue({ openShare: 0.25, closedContributors: ['m1', 'm2'] })
		const vm = instance({ selectedOrg: { uuid: 'o1', label: 'Gemeente A' } })

		expect(vm.orgSharePct).toBe('25%')
		expect(vm.orgClosedContributors).toEqual([{ id: 'm1', name: 'Zaaksysteem' }, { id: 'm2', name: 'm2' }])
		expect(posture.perOrganisationPosture).toHaveBeenCalledWith('o1', [{ uuid: 'm1', name: 'Zaaksysteem' }], [])
	})
})

describe('loading', () => {
	it('fetches the settings when missing, then the four collections', async () => {
		const fetchSettings = vi.fn(async () => {
			fetched.types.push('settings')
		})
		store.objectStore.fetchSettings = fetchSettings
		const vm = instance()

		await View.mounted.call(vm)

		expect(fetched.types).toEqual(['settings', 'module', 'usage', 'organization', 'catalogContract'])
		expect(vm.loading).toBe(false)
	})

	it('skips the settings when present and stops loading after a failure', async () => {
		store.objectStore.settings = { register: '1' }
		store.objectStore.fetchSettings = vi.fn()
		fetched.fail = 'usage'
		const vm = instance()

		await vm.loadData()

		expect(store.objectStore.fetchSettings).not.toHaveBeenCalled()
		expect(vm.loading).toBe(false)
		expect(console.error).toHaveBeenCalledWith('LicensePostureView: failed to load data', expect.any(Error))
	})

	it('formats euros without decimals, and falls back when Intl cannot', () => {
		const vm = instance()

		expect(vm.formatCurrency(1234.6)).toBe(new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }).format(1234.6))

		vi.spyOn(Intl, 'NumberFormat').mockImplementation(() => {
			throw new RangeError('no locale data')
		})
		expect(vm.formatCurrency(1234.6)).toBe('€ 1235')
	})
})
