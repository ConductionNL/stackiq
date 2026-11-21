// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The CMDB overview's service-desk status (connected, not connected, could
 * not be read) and the dashboard widget that lists draft organisations and
 * accepts them.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const store = vi.hoisted(() => ({
	results: [],
	fetchCollection: null,
	patchObject: null,
}))
const user = vi.hoisted(() => ({ current: null }))

vi.mock('../../src/store/store.js', () => ({
	objectStore: {
		getCollection: () => ({ results: store.results }),
		fetchCollection: (...a) => store.fetchCollection(...a),
		patchObject: (...a) => store.patchObject(...a),
	},
}))
vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: () => user.current,
	getRequestToken: () => 'token-1',
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => String(url) }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		String(text).replace(/\{(\w+)\}/g, (_, key) =>
			String(vars?.[key] ?? `{${key}}`),
		),
}))

function stub(name) {
	return defineComponent({
		name,
		props: [
			'type',
			'size',
			'href',
			'variant',
			'rows',
			'columns',
			'loading',
			'emptyText',
			'to',
			'closeAfterClick',
			'ariaLabel',
		],
		emits: ['click'],
		render() {
			return h('div', { class: name }, [
				this.$slots.default?.() ?? [],
				this.$slots.icon?.() ?? [],
				...(this.rows ?? []).map(
					(row) => this.$slots['row-actions']?.({ row }) ?? [],
				),
			])
		},
	})
}

vi.mock('@nextcloud/vue', () => ({
	NcActionButton: stub('NcActionButton'),
	NcActions: stub('NcActions'),
	NcButton: stub('NcButton'),
	NcLoadingIcon: stub('NcLoadingIcon'),
	NcNoteCard: stub('NcNoteCard'),
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDataTable: stub('CnDataTable'),
	registerIcons: () => {},
}))

const { default: CmdbOverview } =
	await import('../../src/views/cmdb/CmdbOverview.vue')
const { default: ConceptOrganisatiesWidget } =
	await import('../../src/views/widgets/ConceptOrganisatiesWidget.vue')

const RouterLink = stub('RouterLink')

describe('CmdbOverview service desk status', () => {
	let fetchMock

	beforeEach(() => {
		fetchMock = vi.fn()
		vi.stubGlobal('fetch', fetchMock)
		user.current = null
	})
	afterEach(() => {
		vi.unstubAllGlobals()
	})

	async function mountOverview() {
		const wrapper = mount(CmdbOverview, {
			global: { stubs: { 'router-link': RouterLink } },
		})
		await flushPromises()
		return wrapper
	}

	function note(wrapper) {
		return wrapper
			.find('[data-testid="cmdb-exchange"]')
			.findComponent({ name: 'NcNoteCard' })
	}

	it('says which service desk is in step, and offers an admin to change it', async () => {
		user.current = { isAdmin: true }
		fetchMock.mockResolvedValue({
			ok: true,
			json: async () => ({
				enabled: true,
				desk: 'topdesk',
				desks: [{ id: 'topdesk', label: 'TOPdesk' }],
			}),
		})

		const wrapper = await mountOverview()

		expect(fetchMock).toHaveBeenCalledWith('/apps/stackiq/api/itsm/status', {
			headers: { Accept: 'application/json', requesttoken: 'token-1' },
		})
		expect(note(wrapper).props('type')).toBe('success')
		expect(note(wrapper).text()).toContain('Stackiq and TOPdesk are in step')
		const button = wrapper
			.findAllComponents({ name: 'NcButton' })
			.find((b) => b.props('href'))
		expect(button.props('href')).toBe('/settings/admin/stackiq#section-itsm')
		expect(button.text()).toBe('Change the exchange')
		expect(
			wrapper.findAllComponents(RouterLink).map((l) => l.props('to').name),
		).toEqual(['Gebruik', 'Modules', 'Koppelingen', 'Contracten'])
	})

	it('names an unlisted desk by its id, and shows no settings button to a non-admin', async () => {
		user.current = { isAdmin: false }
		fetchMock.mockResolvedValue({
			ok: true,
			json: async () => ({ enabled: true, desk: 'servicenow' }),
		})

		const wrapper = await mountOverview()

		expect(note(wrapper).text()).toContain('Stackiq and servicenow are in step')
		expect(
			wrapper
				.findAllComponents({ name: 'NcButton' })
				.some((b) => b.props('href')),
		).toBe(false)
	})

	it('says no desk is connected yet and offers an admin to connect one', async () => {
		user.current = { isAdmin: true }
		fetchMock.mockResolvedValue({
			ok: true,
			json: async () => ({ enabled: false }),
		})

		const wrapper = await mountOverview()

		expect(note(wrapper).props('type')).toBe('info')
		expect(
			wrapper
				.findAllComponents({ name: 'NcButton' })
				.find((b) => b.props('href'))
				.text(),
		).toBe('Connect a service desk')
	})

	it('warns when the status cannot be read', async () => {
		fetchMock.mockResolvedValue({
			ok: false,
			status: 500,
			json: async () => ({}),
		})

		const wrapper = await mountOverview()

		expect(wrapper.vm.statusFailed).toBe(true)
		expect(wrapper.vm.loading).toBe(false)
		expect(note(wrapper).props('type')).toBe('warning')
	})

	it('shows the file import to an admin only, and sends nothing when no file is chosen', async () => {
		fetchMock.mockResolvedValue({
			ok: true,
			json: async () => ({ enabled: false }),
		})
		expect(
			(await mountOverview())
				.find('[data-testid="cmdb-file-import"]')
				.exists(),
		).toBe(false)

		user.current = { isAdmin: true }
		fetchMock.mockResolvedValue({
			ok: true,
			json: async () => ({ enabled: false }),
		})
		const wrapper = await mountOverview()
		fetchMock.mockClear()
		expect(wrapper.vm.$refs.file.files).toHaveLength(0)

		await wrapper.vm.importFile()

		expect(fetchMock).not.toHaveBeenCalled()
		expect(wrapper.vm.importing).toBe(false)
	})
})

