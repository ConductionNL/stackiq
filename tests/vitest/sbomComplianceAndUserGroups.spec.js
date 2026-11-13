// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The SBOM components panel (components of one module version, their
 * vulnerability matches, licences and the import), the compliance matrix
 * view (column sources, scoping by organisation and the selection kept in
 * the URL) and the user groups settings, each run against a plain component
 * context with the stores doubled.
 *
 * @spec openspec/specs/module-compliance-assessment/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const toasts = vi.hoisted(() => [])
vi.mock('@nextcloud/dialogs', () => ({
	showError: (m) => toasts.push(['error', m]),
	showSuccess: (m) => toasts.push(['success', m]),
}))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDataTable: {} }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path, params = {}) => '/index.php' + path.replace(/\{(\w+)\}/g, (m, k) => params[k] ?? m) }))
vi.mock('../../src/components/AlwaysVisibleSection.vue', () => ({ default: {} }))
vi.mock('../../src/components/StandardTabs.vue', () => ({ default: {} }))
vi.mock('../../src/composables/useLiveCollections.js', () => ({ useLiveCollections: vi.fn() }))
vi.mock('../../src/utils/objectTypeCollection.js', () => ({ fetchObjectTypeCollection: vi.fn(), registerObjectTypeFromConfig: vi.fn() }))
const store = vi.hoisted(() => ({
	objectStore: { collections: {}, active: null, getCollection(type) { return this.collections[type] }, getActiveObject() { return this.active } },
	navigationStore: { setSelected: null },
	settingsStore: {},
}))
vi.mock('../../src/store/store.js', () => store)

const { default: SbomComponentsPanel } = await import('../../src/components/sbom/SbomComponentsPanel.vue')
const { default: ComplianceMatrixView } = await import('../../src/views/ComplianceMatrixView.vue')
const { default: UserGroupsConfiguration } = await import('../../src/views/settings/sections/UserGroupsConfiguration.vue')
const { fetchObjectTypeCollection, registerObjectTypeFromConfig } = await import('../../src/utils/objectTypeCollection.js')

const t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))

/**
 * A component context: props and extras, methods bound, data, and computed
 * values (plain or with a setter) as properties.
 *
 * @param {object} comp The component definition.
 * @param {object} [props] Its props.
 * @param {object} [extra] Anything else on `this`.
 * @return {object} The context.
 */
function context(comp, props = {}, extra = {}) {
	const ctx = { $emit: vi.fn(), $refs: {}, t, ...props, ...extra }
	for (const [name, fn] of Object.entries(comp.methods || {})) {
		ctx[name] = fn.bind(ctx)
	}
	Object.assign(ctx, comp.data ? comp.data.call(ctx) : {})
	for (const [name, def] of Object.entries(comp.computed || {})) {
		const get = typeof def === 'function' ? def : def.get
		Object.defineProperty(ctx, name, { get: () => get.call(ctx), set: def.set ? (v) => def.set.call(ctx, v) : undefined, configurable: true })
	}
	return ctx
}

const { objectStore, navigationStore, settingsStore } = store

beforeEach(() => {
	toasts.length = 0
	Object.assign(objectStore, { collections: {}, active: null, settings: null, fetchSettings: null, fetchCollection: null })
	fetchObjectTypeCollection.mockReset()
	registerObjectTypeFromConfig.mockReset()
	globalThis.OC = { requestToken: 'tok' }
	vi.spyOn(console, 'error').mockImplementation(() => {})
	vi.spyOn(console, 'warn').mockImplementation(() => {})
})

afterEach(() => {
	vi.restoreAllMocks()
	vi.unstubAllGlobals()
	window.history.replaceState({}, '', '/')
})

