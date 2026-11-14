// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The merge dialog, mounted: picking a target from the rendered list by
 * mouse or keyboard, the configuration step's comparison table, value
 * pickers, custom value field, file and relation options and their toggles,
 * and the report step's summary, statistics, changes, file actions,
 * warnings and errors, with the buttons that move between them. fetch and
 * the stores are doubled; the Nextcloud controls are stand-ins that render
 * their slots and emit the value a test picks.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const stores = vi.hoisted(() => ({
	catalogStore: { catalogiItem: null, schemaItem: null },
	navigationStore: { setModal: null },
	objectStore: { objectItem: null, setObjectItem: null, mergeObjects: null },
}))

vi.mock('../../src/store/store.js', () => stores)
// The shared @nextcloud/vue stub has no NcSelect, so the module is replaced here.
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent } = await import('vue')
	const control = (name) => defineComponent({ name, render: () => null })
	return Object.fromEntries(['NcButton', 'NcCheckboxRadioSwitch', 'NcDialog', 'NcEmptyContent', 'NcLoadingIcon', 'NcNoteCard', 'NcSelect', 'NcTextField'].map((name) => [name, control(name)]))
})

const { default: MergeObject } = await import('../../src/modals/object/MergeObject.vue')

/** A control stand-in that renders its slots and emits the value a test picks. */
function Control (name) {
  return {
	name,
	inheritAttrs: false,
	props: ['modelValue', 'options', 'label', 'value', 'disabled', 'name'],
	emits: ['update:modelValue', 'click'],
	template: `<div :class="'${name}'" :data-disabled="disabled ? 'true' : null" @click="$emit('click')"><slot name="icon" /><slot name="description" /><slot /></div>`,
}
}

const stubs = {
	NcDialog: { template: '<div class="dialog"><slot /><div class="actions"><slot name="actions" /></div></div>' },
	NcButton: Control('NcButton'),
	NcSelect: Control('NcSelect'),
	NcTextField: Control('NcTextField'),
	NcCheckboxRadioSwitch: Control('NcCheckboxRadioSwitch'),
	NcEmptyContent: Control('NcEmptyContent'),
	NcNoteCard: Control('NcNoteCard'),
	NcLoadingIcon: Control('NcLoadingIcon'),
}

const source = { '@self': { id: 'src', name: 'Bron' }, title: 'Oud', city: '', attachments: [{ name: 'brief.pdf', size: 2048 }], _meta: 1 }
const target = { '@self': { id: 'tgt', name: 'Doel' }, id: 't', title: 'Nieuw', city: '' }

/**
 * Mount the dialog over the given search results and let it load.
 *
 * @param {Array<object>} results The objects the search returns.
 * @return {Promise<object>} The wrapper.
 */
async function mountIt(results = [source, target]) {
	fetch.mockResolvedValue({ json: async () => ({ results }) })
	const wrapper = mount(MergeObject, { global: { stubs, mocks: { t: (app, text, vars = {}) => text.replace('{property}', vars.property) } } })
	await flushPromises()
	return wrapper
}

/**
 * The action button with the given text.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} text Its text.
 * @return {object|undefined} The button.
 */
function action(wrapper, text) {
	return wrapper.findAllComponents({ name: 'NcButton' }).find((b) => b.text().includes(text))
}

/**
 * Mount, pick the target by keyboard and go to the configuration step.
 *
 * @return {Promise<object>} The wrapper.
 */
async function configure() {
	const wrapper = await mountIt()
	await wrapper.find('[role="option"]').trigger('keydown', { key: 'Enter' })
	await action(wrapper, 'Next').trigger('click')
	return wrapper
}

beforeEach(() => {
	stores.catalogStore.catalogiItem = { id: 'reg', title: 'Register A' }
	stores.catalogStore.schemaItem = { id: 'sch', title: 'Schema B' }
	stores.objectStore.objectItem = source
	stores.objectStore.setObjectItem = vi.fn()
	stores.objectStore.mergeObjects = vi.fn()
	stores.navigationStore.setModal = vi.fn()
	vi.stubGlobal('fetch', vi.fn())
	vi.spyOn(console, 'log').mockImplementation(() => {})
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	vi.unstubAllGlobals()
	vi.restoreAllMocks()
})

