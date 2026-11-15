// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * FeaturesRoadmapTabs: the Features and roadmap page with its Extensions tab.
 * The two library pages are stubbed; what is pinned is the tab bar, the count
 * read from the store list, who sees Install, the `?tab=` query and the old
 * Store route's redirect.
 *
 * @spec openspec/changes/features-roadmap-page/specs/features-roadmap/spec.md#requirement-the-uitbreidingen-tab-lists-the-extensions-from-the-store-req-frm-002
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

const auth = vi.hoisted(() => ({ user: { uid: 'u', isAdmin: false } }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => auth.user }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('@conduction/nextcloud-vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		CnFeaturesAndRoadmapPage: defineComponent({
			name: 'CnFeaturesAndRoadmapPage',
			props: { documentationUrl: { type: String, default: '' } },
			setup(props) {
				return () => h('div', { class: 'roadmap', 'data-docs': props.documentationUrl })
			},
		}),
		CnStorePage: defineComponent({
			name: 'CnStorePage',
			props: {
				app: { type: String, default: '' },
				title: { type: String, default: '' },
				description: { type: String, default: '' },
				canInstall: { type: Boolean, default: null },
			},
			data: () => ({ cards: [], builtIn: [{ slug: 'a' }, { slug: 'b' }] }),
			computed: {
				visibleBuiltIn() {
					return this.cards.length > 0 ? [] : this.builtIn
				},
			},
			render() {
				return h('div', { class: 'store', 'data-install': String(this.canInstall) })
			},
		}),
	}
})

const { default: FeaturesRoadmapTabs, tabFromQuery, storeItemCount } = await import('../../src/views/FeaturesRoadmapTabs.vue')

/**
 * Mount with a fake route and router.
 *
 * @param {object} props The props.
 * @param {object} query The route query.
 * @return {{wrapper: object, router: object}} The mounted page and the router double.
 */
function mountTabs(props = {}, query = {}) {
	const router = { replace: vi.fn(() => Promise.resolve()) }
	const wrapper = mount(FeaturesRoadmapTabs, {
		props: { documentationUrl: 'https://docs', store: { app: 'stackiq', title: 'Store', description: 'Lead' }, ...props },
		global: { mocks: { $route: { query }, $router: router } },
	})
	return { wrapper, router }
}

describe('FeaturesRoadmapTabs', () => {
	it('shows the two tabs with the store item count on Extensions', async () => {
		const { wrapper } = mountTabs()
		await flushPromises()

		const tabs = wrapper.findAll('[role="tab"]')
		expect(tabs.map((tab) => tab.text())).toEqual(['Features and roadmap', 'Extensions 2'])
		expect(wrapper.get('[data-testid="frm-tab-count"]').text()).toBe('2')
		expect(wrapper.get('.roadmap').attributes('data-docs')).toBe('https://docs')
	})

	it('updates the count when the store answers with cards', async () => {
		const { wrapper } = mountTabs()
		await flushPromises()

		wrapper.findComponent({ name: 'CnStorePage' }).vm.cards = [{ slug: 'x' }, { slug: 'y' }, { slug: 'z' }]
		await flushPromises()

		expect(wrapper.get('[data-testid="frm-tab-count"]').text()).toBe('3')
	})

	it('hands the store list the app, title and description the Store page had', () => {
		const { wrapper } = mountTabs()
		const store = wrapper.findComponent({ name: 'CnStorePage' })

		expect(store.props('app')).toBe('stackiq')
		expect(store.props('title')).toBe('Store')
		expect(store.props('description')).toBe('Lead')
	})

	it('hides Install from a user who is not an administrator', () => {
		auth.user = { uid: 'u', isAdmin: false }
		const { wrapper } = mountTabs()

		expect(wrapper.findComponent({ name: 'CnStorePage' }).props('canInstall')).toBe(false)
	})

	it('shows Install to an administrator', () => {
		auth.user = { uid: 'admin', isAdmin: true }
		const { wrapper } = mountTabs()

		expect(wrapper.findComponent({ name: 'CnStorePage' }).props('canInstall')).toBe(true)
		auth.user = { uid: 'u', isAdmin: false }
	})

	it('opens the Extensions tab from ?tab=extensions', () => {
		const { wrapper } = mountTabs({}, { tab: 'extensions' })

		expect(wrapper.get('[data-testid="frm-tab-extensions"]').attributes('aria-selected')).toBe('true')
		expect(wrapper.get('[data-testid="frm-panel-extensions"]').isVisible()).toBe(true)
		expect(wrapper.get('[data-testid="frm-panel-features"]').isVisible()).toBe(false)
	})

	it('keeps a chosen tab in the URL', async () => {
		const { wrapper, router } = mountTabs()

		await wrapper.get('[data-testid="frm-tab-extensions"]').trigger('click')

		expect(router.replace).toHaveBeenCalledWith({ query: { tab: 'extensions' } })
		expect(wrapper.get('[data-testid="frm-tab-extensions"]').attributes('aria-selected')).toBe('true')
	})

	it('redirects the old Store route to the Extensions tab', () => {
		const { router } = mountTabs({ redirectToTab: 'extensions' })

		expect(router.replace).toHaveBeenCalledWith({ name: 'FeaturesRoadmap', query: { tab: 'extensions' } })
	})

	it('falls back to the features tab for an unknown query', () => {
		expect(tabFromQuery('nope')).toBe('features')
		expect(tabFromQuery(undefined)).toBe('features')
		expect(tabFromQuery('extensions')).toBe('extensions')
	})

	it('counts nothing before the store has rendered', () => {
		expect(storeItemCount(null)).toBeNull()
		expect(storeItemCount({ cards: [{}], visibleBuiltIn: [] })).toBe(1)
	})
})
