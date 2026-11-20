// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The suggested connections panel on the usage detail page: what it lists,
 * the empty state, the refusal for another organisation, and what Accept,
 * Dismiss and Accept all post and take out of the panel.
 *
 * @spec openspec/changes/connections-derived-dependencies/specs/derived-connections/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path, params = {}) => '/index.php' + path.replace(/\{(\w+)\}/g, (m, k) => params[k] ?? m) }))
const toasts = vi.hoisted(() => [])
vi.mock('@nextcloud/dialogs', () => ({
	showError: (m) => toasts.push(['error', m]),
	showSuccess: (m) => toasts.push(['success', m]),
}))
vi.mock('@conduction/nextcloud-vue', async () => {
	const { h } = await import('vue')
	return {
		CnIcon: { render: () => h('i') },
		CnWidgetWrapper: {
			props: ['title'],
			render() {
				return h('section', [h('h3', this.title), this.$slots.default?.()])
			},
		},
	}
})

const { default: ConnectionSuggestionsPanel } = await import('../../src/components/connections/ConnectionSuggestionsPanel.vue')

const BASE = '/index.php/apps/stackiq/api/usages/u-1/connection-suggestions'

const shared = { id: 'c-1', connection: 'c-1', name: 'Zaken to DMS', type: 'API', direction: 'two-way', reason: 'shared-landscape', draft: false }
const carried = { id: 'c-2', connection: 'c-2', name: 'Old link', type: null, direction: 'one-way', reason: 'carry-over', draft: false }
const draft = { id: 'draft:c-3', connection: null, name: 'Replaced link', type: 'File', direction: null, reason: 'carry-over', draft: true }

/**
 * Mount the panel over a listing reply.
 *
 * @param {Array} results The suggestions the server returns.
 * @return {Promise<object>} The mounted wrapper, loaded.
 */
async function mountPanel(results) {
	http.get.mockResolvedValue({ data: { results } })
	const wrapper = mount(ConnectionSuggestionsPanel, { props: { objectId: 'u-1' } })
	await flushPromises()
	return wrapper
}

/**
 * The ids of the listed suggestions.
 *
 * @param {object} wrapper The mounted panel.
 * @return {string[]} The data-suggestion attributes.
 */
const listed = (wrapper) => wrapper.findAll('[data-suggestion]').map((li) => li.attributes('data-suggestion'))

/**
 * The button with a given label.
 *
 * @param {object} wrapper The mounted panel or an item.
 * @param {string} label The visible text.
 * @return {object} The button wrapper.
 */
const button = (wrapper, label) => wrapper.findAll('button').find((b) => b.text() === label)

beforeEach(() => {
	http.get.mockReset()
	http.post.mockReset()
	toasts.length = 0
})

describe('ConnectionSuggestionsPanel', () => {
	it('reads the suggestions of its usage and lists each with its reason', async () => {
		const wrapper = await mountPanel([shared, carried, draft])

		expect(http.get).toHaveBeenCalledWith(BASE)
		expect(listed(wrapper)).toEqual(['c-1', 'c-2', 'draft:c-3'])
		const items = wrapper.findAll('[data-suggestion]')
		expect(items[0].text()).toContain('Zaken to DMS')
		expect(items[0].text()).toContain('API · two-way')
		expect(items[0].text()).toContain('Both applications are in your landscape')
		expect(items[1].find('.connection-suggestions__meta').text()).toBe('one-way')
		expect(items[1].text()).toContain('Carried over from another version')
		expect(items[2].text()).toContain('Draft from the application this one replaces')
		expect(button(wrapper, 'Accept all')).toBeTruthy()
	})

	it('shows the empty state and no Accept all without suggestions', async () => {
		const wrapper = await mountPanel([])

		expect(wrapper.text()).toContain('No suggestions. Every known connection of this application is already in your usage.')
		expect(wrapper.findAll('button')).toHaveLength(0)
	})

	it('offers no Accept all for a single suggestion', async () => {
		const wrapper = await mountPanel([shared])

		expect(button(wrapper, 'Accept all')).toBeUndefined()
		expect(button(wrapper, 'Accept')).toBeTruthy()
	})

	it('explains a refusal for another organisation and lists nothing', async () => {
		http.get.mockRejectedValue({ response: { status: 403 } })
		const wrapper = mount(ConnectionSuggestionsPanel, { props: { objectId: 'u-1' } })
		await flushPromises()

		expect(wrapper.text()).toContain('Only the organisation that uses this application can see its suggested connections.')
		expect(listed(wrapper)).toEqual([])
	})

	it('reports any other load failure', async () => {
		http.get.mockRejectedValue(new Error('down'))
		const wrapper = mount(ConnectionSuggestionsPanel, { props: { objectId: 'u-1' } })
		await flushPromises()

		expect(wrapper.text()).toContain('The suggested connections could not be loaded.')
	})

	it('accepts one suggestion, takes it out of the panel and announces the addition', async () => {
		const wrapper = await mountPanel([shared, carried])
		http.post.mockResolvedValue({ data: { accepted: ['c-1'] } })

		await button(wrapper.findAll('[data-suggestion]')[0], 'Accept').trigger('click')
		await flushPromises()

		expect(http.post).toHaveBeenCalledWith(BASE + '/accept', { ids: ['c-1'] })
		expect(listed(wrapper)).toEqual(['c-2'])
		expect(toasts).toEqual([['success', 'Connections added: 1']])
		expect(wrapper.emitted('accepted')).toEqual([[['c-1']]])
	})

	it('dismisses one suggestion without announcing an addition', async () => {
		const wrapper = await mountPanel([shared, carried])
		http.post.mockResolvedValue({ data: { dismissed: ['c-2'] } })

		await button(wrapper.findAll('[data-suggestion]')[1], 'Dismiss').trigger('click')
		await flushPromises()

		expect(http.post).toHaveBeenCalledWith(BASE + '/dismiss', { ids: ['c-2'] })
		expect(listed(wrapper)).toEqual(['c-1'])
		expect(toasts).toEqual([])
		expect(wrapper.emitted('accepted')).toBeUndefined()
	})

	it('accepts all suggestions at once', async () => {
		const wrapper = await mountPanel([shared, carried, draft])
		http.post.mockResolvedValue({ data: { accepted: ['c-1', 'c-2', 'new-uuid'] } })

		await button(wrapper, 'Accept all').trigger('click')
		await flushPromises()

		expect(http.post).toHaveBeenCalledWith(BASE + '/accept', { ids: ['c-1', 'c-2', 'draft:c-3'] })
		expect(listed(wrapper)).toEqual([])
		expect(toasts).toEqual([['success', 'Connections added: 3']])
	})

	it('keeps the suggestion and shows the refusal when a change is refused', async () => {
		const wrapper = await mountPanel([shared])
		http.post.mockRejectedValue({ response: { status: 403 } })

		await button(wrapper, 'Accept').trigger('click')
		await flushPromises()

		expect(listed(wrapper)).toEqual(['c-1'])
		expect(toasts).toEqual([['error', 'Only the organisation that uses this application can change its connections.']])
		expect(wrapper.emitted('accepted')).toBeUndefined()
	})

	it('asks nothing without a usage id', async () => {
		const wrapper = mount(ConnectionSuggestionsPanel, { props: { objectId: '' } })
		await flushPromises()

		expect(http.get).not.toHaveBeenCalled()
		expect(listed(wrapper)).toEqual([])
	})
})
