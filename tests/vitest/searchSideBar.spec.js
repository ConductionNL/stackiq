// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * SearchSideBar: it refreshes the metadata and catalogi lists on mount and
 * runs one debounced search for any burst of changes to the search term,
 * metadata or catalogi filters. Its stores are module-local placeholders,
 * captured as they are created so a test can fill them like mocked stores.
 *
 * @spec openspec/specs/fe-shell-navigation/spec.md
 */

import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const captured = vi.hoisted(() => ({ on: false, stores: [] }))

vi.mock('vue', async (importOriginal) => {
	const actual = await importOriginal()
	return {
		...actual,
		reactive(value) {
			const proxy = actual.reactive(value)
			if (captured.on) captured.stores.push(proxy)
			return proxy
		},
	}
})
vi.mock('@nextcloud/vue', async (importOriginal) => {
	const actual = await importOriginal()
	const { defineComponent, h } = await import('vue')
	const slotted = (name, tag) => defineComponent({
		name,
		props: { name: { type: String, default: '' }, subname: { type: String, default: '' } },
		setup(props, { slots }) {
			return () => h(tag, { class: name, 'data-name': props.name, 'data-subname': props.subname }, slots.default ? slots.default() : [])
		},
	})
	return {
		...actual,
		NcAppSidebar: slotted('NcAppSidebar', 'aside'),
		NcAppSidebarTab: slotted('NcAppSidebarTab', 'section'),
	}
})

captured.on = true
const { default: SearchSideBar } =
	await import('../../src/sidebars/search/SearchSideBar.vue')
captured.on = false

// Declared in this order in the component: search, metadata, catalogi.
const [searchStore, metadataStore, catalogiStore] = captured.stores

beforeEach(() => {
	vi.useFakeTimers()
	searchStore.getSearchResults = vi.fn()
	metadataStore.refreshMetaDataList = vi.fn()
	catalogiStore.refreshCatalogiList = vi.fn()
	searchStore.search = ''
	searchStore.searchError = ''
	searchStore.catalogi = {}
	searchStore.metadata = {}
	catalogiStore.catalogiList = []
	metadataStore.metaDataList = []
})

afterEach(() => {
	vi.useRealTimers()
})

describe('SearchSideBar', () => {
	it('creates three stores and starts unstarred', () => {
		expect(captured.stores).toHaveLength(3)
		expect(SearchSideBar.data()).toEqual({ starred: false })
	})

	it('refreshes the metadata and catalogi lists on mount', () => {
		SearchSideBar.mounted.call({})

		expect(metadataStore.refreshMetaDataList).toHaveBeenCalledTimes(1)
		expect(catalogiStore.refreshCatalogiList).toHaveBeenCalledTimes(1)
		expect(searchStore.getSearchResults).not.toHaveBeenCalled()
	})

	it('runs one search 500 ms after the last of a burst of changes', () => {
		const ctx = { debouncedSearch: SearchSideBar.methods.debouncedSearch }

		expect(SearchSideBar.watch.search).toBe('debouncedSearch')
		SearchSideBar.watch.metadata.handler.call(ctx)
		vi.advanceTimersByTime(300)
		SearchSideBar.watch.catalogi.handler.call(ctx)
		vi.advanceTimersByTime(499)
		expect(searchStore.getSearchResults).not.toHaveBeenCalled()

		vi.advanceTimersByTime(1)
		expect(searchStore.getSearchResults).toHaveBeenCalledTimes(1)
	})

	it('watches the metadata and catalogi filters deeply', () => {
		expect(SearchSideBar.watch.metadata.deep).toBe(true)
		expect(SearchSideBar.watch.catalogi.deep).toBe(true)
	})

	it('renders a switch per catalogus and publication type, with a fallback title', () => {
		catalogiStore.catalogiList = [{ id: 1, title: 'Cat' }, { id: 2 }]
		metadataStore.metaDataList = [{ id: 7, title: 'Type' }]
		const wrapper = mount(SearchSideBar, { props: { search: '', metadata: {}, catalogi: {} } })

		expect(wrapper.findAll('.NcAppSidebarTab').map((tab) => tab.attributes('data-name')))
			.toEqual(['Zoeken', 'Catalogi', 'Publicatie typen'])
		const text = wrapper.text()
		expect(text).toContain('Cat')
		expect(text).toContain('Geen titel')
		expect(text).toContain('Type')
		expect(metadataStore.refreshMetaDataList).toHaveBeenCalledTimes(1)
	})

	it('shows the search error only when there is one', () => {
		const props = { props: { search: '', metadata: {}, catalogi: {} } }
		expect(mount(SearchSideBar, props).text()).not.toContain('Timeout')

		searchStore.searchError = 'Timeout'
		expect(mount(SearchSideBar, props).text()).toContain('Timeout')
	})
})
