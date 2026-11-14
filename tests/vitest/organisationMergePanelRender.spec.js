// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The organisation merge panel as it renders: the merged notice with its
 * link to the target, no controls for a non-admin, the admin's preview
 * button gated on a chosen target, blockers and errors shown inline, the
 * confirm dialog opened by a clean dry run, and the success state.
 *
 * @spec openspec/specs/organisation-merge/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const http = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path, params = {}) => '/index.php' + path.replace(/\{(\w+)\}/g, (m, k) => params[k] ?? m) }))
const toasts = vi.hoisted(() => [])
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: (m) => toasts.push(['success', m]) }))
const stores = vi.hoisted(() => ({
	settingsStore: { getIsAdmin: true },
	organisatieStore: { dryRunMerge: null, executeMerge: null },
}))
vi.mock('../../src/store/store.js', () => stores)
vi.mock('../../src/modals/object/MergeOrganisationConfirmDialog.vue', () => ({
	default: {
		name: 'MergeOrganisationConfirmDialog',
		props: ['show', 'sourceName', 'targetName', 'counts', 'busy', 'error'],
		emits: ['confirm', 'cancel'],
		render() {
			return this.show ? h('div', { class: 'confirm', 'data-source': this.sourceName, 'data-target': this.targetName }, this.error) : null
		},
	},
}))
vi.mock('@conduction/nextcloud-vue', async () => {
	const { h: render } = await import('vue')
	return {
		CnIcon: { render: () => render('i') },
		CnWidgetWrapper: {
			render() {
				return render('div', [this.$slots['title-icon']?.(), this.$slots.default?.()])
			},
		},
	}
})

const { default: OrganisationMergePanel } = await import('../../src/components/organisations/OrganisationMergePanel.vue')

const TARGET = { value: 'o-2', label: 'Gemeente West' }

/**
 * Mount the panel over a source organisation.
 *
 * @param {object} source The source object reply.
 * @param {object} [mocks] Extra global mocks.
 * @return {Promise<object>} The wrapper, loaded.
 */
async function mountPanel(source, mocks = {}) {
	http.get.mockResolvedValue({ data: source })
	const wrapper = mount(OrganisationMergePanel, {
		props: { objectId: 'o-1' },
		global: {
			mocks,
			stubs: { NcSelect: { props: ['modelValue'], render: () => h('div', { class: 'select' }) } },
		},
	})
	await flushPromises()
	return wrapper
}

const buttons = (wrapper) => wrapper.findAll('button')
const notes = (wrapper) => wrapper.findAll('.note-card-stub').map((n) => n.text())

beforeEach(() => {
	http.get.mockReset()
	toasts.length = 0
	stores.settingsStore.getIsAdmin = true
	stores.organisatieStore.dryRunMerge = vi.fn()
	stores.organisatieStore.executeMerge = vi.fn()
})

