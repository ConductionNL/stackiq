// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DirectorySideBar: copying a listing's metadata into the local catalogue,
 * deleting a copied one, syncing the directory and keeping the metadata
 * switches in step with what is already present. The component's stores are
 * module-local placeholders; they are captured as they are created so a test
 * can fill them like any mocked store. Only `fetch` and `OC` are stubbed.
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
const nav = vi.hoisted(() => ({ selected: 'directory', setModal: null }))
vi.mock('../../src/store/store.js', () => ({ navigationStore: nav }))
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
		NcEmptyContent: defineComponent({
			name: 'NcEmptyContent',
			props: { name: { type: String, default: '' } },
			setup(props, { slots }) {
				return () => h('div', { class: 'NcEmptyContent' }, [props.name, slots.action ? slots.action() : null])
			},
		}),
	}
})


captured.on = true
const { default: DirectorySideBar } =
	await import('../../src/sidebars/directory/DirectorySideBar.vue')
captured.on = false

const directoryStore = captured.stores.find((store) => 'listingItem' in store)
const metadataStore = captured.stores.find((store) => !('listingItem' in store))

/**
 * Let pending promise callbacks run.
 *
 * @return {Promise<void>}
 */
function flush() {
	return new Promise((resolve) => setTimeout(resolve, 0))
}

/**
 * A component context: methods bound, data and computed as getters.
 *
 * @return {object} The context.
 */
