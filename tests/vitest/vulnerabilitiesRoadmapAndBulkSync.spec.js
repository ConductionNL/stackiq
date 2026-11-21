// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The vulnerabilities list (severity bands, counts and the record actions),
 * the lifecycle roadmap (phases per organisation, ordered by the nearest
 * date) and the bulk standards sync dialog, each run against a plain
 * component context with the stores doubled.
 *
 * @spec openspec/specs/application-lifecycle-tracking/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/composables/useLiveCollections.js', () => ({ useLiveCollections: vi.fn() }))
vi.mock('../../src/utils/objectTypeCollection.js', () => ({ fetchObjectTypeCollection: vi.fn() }))
const store = vi.hoisted(() => ({
	objectStore: { collections: {}, getCollection(type) { return this.collections[type] } },
	navigationStore: {},
}))
vi.mock('../../src/store/store.js', () => store)

const { default: KwetsbaarhedenView } = await import('../../src/views/KwetsbaarhedenView.vue')
const { default: LifecycleRoadmapView } = await import('../../src/views/LifecycleRoadmapView.vue')
const { default: BulkSyncDialog } = await import('../../src/modals/BulkSyncDialog.vue')
const { fetchObjectTypeCollection } = await import('../../src/utils/objectTypeCollection.js')

/**
 * A component context: props and extras, methods bound, data, and computed
 * values as getters.
 *
 * @param {object} comp The component definition.
 * @param {object} [props] Its props.
 * @return {object} The context.
 */
function context(comp, props = {}) {
	const ctx = { $emit: vi.fn(), ...props }
	for (const [name, fn] of Object.entries(comp.methods || {})) {
		ctx[name] = fn.bind(ctx)
	}
	Object.assign(ctx, comp.data ? comp.data.call(ctx) : {})
	for (const [name, def] of Object.entries(comp.computed || {})) {
		Object.defineProperty(ctx, name, { get: () => def.call(ctx), configurable: true })
	}
	return ctx
}

const { objectStore, navigationStore } = store

beforeEach(() => {
	objectStore.collections = {}
	objectStore.settings = null
	objectStore.fetchSettings = null
	objectStore.setActiveObject = vi.fn()
	Object.assign(navigationStore, { setTransferData: vi.fn(), setModal: vi.fn(), setDialog: vi.fn(), setSelected: vi.fn() })
	fetchObjectTypeCollection.mockReset()
	globalThis.OC = { requestToken: 'tok' }
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	vi.restoreAllMocks()
	vi.unstubAllGlobals()
	vi.useRealTimers()
})