describe('SbomComponentsPanel', () => {
	const panel = (objectId = 'mv1') => context(SbomComponentsPanel, { objectId })

	beforeEach(() => {
		objectStore.collections = {
			moduleVersion: { results: [{ uuid: 'mv1', object: { module: 'm1', sbomLastImportedAt: '2026-05-01T10:00:00Z', sbomFileName: 'bom.json', sbomFormat: 'spdx-json' } }] },
			sbomComponent: { results: [
				{ uuid: 'c2', object: { moduleVersion: 'mv1', name: 'zlib', version: '1.3', licenses: ['Zlib'], vexCveIds: ['cve-2026-1'] } },
				{ uuid: 'c1', object: { moduleVersion: 'mv1', name: 'axios', purl: 'pkg:npm/axios@1.0.0', licenses: ['MIT', 'MIT', ''] } },
				{ uuid: 'c3', object: { moduleVersion: 'other', name: 'left-pad' } },
			] },
			vulnerability: { results: [{ id: 'v1', cveCode: 'CVE-2026-1' }, { id: 'v2', name: 'Axios SSRF', affectedModules: ['m1'] }] },
		}
	})

	it('lists the components of this version by name with their licences', () => {
		const p = panel()
		expect([p.parentModuleId, p.components.map((c) => c.uuid), p.totalComponents, p.distinctLicenseCount]).toEqual(['m1', ['c1', 'c2'], 2, 2])
		expect(p.rows.map((r) => [r.id, r.name, r.licenses, r.confirmedCount])).toEqual([['c1', 'axios', 'MIT, MIT, ', 0], ['c2', 'zlib', 'Zlib', 1]])
		expect(p.matchedVulnerabilityCount).toBeGreaterThanOrEqual(1)
		expect(p.lastImportedLabel).toMatch(/^Last imported .+ from bom\.json \(SPDX\)$/)
	})

	it('prefers the active version, and says nothing without an import', () => {
		objectStore.active = { uuid: 'mv1', module: 'm9' }
		expect(panel().parentModuleId).toBe('m9')
		objectStore.active = null
		const none = panel('unknown')
		expect([none.moduleVersion, none.moduleVersionData, none.lastImportedLabel, none.components]).toEqual([null, {}, '', []])
		objectStore.collections.moduleVersion.results[0].object = { sbomLastImportedAt: 'garbage' }
		expect(panel().lastImportedLabel).toBe('Last imported garbage from ? (CycloneDX)')
	})

	it('loads the settings and the three collections', async () => {
		objectStore.fetchSettings = vi.fn()
		const p = panel()
		await SbomComponentsPanel.mounted.call(p)
		expect([objectStore.fetchSettings.mock.calls.length, fetchObjectTypeCollection.mock.calls.map((c) => c[1]), p.loading]).toEqual([1, ['sbomComponent', 'vulnerability', 'moduleVersion'], false])
		fetchObjectTypeCollection.mockRejectedValueOnce(new Error('x'))
		await p.loadData()
		expect([p.loading, console.error.mock.calls.length]).toEqual([false, 1])
	})

	it('picks a file and sizes it', () => {
		const p = panel()
		p.uploadError = 'old'
		p.handleFileSelect({ target: { files: [] } })
		expect(p.uploadError).toBe('old')
		p.handleFileSelect({ target: { files: [{ name: 'bom.json' }] } })
		expect([p.selectedFile, p.uploadError, p.uploadSuccessMessage]).toEqual([{ name: 'bom.json' }, null, null])
		expect([p.formatFileSize(0), p.formatFileSize(512), p.formatFileSize(1536), p.formatFileSize(5 * 1024 * 1024)]).toEqual(['0 Bytes', '512 Bytes', '1.5 KB', '5 MB'])
	})

	it('imports the SBOM and reloads the components', async () => {
		const fetchSpy = vi.fn(async () => ({ ok: true, json: async () => ({ componentCount: 12 }) }))
		vi.stubGlobal('fetch', fetchSpy)
		objectStore.fetchCollection = vi.fn()
		const p = panel('mv 1')
		await p.importSbom()
		expect(fetchSpy).not.toHaveBeenCalled()
		p.selectedFile = new Blob(['{}'])
		p.format = 'spdx-json'
		p.$refs.fileInput = { value: 'C:/bom.json' }
		await p.importSbom()
		const [url, init] = fetchSpy.mock.calls[0]
		expect([url, init.method, init.headers.requesttoken, init.body.get('format')]).toEqual(['/index.php/apps/stackiq/api/moduleversies/mv 1/sbom', 'POST', 'tok', 'spdx-json'])
		expect([p.uploadSuccessMessage, p.selectedFile, p.$refs.fileInput.value, objectStore.fetchCollection.mock.calls.map((c) => c[0]), p.uploading]).toEqual(['Imported 12 components.', null, '', ['sbomComponent', 'moduleVersion'], false])
	})

	it('explains a refused import', async () => {
		const p = panel()
		p.selectedFile = new Blob(['x'])
		vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({ success: false, message: 'Not CycloneDX' }) })))
		await p.importSbom()
		expect(p.uploadError).toBe('Not CycloneDX')
		vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, json: async () => ({}) })))
		await p.importSbom()
		expect([p.uploadError, p.uploadSuccessMessage, p.selectedFile !== null]).toEqual(['SBOM import failed', null, true])
	})
})

