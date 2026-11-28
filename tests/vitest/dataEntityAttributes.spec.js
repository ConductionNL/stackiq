// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The attributes of a data entity (architecture-data-model-and-ggm, task 4): key
 * attributes first and marked "key" in text, editable on a drawn entity; an
 * imported GGM entity shows no edit control and the notice about the GGM file.
 *
 * @spec openspec/changes/archive/2026-11-25-architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-004-a-municipality-shall-add-its-own-data-entities-with-attributes-keys-and-cardinalities
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const BASE = '/index.php/apps/openregister/api/objects/7/'
const db = { element: [] }
const calls = []
let failPut = false

const http = {
	async get(url) {
		calls.push(['GET', url])
		const id = decodeURIComponent(url.slice(BASE.length).split('/')[1])
		const found = db.element.find((o) => o['@self'].id === id)
		if (!found) throw Object.assign(new Error('404'), { response: { status: 404 } })
		return { data: JSON.parse(JSON.stringify(found)) }
	},
	async put(url, body) {
		calls.push(['PUT', url, body])
		if (failPut) throw new Error('500')
		const id = decodeURIComponent(url.slice(BASE.length).split('/')[1])
		const saved = { ...JSON.parse(JSON.stringify(body)), '@self': { id } }
		db.element = db.element.map((o) => (o['@self'].id === id ? saved : o))
		return { data: saved }
	},
}

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/l10n', () => {
	const fill = (text, vars) => String(text).replace(/\{(\w+)\}/g, (m, k) => (vars && k in vars ? String(vars[k]) : m))
	return { translate: (app, text, vars) => fill(text, vars) }
})

/**
 * A stub that renders its default slot inside a tag and emits its model updates.
 *
 * @param {string} name The component name.
 * @param {string} tag The element.
 * @return {object}
 */
function passthrough(name, tag = 'div') {
	return defineComponent({
		name,
		inheritAttrs: false,
		props: ['name', 'type', 'variant', 'disabled', 'label', 'inputLabel', 'modelValue', 'options', 'reduce'],
		emits: ['click', 'update:modelValue'],
		setup(props, { slots, emit, attrs }) {
			return () => h(tag, {
				...attrs,
				'data-stub': name,
				'data-label': props.inputLabel ?? props.label ?? '',
				type: tag === 'button' ? (props.type ?? 'button') : undefined,
				disabled: props.disabled,
				onClick: () => emit('click'),
			}, [props.name ? h('span', props.name) : null, slots.default?.()])
		},
	})
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: passthrough('NcButton', 'button'),
	NcCheckboxRadioSwitch: passthrough('NcCheckboxRadioSwitch'),
	NcLoadingIcon: passthrough('NcLoadingIcon'),
	NcNoteCard: passthrough('NcNoteCard'),
	NcSelect: passthrough('NcSelect'),
	NcTextField: passthrough('NcTextField'),
}))

vi.mock('@conduction/nextcloud-vue', () => ({
	CnIcon: passthrough('CnIcon', 'span'),
	CnWidgetWrapper: defineComponent({
		name: 'CnWidgetWrapper',
		props: ['title'],
		setup(props, { slots }) {
			return () => h('section', [h('h2', props.title), slots.default?.()])
		},
	}),
}))

const { default: DataEntityAttributes } = await import('../../src/views/architecture/DataEntityAttributes.vue')

const melding = {
	'@self': { id: 'uuid-melding' },
	identifier: 'id-seed-el-melding',
	type: 'BusinessObject',
	name: 'Melding openbare ruimte',
	origin: 'drawn',
	properties: [],
	attributes: [
		{ name: 'datum melding', dataType: 'date', isKey: false },
		{ name: 'meldingnummer', dataType: 'text', isKey: true },
		{ name: 'locatie', dataType: 'text', isKey: false },
	],
}
const werknemer = { '@self': { id: 'uuid-werknemer' }, identifier: 'id-ggm-werknemer', type: 'BusinessObject', name: 'Werknemer', origin: 'imported', properties: [] }
const legacy = { '@self': { id: 'uuid-legacy' }, identifier: 'id-ggm-legacy', type: 'BusinessObject', name: 'Aanvraag', properties: [] }

/**
 * Mount the widget for one entity.
 *
 * @param {string} objectId The entity uuid.
 * @return {Promise<object>}
 */
async function open(objectId) {
	const wrapper = mount(DataEntityAttributes, { props: { objectId, register: 7 } })
	await flushPromises()
	return wrapper
}

