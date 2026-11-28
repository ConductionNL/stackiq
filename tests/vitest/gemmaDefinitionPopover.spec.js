// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * GEMMA definitions in place: the popover shows "<name>, from GEMMA", the
 * definition and the GEMMA Online link (only the name and the link without a
 * definition), and the reference component facet gives each value one.
 *
 * @spec openspec/changes/archive/2026-11-24-gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-gemma-terms-show-their-definition-where-they-appear-req-gpl-003
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m)),
	translatePlural: (app, one, many, count) => (count === 1 ? one : many),
	getLanguage: () => 'en',
	getLocale: () => 'en',
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnFacetSidebar: defineComponent({ name: 'CnFacetSidebar', render: () => h('div') }),
	CnIndexPage: defineComponent({ name: 'CnIndexPage', render: () => h('div') }),
}))
vi.mock('../../src/modals/SaveFacetViewModal.vue', () => ({
	default: defineComponent({ name: 'SaveFacetViewModal', render: () => h('div') }),
}))

const { default: GemmaDefinitionPopover } = await import('../../src/components/gemma/GemmaDefinitionPopover.vue')
const { default: FacetedCatalogIndexView } = await import('../../src/views/FacetedCatalogIndexView.vue')

const stubs = {
	NcPopover: { template: '<div class="popover"><div class="trigger"><slot name="trigger" /></div><div class="content"><slot /></div></div>' },
	NcButton: { props: ['ariaLabel'], template: '<button :aria-label="ariaLabel"><slot name="icon" /></button>' },
	InformationOutline: true,
	OpenInNew: true,
}

describe('GemmaDefinitionPopover', () => {
	it('shows the name from GEMMA, the definition and the GEMMA Online link', () => {
		const wrapper = mount(GemmaDefinitionPopover, {
			props: { name: 'Zaaksysteem', documentation: 'Ondersteunt zaakgericht werken.', gemmaUrl: 'https://www.gemmaonline.nl/zaaksysteem' },
			global: { stubs },
		})

		expect(wrapper.find('[data-testid="gemma-definition-button"]').attributes('aria-label')).toBe('What is Zaaksysteem?')
		expect(wrapper.find('.gemma-definition__name').text()).toBe('Zaaksysteem, from GEMMA')
		expect(wrapper.find('.gemma-definition__text').text()).toBe('Ondersteunt zaakgericht werken.')
		const link = wrapper.find('a.gemma-definition__link')
		expect(link.text()).toBe('More in GEMMA Online')
		expect(link.attributes('href')).toBe('https://www.gemmaonline.nl/zaaksysteem')
		expect(link.attributes('target')).toBe('_blank')
		expect(link.attributes('rel')).toBe('noopener noreferrer')
	})

	it('shows only the name and the link for a component without documentation', () => {
		const wrapper = mount(GemmaDefinitionPopover, {
			props: { name: 'Kennisbank', gemmaUrl: 'https://www.gemmaonline.nl/kennisbank' },
			global: { stubs },
		})

		const body = wrapper.find('[data-testid="gemma-definition"]')
		expect(body.find('.gemma-definition__text').exists()).toBe(false)
		expect(body.find('.gemma-definition__name').text()).toBe('Kennisbank, from GEMMA')
		expect(body.find('a').text()).toBe('More in GEMMA Online')
	})

	it('leaves the link out when the element has no GEMMA Online page', () => {
		const wrapper = mount(GemmaDefinitionPopover, { props: { name: 'Eigen' }, global: { stubs } })
		expect(wrapper.find('a').exists()).toBe(false)
	})
})

describe('the reference component facet', () => {
	/**
	 * Mount the Applications page with these reference component buckets.
	 *
	 * @param {Array<object>} values The buckets.
	 * @return {object} The wrapper.
	 */
	function mountPage(values) {
		setActivePinia(createPinia())
		const wrapper = mount(FacetedCatalogIndexView, {
			props: { schema: 'module', register: 'voorzieningen', title: 'Applications' },
			global: {
				mocks: { $route: { query: {} }, $router: { push: vi.fn(), replace: vi.fn(async () => {}) } },
				stubs: { ...stubs, NcActions: true, NcActionButton: true, NcActionCaption: true },
				plugins: [
					(app) => {
						app.config.globalProperties.t = (a, text) => text
					},
				],
			},
		})
		wrapper.vm.facetStore.module.data = { referenceComponent: values }
		return wrapper
	}

	it('gives each value an information button with its definition', async () => {
		const wrapper = mountPage([
			{ value: 'Zaaksysteem', label: 'Zaaksysteem', count: 4, documentation: 'Zaken', gemmaUrl: 'https://g/zs' },
			{ value: 'Kennisbank', label: 'Kennisbank', count: 1, documentation: '', gemmaUrl: '' },
		])
		await wrapper.vm.$nextTick()

		const items = wrapper.findAll('[data-testid="gemma-definitions"] li')
		expect(items).toHaveLength(2)
		expect(items[0].find('.gemma-definition__name').text()).toBe('Zaaksysteem, from GEMMA')
		expect(items[0].find('.gemma-definition__text').text()).toBe('Zaken')
		expect(items[0].find('a').attributes('href')).toBe('https://g/zs')
		expect(items[1].find('.gemma-definition__text').exists()).toBe(false)
		expect(items[1].find('a').exists()).toBe(false)
	})

	it('shows no definitions section without reference component values', async () => {
		const wrapper = mountPage([])
		await wrapper.vm.$nextTick()
		expect(wrapper.find('[data-testid="gemma-definitions"]').exists()).toBe(false)
	})
})
