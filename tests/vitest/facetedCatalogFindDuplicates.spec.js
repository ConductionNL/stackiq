// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Find duplicates on the Applications and Services pages: shown, as a link to
 * OpenRegister's Duplicate candidates page, only when `/api/me` says the user
 * may find duplicates (a Nextcloud admin or a functional administrator).
 *
 * @spec openspec/specs/record-reconciliation/spec.md#requirement-req-rrc-002-the-catalogue-pages-shall-lead-an-administrator-to-openregisters-duplicate-candidates
 */

import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

vi.mock('@conduction/nextcloud-vue', () => ({
	CnFacetSidebar: defineComponent({ name: 'CnFacetSidebar', render: () => h('div') }),
	CnIndexPage: defineComponent({ name: 'CnIndexPage', render: () => h('div') }),
}))
vi.mock('../../src/modals/SaveFacetViewModal.vue', () => ({
	default: defineComponent({ name: 'SaveFacetViewModal', render: () => h('div') }),
}))
vi.mock('vue-material-design-icons/ContentDuplicate.vue', () => ({ default: { render: () => null } }))

const { default: FacetedCatalogIndexView } = await import('../../src/views/FacetedCatalogIndexView.vue')

/**
 * Mount the view with `/api/me` answering `me` (or failing).
 *
 * @param {?object|Error} me The `/api/me` body, or an error to throw.
 * @return {Promise<object>} The wrapper.
 */
async function open(me) {
	vi.stubGlobal('fetch', vi.fn(async (url) => {
		if (String(url).endsWith('/apps/stackiq/api/me')) {
			if (me instanceof Error) {
				throw me
			}
			return { ok: me !== null, json: async () => me }
		}
		return { ok: false, json: async () => ({}) }
	}))
	setActivePinia(createPinia())
	const wrapper = mount(FacetedCatalogIndexView, {
		props: { schema: 'module', register: 'voorzieningen', title: 'Applications' },
		global: {
			mocks: {
				$route: { query: {} },
				$router: { push: vi.fn(), replace: vi.fn(async () => {}) },
				t: (app, text) => text,
			},
			stubs: {
				NcActions: true,
				NcActionButton: true,
				NcActionCaption: true,
				NcButton: {
					props: ['href', 'variant'],
					template: '<a class="nc-button" :href="href"><slot /></a>',
				},
			},
		},
	})
	await flushPromises()
	return wrapper
}

const button = (wrapper) => wrapper.find('[data-testid="find-duplicates"]')

describe('Find duplicates on the faceted catalogue pages', () => {
	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it("links an administrator to OpenRegister's duplicate candidates", async () => {
		const wrapper = await open({ mayFindDuplicates: true })

		expect(fetch).toHaveBeenCalledWith('/index.php/apps/stackiq/api/me', expect.anything())
		expect(button(wrapper).exists()).toBe(true)
		expect(button(wrapper).attributes('href')).toBe('/index.php/apps/openregister/duplicates')
		expect(button(wrapper).text()).toBe('Find duplicates')
	})

	it('hides the action from other users', async () => {
		const wrapper = await open({ mayFindDuplicates: false, isBeheerder: true })

		expect(button(wrapper).exists()).toBe(false)
	})

	it('hides the action when the flag is missing, refused or the lookup fails', async () => {
		expect(button(await open({})).exists()).toBe(false)
		expect(button(await open(null)).exists()).toBe(false)
		expect(button(await open(new Error('offline'))).exists()).toBe(false)
	})
})