describe('ComplianceMatrixView', () => {
	const view = () => context(ComplianceMatrixView)

	beforeEach(() => {
		objectStore.collections = {
			module: { results: [{ uuid: 'm1', name: 'Zaaksysteem' }, { uuid: 'm2', object: { name: 'DMS' } }] },
			element: { results: [{ uuid: 's1', gemmaType: 'standard_version', name: 'StUF 3.01' }, { uuid: 'x', gemmaType: 'process' }, { uuid: 's2', object: { gemmaType: 'standard_version' }, title: 'ZGW 1.5' }] },
			bioMeasure: { results: [{ uuid: 'b1', name: 'BIO 9.1' }] },
			usage: { results: [{ object: { consumer: 'o1', module: 'm2' } }, { consumer: 'o2', module: 'm1' }] },
			organization: { results: [{ uuid: 'o1', name: 'Gemeente A' }, { uuid: 'o2' }] },
			compliancy: { results: [] },
		}
	})

	it('offers standard versions or BIO measures as columns', () => {
		const v = view()
		expect([v.standardOptions.map((o) => [o.uuid, o.label]), v.noColumnsImported]).toEqual([[['s1', 'StUF 3.01'], ['s2', 'ZGW 1.5']], false])
		v.selectedColumns = [v.standardOptions[0]]
		v.columnSource = 'bioMeasure'
		expect([v.columnOptions.map((o) => o.uuid), v.selectedColumns, v.selectedStandards.length]).toEqual([['b1'], [], 1])
		v.selectedColumns = v.bioMaatregelOptions
		expect(v.selectedBioMeasures.map((o) => o.uuid)).toEqual(['b1'])
		objectStore.collections.bioMeasure = undefined
		expect(v.noColumnsImported).toBe(true)
	})

	it('narrows the modules to those an organisation uses', () => {
		const v = view()
		expect(v.scopedModules.map((m) => m.uuid)).toEqual(['m1', 'm2'])
		v.selectedOrganisation = v.organisationOptions[0]
		expect([v.organisationOptions.map((o) => o.label), v.scopedModules.map((m) => m.uuid)]).toEqual([['Gemeente A', 'o2'], ['m2']])
		v.selectedColumns = [v.standardOptions[0]]
		expect(v.matrix).toBeTruthy()
	})

	it('keeps the selection in the URL and restores it', () => {
		const v = view()
		v.selectedStandards = [v.standardOptions[1]]
		v.selectedOrganisation = { uuid: 'o1' }
		ComplianceMatrixView.watch.columnSource.call(v)
		const params = new URL(window.location.href).searchParams
		expect([params.get('columnSource'), params.get('standards'), params.get('bioMeasures'), params.get('org')]).toEqual(['standard_version', 's2', null, 'o1'])
		window.history.replaceState({}, '', '/?columnSource=bioMeasure&standards=s1,%20s9&bioMeasures=b1&org=o2')
		const w = view()
		w.restoreSelectionFromUrl()
		expect([w.columnSource, w.selectedStandards.map((o) => o.uuid), w.selectedBioMeasures.map((o) => o.uuid), w.selectedOrganisation.uuid]).toEqual(['bioMeasure', ['s1'], ['b1'], 'o2'])
		window.history.replaceState({}, '', '/?columnSource=odd&org=nobody')
		const x = view()
		x.restoreSelectionFromUrl()
		expect([x.columnSource, x.selectedOrganisation]).toEqual(['standard_version', null])
	})

	it('loads every collection it needs and survives a missing one', async () => {
		objectStore.fetchSettings = vi.fn()
		objectStore.fetchCollection = vi.fn(async (type) => {
			if (type === 'bioMeasure') {
				throw new Error('not configured')
			}
		})
		const v = view()
		await ComplianceMatrixView.mounted.call(v)
		expect([registerObjectTypeFromConfig.mock.calls.map((c) => c[1]), objectStore.fetchCollection.mock.calls.length, console.warn.mock.calls.length, v.loading]).toEqual([['module', 'compliancy', 'element', 'bioMeasure', 'usage', 'organization'], 6, 1, false])
		objectStore.fetchSettings = vi.fn(async () => { throw new Error('x') })
		await v.loadData()
		expect([console.error.mock.calls.length, v.loading]).toEqual([1, false])
	})

	it('labels modules, states, cells and module facts', () => {
		const v = view()
		expect([v.moduleLabel({ name: 'A' }), v.moduleLabel({ object: { name: 'B' } }), v.moduleLabel({ uuid: 'u' })]).toEqual(['A', 'B', 'u'])
		expect([v.stateLabel('verified'), v.stateLabel('claimed'), v.stateLabel('none')]).toEqual(['Verified', 'Claimed', 'None'])
		expect(v.cellAriaLabel({ module: { name: 'A' }, cells: { s1: { state: 'claimed' } } }, { uuid: 's1', label: 'StUF' })).toBe('A — StUF: Claimed')
		expect([v.bbnLevelLabel({ bbnLevel: 'BBN2' }), v.bbnLevelLabel({})]).toEqual(['BBN2', 'Not set'])
		expect(['executed', 'required', 'not required', undefined].map((dpiaStatus) => v.dpiaStatusLabel({ dpiaStatus }))).toEqual(['Executed', 'Required', 'Not required', 'Not set'])
	})

	it('opens the compliance list for a record with an id', () => {
		const v = view()
		navigationStore.setSelected = vi.fn()
		v.openRecord(null)
		v.openRecord({ '@self': { id: 'c1' } })
		expect(navigationStore.setSelected.mock.calls).toEqual([['komplianties']])
	})
})

