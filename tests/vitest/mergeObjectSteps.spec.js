/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the merge dialog's navigation and helpers: the start-up
 * guard, the target search, stepping between target choice and merge
 * configuration, the mergeable property list, changing a property's
 * choice, opening the merged object, the list toggles, the source file
 * list and the file size and type labels.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const stores = vi.hoisted(() => ({
	catalogStore: { catalogiItem: null, schemaItem: null },
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

const { default: MergeObject } =
	await import('../../src/modals/object/MergeObject.vue')

/**
 * A stand-in for the dialog: its data, the given state, every method bound
 * and the computed properties as getters.
 *
 * @param {object} state The dialog state.
 * @return {object} The stand-in.
 */
function viewModel(state = {}) {
	const vm = { ...MergeObject.data(), ...state }
	for (const [name, getter] of Object.entries(MergeObject.computed)) {
		if (!(name in state)) {
			Object.defineProperty(vm, name, { get: getter.bind(vm), configurable: true })
		}
	}
	for (const [name, fn] of Object.entries(MergeObject.methods)) {
		vm[name] = fn.bind(vm)
	}
	return vm
}

const SOURCE = { '@self': { id: 'src' }, id: 'src', title: 'Source', _meta: 1, extra: 'x' }
const TARGET = { '@self': { id: 'tgt' }, id: 'tgt', title: 'Target', other: 2 }

beforeEach(() => {
	vi.spyOn(console, 'log').mockImplementation(() => {})
	vi.spyOn(console, 'error').mockImplementation(() => {})
	stores.catalogStore.catalogiItem = { id: 'reg' }
	stores.catalogStore.schemaItem = { id: 'sch' }
	stores.navigationStore.setModal = vi.fn()
	stores.objectStore.objectItem = SOURCE
	stores.objectStore.setObjectItem = vi.fn()
})

afterEach(() => {
	vi.restoreAllMocks()
	vi.unstubAllGlobals()
})

describe('MergeObject start-up and search', () => {
	it('closes when there is no source object', () => {
		stores.objectStore.objectItem = null
		const vm = viewModel()
		vm.loadSourceData = vi.fn()
		vm.searchObjects = vi.fn()

		vm.initializeMerge()

		expect(stores.navigationStore.setModal).toHaveBeenCalledWith(false)
		expect(vm.loadSourceData).not.toHaveBeenCalled()
		expect(vm.searchObjects).not.toHaveBeenCalled()
	})

	it('loads the source data and searches when there is a source', () => {
		const vm = viewModel()
		vm.loadSourceData = vi.fn()
		vm.searchObjects = vi.fn()

		vm.initializeMerge()

		expect(vm.loadSourceData).toHaveBeenCalledTimes(1)
		expect(vm.searchObjects).toHaveBeenCalledTimes(1)
	})

	it('does not search without a register and schema', async () => {
		const fetchMock = vi.fn()
		vi.stubGlobal('fetch', fetchMock)
		stores.catalogStore.schemaItem = null
		const vm = viewModel()

		await vm.searchObjects()

		expect(fetchMock).not.toHaveBeenCalled()
		expect(vm.loading).toBe(false)
	})

	it('searches the register and schema and leaves out the source object', async () => {
		const fetchMock = vi.fn(async () => ({
			json: async () => ({
				results: [{ '@self': { id: 'src' } }, { '@self': { id: 'a' } }],
			}),
		}))
		vi.stubGlobal('fetch', fetchMock)
		const vm = viewModel({ searchTerm: 'abc' })

		await vm.searchObjects()

		expect(fetchMock).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/reg/sch?_search=abc',
		)
		expect(vm.availableObjects).toEqual([{ '@self': { id: 'a' } }])
		expect(vm.loading).toBe(false)
	})

	it('empties the list when the search fails', async () => {
		vi.stubGlobal('fetch', vi.fn(async () => { throw new Error('down') }))
		const vm = viewModel({ availableObjects: [{ id: 'old' }] })

		await vm.searchObjects()

		expect(vm.availableObjects).toEqual([])
		expect(vm.loading).toBe(false)
	})
})

describe('MergeObject steps', () => {
	it('lists the non-meta properties of both objects once', () => {
		const vm = viewModel({ selectedTargetObject: TARGET })

		expect(vm.mergeableProperties).toEqual(['id', 'title', 'extra', 'other'])
	})

	it('has no mergeable properties without a target', () => {
		expect(viewModel().mergeableProperties).toEqual([])
	})

	it('does not advance without a chosen target', () => {
		const vm = viewModel()

		vm.nextStep()

		expect(vm.step).toBe(1)
	})

	it('advances to step 2 and seeds the merge data once a target is chosen', () => {
		const vm = viewModel()

		vm.selectTargetObject(TARGET)
		vm.nextStep()

		expect(vm.selectedTargetObject).toBe(TARGET)
		expect(vm.step).toBe(2)
		expect(vm.mergedData).toEqual({ id: 'tgt', title: 'Target', extra: 'x', other: 2 })
		expect(vm.canMerge).toBe(true)
	})

	it('goes back from step 2 only', () => {
		const vm = viewModel({ step: 2 })
		vm.previousStep()
		expect(vm.step).toBe(1)

		vm.step = 3
		vm.previousStep()
		expect(vm.step).toBe(3)
	})
})

describe('MergeObject property choice', () => {
	it('stores the chosen value and clears the custom text', () => {
		const vm = viewModel({ customValues: { title: 'typed' } })
		const option = { label: 'From Source: Source', value: 'Source' }

		vm.onPropertySelectionChange('title', option)

		expect(vm.mergedData.title).toBe('Source')
		expect(vm.propertySelections.title).toBe(option)
		expect(vm.customValues.title).toBe('')
	})

	it('keeps typed custom text when switching to custom, and seeds it when absent', () => {
		const vm = viewModel({ customValues: { title: 'typed' } })
		const custom = { label: 'Other/Custom', value: 'custom' }

		vm.onPropertySelectionChange('title', custom)
		vm.onPropertySelectionChange('other', custom)

		expect(vm.customValues).toEqual({ title: 'typed', other: '' })
		expect(vm.mergedData).toEqual({ title: 'custom', other: 'custom' })
	})

	it('ignores a cleared selection', () => {
		const vm = viewModel({ mergedData: { title: 'Kept' } })

		vm.onPropertySelectionChange('title', null)
		vm.onPropertySelectionChange('title', { label: 'No value' })

		expect(vm.mergedData).toEqual({ title: 'Kept' })
	})
})

describe('MergeObject result and helpers', () => {
	it('opens the target in view mode', () => {
		const vm = viewModel({ selectedTargetObject: TARGET })

		vm.viewMergedObject()

		expect(stores.objectStore.setObjectItem).toHaveBeenCalledWith(TARGET)
		expect(stores.navigationStore.setModal).toHaveBeenCalledWith('viewObject')
	})

	it('does not navigate without a target', () => {
		viewModel().viewMergedObject()

		expect(stores.navigationStore.setModal).not.toHaveBeenCalled()
	})

	it('toggles the file and relation lists', () => {
		const vm = viewModel()

		vm.toggleFileList()
		vm.toggleRelationList()
		expect([vm.showFileList, vm.showRelationList]).toEqual([false, false])
		vm.toggleFileList()
		expect(vm.showFileList).toBe(true)
	})

	it('takes the source files from its attachments', async () => {
		const files = [{ name: 'a.pdf' }]
		stores.objectStore.objectItem = { ...SOURCE, attachments: files }
		const vm = viewModel()

		await vm.loadSourceData()

		expect(vm.sourceFiles).toBe(files)
		expect(vm.sourceRelations).toEqual([])
	})

	it('has no source files when attachments is not a list', async () => {
		stores.objectStore.objectItem = { ...SOURCE, attachments: 'nope' }
		const vm = viewModel({ sourceFiles: [{ name: 'old' }] })

		await vm.loadSourceData()

		expect(vm.sourceFiles).toEqual([])
	})

	it('formats file sizes', () => {
		const vm = viewModel()

		expect(vm.formatFileSize(0)).toBe('N/A')
		expect(vm.formatFileSize(512)).toBe('512 Bytes')
		expect(vm.formatFileSize(1536)).toBe('1.5 KB')
		expect(vm.formatFileSize(5 * 1024 * 1024)).toBe('5 MB')
	})

	it('names file types by extension', () => {
		const vm = viewModel()

		expect(vm.getFileType('')).toBe('Unknown')
		expect(vm.getFileType('report.DOCX')).toBe('Word')
		expect(vm.getFileType('photo.jpeg')).toBe('Image')
		expect(vm.getFileType('data.csv')).toBe('CSV')
	})

	it('closes the dialog', () => {
		viewModel().closeModal()

		expect(stores.navigationStore.setModal).toHaveBeenCalledWith(false)
	})
})
