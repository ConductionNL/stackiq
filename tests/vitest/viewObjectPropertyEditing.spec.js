// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation test for editing a property in the object view: whether
 * it is editable and the warning when not (const, immutable with a value),
 * which properties count as constant or immutable, selecting a row by
 * click, updating a value (themes as ids, dates converted), and dropping a
 * property (schema default, removal, deletion marker, selection cleared).
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/store/store.js', () => ({
	catalogStore: {},
	navigationStore: {},
	objectStore: {},
}))

const { default: ViewObject } =
	await import('../../src/modals/object/ViewObject.vue')

const SCHEMA = {
	free: { type: 'string' },
	fixed: { type: 'string', const: 'C' },
	zeroConst: { type: 'number', const: 0 },
	locked: { type: 'string', immutable: true },
	readOnlyProp: { type: 'string', readOnly: true },
	count: { type: 'number' },
	whole: { type: 'integer' },
	flag: { type: 'boolean' },
	list: { type: 'array' },
	map: { type: 'object' },
	other: { type: 'file' },
	when: { type: 'string', format: 'date' },
	themes: { type: 'array' },
}

/**
 * A stand-in for the component with the given state and every method bound.
 *
 * @param {object} state The component state.
 * @return {object} The stand-in.
 */
function viewModel(state = {}) {
	const vm = {
		formData: {},
		currentObject: null,
		selectedProperty: null,
		objectProperties: [],
		getSchemaProperties: () => SCHEMA,
		...state,
	}
	for (const [name, fn] of Object.entries(ViewObject.methods)) {
		if (!(name in vm)) {
			vm[name] = fn.bind(vm)
		}
	}
	return vm
}

afterEach(() => {
	vi.restoreAllMocks()
	vi.unstubAllEnvs()
})

describe('ViewObject editability', () => {
	it('decides whether a property is editable and why not', () => {
		const vm = viewModel()
		const cases = [
			['unknown', 'x'],
			['free', 'x'],
			['fixed', 'C'],
			['zeroConst', 0],
			['locked', 'set'],
			['locked', ''],
			['locked', null],
			['locked', undefined],
			['locked', 0],
			['readOnlyProp', 'x'],
		]
		expect(
			cases.map(([key, value]) => [
				vm.isPropertyEditable(key, value),
				vm.getEditabilityWarning(key, value),
			]),
		).toEqual([
			[true, null],
			[true, null],
			[
				false,
				"This property is constant and must always be 'C'. Const properties cannot be modified to maintain data integrity.",
			],
			[
				false,
				"This property is constant and must always be '0'. Const properties cannot be modified to maintain data integrity.",
			],
			[
				false,
				"This property is immutable and cannot be changed once it has a value. Current value: 'set'. Immutable properties preserve data consistency.",
			],
			[true, null],
			[true, null],
			[true, null],
			[
				false,
				"This property is immutable and cannot be changed once it has a value. Current value: '0'. Immutable properties preserve data consistency.",
			],
			[true, null],
		])
	})

	it('knows constant and immutable properties, by schema or by name', () => {
		vi.stubEnv('NODE_ENV', 'test')
		const vm = viewModel()
		const keys = [
			'free',
			'fixed',
			'zeroConst',
			'locked',
			'readOnlyProp',
			'unknown',
			'myReadOnlyField',
			'IMMUTABLE_id',
			'constantValue',
		]
		expect(keys.map((key) => vm.isConstantOrImmutable(key))).toEqual([
			false,
			true,
			true,
			true,
			true,
			false,
			true,
			true,
			true,
		])
	})

	it('logs the detection in development only', () => {
		const log = vi.spyOn(console, 'log').mockImplementation(() => {})
		vi.stubEnv('NODE_ENV', 'development')
		viewModel().isConstantOrImmutable('locked')
		viewModel().isConstantOrImmutable('unknown')
		expect(log.mock.calls).toEqual([
			[
				'Property locked detection:',
				{
					isConstant: false,
					isImmutable: true,
					isImmutableByName: false,
					schemaProperty: SCHEMA.locked,
				},
			],
		])
		vi.stubEnv('NODE_ENV', 'production')
		viewModel().isConstantOrImmutable('locked')
		expect(log).toHaveBeenCalledTimes(1)
	})
})