describe('UserGroupsConfiguration', () => {
	const section = () => context(UserGroupsConfiguration, {}, { store: settingsStore })

	beforeEach(() => {
		Object.assign(settingsStore, { loading: false, genericUserGroups: ['gebruiker'], organizationAdminGroups: null, superUserGroups: [], saveConfiguration: vi.fn(), loadUserGroupsOnly: vi.fn() })
	})

	it('edits each group list in the store', () => {
		const s = section()
		expect([s.genericUserGroups, s.organizationAdminGroups, s.hasChanges, s.loading]).toEqual([['gebruiker'], [], true, false])
		s.addGroup()
		s.updateGroupName(1, 'maintainer')
		s.removeGroup(0)
		expect(settingsStore.genericUserGroups).toEqual(['maintainer'])
		s.organizationAdminGroups = ['a']
		s.addOrganizationAdminGroup()
		s.updateOrganizationAdminGroupName(1, 'b')
		s.removeOrganizationAdminGroup(0)
		s.superUserGroups = []
		s.addSuperUserGroup()
		s.updateSuperUserGroupName(0, 'admin')
		s.addSuperUserGroup()
		s.removeSuperUserGroup(1)
		expect([settingsStore.organizationAdminGroups, settingsStore.superUserGroups]).toEqual([['b'], ['admin']])
		settingsStore.genericUserGroups = []
		settingsStore.organizationAdminGroups = []
		settingsStore.superUserGroups = []
		expect(s.hasChanges).toBe(false)
	})

	it('describes the known groups and calls the rest custom', () => {
		const s = section()
		expect([s.getGroupDescription('ambtenaar'), s.getGroupDescription('superuser'), s.getGroupDescription('team-x')]).toEqual(['Government employee role (manual assignment only)', 'System administrator role', 'Custom user group'])
	})

	it('reloads the groups on mount and saves them, saying how it went', async () => {
		const s = section()
		UserGroupsConfiguration.mounted.call(s)
		await new Promise((resolve) => setTimeout(resolve, 0))
		await s.saveAllGroups()
		settingsStore.saveConfiguration.mockRejectedValueOnce(new Error('denied'))
		await s.saveAllGroups()
		settingsStore.loadUserGroupsOnly.mockRejectedValueOnce(new Error('offline'))
		await s.loadAllGroups()
		expect(toasts).toEqual([['success', 'User groups reloaded successfully'], ['success', 'All user groups saved successfully'], ['error', 'Failed to save user groups: denied'], ['error', 'Failed to reload user groups: offline']])
	})
})
