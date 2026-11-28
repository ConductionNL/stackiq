// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The Diagram view of the connections list: it draws the rows it gets, reads
 * the names of applications the rows name by uuid, and a line opens its
 * connection.
 *
 * @spec openspec/changes/archive/2026-11-25-connections-diagram-and-graph-export/specs/connection-diagram-and-export/spec.md#requirement-req-cdx-002-the-connections-list-can-be-shown-as-a-diagram-of-the-filtered-rows
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import ConnectionsDiagram from '../../src/components/connections/ConnectionsDiagram.vue'
import { objectStore } from '../../src/store/store.js'

vi.mock('../../src/store/store.js', () => ({
	objectStore: { fetchCollectionForOptions: vi.fn() },
}))
vi.mock('../../src/utils/objectTypes.js', () => ({ ensureObjectType: vi.fn() }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnGraphCanvas: {
		name: 'CnGraphCanvas',
		props: { nodes: { type: Array, default: () => [] }, edges: { type: Array, default: () => [] } },
		emits: ['edge-select'],
		template: '<div class="canvas"><span v-for="node in nodes" :key="node.id" class="node">{{ node.data.label }}</span></div>',
	},
}))
vi.mock('@nextcloud/vue', () => ({
	NcEmptyContent: { props: ['name'], template: '<p class="empty">{{ name }}</p>' },
	NcLoadingIcon: { template: '<span />' },
}))

const ROWS = [{ id: 'c-1', type: 'api', dataExchangeDirection: 'AtoB', moduleA: 'm-zaak', moduleB: 'm-dms' }]

describe('ConnectionsDiagram', () => {
	it('names the applications, and a line opens its connection', async () => {
		objectStore.fetchCollectionForOptions.mockResolvedValue([
			{ id: 'm-zaak', name: 'Zaaksysteem' },
			{ id: 'm-dms', name: 'DMS' },
		])
		const wrapper = mount(ConnectionsDiagram, { props: { rows: ROWS } })
		await flushPromises()

		expect(objectStore.fetchCollectionForOptions).toHaveBeenCalledWith('module', { _ids: ['m-zaak', 'm-dms'], _limit: 2 })
		expect(wrapper.findAll('.node').map((node) => node.text()).sort()).toEqual(['DMS', 'Zaaksysteem'])

		wrapper.findComponent({ name: 'CnGraphCanvas' }).vm.$emit('edge-select', { edge: { id: 'c-1' } })
		expect(wrapper.emitted('rowClick')[0][0]).toEqual(ROWS[0])
	})

	it('says so when the filters leave nothing to draw', () => {
		const wrapper = mount(ConnectionsDiagram, { props: { rows: [] } })
		expect(wrapper.find('.empty').text()).toBe('No connections to draw')
	})
})