describe('MergeObject, rendered', () => {
	it('lists the other objects as options and marks the one picked by mouse or keyboard', async () => {
		const other = { '@self': { id: 'o2', title: 'Derde' } }
		const wrapper = await mountIt([source, target, other])
		expect(wrapper.find('.detail-grid').text()).toContain('Register A')
		expect(wrapper.find('.detail-grid').text()).toContain('Schema B')
		expect(wrapper.find('.step-1').text()).toContain('Bron')
		const options = wrapper.findAll('[role="option"]')
		expect(options.map((o) => o.find('strong').text())).toEqual(['Doel', 'Derde'])
		expect(action(wrapper, 'Next').attributes('data-disabled')).toBe('true')

		await options[1].trigger('click')
		expect(options[1].attributes('aria-selected')).toBe('true')
		await options[0].trigger('keydown', { key: ' ' })
		expect([options[0].attributes('aria-selected'), options[1].attributes('aria-selected')]).toEqual(['true', 'false'])
		expect(action(wrapper, 'Next').attributes('data-disabled')).toBeUndefined()
	})

	it('searches again with the typed term, and says when nothing matches', async () => {
		const wrapper = await mountIt([source])
		expect(wrapper.findComponent({ name: 'NcEmptyContent' }).text()).toBe('No objects available for merging')
		await wrapper.findComponent({ name: 'NcTextField' }).vm.$emit('update:modelValue', 'zzz')
		await flushPromises()
		expect(fetch).toHaveBeenLastCalledWith('/index.php/apps/openregister/api/objects/reg/sch?_search=zzz')
		expect(wrapper.findComponent({ name: 'NcEmptyContent' }).text()).toBe('No objects match your search criteria')
	})

	it('compares every property, fixes the id to the target, and offers a custom value', async () => {
		const wrapper = await configure()
		const rows = wrapper.findAll('.merge-table tbody tr')
		expect(rows.map((r) => r.find('.property-name').text())).toEqual(['title', 'city', 'attachments', 'id'])
		expect(rows[3].find('.fixed-value').text()).toBe('t (Target ID)')
		expect(wrapper.findAllComponents({ name: 'NcTextField' }).map((f) => f.props('label'))).toEqual(['Custom value for city'])

		const titlePicker = wrapper.findAllComponents({ name: 'NcSelect' })[0]
		expect(titlePicker.props('options').map((o) => o.label)).toEqual(['From Source: Oud', 'From Target: Nieuw', 'Other/Custom'])
		await titlePicker.vm.$emit('update:modelValue', { label: 'Other/Custom', value: 'custom' })
		const custom = wrapper.findAllComponents({ name: 'NcTextField' })[0]
		expect(custom.props('label')).toBe('Custom value for title')
		await custom.vm.$emit('update:modelValue', 'Samengevoegd')
		expect(wrapper.vm.customValues.title).toBe('Samengevoegd')
		await titlePicker.vm.$emit('update:modelValue', { label: 'From Source: Oud', value: 'Oud' })
		expect(wrapper.findAllComponents({ name: 'NcTextField' }).map((f) => f.props('label'))).toEqual(['Custom value for city'])
	})

	it('lists the source files, hides them and the relations on their toggles, and takes the chosen actions', async () => {
		const wrapper = await configure()
		expect(wrapper.find('.file-table tbody td').text()).toBe('brief.pdf')
		expect(wrapper.findAll('.file-table tbody td').map((td) => td.text())).toEqual(['brief.pdf', '2 KB', 'PDF'])
		expect(wrapper.find('.no-relations').exists()).toBe(true)

		const radios = wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
		await radios[1].vm.$emit('update:modelValue', 'delete')
		await radios[3].vm.$emit('update:modelValue', 'drop')
		expect([wrapper.vm.fileAction, wrapper.vm.relationAction]).toEqual(['delete', 'drop'])

		await action(wrapper, 'Hide Files').trigger('click')
		await action(wrapper, 'Hide Relations').trigger('click')
		expect(wrapper.find('.file-list').exists()).toBe(false)
		expect(wrapper.find('.no-relations').exists()).toBe(false)
		expect(action(wrapper, 'View Files').exists()).toBe(true)
		expect(action(wrapper, 'View Relations').exists()).toBe(true)

		await action(wrapper, 'Back').trigger('click')
		expect(wrapper.find('.step-1').exists()).toBe(true)
	})

	it('reports a successful merge in full and opens the merged object', async () => {
		stores.objectStore.mergeObjects.mockResolvedValue({
			data: {
				success: true,
				statistics: { propertiesChanged: 2, filesTransferred: 1 },
				actions: {
					properties: [{ property: 'title', oldValue: 'Nieuw', newValue: 'Oud' }],
					files: [{ name: 'brief.pdf', action: 'transferred', success: true }, { name: 'foto.png', action: 'transfer', success: false, error: 'Locked' }],
				},
				warnings: ['Check the address'],
			},
		})
		const wrapper = await configure()
		await action(wrapper, 'Merge Objects').trigger('click')
		await flushPromises()

		expect(stores.objectStore.mergeObjects).toHaveBeenCalledWith(expect.objectContaining({ sourceObjectId: 'src', target: 'tgt', fileAction: 'transfer' }))
		const report = wrapper.find('.merge-report')
		expect(wrapper.findComponent({ name: 'NcNoteCard' }).text()).toBe('Objects successfully merged!')
		expect(report.findAll('.object-title').map((s) => s.text())).toEqual(['Doel', 'Bron'])
		expect(report.text()).toContain('Properties changed: 2')
		expect(report.text()).toContain('Files transferred: 1')
		expect(report.findAll('.report-table tbody td').map((td) => td.text())).toEqual(['title', 'Nieuw', 'Oud'])
		expect(report.find('.error-text').text()).toBe('(Failed: Locked)')
		expect(report.find('.warning-text').text()).toBe('Check the address')
		expect(action(wrapper, 'Close').exists()).toBe(true)

		await action(wrapper, 'View Object').trigger('click')
		expect(stores.objectStore.setObjectItem).toHaveBeenCalledWith(target)
		expect(stores.navigationStore.setModal).toHaveBeenCalledWith('viewObject')
	})

	it('reports a failed merge with its error and closes', async () => {
		stores.objectStore.mergeObjects.mockRejectedValue(new Error('Schema mismatch'))
		const wrapper = await configure()
		await action(wrapper, 'Merge Objects').trigger('click')
		await flushPromises()

		expect(wrapper.findComponent({ name: 'NcNoteCard' }).text()).toBe('Merge failed. Please check the details below.')
		expect(wrapper.find('.merge-report .error-text').text()).toBe('Schema mismatch')
		expect(action(wrapper, 'View Object')).toBeUndefined()
		await action(wrapper, 'Close').trigger('click')
		expect(stores.navigationStore.setModal).toHaveBeenCalledWith(false)
	})
})
