// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest: an application is copied with the links the user picks
 * (applications-copy-with-links, task 1). The Modules and Diensten pages
 * declare which links a copy may take along, and FacetedCatalogIndexView hands
 * that `copy` config to its embedded CnIndexPage, whose single and mass copy
 * dialogs then list the links.
 *
 * @spec openspec/changes/applications-copy-with-links/specs/application-copy/spec.md#requirement-an-application-is-copied-with-the-links-the-user-picks-req-acp-001
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import manifest from '../../src/manifest.json'

vi.mock('@conduction/nextcloud-vue', () => ({
	CnFacetSidebar: defineComponent({ name: 'CnFacetSidebar', render: () => h('div') }),
	CnIndexPage: defineComponent({
		name: 'CnIndexPage',
		props: { copy: { type: Object, default: null } },
		render() {
			return h('div', { class: 'index-page-stub' })
		},
	}),
}))
vi.mock('../../src/modals/SaveFacetViewModal.vue', () => ({
	default: defineComponent({ name: 'SaveFacetViewModal', render: () => h('div') }),
}))

import FacetedCatalogIndexView from '../../src/views/FacetedCatalogIndexView.vue'

const LINKS = ['relationRows', 'incoming', 'files']

/**
 * Mount the view with the given extra props.
 *
 * @param {object} props Extra props.
 * @return {object} The wrapper.
 */
function mountView(props = {}) {
	setActivePinia(createPinia())
	return mount(FacetedCatalogIndexView, {
		props: { schema: 'module', register: 'voorzieningen', title: 'Applications', ...props },
		global: {
			mocks: {
				$route: { query: {} },
				$router: { push: vi.fn(), replace: vi.fn(async () => {}) },
				t: (app, text) => text,
			},
			stubs: { NcTextField: true, NcButton: true, NcActions: true, NcActionButton: true, NcActionCaption: true },
			plugins: [(app) => { app.config.globalProperties.t = (a, text) => text }],
		},
	})
}

describe('applications are copied with their links', () => {
	it.each(['Modules', 'Diensten'])('the %s page lets a copy take its connections, incoming links and files', (id) => {
		const page = manifest.pages.find((p) => p.id === id)

		expect(page.component).toBe('FacetedCatalogIndexView')
		expect(page.config.copy).toEqual({ include: LINKS })
	})

	it('hands the page copy config to the embedded index page', () => {
		const wrapper = mountView({ copy: { include: LINKS } })

		expect(wrapper.findComponent({ name: 'CnIndexPage' }).props('copy')).toEqual({ include: LINKS })
	})

	it('copies the fields only when the page declares no links', () => {
		const wrapper = mountView()

		expect(wrapper.findComponent({ name: 'CnIndexPage' }).props('copy')).toBeNull()
	})
})
