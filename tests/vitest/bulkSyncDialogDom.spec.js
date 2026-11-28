// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * BulkSyncDialog, mounted: hidden while closed; the preview names the
 * compliance count; while syncing the button reads "Syncing..." and is
 * disabled with the progress shown; afterwards the results, the module table
 * and any errors render, missing counters read 0, and Close emits the close.
 *
 * @spec openspec/specs/fe-settings-ui/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/vue', async () => {
	const { h } = await import('vue')
	return {
		NcModal: {
			emits: ['close'],
			render() {
				return h('section', { class: 'modal' }, [
					h('button', { class: 'modal-close', onClick: () => this.$emit('close') }),
					this.$slots.default?.(),
				])
			},
		},
		NcButton: {
			props: { variant: { default: 'secondary' }, disabled: { default: false } },
			emits: ['click'],
			render() {
				return h(
					'button',
					{ class: this.variant, disabled: this.disabled, onClick: () => this.$emit('click') },
					this.$slots.default?.(),
				)
			},
		},
		NcLoadingIcon: { render: () => h('i', { class: 'loading' }) },
	}
})

const { default: BulkSyncDialog } = await import('../../src/modals/BulkSyncDialog.vue')

const RESULT = {
	totalProcessed: 12,
	modulesFound: 4,
	modulesUpdated: 3,
	standardsAdded: 7,
	modules: [
		{ uuid: 'm-1', name: 'Zaaksysteem', status: 'updated', reason: 'Added 2', complianceCount: 3, standardsCount: 5 },
		{ uuid: 'm-2', name: 'DMS', status: 'unchanged', reason: 'Up to date', complianceCount: 1, standardsCount: 2 },
	],
	errors: [],
}

/**
 * Mount the dialog.
 *
 * @param {object} props The props.
 * @return {object} The wrapper.
 */
const open = (props = {}) => mount(BulkSyncDialog, { props: { open: true, complianceCount: 12, ...props } })

/**
 * Let fetch answer with the given body.
 *
 * @param {object} data The response `data`.
 * @return {void}
 */
const answer = (data) => vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({ data }) })))

const statValues = (wrapper) => wrapper.findAll('.stat-item').map((item) => [item.find('.stat-label').text(), item.find('.stat-value').text()])

describe('BulkSyncDialog mounted', () => {
	beforeEach(() => {
		globalThis.OC = { requestToken: 'tok' }
		vi.spyOn(console, 'error').mockImplementation(() => {})
	})

	afterEach(() => {
		vi.restoreAllMocks()
		vi.unstubAllGlobals()
	})

	it('renders nothing while closed', () => {
		expect(open({ open: false }).find('section.modal').exists()).toBe(false)
	})

	it('previews the run with the compliance count and offers Start Sync and Cancel', () => {
		const wrapper = open()

		expect(wrapper.find('.preview-list li').text()).toBe('Scan all 12 compliance objects')
		expect(wrapper.find('.results-section').exists()).toBe(false)
		expect(wrapper.find('.loading-section').exists()).toBe(false)
		expect(wrapper.find('button.primary').text()).toBe('Start Sync')
		expect(wrapper.find('button.secondary').text()).toBe('Cancel')
	})

	it('shows the progress and a disabled "Syncing..." button while the sync runs', async () => {
		let release
		vi.stubGlobal('fetch', vi.fn(() => new Promise((resolve) => { release = resolve })))
		const wrapper = open()

		await wrapper.find('button.primary').trigger('click')

		expect(wrapper.find('button.primary').text()).toBe('Syncing…')
		expect(wrapper.find('button.primary').attributes('disabled')).toBeDefined()
		expect(wrapper.find('.progress-info').text()).toContain('Processed: 0 /')
		expect(wrapper.find('.progress-info').text()).toContain('12')
		expect(wrapper.emitted('loading-change')).toEqual([[true]])

		release({ ok: true, json: async () => ({ data: RESULT }) })
		await flushPromises()
		expect(wrapper.find('.loading-section').exists()).toBe(false)
	})

	it('renders the results and the module table after a sync', async () => {
		answer(RESULT)
		const wrapper = open()

		await wrapper.find('button.primary').trigger('click')
		await flushPromises()

		expect(wrapper.find('.preview-section').exists()).toBe(false)
		expect(statValues(wrapper)).toEqual([
			['Compliance objects processed:', '12'],
			['Modules found:', '4'],
			['Modules updated:', '3'],
			['Modules already up-to-date:', '0'],
			['Modules with no standards:', '0'],
			['Standards added:', '7'],
		])
		expect(wrapper.find('.modules-table-section h4').text()).toBe('Processed Modules (2):')
		const rows = wrapper.findAll('tbody tr')
		expect(rows.map((row) => row.classes())).toEqual([['status-updated'], ['status-unchanged']])
		expect(rows[0].findAll('td').map((cell) => cell.text())).toEqual(['Zaaksysteem m-1', 'updated', 'Added 2', '3', '5'])
		expect(rows[0].find('.status-badge').classes()).toContain('badge-updated')
		expect(wrapper.find('.errors-section').exists()).toBe(false)
		expect(wrapper.find('button.primary').exists()).toBe(false)
		expect(wrapper.find('button.secondary').text()).toBe('Close')
		expect(wrapper.emitted('synced')).toHaveLength(1)
	})

	it('shows the counters the server sends and skips the table without modules', async () => {
		answer({ ...RESULT, modules: [], modulesAlreadyUpToDate: 5, modulesWithNoStandards: 2 })
		const wrapper = open()

		await wrapper.find('button.primary').trigger('click')
		await flushPromises()

		expect(statValues(wrapper).slice(3, 5)).toEqual([['Modules already up-to-date:', '5'], ['Modules with no standards:', '2']])
		expect(wrapper.find('.modules-table-section').exists()).toBe(false)
	})

	it('lists the errors of a failed sync', async () => {
		vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, status: 500 })))
		const wrapper = open()

		await wrapper.find('button.primary').trigger('click')
		await flushPromises()

		expect(wrapper.findAll('.error-list li').map((item) => item.text())).toEqual(['Sync failed: HTTP error! status: 500'])
		expect(wrapper.emitted('synced')).toBeUndefined()
	})

	it('Close after a sync and the modal close both ask the parent to close and reset', async () => {
		answer(RESULT)
		const wrapper = open()
		await wrapper.find('button.primary').trigger('click')
		await flushPromises()

		await wrapper.find('button.secondary').trigger('click')
		expect(wrapper.find('.preview-section').exists()).toBe(true)
		await wrapper.find('button.modal-close').trigger('click')

		expect(wrapper.emitted('update:open')).toEqual([[false], [false]])
	})

	it('starts over when it is opened again', async () => {
		answer(RESULT)
		const wrapper = open()
		await wrapper.find('button.primary').trigger('click')
		await flushPromises()

		await wrapper.setProps({ open: false })
		await wrapper.setProps({ open: true })

		expect(wrapper.find('.preview-section').exists()).toBe(true)
		expect(wrapper.find('.results-section').exists()).toBe(false)
	})
})
