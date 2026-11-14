// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * How the object view decides, per property, which input to render, how to
 * label it, whether it may be edited or dropped, and what dropping it does.
 * The methods are called with a stand-in component instance whose schema is
 * fixed per test, so each rule is pinned without mounting the whole modal.
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
	title: { type: 'string', title: 'Title', description: 'The display name' },
	sameTitle: { type: 'string', title: 'sameTitle', description: 'Only a description' },
	placeholder: { type: 'string', title: ' New ' },
	blankTitle: { type: 'string', title: '   ' },
	bare: { type: 'string' },
	born: { type: 'string', format: 'date' },
	at: { type: 'string', format: 'time' },
	stamp: { type: 'string', format: 'date-time' },
	mail: { type: 'string', format: 'email' },
	site: { type: 'string', format: 'url' },
	link: { type: 'string', format: 'uri' },
	secret: { type: 'string', format: 'password' },
	notes: { type: 'string', format: 'text' },
	body: { type: 'string', format: 'markdown' },
	count: { type: 'integer', minimum: 1, maximum: 9 },
	price: { type: 'number' },
	active: { type: 'boolean' },
	tags: { type: 'array' },
	meta: { type: 'object' },
	odd: { type: 'null' },
	kind: { type: 'string', const: 'fixed' },
	code: { type: 'string', immutable: true },
	locked: { type: 'string', readOnly: true },
}

/**
 * A stand-in component instance with every ViewObject method bound to it.
 *
 * @param {object} state Instance state to start from.
 * @return {object} The instance.
 */
function instance(state = {}) {
	const ctx = {
		formData: {},
		currentObject: null,
		selectedProperty: null,
		objectProperties: [],
		$nextTick: (callback) => callback?.(),
		$refs: {},
		...state,
	}
	for (const [name, method] of Object.entries(ViewObject.methods)) {
		ctx[name] = method.bind(ctx)
	}
	ctx.getSchemaProperties = () => state.schema ?? SCHEMA
	return ctx
}

afterEach(() => {
	vi.restoreAllMocks()
})

describe('ViewObject input type and component per property', () => {
	it('maps schema type and format to the HTML input type', () => {
		const vm = instance()
		const types = Object.fromEntries(
			['born', 'at', 'stamp', 'mail', 'site', 'link', 'secret', 'bare', 'count', 'price', 'active', 'tags', 'unknown']
				.map((key) => [key, vm.getPropertyInputType(key)]),
		)

		expect(types).toEqual({
			born: 'date',
			at: 'time',
			stamp: 'datetime-local',
			mail: 'email',
			site: 'url',
			link: 'url',
			secret: 'password',
			bare: 'text',
			count: 'number',
			price: 'number',
			active: 'checkbox',
			tags: 'text',
			unknown: 'text',
		})
	})

	it('maps schema type and format to the input component', () => {
		const vm = instance()
		const components = Object.fromEntries(
			['active', 'born', 'stamp', 'at', 'notes', 'body', 'mail', 'count', 'price', 'tags', 'meta', 'unknown']
				.map((key) => [key, vm.getPropertyInputComponent(key)]),
		)

		expect(components).toEqual({
			active: 'NcCheckboxRadioSwitch',
			born: 'NcDateTimePicker',
			stamp: 'NcDateTimePicker',
			at: 'NcDateTimePicker',
			notes: 'NcTextArea',
			body: 'Editor',
			mail: 'NcTextField',
			count: 'NcTextField',
			price: 'NcTextField',
			tags: 'NcTextFieldArray',
			meta: 'NcTextField',
			unknown: 'NcTextField',
		})
	})

	it('gives integers a step of 1, numbers any step, and passes the bounds through', () => {
		const vm = instance()

		expect(vm.getPropertyStep('count')).toBe('1')
		expect(vm.getPropertyStep('price')).toBe('any')
		expect(vm.getPropertyStep('title')).toBeUndefined()
		expect(vm.getPropertyMinimum('count')).toBe(1)
		expect(vm.getPropertyMaximum('count')).toBe(9)
		expect(vm.getPropertyMinimum('unknown')).toBeUndefined()
	})
})

describe('ViewObject property labels', () => {
	it('uses a real schema title, and the key for a blank, placeholder or missing one', () => {
		const vm = instance()

		expect(vm.getPropertyDisplayName('title')).toBe('Title')
		expect(vm.getPropertyDisplayName('placeholder')).toBe('placeholder')
		expect(vm.getPropertyDisplayName('blankTitle')).toBe('blankTitle')
		expect(vm.getPropertyDisplayName('unknown')).toBe('unknown')
	})

	it('names an invalid key Unknown Property and warns', () => {
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		const vm = instance()

		expect(vm.getPropertyDisplayName('')).toBe('Unknown Property')
		expect(vm.getPropertyDisplayName(42)).toBe('Unknown Property')
		expect(warn).toHaveBeenCalledTimes(2)
	})

	it('builds the tooltip from title and description, else the description, else the key', () => {
		const vm = instance()

		expect(vm.getPropertyTooltip('title')).toBe('Title: The display name')
		expect(vm.getPropertyTooltip('sameTitle')).toBe('Only a description')
		expect(vm.getPropertyTooltip('bare')).toBe('Property: bare')
		expect(vm.getPropertyNewMessage('bare')).toBe(
			"Property 'bare' is defined in the schema but doesn't have a value yet. Click to add a value.",
		)
	})
})