describe('ConceptOrganisatiesWidget', () => {
	beforeEach(() => {
		store.results = [
			{ id: 'o-1', name: 'Acme', status: 'draft', website: 'acme.example' },
			{ id: 'o-2', title: 'Titled', status: 'Draft', type: 'Gemeente' },
			{ id: 'o-3', status: 'DRAFT' },
			{ id: 'o-4', name: 'Live', status: 'Active' },
		]
		store.fetchCollection = vi.fn(async () => {})
		store.patchObject = vi.fn(async () => {})
	})

	it('lists only draft organisations with their name and website or type', async () => {
		const wrapper = mount(ConceptOrganisatiesWidget, {
			props: { title: 'Concept' },
		})
		await flushPromises()

		expect(store.fetchCollection).toHaveBeenCalledWith('organization')
		expect(wrapper.findComponent({ name: 'CnDataTable' }).props('rows')).toEqual(
			[
				{ id: 'o-1', mainText: 'Acme', subText: 'acme.example' },
				{ id: 'o-2', mainText: 'Titled', subText: 'Gemeente' },
				{ id: 'o-3', mainText: 'Unknown organisation', subText: '' },
			],
		)
		expect(wrapper.vm.loading).toBe(false)
	})

	it('accepts an organisation by setting it active, then reloads', async () => {
		const wrapper = mount(ConceptOrganisatiesWidget, {
			props: { title: 'Concept' },
		})
		await flushPromises()
		store.fetchCollection.mockClear()

		const pending = wrapper.vm.onAccept({ id: 'o-1' })
		expect(wrapper.vm.processingIds).toEqual(['o-1'])
		await pending

		expect(store.patchObject).toHaveBeenCalledWith('organization', 'o-1', {
			status: 'Active',
		})
		expect(store.fetchCollection).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.processingIds).toEqual([])
	})

	it('accepts from the row action and refreshes from the header button', async () => {
		const wrapper = mount(ConceptOrganisatiesWidget, {
			props: { title: 'Concept' },
		})
		await flushPromises()

		wrapper.findAllComponents({ name: 'NcActionButton' })[1].vm.$emit('click')
		await flushPromises()
		expect(store.patchObject).toHaveBeenCalledWith('organization', 'o-2', {
			status: 'Active',
		})

		store.fetchCollection.mockClear()
		wrapper.findAllComponents({ name: 'NcButton' })[0].vm.$emit('click')
		await flushPromises()
		expect(store.fetchCollection).toHaveBeenCalledTimes(1)
	})

	it('keeps the list when accepting fails and clears the busy mark', async () => {
		const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {})
		store.patchObject = vi.fn(async () => {
			throw new Error('forbidden')
		})
		const wrapper = mount(ConceptOrganisatiesWidget, {
			props: { title: 'Concept' },
		})
		await flushPromises()
		store.fetchCollection.mockClear()

		await wrapper.vm.onAccept({ id: 'o-1' })

		expect(store.fetchCollection).not.toHaveBeenCalled()
		expect(wrapper.vm.processingIds).toEqual([])
		expect(consoleError).toHaveBeenCalledWith(
			'Error accepting organization:',
			expect.any(Error),
		)
		consoleError.mockRestore()
	})
})