function context() {
	const ctx = {}
	for (const [name, fn] of Object.entries(DirectorySideBar.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	Object.assign(ctx, DirectorySideBar.data.call(ctx))
	for (const [name, def] of Object.entries(DirectorySideBar.computed)) {
		Object.defineProperty(ctx, name, { get: () => def.call(ctx), configurable: true })
	}
	return ctx
}

beforeEach(() => {
	vi.stubGlobal('OC', { requestToken: 'tok' })
	vi.stubGlobal('fetch', vi.fn())
	metadataStore.refreshMetaDataList = vi.fn()
	metadataStore.metaDataList = [
		{ id: 1, source: 'https://a.test/m/1' },
		{ id: 2, source: 'https://a.test/m/2' },
	]
	directoryStore.listingItem.id = 9
	directoryStore.listingItem.metadata = []
	directoryStore.listingItem.title = ''
	nav.selected = 'directory'
	nav.setModal = vi.fn()
})

afterEach(() => {
	vi.unstubAllGlobals()
	vi.restoreAllMocks()
})

describe('DirectorySideBar', () => {
	it('starts idle and reads the listing from the directory store', () => {
		const ctx = context()
		expect(ctx.checkedMetadata).toEqual({})
		expect(ctx.loading).toBe(false)
		expect(ctx.syncLoading).toBe(false)
		expect(ctx.listingItem).toBe(directoryStore.listingItem)
	})

	it('refreshes the metadata list and sets the switches when created', () => {
		directoryStore.listingItem.metadata = ['https://a.test/m/2', 'https://b.test/x']
		const ctx = context()

		DirectorySideBar.created.call(ctx)

		expect(metadataStore.refreshMetaDataList).toHaveBeenCalledTimes(1)
		expect(ctx.checkedMetadata).toEqual({
			'https://a.test/m/2': true,
			'https://b.test/x': false,
		})
		expect(ctx.loading).toBe(false)
	})

	it('leaves the switches alone when the listing has no metadata array', () => {
		directoryStore.listingItem.metadata = null
		const ctx = context()
		ctx.loading = true

		ctx.checkMetadataSwitches()

		expect(ctx.checkedMetadata).toEqual({})
		expect(ctx.loading).toBe(false)
	})

	it('opens a link in the given target', () => {
		const open = vi.spyOn(window, 'open').mockImplementation(() => null)
		const ctx = context()

		ctx.openLink('https://x.test', '_blank')
		ctx.openLink('https://y.test')

		expect(open).toHaveBeenNthCalledWith(1, 'https://x.test', '_blank')
		expect(open).toHaveBeenNthCalledWith(2, 'https://y.test', '')
	})

	it('finds the local id of a metadata source', () => {
		const ctx = context()
		expect(ctx.getMetadataId('https://a.test/m/2')).toBe(2)
		expect(ctx.getMetadataId('https://nowhere.test')).toBeUndefined()
	})

	it('copies metadata that is not present yet', async () => {
		globalThis.fetch.mockResolvedValueOnce({
			json: () => Promise.resolve({ source: 'https://new.test', title: 'T', properties: {} }),
		})
		const ctx = context()
		ctx.createMetadata = vi.fn()

		ctx.copyMetadata('https://new.test')
		expect(ctx.loading).toBe(true)
		await flush()

		expect(globalThis.fetch).toHaveBeenCalledWith('https://new.test', { method: 'GET' })
		expect(metadataStore.refreshMetaDataList).toHaveBeenCalled()
		expect(ctx.createMetadata).toHaveBeenCalledWith({
			source: 'https://new.test',
			title: 'T',
			properties: {},
		})
		expect(ctx.loading).toBe(false)
	})

	it('does not copy metadata whose source is already present', async () => {
		globalThis.fetch.mockResolvedValueOnce({
			json: () => Promise.resolve({ source: 'https://a.test/m/1' }),
		})
		const ctx = context()
		ctx.createMetadata = vi.fn()

		ctx.copyMetadata('https://a.test/m/1')
		await flush()

		expect(ctx.createMetadata).not.toHaveBeenCalled()
	})

	it('keeps the error when fetching the metadata fails', async () => {
		const err = new Error('offline')
		globalThis.fetch.mockRejectedValueOnce(err)
		const ctx = context()

		ctx.copyMetadata('https://new.test')
		await flush()

		expect(ctx.error).toBe(err)
		expect(ctx.loading).toBe(false)
	})

	it('posts a marked copy without ids or empty properties', async () => {
		globalThis.fetch.mockResolvedValueOnce({})
		const ctx = context()

		ctx.createMetadata({ id: 3, _id: 'x', title: 'Base', properties: {}, source: 's' })
		expect(ctx.loading).toBe(true)
		await flush()

		const [url, init] = globalThis.fetch.mock.calls[0]
		expect(url).toBe('/index.php/apps/opencatalogi/api/metadata')
		expect(init.method).toBe('POST')
		expect(init.headers).toEqual({ requesttoken: 'tok', 'Content-Type': 'application/json' })
		expect(JSON.parse(init.body)).toEqual({ title: 'KOPIE: Base', source: 's' })
		expect(ctx.loading).toBe(false)
	})

	it('keeps non-empty properties on the copy and records a failed post', async () => {
		const err = new Error('no')
		globalThis.fetch.mockRejectedValueOnce(err)
		const ctx = context()

		ctx.createMetadata({ title: 'Base', properties: { a: 1 } })
		await flush()

		expect(JSON.parse(globalThis.fetch.mock.calls[0][1].body))
			.toEqual({ title: 'KOPIE: Base', properties: { a: 1 } })
		expect(ctx.error).toBe(err)
		expect(ctx.loading).toBe(false)
	})

	it('deletes the copy by its local id', async () => {
		globalThis.fetch.mockResolvedValueOnce({})
		const ctx = context()

		ctx.deleteMetadata('https://a.test/m/2')
		expect(ctx.loading).toBe(true)
		await flush()

		expect(globalThis.fetch).toHaveBeenCalledWith('/index.php/apps/opencatalogi/api/metadata/2', {
			method: 'DELETE',
			headers: { requesttoken: 'tok', 'Content-Type': 'application/json' },
		})
		expect(ctx.loading).toBe(false)
	})

	it('records a failed delete', async () => {
		const err = new Error('gone')
		globalThis.fetch.mockRejectedValueOnce(err)
		const ctx = context()

		ctx.deleteMetadata('https://a.test/m/1')
		await flush()

		expect(ctx.error).toBe(err)
		expect(ctx.loading).toBe(false)
	})

	it('syncs the directory of the current listing', async () => {
		globalThis.fetch.mockResolvedValueOnce({})
		const ctx = context()

		ctx.synDirectroy()
		expect(ctx.syncLoading).toBe(true)
		await flush()

		expect(globalThis.fetch).toHaveBeenCalledWith(
			'/index.php/apps/opencatalogi/api/directory/9/sync',
			{ method: 'GET' },
		)
		expect(ctx.syncLoading).toBe(false)
	})

	it('records a failed sync', async () => {
		const err = new Error('sync')
		globalThis.fetch.mockRejectedValueOnce(err)
		const ctx = context()

		ctx.synDirectroy()
		await flush()

		expect(ctx.error).toBe(err)
		expect(ctx.syncLoading).toBe(false)
	})

	it('copies on a switch turned on and deletes on one turned off', () => {
		const ctx = context()
		ctx.copyMetadata = vi.fn()
		ctx.deleteMetadata = vi.fn()
		const handler = DirectorySideBar.watch.checkedMetadata.handler

		handler.call(ctx, { 'https://on.test': true })
		handler.call(ctx, { 'https://off.test': false })
		handler.call(ctx, { '': false })

		expect(ctx.copyMetadata).toHaveBeenCalledWith('https://on.test')
		expect(ctx.deleteMetadata).toHaveBeenCalledTimes(1)
		expect(ctx.deleteMetadata).toHaveBeenCalledWith('https://off.test')
	})

	it('rechecks the switches on a listing change only once metadata is loaded', () => {
		const ctx = context()
		ctx.checkMetadataSwitches = vi.fn()
		const handler = DirectorySideBar.watch.listingItem.handler

		handler.call(ctx, false)
		expect(ctx.checkMetadataSwitches).not.toHaveBeenCalled()

		handler.call(ctx, { id: 1 })
		expect(ctx.loading).toBe(true)
		expect(ctx.checkMetadataSwitches).toHaveBeenCalledTimes(1)

		metadataStore.metaDataList = undefined
		handler.call(ctx, { id: 2 })
		expect(ctx.checkMetadataSwitches).toHaveBeenCalledTimes(1)
	})

	it('shows the empty state with a directory action when nothing is selected', async () => {
		directoryStore.listingItem.id = null
		const open = vi.spyOn(window, 'open').mockImplementation(() => null)
		const wrapper = mount(DirectorySideBar)

		expect(wrapper.find('.NcAppSidebar').attributes('data-name')).toBe('Geen listing')
		expect(wrapper.find('.NcAppSidebarTab').exists()).toBe(false)
		expect(wrapper.find('.NcEmptyContent').text()).toContain('Geen listing')
		const buttons = wrapper.findAll('button')
		await buttons[0].trigger('click')
		await buttons[1].trigger('click')

		expect(nav.setModal).toHaveBeenCalledWith('addDirectory')
		expect(open).toHaveBeenCalledWith(
			'https://conduction.gitbook.io/opencatalogi-nextcloud/beheerders/directory',
			'_blank',
		)
	})

	it('shows the empty state when another view is selected', () => {
		nav.selected = 'catalogi'
		const wrapper = mount(DirectorySideBar)

		expect(wrapper.find('.NcAppSidebarTab').exists()).toBe(false)
		expect(wrapper.text()).toContain('Directory inlezen')
	})

	it('shows the listing tabs with one switch per metadata source and syncs on click', async () => {
		directoryStore.listingItem.title = 'Listing A'
		directoryStore.listingItem.organisation.title = 'Org'
		directoryStore.listingItem.metadata = ['https://a.test/m/1']
		globalThis.fetch.mockResolvedValue({})
		const wrapper = mount(DirectorySideBar)

		const sidebar = wrapper.find('.NcAppSidebar')
		expect(sidebar.attributes('data-name')).toBe('Listing A')
		expect(sidebar.attributes('data-subname')).toBe('Org')
		expect(wrapper.findAll('.NcAppSidebarTab').map((tab) => tab.attributes('data-name')))
			.toEqual(['Details', 'Configuratie', 'Publicatie typen'])
		expect(wrapper.text()).toContain('https://a.test/m/1')
		expect(wrapper.find('.NcEmptyContent').exists()).toBe(false)

		await wrapper.find('.syncButton').trigger('click')
		expect(globalThis.fetch).toHaveBeenCalledWith(
			'/index.php/apps/opencatalogi/api/directory/9/sync',
			{ method: 'GET' },
		)
	})
})