describe('KwetsbaarhedenView', () => {
	beforeEach(() => {
		objectStore.collections = {
			vulnerability: { results: [
				{ uuid: 'v1', object: { name: 'Low one', cvssScore: '3,1', modules: ['m1'] } },
				{ uuid: 'v2', name: 'Big one', cveCode: 'CVE-1', cvssScore: 9.8, modules: ['m1', 'm2'] },
				{ uuid: 'v3', cvssScore: 'n/a' },
				{ uuid: 'v4', name: 'Mid', cvssScore: 5 },
			] },
			usage: { results: [] },
		}
	})

	it('lists vulnerabilities worst first with their score and reach', () => {
		const v = context(KwetsbaarhedenView)
		expect(v.rows.map((r) => [r.uuid, r.band, r.cvssScore, r.affectedCount])).toEqual([['v2', 'Critical', 9.8, 2], ['v4', 'Medium', 5, 0], ['v1', 'Low', 3.1, 1], ['v3', 'Unknown', null, 0]])
		expect([v.rows[3].name, v.rows[0].cveCode, v.rows[3].cveCode, v.rows[0].exposureCount]).toEqual(['Unnamed vulnerability', 'CVE-1', '', 0])
	})

	it('counts each severity band and filters by the chosen one', () => {
		const v = context(KwetsbaarhedenView)
		expect(v.severityTabs.map((tab) => [tab.label, tab.count])).toEqual([['All', 4], ['Critical', 1], ['High', 0], ['Medium', 1], ['Low', 1]])
		v.selectedBand = 'Low'
		expect(v.filteredRows.map((r) => r.uuid)).toEqual(['v1'])
		expect([v.severityLabel('Unknown'), v.severityLabel('Odd')]).toEqual(['Unknown', 'Odd'])
	})

	it('loads the settings and both collections, surviving a failure', async () => {
		objectStore.fetchSettings = vi.fn()
		const v = context(KwetsbaarhedenView)
		await KwetsbaarhedenView.mounted.call(v)
		expect([objectStore.fetchSettings.mock.calls.length, fetchObjectTypeCollection.mock.calls.map((c) => c[1]), v.loading]).toEqual([1, ['vulnerability', 'usage'], false])
		fetchObjectTypeCollection.mockRejectedValueOnce(new Error('x'))
		await v.loadData()
		expect([v.loading, console.error.mock.calls.length]).toEqual([false, 1])
	})

	it('reports, edits, deletes and opens a vulnerability', () => {
		const v = context(KwetsbaarhedenView)
		const row = v.rows[0]
		v.reportVulnerability()
		v.editVulnerability(row)
		v.deleteVulnerability(row)
		v.openDetail(row)
		expect(navigationStore.setTransferData.mock.calls.map((c) => c[0])).toEqual(['create', row.raw, row.raw, row.raw])
		expect([navigationStore.setModal.mock.calls.length, navigationStore.setDialog.mock.calls[0], objectStore.setActiveObject.mock.calls[0]]).toEqual([3, ['deleteObject', { objectType: 'vulnerability' }], ['vulnerability', row.raw]])
	})
})

describe('LifecycleRoadmapView', () => {
	const now = new Date('2026-05-15T12:00:00Z')

	beforeEach(() => {
		objectStore.collections = {
			organization: { results: [{ uuid: 'o1', name: 'Gemeente A' }, { id: 'o2', title: 'B' }] },
			module: { results: [{ uuid: 'm1', name: 'Zaaksysteem' }, { uuid: 'm2', name: 'DMS' }, { uuid: 'm3', name: 'New DMS' }] },
			moduleVersion: { results: [{ uuid: 'mv1', dateEndSupport: '2026-08-01' }, { uuid: 'mv2', dateEndSupport: '2027-12-31' }] },
			usage: { results: [
				{ uuid: 'g1', consumer: 'o1', module: 'm1', moduleVersion: 'mv1', startDateInProduction: '2020-01-01' },
				{ uuid: 'g2', object: { consumer: 'o1', module: 'm2', moduleVersion: 'mv2', startDateInProduction: '2019-01-01', startDateOutPhasing: '2026-01-01', plannedReplacement: 'm3', plannedReplacementDate: '2026-09-01' } },
				{ uuid: 'g3', consumer: 'o1', module: 'gone' },
				{ uuid: 'g4', consumer: 'o1', module: 'm1', moduleVersion: 'mv2', startDateInProduction: '2021-01-01' },
				{ uuid: 'g5', consumer: 'o2', module: 'm1' },
			] },
		}
	})

	it('groups the organisation usages by phase, nearest date first', () => {
		vi.useFakeTimers({ now, toFake: ['Date'] })
		const v = context(LifecycleRoadmapView)
		expect(v.phaseGroups).toEqual([])
		v.selectedOrg = v.organisationOptions[0]
		expect(v.organisationOptions).toEqual([{ uuid: 'o1', label: 'Gemeente A' }, { uuid: 'o2', label: 'B' }])
		const groups = v.phaseGroups
		expect(groups.map((g) => [g.phase, g.entries.map((e) => e.uuid)])).toEqual([['Onbekend', ['g3']], ['In production', ['g1', 'g4']], ['To be phased out', ['g2']]])
		const [g1] = groups[1].entries
		expect([g1.appName, g1.eolApproaching, g1.eol.passed, groups[2].entries[0].replacementName, groups[0].entries[0].appName]).toEqual(['Zaaksysteem', true, false, 'New DMS', 'gone'])
	})

	it('keeps the unknown phase even when it is empty, and labels phases', () => {
		vi.useFakeTimers({ now, toFake: ['Date'] })
		const v = context(LifecycleRoadmapView)
		v.selectedOrg = { uuid: 'nobody' }
		expect(v.phaseGroups).toEqual([{ phase: 'Onbekend', entries: [] }])
		expect(v.orderedPhases.map((p) => v.phaseLabel(p))).toEqual(['Unknown', 'Acquisition', 'Planned', 'In production', 'Phasing out', 'Phased out'])
		expect([v.phaseLabel('odd'), v.urgency({ eol: {} }), v.urgency({ eol: { endDate: '2026-06-01' }, phaseOutDate: '2026-05-20' })]).toEqual(['odd', Infinity, new Date('2026-05-20').getTime()])
	})

	it('loads the four collections and opens a module', async () => {
		objectStore.fetchSettings = vi.fn()
		const v = context(LifecycleRoadmapView)
		await LifecycleRoadmapView.mounted.call(v)
		expect([fetchObjectTypeCollection.mock.calls.map((c) => c[1]), v.loading]).toEqual([['usage', 'moduleVersion', 'module', 'organization'], false])
		fetchObjectTypeCollection.mockRejectedValueOnce(new Error('x'))
		await v.loadData()
		expect(console.error).toHaveBeenCalledTimes(1)
		v.openModule('')
		v.openModule('m1')
		v.onOrgChange()
		expect(navigationStore.setSelected.mock.calls).toEqual([['moduleversies']])
	})
})

