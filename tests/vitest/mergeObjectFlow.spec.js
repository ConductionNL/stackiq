// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The merge dialog's flow around the merge itself: opening (or closing
 * without a source), searching the merge targets, stepping between target
 * selection and configuration, the mergeable properties, changing a
 * property's choice, the source files, viewing the merged object, and the
 * file size and type labels.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const stores = vi.hoisted(() => ({
	catalogStore: { catalogiItem: { id: 'reg' }, schemaItem: { id: 'sch' } },
	navigationStore: { setModal: null },
	objectStore: { objectItem: null, setObjectItem: null },
}))

vi.mock('../../src/store/store.js', () => stores)
vi.mock('@nextcloud/vue', () => ({
	NcButton: {},
	NcDialog: {},
	NcLoadingIcon: {},
	NcNoteCard: {},
	NcSelect: {},
	NcTextField: {},
	NcCheckboxRadioSwitch: {},
	NcEmptyContent: {},
}))

const { default: MergeObject } = await import('../../src/modals/object/MergeObject.vue')

/**
 * A dialog instance: its data, bound methods and computed getters.
 *
 * @param {object} [state] Data to override.
 * @return {object} The instance.
 */
function dialog(state = {}) {
	const vm = { ...MergeObject.data(), ...state }
	for (const [name, fn] of Object.entries(MergeObject.methods)) {
		vm[name] = fn.bind(vm)
	}
	for (const [name, getter] of Object.entries(MergeObject.computed)) {
		Object.defineProperty(vm, name, { get: () => getter.call(vm), configurable: true })
	}
	return vm
}

const source = { '@self': { id: 'src' }, id: 's', title: 'Bron', attachments: [{ name: 'a.pdf' }], _meta: 1 }
const target = { '@self': { id: 'tgt' }, id: 't', title: 'Doel', city: 'Utrecht' }

let fetchMock

beforeEach(() => {
	stores.objectStore.objectItem = source
	stores.objectStore.setObjectItem = vi.fn()
	stores.navigationStore.setModal = vi.fn()
	stores.catalogStore.catalogiItem = { id: 'reg' }
	stores.catalogStore.schemaItem = { id: 'sch' }
	fetchMock = vi.fn()
	vi.stubGlobal('fetch', fetchMock)
	vi.spyOn(console, 'log').mockImplementation(() => {})
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	vi.unstubAllGlobals()
	vi.restoreAllMocks()
})

describe('opening the dialog', () => {
	it('loads the source files and lists the other objects of the schema', async () => {
		fetchMock.mockResolvedValue({ json: async () => ({ results: [source, target] }) })
		const vm = dialog({ searchTerm: 'do' })

		MergeObject.mounted.call(vm)
		await vi.waitFor(() => expect(vm.loading).toBe(false))

		expect(fetchMock).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/reg/sch?_search=do')
		expect(vm.availableObjects).toEqual([target])
		expect(vm.sourceFiles).toEqual([{ name: 'a.pdf' }])
		expect(vm.sourceRelations).toEqual([])
		expect(stores.navigationStore.setModal).not.toHaveBeenCalled()
	})

	it('closes straight away without a source object', () => {
		stores.objectStore.objectItem = null
		const vm = dialog()

		vm.initializeMerge()

		expect(stores.navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(fetchMock).not.toHaveBeenCalled()
	})

	it('does not search without a register and schema, and empties the list on a failed search', async () => {
		stores.catalogStore.schemaItem = null
		const vm = dialog({ availableObjects: [target] })
		await vm.searchObjects()
		expect(fetchMock).not.toHaveBeenCalled()

		stores.catalogStore.schemaItem = { id: 'sch' }
		fetchMock.mockRejectedValue(new Error('offline'))
		await vm.searchObjects()
		expect(vm.availableObjects).toEqual([])
		expect(vm.loading).toBe(false)
	})

	it('has no source files when the source has no attachment list', async () => {
		stores.objectStore.objectItem = { ...source, attachments: 'none' }
		const vm = dialog({ sourceFiles: [{ name: 'old' }] })

		await vm.loadSourceData()

		expect(vm.sourceFiles).toEqual([])
	})
})

describe('stepping through the merge', () => {
	it('only moves on once a target is chosen, and seeds the merge from both objects', () => {
		const vm = dialog()

		vm.nextStep()
		expect(vm.step).toBe(1)

		vm.selectTargetObject(target)
		expect(vm.mergeableProperties).toEqual(['id', 'title', 'attachments', 'city'])
		vm.nextStep()

		expect(vm.step).toBe(2)
		expect(vm.mergedData).toEqual({
			id: 't',
			title: 'Doel',
			attachments: [{ name: 'a.pdf' }],
			city: 'Utrecht',
		})
		expect(vm.canMerge).toBe(true)

		vm.previousStep()
		expect(vm.step).toBe(1)
		vm.previousStep()
		expect(vm.step).toBe(1)
	})

	it('has nothing to merge before a target is chosen', () => {
		const vm = dialog()

		expect(vm.mergeableProperties).toEqual([])
		expect(vm.canMerge).toBe(false)
	})

	it('stores the chosen value of a property and resets its custom text', () => {
		const vm = dialog({ customValues: { title: 'typed' } })

		vm.onPropertySelectionChange('title', { label: 'From Source: Bron', value: 'Bron' })
		expect(vm.mergedData.title).toBe('Bron')
		expect(vm.propertySelections.title).toEqual({ label: 'From Source: Bron', value: 'Bron' })
		expect(vm.customValues.title).toBe('')

		vm.customValues.title = 'mine'
		vm.onPropertySelectionChange('title', { label: 'Other/Custom', value: 'custom' })
		expect(vm.mergedData.title).toBe('custom')
		expect(vm.customValues.title).toBe('mine')

		vm.onPropertySelectionChange('city', { label: 'Other/Custom', value: 'custom' })
		expect(vm.customValues.city).toBe('')

		vm.onPropertySelectionChange('title', null)
		expect(vm.mergedData.title).toBe('custom')
	})

	it('opens the merged target object', () => {
		const vm = dialog()
		vm.viewMergedObject()
		expect(stores.objectStore.setObjectItem).not.toHaveBeenCalled()

		vm.selectTargetObject(target)
		vm.viewMergedObject()
		expect(stores.objectStore.setObjectItem).toHaveBeenCalledWith(target)
		expect(stores.navigationStore.setModal).toHaveBeenCalledWith('viewObject')
	})

	it('folds and unfolds the file and relation lists', () => {
		const vm = dialog()

		vm.toggleFileList()
		vm.toggleRelationList()
		vm.toggleRelationList()

		expect([vm.showFileList, vm.showRelationList]).toEqual([false, true])
	})
})

describe('file labels', () => {
	it('formats file sizes', () => {
		const vm = dialog()

		expect([0, 512, 1536, 1048576, 3221225472].map((b) => vm.formatFileSize(b)))
			.toEqual(['N/A', '512 Bytes', '1.5 KB', '1 MB', '3 GB'])
	})

	it('names file types by extension', () => {
		const vm = dialog()

		expect(['rapport.PDF', 'notes.docx', 'foto.jpeg', 'data.csv', '', 'archief.rar'].map((f) => vm.getFileType(f)))
			.toEqual(['PDF', 'Word', 'Image', 'CSV', 'Unknown', 'Archive'])
	})
})
