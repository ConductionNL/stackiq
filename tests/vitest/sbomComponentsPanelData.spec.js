/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * SbomComponentsPanel — what the panel computes from the store and what its
 * upload does: the module version's own components sorted by name, table
 * rows with license text and match counts, the summary tiles, the file size
 * label, file selection, the import call with its success and failure
 * notes, and loading the collections it joins.
 *
 * Like `sbomProvenanceLabel.spec.js`, the component's real computeds are
 * installed as getters on a plain instance and its methods are called on it,
 * so nothing renders and only the store and `fetch` are doubled.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const stubs = vi.hoisted(() => ({
	active: null,
	collections: {},
	fetchCollection: null,
	fetchSettings: null,
	settings: {},
	fetchType: null,
}))

vi.mock('@conduction/nextcloud-vue', () => ({
	CnDataTable: { name: 'CnDataTable', render: () => null },
}))

vi.mock('../../src/store/store.js', () => ({
	objectStore: {
		getActiveObject: () => stubs.active,
		getCollection: (type) => stubs.collections[type] ?? { results: [] },
		fetchCollection: (...args) => stubs.fetchCollection(...args),
		fetchSettings: () => stubs.fetchSettings(),
		get settings() {
			return stubs.settings
		},
	},
}))

vi.mock('../../src/utils/objectTypeCollection.js', () => ({
	fetchObjectTypeCollection: (store, type) => stubs.fetchType(type),
}))

const Panel = (await import('../../src/components/sbom/SbomComponentsPanel.vue')).default

const VERSION = 'mv-1'
const MODULE = 'mod-1'

/**
 * A stand-in instance with the real computeds and methods.
 *
 * @param {object} base Instance fields.
 * @return {object} The instance.
 */
function instance(base = {}) {
	const vm = { objectId: VERSION, ...Panel.data.call({}), $refs: {}, ...base }
	for (const [name, getter] of Object.entries(Panel.computed)) {
		Object.defineProperty(vm, name, { get: () => getter.call(vm), configurable: true })
	}
	for (const [name, method] of Object.entries(Panel.methods)) {
		vm[name] = method.bind(vm)
	}
	return vm
}

beforeEach(() => {
	stubs.active = null
	stubs.settings = {}
	stubs.collections = {
		moduleVersion: { results: [{ uuid: VERSION, object: { module: MODULE } }] },
		sbomComponent: {
			results: [
				{ uuid: 'c-2', object: { moduleVersion: VERSION, name: 'zlib', version: '1.3', purl: 'pkg:generic/zlib@1.3', licenses: ['Zlib'] } },
				{ uuid: 'c-1', object: { moduleVersion: { uuid: VERSION }, name: 'axios', version: '1.7.0', purl: 'pkg:npm/axios@1.7.0', licenses: ['MIT', '', 'MIT'], vexCveIds: ['cve-2024-1'] } },
				{ uuid: 'c-3', object: { moduleVersion: 'other-version', name: 'left-pad', licenses: ['WTFPL'] } },
				{ uuid: 'c-4', object: { moduleVersion: VERSION, name: 'lodash', licenses: 'MIT' } },
			],
		},
		vulnerability: {
			results: [
				{ uuid: 'v-1', object: { cveCode: 'CVE-2024-1', name: 'axios redirect' } },
				{ uuid: 'v-2', object: { cveCode: 'CVE-2024-2', name: 'zlib overflow', modules: [MODULE] } },
				{ uuid: 'v-3', object: { cveCode: 'CVE-2024-3', name: 'lodash prototype', modules: ['other-module'] } },
			],
		},
	}
	stubs.fetchCollection = vi.fn().mockResolvedValue(undefined)
	stubs.fetchSettings = vi.fn().mockResolvedValue(undefined)
	stubs.fetchType = vi.fn().mockResolvedValue(undefined)
	globalThis.OC = { requestToken: 'tok' }
})

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('SbomComponentsPanel — what it shows', () => {
	it('lists only this version\'s components, sorted by name', () => {
		expect(instance().components.map((c) => c.uuid)).toEqual(['c-1', 'c-4', 'c-2'])
		expect(instance().totalComponents).toBe(3)
	})

	it('builds table rows with license text and confirmed and possible match counts', () => {
		expect(instance().rows).toEqual([
			{ id: 'c-1', name: 'axios', version: '1.7.0', purl: 'pkg:npm/axios@1.7.0', licenses: 'MIT, , MIT', confirmedCount: 1, possibleCount: 0 },
			{ id: 'c-4', name: 'lodash', version: '', purl: '', licenses: '', confirmedCount: 0, possibleCount: 0 },
			{ id: 'c-2', name: 'zlib', version: '1.3', purl: 'pkg:generic/zlib@1.3', licenses: 'Zlib', confirmedCount: 0, possibleCount: 1 },
		])
	})

	it('counts distinct non-empty licenses and distinct matched vulnerabilities', () => {
		const vm = instance()

		expect(vm.distinctLicenseCount).toBe(2)
		expect(vm.matchedVulnerabilityCount).toBe(2)
	})

	it('scopes possible matches to the parent module, so an unknown version matches nothing possible', () => {
		stubs.collections.moduleVersion = { results: [] }

		const vm = instance()

		expect(vm.parentModuleId).toBe('')
		expect(vm.rows.map((r) => r.possibleCount)).toEqual([0, 0, 0])
		expect(vm.matchedVulnerabilityCount).toBe(1)
	})

	it('names the provenance format SPDX or CycloneDX and keeps an unreadable date as written', () => {
		stubs.collections.moduleVersion = { results: [{ uuid: VERSION, object: { sbomLastImportedAt: 'gisteren', sbomFormat: 'spdx-json' } }] }

		expect(instance().lastImportedLabel).toBe('Last imported gisteren from ? (SPDX)')
	})

	it('formats a file size in the nearest unit', () => {
		const vm = instance()

		expect(vm.formatFileSize(0)).toBe('0 Bytes')
		expect(vm.formatFileSize(512)).toBe('512 Bytes')
		expect(vm.formatFileSize(1536)).toBe('1.5 KB')
		expect(vm.formatFileSize(5 * 1024 * 1024)).toBe('5 MB')
	})
})