describe('ViewObject editability', () => {
	it('refuses a const property, and an immutable one only once it has a value', () => {
		const vm = instance()

		expect(vm.isPropertyEditable('unknown', 'x')).toBe(true)
		expect(vm.isPropertyEditable('kind', undefined)).toBe(false)
		expect(vm.isPropertyEditable('code', 'A-1')).toBe(false)
		for (const empty of [null, undefined, '']) {
			expect(vm.isPropertyEditable('code', empty)).toBe(true)
		}
		expect(vm.isPropertyEditable('title', 'x')).toBe(true)
	})

	it('explains why a property cannot be edited, and says nothing when it can', () => {
		const vm = instance()

		expect(vm.getEditabilityWarning('kind', 'other')).toBe(
			"This property is constant and must always be 'fixed'. Const properties cannot be modified to maintain data integrity.",
		)
		expect(vm.getEditabilityWarning('code', 'A-1')).toBe(
			"This property is immutable and cannot be changed once it has a value. Current value: 'A-1'. Immutable properties preserve data consistency.",
		)
		expect(vm.getEditabilityWarning('code', '')).toBeNull()
		expect(vm.getEditabilityWarning('unknown', 'x')).toBeNull()
	})

	it('treats const, immutable, readOnly and protected names as constant or immutable', () => {
		const vm = instance()

		expect(vm.isConstantOrImmutable('kind')).toBe(true)
		expect(vm.isConstantOrImmutable('code')).toBe(true)
		expect(vm.isConstantOrImmutable('locked')).toBe(true)
		expect(vm.isConstantOrImmutable('title')).toBe(false)
		expect(vm.isConstantOrImmutable('ReadOnlyNote')).toBe(true)
		expect(vm.isConstantOrImmutable('myConstantValue')).toBe(true)
		expect(vm.isConstantOrImmutable('free')).toBe(false)
	})
})

describe('ViewObject row selection', () => {
	/**
	 * A click event on an element.
	 *
	 * @param {string} tagName The element's tag.
	 * @param {boolean} inInput Whether it sits in a value input container.
	 * @return {object} The event.
	 */
	const click = (tagName = 'TD', inInput = false) => ({
		target: { tagName, closest: () => (inInput ? {} : null) },
	})

	it('selects an editable row', () => {
		const vm = instance({ objectProperties: [['title', 'Hello']] })

		vm.handleRowClick('title', click())

		expect(vm.selectedProperty).toBe('title')
	})

	it('ignores clicks on inputs, buttons and value containers', () => {
		const vm = instance()

		vm.handleRowClick('title', click('INPUT'))
		vm.handleRowClick('title', click('BUTTON'))
		vm.handleRowClick('title', click('SPAN', true))

		expect(vm.selectedProperty).toBeNull()
	})

	it('keeps the selection on a second click and refuses a non-editable row', () => {
		const vm = instance({
			selectedProperty: 'title',
			formData: { code: 'A-1' },
		})
		vm.handleRowClick('title', click())
		expect(vm.selectedProperty).toBe('title')

		const other = instance({ objectProperties: [['kind', 'fixed'], ['code', 'A-1']] })
		other.handleRowClick('kind', click())
		other.handleRowClick('code', click())
		expect(other.selectedProperty).toBeNull()
	})

	it('reads the edited value before the original one when deciding', () => {
		const vm = instance({ formData: { code: '' }, objectProperties: [['code', 'A-1']] })

		vm.handleRowClick('code', click())

		expect(vm.selectedProperty).toBe('code')
	})
})

describe('ViewObject dropping a property', () => {
	it('offers the drop only for a set, mutable, non-metadata property', () => {
		const vm = instance({
			formData: { title: 'x' },
			currentObject: { price: 3, kind: 'fixed' },
		})

		expect(vm.canDropProperty('@self')).toBe(false)
		expect(vm.canDropProperty('id')).toBe(false)
		expect(vm.canDropProperty('kind')).toBe(false)
		expect(vm.canDropProperty('title')).toBe(true)
		expect(vm.canDropProperty('price')).toBe(true)
		expect(vm.canDropProperty('bare')).toBe(false)
		expect(instance().canDropProperty('bare')).toBeFalsy()
	})

	it('names a reset for a schema property and a removal for a free one', () => {
		const vm = instance()

		expect(vm.getDropPropertyTooltip('title')).toBe("Reset 'Title' to empty value")
		expect(vm.getDropPropertyTooltip('extra')).toBe("Remove 'extra' property completely")
	})

	it('resets a schema property to the empty value of its type', () => {
		const vm = instance({ schema: { ...SCHEMA, fixedString: { type: 'string', const: 'K' } } })
		for (const key of ['bare', 'fixedString', 'count', 'price', 'active', 'tags', 'meta', 'odd']) {
			vm.dropProperty(key)
		}

		expect(vm.formData).toEqual({
			bare: '',
			fixedString: 'K',
			count: 0,
			price: 0,
			active: false,
			tags: [],
			meta: {},
			odd: '',
		})
	})

	it('removes a free property, marking one the stored object had, and clears its selection', () => {
		const vm = instance({
			formData: { draftOnly: 'x', stored: 'edited' },
			currentObject: { stored: 'original' },
			selectedProperty: 'stored',
		})

		vm.dropProperty('draftOnly')
		vm.dropProperty('stored')

		expect(Object.hasOwn(vm.formData, 'draftOnly')).toBe(false)
		expect(Object.hasOwn(vm.formData, 'stored')).toBe(true)
		expect(vm.formData.stored).toBeUndefined()
		expect(vm.selectedProperty).toBeNull()
	})

	it('keeps another property selected when one is dropped', () => {
		const vm = instance({ formData: { bare: 'x' }, selectedProperty: 'title' })

		vm.dropProperty('bare')

		expect(vm.selectedProperty).toBe('title')
	})
})