describe('OrganisationMergePanel rendering', () => {
	it('shows a merged organisation as read-only with a link to its target', async () => {
		const push = vi.fn()
		const wrapper = await mountPanel({ '@self': {}, name: 'Gemeente Oost', status: 'merged', mergedInto: 'o-9' }, { $router: { push } })

		expect(notes(wrapper)).toEqual(['This organisation has been merged and is no longer active.'])
		expect(wrapper.find('.select').exists()).toBe(false)
		expect(buttons(wrapper).map((b) => b.text())).toEqual(['Go to the organisation it was merged into'])

		await buttons(wrapper)[0].trigger('click')

		expect(push).toHaveBeenCalledWith({ name: 'OrganisatieDetail', params: { id: 'o-9' } })
	})

	it('shows no link when the merged organisation names no target', async () => {
		const wrapper = await mountPanel({ '@self': {}, status: 'merged' })

		expect(buttons(wrapper)).toHaveLength(0)
	})

	it('offers no merge controls to a non-admin', async () => {
		stores.settingsStore.getIsAdmin = false
		const wrapper = await mountPanel({ '@self': {}, name: 'Gemeente Oost', status: 'active' })

		expect(wrapper.find('.select').exists()).toBe(false)
		expect(buttons(wrapper)).toHaveLength(0)
		expect(notes(wrapper)).toEqual([])
	})

	it('disables the preview until a target is chosen', async () => {
		const wrapper = await mountPanel({ '@self': {}, name: 'Gemeente Oost', status: 'active' })

		expect(wrapper.find('.select').exists()).toBe(true)
		expect(buttons(wrapper).map((b) => b.text())).toEqual(['Preview merge'])
		expect(buttons(wrapper)[0].attributes('disabled')).toBeDefined()

		await wrapper.setData({ selectedTarget: TARGET })

		expect(buttons(wrapper)[0].attributes('disabled')).toBeUndefined()
	})

	it('lists the dry run blockers and keeps the confirm dialog closed', async () => {
		const wrapper = await mountPanel({ '@self': {}, name: 'Gemeente Oost', status: 'active' })
		stores.organisatieStore.dryRunMerge.mockResolvedValue({
			counts: { contracts: 2 },
			blockers: [{ type: 'self', message: 'Cannot merge into itself.' }, { type: 'merged', message: 'Target is already merged.' }],
		})
		await wrapper.setData({ selectedTarget: TARGET })

		await buttons(wrapper)[0].trigger('click')
		await flushPromises()

		expect(stores.organisatieStore.dryRunMerge).toHaveBeenCalledWith('o-1', 'o-2')
		expect(notes(wrapper)).toEqual(['Cannot merge into itself.', 'Target is already merged.'])
		expect(wrapper.find('.confirm').exists()).toBe(false)
	})

	it('shows a failed preview inline', async () => {
		const wrapper = await mountPanel({ '@self': {}, name: 'Gemeente Oost', status: 'active' })
		stores.organisatieStore.dryRunMerge.mockRejectedValue(new Error('Preview failed upstream'))
		await wrapper.setData({ selectedTarget: TARGET })

		await buttons(wrapper)[0].trigger('click')
		await flushPromises()

		expect(notes(wrapper)).toEqual(['Preview failed upstream'])
	})

	it('opens the confirm dialog on a clean dry run, and shows success after the merge', async () => {
		const wrapper = await mountPanel({ '@self': {}, name: 'Gemeente Oost', status: 'active' })
		stores.organisatieStore.dryRunMerge.mockResolvedValue({ counts: { contracts: 2 }, blockers: [] })
		stores.organisatieStore.executeMerge.mockResolvedValue({})
		await wrapper.setData({ selectedTarget: TARGET })

		await buttons(wrapper)[0].trigger('click')
		await flushPromises()

		const confirm = wrapper.find('.confirm')
		expect(confirm.attributes('data-source')).toBe('Gemeente Oost')
		expect(confirm.attributes('data-target')).toBe('Gemeente West')

		await wrapper.findComponent({ name: 'MergeOrganisationConfirmDialog' }).vm.$emit('confirm')
		await flushPromises()

		expect(stores.organisatieStore.executeMerge).toHaveBeenCalledWith('o-1', 'o-2')
		expect(wrapper.find('.confirm').exists()).toBe(false)
		expect(toasts).toEqual([['success', 'Organisation successfully merged.']])
		expect(notes(wrapper)).toEqual(['This organisation has been merged and is no longer active.'])
		expect(buttons(wrapper).map((b) => b.text())).toEqual(['Go to the organisation it was merged into'])
	})

	it('keeps the dialog open with the error when the merge fails', async () => {
		const wrapper = await mountPanel({ '@self': {}, name: 'Gemeente Oost', status: 'active' })
		stores.organisatieStore.dryRunMerge.mockResolvedValue({ blockers: [] })
		stores.organisatieStore.executeMerge.mockRejectedValue(new Error('Merge conflict'))
		await wrapper.setData({ selectedTarget: TARGET })
		await buttons(wrapper)[0].trigger('click')
		await flushPromises()

		await wrapper.findComponent({ name: 'MergeOrganisationConfirmDialog' }).vm.$emit('confirm')
		await flushPromises()

		expect(wrapper.find('.confirm').text()).toBe('Merge conflict')
		expect(buttons(wrapper).map((b) => b.text())).toEqual(['Preview merge'])
	})

	it('renders the controls with an unknown name when the source cannot be read', async () => {
		http.get.mockRejectedValue(new Error('404'))
		const wrapper = mount(OrganisationMergePanel, {
			props: { objectId: 'o-1' },
			global: { stubs: { NcSelect: { render: () => h('div', { class: 'select' }) } } },
		})
		await flushPromises()

		expect(wrapper.vm.sourceName).toBe('Unknown organisation')
		expect(wrapper.find('.select').exists()).toBe(true)
	})
})
