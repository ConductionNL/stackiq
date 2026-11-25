// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * IntegrationLeafTab, mounted: it renders the registered provider's own tab
 * with the sidebar's object props, a mount-mode provider through the mount
 * host, and the generic linked-items tab when no provider is registered.
 *
 * @spec openspec/changes/archive/2026-11-25-adopt-integration-leaves
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const registry = vi.hoisted(() => ({ list: [], tabs: {} }))

vi.mock('@conduction/nextcloud-vue', async () => {
	const { computed, h } = await import('vue')
	const echo = (name) => ({
		name,
		inheritAttrs: false,
		render() {
			return h('div', { class: name, 'data-props': JSON.stringify(this.$attrs) })
		},
	})
	return {
		CnIntegrationTab: echo('generic-tab'),
		CnLeafMountHost: echo('mount-host'),
		useIntegrationRegistry: () => ({
			integrations: computed(() => registry.list),
			resolveTab: (id) => registry.tabs[id] || null,
		}),
	}
})

const { default: IntegrationLeafTab } = await import('../../src/components/integrations/IntegrationLeafTab.vue')

const SIDEBAR = { objectId: 'c-1', objectType: 'object', register: 'voorzieningen', schema: 'catalogContract', apiBase: '/apps/openregister/api' }

/**
 * Mount the tab for a leaf with the sidebar's props.
 *
 * @param {string} integrationId The leaf.
 * @param {object} extra Extra props.
 * @return {object} The wrapper.
 */
function open(integrationId, extra = {}) {
	return mount(IntegrationLeafTab, { props: { integrationId, ...SIDEBAR, ...extra } })
}

const propsOf = (wrapper, cls) => JSON.parse(wrapper.find('.' + cls).attributes('data-props'))

describe('IntegrationLeafTab', () => {
	beforeEach(() => {
		registry.list = []
		registry.tabs = {}
	})

	it("renders the provider's own tab with the object props", async () => {
		const { h } = await import('vue')
		registry.list = [{ id: 'calendar', label: 'Calendar' }, { id: 'deck', label: 'Deck' }]
		registry.tabs.calendar = {
			props: ['objectId', 'objectType', 'register', 'schema', 'apiBase'],
			render() {
				return h('div', { class: 'calendar-tab' }, [this.objectId, '|', this.register, '|', this.schema, '|', this.apiBase, '|', this.objectType])
			},
		}

		const wrapper = open('calendar')

		expect(wrapper.find('.calendar-tab').text()).toBe('c-1|voorzieningen|catalogContract|/apps/openregister/api|object')
		expect(wrapper.find('.generic-tab').exists()).toBe(false)
		expect(wrapper.find('.mount-host').exists()).toBe(false)
	})

	it('renders a mount-mode provider through the mount host with the single-entity context', () => {
		const provider = { id: 'bookmarks', renderMode: 'mount' }
		registry.list = [provider]

		const wrapper = open('bookmarks')

		const props = propsOf(wrapper, 'mount-host')
		expect(props.provider).toEqual(provider)
		expect(props.active).toBe(true)
		expect(props.mountProps).toEqual({
			...SIDEBAR,
			surface: 'single-entity',
			integrationContext: { register: 'voorzieningen', schema: 'catalogContract', objectId: 'c-1' },
		})
	})

	it('falls back to the generic linked-items tab when the leaf is not registered', () => {
		registry.list = [{ id: 'deck' }]

		const wrapper = open('contacts')

		expect(propsOf(wrapper, 'generic-tab')).toEqual({ integrationId: 'contacts', ...SIDEBAR })
	})

	it('falls back to the generic tab when the provider has no tab component', () => {
		registry.list = [{ id: 'contacts' }]

		const wrapper = open('contacts')

		expect(wrapper.find('.generic-tab').exists()).toBe(true)
	})

	it('passes the slug when the sidebar hands over a schema object', () => {
		const wrapper = open('contacts', { schema: { slug: 'contactPerson', title: 'Contact person' } })

		expect(propsOf(wrapper, 'generic-tab').schema).toBe('contactPerson')
	})
})
