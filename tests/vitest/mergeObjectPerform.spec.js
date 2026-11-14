// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation test for the merge dialog's merge and value display: the
 * merged object sent (ids left out, custom values, label/value options
 * unwrapped), the request, the result step for success and failure, the
 * log lines, and how a value is shown (N/A, JSON, truncation).
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const stores = vi.hoisted(() => ({
	catalogStore: { catalogiItem: { id: 'reg' }, schemaItem: { id: 'sch' } },
	navigationStore: {},
	objectStore: { mergeObjects: null },
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
 * A stand-in for the dialog with the given state and every method bound.
 *
 * @param {object} state The dialog state.
 * @return {object} The stand-in.
 */
function viewModel(state) {
	const vm = { loading: false, step: 2, mergeResult: null, ...state }
	vm.canMerge = Object.keys(vm.mergedData || {}).length > 0
	for (const [name, fn] of Object.entries(MergeObject.methods)) {
		vm[name] = fn.bind(vm)
	}
	return vm
}

let log
let error

beforeEach(() => {
	log = vi.spyOn(console, 'log').mockImplementation(() => {})
	error = vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	vi.restoreAllMocks()
})

const STATE = {
	mergedData: {
		id: 'drop',
		'@self': { id: 'drop' },
		title: 'Kept',
		choice: { label: 'Option', value: 'opt-1' },
		noValue: { label: 'Only label' },
		custom: 'custom',
		emptyCustom: 'custom',
		zero: 0,
	},
	customValues: { custom: 'Typed' },
	sourceObject: { '@self': { id: 'src' } },
	selectedTargetObject: { '@self': { id: 'tgt' } },
	fileAction: 'transfer',
	relationAction: 'transfer',
}

describe('MergeObject performMerge', () => {
	it('does nothing when there is nothing to merge', async () => {
		stores.objectStore.mergeObjects = vi.fn()
		const vm = viewModel({ ...STATE, mergedData: {} })
		await vm.performMerge()
		expect(stores.objectStore.mergeObjects).not.toHaveBeenCalled()
		expect(vm.step).toBe(2)
	})

	it('sends the merged object and shows the result', async () => {
		stores.objectStore.mergeObjects = vi.fn(async () => ({
			data: { success: true },
		}))
		const vm = viewModel(STATE)
		await vm.performMerge()
		expect(stores.objectStore.mergeObjects).toHaveBeenCalledWith({
			register: 'reg',
			schema: 'sch',
			sourceObjectId: 'src',
			target: 'tgt',
			object: {
				title: 'Kept',
				choice: 'opt-1',
				noValue: { label: 'Only label' },
				custom: 'Typed',
				emptyCustom: '',
				zero: 0,
			},
			fileAction: 'transfer',
			relationAction: 'transfer',
		})
		expect(
			Object.keys(stores.objectStore.mergeObjects.mock.calls[0][0].object),
		).toEqual(['title', 'choice', 'noValue', 'custom', 'emptyCustom', 'zero'])
		expect(vm.mergeResult).toEqual({ success: true })
		expect(vm.step).toBe(3)
		expect(vm.loading).toBe(false)
		expect(log.mock.calls.map((call) => call[0])).toEqual([
			'Raw mergedData before processing:',
			'Extracting value from object for',
			'Final merged data to send:',
		])
		expect(log.mock.calls[1]).toEqual([
			'Extracting value from object for',
			'choice',
			':',
			'opt-1',
		])
	})

	it('shows a failed merge', async () => {
		stores.objectStore.mergeObjects = vi.fn(async () => {
			throw new Error('conflict')
		})
		const vm = viewModel(STATE)
		await vm.performMerge()
		expect(vm.mergeResult).toEqual({ success: false, errors: ['conflict'] })
		expect(vm.step).toBe(3)
		expect(vm.loading).toBe(false)
		expect(error).toHaveBeenCalledTimes(1)

		stores.objectStore.mergeObjects = vi.fn(async () => {
			throw new Error('')
		})
		const other = viewModel(STATE)
		await other.performMerge()
		expect(other.mergeResult).toEqual({
			success: false,
			errors: ['An error occurred during merge'],
		})
	})
})

describe('MergeObject displayValue', () => {
	it('shows a value, truncated', () => {
		const vm = viewModel({ mergedData: {} })
		expect(vm.displayValue(null)).toBe('N/A')
		expect(vm.displayValue(undefined)).toBe('N/A')
		expect(vm.displayValue('')).toBe('')
		expect(vm.displayValue(0)).toBe('0')
		expect(vm.displayValue(false)).toBe('false')
		expect(vm.displayValue({ a: 1 })).toBe('{\n  "a": 1\n}')
		expect(vm.displayValue('x'.repeat(100))).toBe('x'.repeat(100))
		expect(vm.displayValue('x'.repeat(101))).toBe('x'.repeat(100) + '...')
		expect(vm.displayValue('abcdef', 3)).toBe('abc...')
		expect(vm.displayValue([1, 2], 3)).toBe('[\n ...')
	})
})