describe('ViewObject handleRowClick', () => {
	const click = (key, state, target = {}) => {
		const vm = viewModel(state)
		vm.selectProperty = vi.fn()
		const event = { target: { tagName: 'TD', closest: () => null, ...target } }
		vm.handleRowClick(key, event)
		return vm.selectProperty.mock.calls
	}

	it('selects an editable row that is not selected yet', () => {
		expect(click('free', {})).toEqual([['free']])
		expect(click('locked', { formData: { locked: '' } })).toEqual([['locked']])
		expect(click('locked', { objectProperties: [['locked', '']] })).toEqual([
			['locked'],
		])
	})

	it('ignores clicks on controls, the selected row and locked rows', () => {
		expect(click('free', {}, { tagName: 'INPUT' })).toEqual([])
		expect(click('free', {}, { tagName: 'BUTTON' })).toEqual([])
		expect(click('free', {}, { closest: () => ({}) })).toEqual([])
		expect(click('free', { selectedProperty: 'free' })).toEqual([])
		expect(click('fixed', {})).toEqual([])
		expect(click('locked', { objectProperties: [['locked', 'set']] })).toEqual(
			[],
		)
		expect(
			click('locked', {
				formData: { locked: 'x' },
				objectProperties: [['locked', '']],
			}),
		).toEqual([])
	})
})

describe('ViewObject updatePropertyValue', () => {
	it('stores theme ids, converted dates and plain values', () => {
		const vm = viewModel({ formData: [1] })
		vm.updatePropertyValue('themes', [{ id: 't1' }, 't2'])
		expect(vm.formData).toEqual({ themes: ['t1', 't2'] })
		vm.updatePropertyValue('themes', 't3')
		expect(vm.formData.themes).toBe('t3')
		vm.updatePropertyValue('when', new Date(2026, 0, 2))
		expect(vm.formData.when).toBe('2026-01-02')
		vm.updatePropertyValue('free', 'text')
		expect(vm.formData.free).toBe('text')
		const empty = viewModel({ formData: null })
		empty.updatePropertyValue('free', 'y')
		expect(empty.formData).toEqual({ free: 'y' })
	})
})

describe('ViewObject dropProperty', () => {
	it('resets schema properties to their default', () => {
		const vm = viewModel({ selectedProperty: 'fixed' })
		for (const key of [
			'free',
			'fixed',
			'count',
			'whole',
			'flag',
			'list',
			'map',
			'other',
		]) {
			vm.dropProperty(key)
		}
		expect(vm.formData).toEqual({
			free: '',
			fixed: 'C',
			count: 0,
			whole: 0,
			flag: false,
			list: [],
			map: {},
			other: '',
		})
		expect(vm.selectedProperty).toBe(null)
	})

	it('removes other properties and marks those of the object as deleted', () => {
		const vm = viewModel({
			formData: { extra: 'edited', loose: 'x' },
			currentObject: { extra: 'orig', kept: 1 },
			selectedProperty: 'kept',
		})
		vm.dropProperty('extra')
		vm.dropProperty('loose')
		vm.dropProperty('missing')
		expect(vm.formData).toEqual({ extra: undefined })
		expect(Object.keys(vm.formData)).toEqual(['extra'])
		expect(vm.selectedProperty).toBe('kept')
	})
})

describe('ViewObject canDropProperty', () => {
	it('offers the drop button for set, mutable, non-metadata properties', () => {
		vi.stubEnv('NODE_ENV', 'test')
		const vm = viewModel({
			formData: { free: 'x', fixed: 'C' },
			currentObject: { count: 1, id: 'i' },
		})
		expect(
			['@self', 'id', 'fixed', 'free', 'count', 'list'].map((key) =>
				vm.canDropProperty(key),
			),
		).toEqual([false, false, false, true, true, false])
		expect(viewModel({ currentObject: null }).canDropProperty('free')).toBe(null)
		expect(viewModel({ currentObject: undefined }).canDropProperty('free')).toBe(
			undefined,
		)
	})
})