describe('BulkSyncDialog', () => {
	const dialog = () => context(BulkSyncDialog, { open: false, complianceCount: 12 })

	it('starts clean each time it opens', () => {
		const d = dialog()
		Object.assign(d, { syncCompleted: true, syncProgress: { processed: 5, total: 5, modulesUpdated: 1 } })
		BulkSyncDialog.watch.open.call(d, false)
		expect(d.syncCompleted).toBe(true)
		BulkSyncDialog.watch.open.call(d, true)
		expect([d.syncCompleted, d.syncProgress.processed]).toEqual([false, 0])
	})

	it('syncs the standards and reports the result', async () => {
		const fetchSpy = vi.fn(async () => ({ ok: true, json: async () => ({ data: { totalProcessed: 12, modulesFound: 4, modulesUpdated: 3, standardsAdded: 7, errors: [] } }) }))
		vi.stubGlobal('fetch', fetchSpy)
		const d = dialog()
		await d.startBulkSync()
		expect([fetchSpy.mock.calls[0][0], fetchSpy.mock.calls[0][1].headers.requesttoken, d.syncProgress.total, d.syncResults.standardsAdded, d.syncCompleted, d.bulkSyncLoading]).toEqual(['/index.php/apps/stackiq/api/bulk-sync-standards', 'tok', 12, 7, true, false])
		expect(d.$emit.mock.calls).toEqual([['loading-change', true], ['synced'], ['loading-change', false]])
	})

	it('records a failed sync as an error and closes cleanly', async () => {
		vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, status: 502 })))
		const d = dialog()
		await d.startBulkSync()
		expect([d.syncResults.errors, d.syncCompleted, d.$emit.mock.calls.some((c) => c[0] === 'synced')]).toEqual([['Sync failed: HTTP error! status: 502'], true, false])
		d.closeBulkSyncDialog()
		expect([d.syncCompleted, d.syncResults.errors, d.$emit.mock.calls.slice(-2)]).toEqual([false, [], [['loading-change', false], ['update:open', false]]])
	})
})