const button = (wrapper, text) => wrapper.findAll('button').find((b) => b.text() === text)
const shown = (wrapper) => wrapper.findAll('[data-testid="data-entity-attribute"]').map((row) => row.findAll('td').map((td) => td.text()))

beforeEach(() => {
	db.element = [melding, werknemer, legacy].map((o) => JSON.parse(JSON.stringify(o)))
	calls.length = 0
	failPut = false
})

describe('DataEntityAttributes', () => {
	it('lists key attributes first, marked key in text, with an edit control on a drawn entity', async () => {
		const wrapper = await open('uuid-melding')
		expect(shown(wrapper)).toEqual([
			['meldingnummer', 'Text', 'key', ''],
			['datum melding', 'Date', '', ''],
			['locatie', 'Text', '', ''],
		])
		expect(button(wrapper, 'Edit attributes')).toBeTruthy()
		expect(wrapper.find('[data-testid="data-entity-attributes-imported"]').exists()).toBe(false)
	})

	it.each([['uuid-werknemer'], ['uuid-legacy']])('shows no edit control and the GGM notice on imported entity %s', async (id) => {
		const wrapper = await open(id)
		expect(wrapper.find('[data-testid="data-entity-attributes-imported"]').text()).toBe('The GGM does not publish attributes in this file.')
		expect(button(wrapper, 'Edit attributes')).toBeUndefined()
		expect(wrapper.find('form').exists()).toBe(false)
		expect(wrapper.find('[data-stub="NcTextField"]').exists()).toBe(false)
	})

	it('adds a key attribute and saves the list key-first, keeping the other fields', async () => {
		const wrapper = await open('uuid-melding')
		await button(wrapper, 'Edit attributes').trigger('click')

		const rows = wrapper.findAll('[data-testid="data-entity-attribute-row"]')
		expect(rows).toHaveLength(3)

		await button(wrapper, 'Add attribute').trigger('click')
		const added = wrapper.findAll('[data-testid="data-entity-attribute-row"]')[3]
		added.findAllComponents({ name: 'NcTextField' })[0].vm.$emit('update:modelValue', 'zaaknummer')
		added.findComponent({ name: 'NcCheckboxRadioSwitch' }).vm.$emit('update:modelValue', true)
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		const put = calls.find(([verb]) => verb === 'PUT')
		expect(put[1]).toBe(BASE + 'element/uuid-melding')
		expect(put[2]).not.toHaveProperty('@self')
		expect(put[2]).toMatchObject({ identifier: 'id-seed-el-melding', type: 'BusinessObject', origin: 'drawn', name: 'Melding openbare ruimte' })
		expect(put[2].attributes).toEqual([
			{ name: 'meldingnummer', dataType: 'text', isKey: true },
			{ name: 'zaaknummer', dataType: 'text', isKey: true },
			{ name: 'datum melding', dataType: 'date', isKey: false },
			{ name: 'locatie', dataType: 'text', isKey: false },
		])

		expect(shown(wrapper).map((row) => [row[0], row[2]])).toEqual([
			['meldingnummer', 'key'],
			['zaaknummer', 'key'],
			['datum melding', ''],
			['locatie', ''],
		])
		expect(wrapper.find('[data-testid="data-entity-attributes-message"]').text()).toBe('The attributes are saved.')
	})

	it('removes an attribute and drops a row left without a name', async () => {
		const wrapper = await open('uuid-melding')
		await button(wrapper, 'Edit attributes').trigger('click')
		await wrapper.findAll('[data-testid="data-entity-attribute-row"]')[2].findAll('button').find((b) => b.text() === 'Remove').trigger('click')
		await button(wrapper, 'Add attribute').trigger('click')
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(calls.find(([verb]) => verb === 'PUT')[2].attributes.map((a) => a.name)).toEqual(['meldingnummer', 'datum melding'])
	})

	it('keeps the form open and says so when the save fails', async () => {
		failPut = true
		const wrapper = await open('uuid-melding')
		await button(wrapper, 'Edit attributes').trigger('click')
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(wrapper.find('form').exists()).toBe(true)
		expect(wrapper.find('[data-testid="data-entity-attributes-message"]').text()).toBe('The attributes could not be saved.')
	})

	it('cancels without writing', async () => {
		const wrapper = await open('uuid-melding')
		await button(wrapper, 'Edit attributes').trigger('click')
		await button(wrapper, 'Cancel').trigger('click')
		expect(wrapper.find('form').exists()).toBe(false)
		expect(calls.some(([verb]) => verb === 'PUT')).toBe(false)
	})
})
