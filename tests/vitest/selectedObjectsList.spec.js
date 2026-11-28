// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the selected-objects list, mounted: which objects it shows
 * (props or the object store), the name, schema and error each row reads,
 * the empty state, and the store write a remove button makes.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const store = vi.hoisted(() => ({
	objectStore: {
		selectedObjects: [],
		errors: {},
		setSelectedObjects: null,
		getObjectError(id) {
			return this.errors[id] ?? null
		},
	},
}))

vi.mock('../../src/store/store.js', () => ({ objectStore: store.objectStore }))

const { default: SelectedObjectsList } =
	await import('../../src/components/SelectedObjectsList.vue')

/**
 * An empty-content stub that renders its name and its description slot.
 */
const NcEmptyContentStub = defineComponent({
	name: 'NcEmptyContent',
	props: { name: { type: String, default: '' } },
	setup(props, { slots }) {
		return () => h('div', { class: 'empty-content-stub' }, [
			h('span', { class: 'empty-name' }, props.name),
			h('span', { class: 'empty-description' }, slots.description ? slots.description() : []),
		])
	},
})

/**
 * Mount the list with the given props.
 *
 * @param {object} props The props.
 * @return {object} The wrapper.
 */
function mountWith(props = {}) {
	return mount(SelectedObjectsList, {
		props,
		global: { stubs: { TransitionGroup: false, NcEmptyContent: NcEmptyContentStub } },
	})
}

describe('SelectedObjectsList', () => {
	beforeEach(() => {
		store.objectStore.selectedObjects = []
		store.objectStore.errors = {}
		store.objectStore.setSelectedObjects = vi.fn()
	})

	it('shows the empty state with the given texts when nothing is selected', () => {
		const wrapper = mountWith({ title: 'Picked', emptyTitle: 'None picked', emptyDescription: 'Nothing here.' })
		expect(wrapper.find('h4').text()).toBe('Picked (0)')
		expect(wrapper.find('.selected-objects-list').exists()).toBe(false)
		expect(wrapper.find('.empty-name').text()).toBe('None picked')
		expect(wrapper.find('.empty-description').text()).toBe('Nothing here.')
	})

	it('lists the store selection when no objects prop is given', () => {
		store.objectStore.selectedObjects = [
			{ id: 'a', '@self': { name: 'Self name', schema: { title: 'Schema title' } } },
			{ id: 'b', name: 'Plain name', schema: 'plain-schema' },
		]
		const wrapper = mountWith()
		expect(wrapper.find('h4').text()).toBe('Selected Publications (2)')
		const rows = wrapper.findAll('.selected-object-item')
		expect(rows.map((r) => r.find('strong').text())).toEqual(['Self name', 'Plain name'])
		expect(rows.map((r) => r.find('.object-schema').text())).toEqual(['Schema title', 'plain-schema'])
	})

	it('prefers the objects prop over the store selection', () => {
		store.objectStore.selectedObjects = [{ id: 'store', name: 'From store' }]
		const wrapper = mountWith({ objects: [{ id: 'prop', title: 'From prop' }] })
		expect(wrapper.findAll('strong').map((s) => s.text())).toEqual(['From prop'])
	})

	it('resolves names and schemas through every fallback', () => {
		const wrapper = mountWith({ title: 'Selected Objects' })
		const vm = wrapper.vm
		expect(vm.getObjectName({ '@self': { title: 'Self title' } })).toBe('Self title')
		expect(vm.getObjectName({})).toBe('Unnamed Object')
		expect(mountWith().vm.getObjectName({})).toBe('Unnamed Publication')
		expect(vm.getObjectSchema({ schema: { name: 'N', title: 'T' } })).toBe('N')
		expect(vm.getObjectSchema({ schema: { id: 7 } })).toBe(7)
		expect(vm.getObjectSchema({ schema: {} })).toBe('Unknown Schema')
		expect(vm.getObjectSchema({ '@self': { schema: 'self-schema' }, schema: 'outer' })).toBe('self-schema')
		expect(vm.getObjectSchema({})).toBe('No Schema')
	})

	it('marks a row with the store error for its object', () => {
		store.objectStore.errors = { b: 'Could not publish' }
		const wrapper = mountWith({ objects: [{ id: 'a', name: 'A' }, { '@self': { id: 'b' }, id: 'b', name: 'B' }] })
		const rows = wrapper.findAll('.selected-object-item')
		expect(rows[0].classes()).not.toContain('has-error')
		expect(rows[0].find('.object-error').exists()).toBe(false)
		expect(rows[1].classes()).toContain('has-error')
		expect(rows[1].find('.object-error').text()).toBe('Could not publish')
	})

	it('removes the clicked object from the store selection', async () => {
		const a = { id: 'a', name: 'A' }
		const b = { '@self': { id: 'b', name: 'B' } }
		store.objectStore.selectedObjects = [a, b]
		const wrapper = mountWith()
		const remove = wrapper.findAll('button')
		expect(remove.map((x) => x.attributes('aria-label'))).toEqual(['Remove A', 'Remove B'])
		await remove[1].trigger('click')
		expect(store.objectStore.setSelectedObjects).toHaveBeenCalledTimes(1)
		expect(store.objectStore.setSelectedObjects).toHaveBeenCalledWith([a])
		expect(store.objectStore.selectedObjects).toEqual([a, b])
	})

	it('leaves the store alone for an id it does not hold, and hides remove buttons on request', () => {
		store.objectStore.selectedObjects = [{ id: 'a', name: 'A' }]
		const wrapper = mountWith()
		wrapper.vm.removeObject('missing')
		expect(store.objectStore.setSelectedObjects).not.toHaveBeenCalled()
		expect(mountWith({ showRemove: false }).findAll('button')).toHaveLength(0)
	})
})
