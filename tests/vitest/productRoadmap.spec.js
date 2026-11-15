// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the product roadmap widget, mounted: the store reads for
 * the module and its versions, the statement or empty text, the events
 * handed to the timeline, the month grouping, the error state and the
 * reload when the module changes.
 *
 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const mocks = vi.hoisted(() => ({
	fetchObject: null,
	fetchCollectionForOptions: null,
	ensureObjectType: null,
}))

vi.mock('../../src/store/store.js', () => ({
	objectStore: {
		fetchObject: (...args) => mocks.fetchObject(...args),
		fetchCollectionForOptions: (...args) => mocks.fetchCollectionForOptions(...args),
	},
}))
vi.mock('../../src/utils/objectTypes.js', () => ({
	ensureObjectType: (...args) => mocks.ensureObjectType(...args),
}))
vi.mock('@conduction/nextcloud-vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		CnIcon: defineComponent({ name: 'CnIcon', setup: () => () => h('i') }),
		CnWidgetWrapper: defineComponent({
			name: 'CnWidgetWrapper',
			props: { title: { type: String, default: '' } },
			setup(props, { slots }) {
				return () => h('section', { 'data-title': props.title }, slots.default ? slots.default() : [])
			},
		}),
		CnTimelineView: defineComponent({
			name: 'CnTimelineView',
			props: { events: { type: Array, default: () => [] }, groupBy: { type: Function, default: null }, emptyLabel: { type: String, default: '' } },
			setup: () => () => h('div', { class: 'timeline-stub' }),
		}),
	}
})

const { default: ProductRoadmap } =
	await import('../../src/components/roadmap/ProductRoadmap.vue')

/**
 * Mount the roadmap for a module and wait for it to load.
 *
 * @param {string} objectId The module id.
 * @return {Promise<object>} The wrapper.
 */
async function mounted(objectId) {
	const wrapper = mount(ProductRoadmap, { props: { objectId } })
	await flushPromises()
	return wrapper
}

describe('ProductRoadmap', () => {
	beforeEach(() => {
		mocks.ensureObjectType = vi.fn()
		mocks.fetchObject = vi.fn(async () => ({ roadmapStatement: 'We ship quarterly.' }))
		mocks.fetchCollectionForOptions = vi.fn(async () => [
			{ id: 'v1', version: '1.0', dateInUse: '2026-01-15', status: 'in use' },
			{ id: 'v2', version: '2.0', dateInDevelopment: '2026-06-01', status: 'in development', shortDescription: 'Next' },
			{ id: 'v3', version: 'undated', status: 'in use' },
		])
	})

	it('reads the module and its versions and hands the dated ones to the timeline', async () => {
		const wrapper = await mounted('mod-1')
		expect(mocks.ensureObjectType.mock.calls).toEqual([['module'], ['moduleVersion']])
		expect(mocks.fetchObject).toHaveBeenCalledWith('module', 'mod-1')
		expect(mocks.fetchCollectionForOptions).toHaveBeenCalledWith('moduleVersion', { module: 'mod-1', _limit: 200 })
		expect(wrapper.find('section').attributes('data-title')).toBe('Roadmap')
		expect(wrapper.find('[data-testid="product-roadmap-statement"]').text()).toBe('We ship quarterly.')
		const timeline = wrapper.findComponent({ name: 'CnTimelineView' })
		expect(timeline.props('events').map((e) => [e.id, e.kind, e.description])).toEqual([
			['v1', 'released', ''],
			['v2', 'planned', 'Next'],
		])
		expect(timeline.props('emptyLabel')).toBe('No versions with a date yet')
		expect(timeline.props('groupBy')({ start: '2026-03-04T12:00:00' }).key).toBe('2026-03')
	})

	it('shows the empty text when the module has no roadmap statement', async () => {
		mocks.fetchObject = vi.fn(async () => null)
		const wrapper = await mounted('mod-1')
		expect(wrapper.find('[data-testid="product-roadmap-statement"]').exists()).toBe(false)
		expect(wrapper.find('.product-roadmap__empty').text()).toBe('The supplier has not published a roadmap for this application')
	})

	it('shows the error note and no events when a read fails', async () => {
		mocks.fetchCollectionForOptions = vi.fn(async () => {
			throw new Error('down')
		})
		const wrapper = await mounted('mod-1')
		expect(wrapper.text()).toContain('The roadmap could not be loaded.')
		expect(wrapper.findComponent({ name: 'CnTimelineView' }).exists()).toBe(false)
		expect(wrapper.vm.events).toEqual([])
		expect(wrapper.vm.loading).toBe(false)
	})

	it('reloads when the module changes', async () => {
		const wrapper = await mounted('mod-1')
		await wrapper.setProps({ objectId: 'mod-2' })
		await flushPromises()
		expect(mocks.fetchObject.mock.calls).toEqual([['module', 'mod-1'], ['module', 'mod-2']])
	})
})