describe('SbomComponentsPanel — selecting and importing', () => {
	it('keeps a selected file and clears the previous notes', () => {
		const vm = instance({ uploadError: 'old', uploadSuccessMessage: 'old' })
		const file = { name: 'bom.json', size: 10 }

		vm.handleFileSelect({ target: { files: [file] } })
		expect([vm.selectedFile, vm.uploadError, vm.uploadSuccessMessage]).toEqual([file, null, null])

		vm.handleFileSelect({ target: { files: [] } })
		expect(vm.selectedFile).toBe(file)
	})

	it('posts the file and format, says how many components came in and refetches', async () => {
		const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ success: true, componentCount: 12 }) })
		vi.stubGlobal('fetch', fetchMock)
		const fileInput = { value: 'C:\\fakepath\\bom.json' }
		const vm = instance({ selectedFile: new Blob(['{}'], { type: 'application/json' }), format: 'spdx-json', $refs: { fileInput } })

		await vm.importSbom()

		const [url, options] = fetchMock.mock.calls[0]
		expect(url).toMatch(/^\/index\.php\/apps\/stackiq\/api\/moduleversies\/.+\/sbom$/)
		expect(options.method).toBe('POST')
		expect(options.headers).toEqual({ requesttoken: 'tok', 'X-Requested-With': 'XMLHttpRequest' })
		expect(options.body.get('format')).toBe('spdx-json')
		expect(options.body.get('sbomFile')).toBeInstanceOf(Blob)
		expect(vm.uploadSuccessMessage).toBe('Imported 12 components.')
		expect([vm.selectedFile, fileInput.value, vm.uploading, vm.uploadError]).toEqual([null, '', false, null])
		expect(stubs.fetchCollection.mock.calls).toEqual([['sbomComponent', { _limit: 1000 }], ['moduleVersion', { _limit: 1000 }]])
	})

	it('shows the server message when the import is refused, and a default one without', async () => {
		vi.stubGlobal('fetch', vi.fn()
			.mockResolvedValueOnce({ ok: false, json: async () => ({ message: 'Not a CycloneDX document' }) })
			.mockResolvedValueOnce({ ok: true, json: async () => ({ success: false }) }))
		const vm = instance({ selectedFile: new Blob(['x']) })

		await vm.importSbom()
		expect(vm.uploadError).toBe('Not a CycloneDX document')

		await vm.importSbom()
		expect(vm.uploadError).toBe('SBOM import failed')
		expect(vm.uploading).toBe(false)
		expect(stubs.fetchCollection).not.toHaveBeenCalled()
	})

	it('does nothing without a file or a version', async () => {
		const fetchMock = vi.fn()
		vi.stubGlobal('fetch', fetchMock)

		await instance().importSbom()
		await instance({ objectId: null, selectedFile: new Blob(['x']) }).importSbom()

		expect(fetchMock).not.toHaveBeenCalled()
	})
})

describe('SbomComponentsPanel — loading', () => {
	it('loads settings when missing and the three collections it joins', async () => {
		stubs.settings = null
		const vm = instance()

		await vm.loadData()

		expect(stubs.fetchSettings).toHaveBeenCalledTimes(1)
		expect(stubs.fetchType.mock.calls.map(([type]) => type)).toEqual(['sbomComponent', 'vulnerability', 'moduleVersion'])
		expect(vm.loading).toBe(false)
	})

	it('stops loading when a collection fails, without throwing', async () => {
		stubs.fetchType.mockRejectedValueOnce(new Error('offline'))
		const error = vi.spyOn(console, 'error').mockImplementation(() => {})
		const vm = instance()

		await vm.loadData()

		expect(stubs.fetchSettings).not.toHaveBeenCalled()
		expect(vm.loading).toBe(false)
		expect(error).toHaveBeenCalledWith('SbomComponentsPanel: failed to load data', expect.any(Error))
		error.mockRestore()
	})
})
