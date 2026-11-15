// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * OrganizationSynchronization as rendered: the sync-status overview (configured
 * vs not, the processing counts and their severity class), the manual-sync
 * result statistics and error list, and the organisation-to-voorzieningen
 * sync controls, button label (preview vs apply) and result breakdown.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const store = vi.hoisted(() => ({}))
const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))

vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('../../src/store/store.js', () => ({ settingsStore: store }))
vi.mock('../../src/utils/heartbeat.js', () => ({ withHeartbeat: (op) => op() }))

const { default: OrganizationSynchronization } = await import('../../src/views/settings/sections/OrganizationSynchronization.vue')

const stubs = {
	AlwaysVisibleSection: {
		props: ['loading'],
		template: '<section :data-loading="String(loading)"><slot name="header-actions" /><slot /></section>',
	},
	NcNoteCard: { props: ['type'], template: '<div :data-note="type"><slot name="icon" /><slot /></div>' },
	NcButton: {
		props: ['disabled'],
		emits: ['click'],
		template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>',
	},
	NcSelect: {
		props: ['modelValue', 'options'],
		emits: ['update:modelValue'],
		template: '<select data-testid="window" @change="$emit(\'update:modelValue\', options[Number($event.target.value)])"><option v-for="(o, i) in options" :key="i" :value="i" /></select>',
	},
	NcLoadingIcon: { template: '<i data-testid="spinner" />' },
	Refresh: true,
	Sync: true,
	CheckCircle: { template: '<i data-testid="ok" />' },
	Alert: { template: '<i data-testid="alert" />' },
}

const fetchMock = vi.fn()

/**
 * Queue one fetch answer.
 *
 * @param {object} body The JSON body to resolve with.
 * @param {boolean} ok Whether the response is ok.
 * @return {void}
 */
function answer(body, ok = true) {
	fetchMock.mockResolvedValueOnce({ ok, status: ok ? 200 : 500, statusText: ok ? 'OK' : 'Error', json: async () => body })
}

async function mountSection(statusBody = { configured: false }) {
	store.loadingSyncSettings = false
	store.settings = { syncTimeWindow: 10 }
	store.loadSyncConfig = vi.fn(async () => {})
	answer(statusBody) // the created() sync-status fetch
	const wrapper = mount(OrganizationSynchronization, { global: { stubs } })
	await flushPromises()
	return wrapper
}


beforeEach(() => {
	vi.clearAllMocks()
	vi.spyOn(console, 'error').mockImplementation(() => {})
	global.fetch = fetchMock
	global.OC = { requestToken: 'tok' }
})

describe('OrganizationSynchronization mounted', () => {
	it('shows a not-configured status without the detail grid', async () => {
		const wrapper = await mountSection({ configured: false, message: 'Set a register first' })
		expect(wrapper.find('.status-missing').text()).toContain('Not configured')
		expect(wrapper.find('.config-details').exists()).toBe(false)
		expect(wrapper.find('.status-message').text()).toBe('Set a register first')
	})

	it('renders the configured status detail with the processing severity classes', async () => {
		const wrapper = await mountSection({
			configured: true,
			syncMode: 'incremental',
			timeWindow: 120,
			totalOrganizationObjects: 1234,
			organizationsToProcess: 150,
			contactPersonsToProcess: 5,
			contactSchemaConfigured: true,
			efficiencyImprovement: '80%',
			lastSyncTime: '2026-05-15T10:00:00Z',
		})
		const details = wrapper.find('.config-details')
		expect(details.text()).toContain('incremental')
		expect(details.text()).toContain('2 hours')
		expect(details.text()).toContain('1,234')
		const values = wrapper.findAll('.config-value')
		expect(values.find((v) => v.text() === '150').classes()).toContain('processing-high')
		expect(values.find((v) => v.text() === '5').classes()).toContain('processing-low')
		expect(wrapper.find('.efficiency-highlight').text()).toBe('80%')
		expect(wrapper.findAll('.status-configured')).toHaveLength(2)
	})

	it('runs a manual sync and shows its statistics, then reloads the status', async () => {
		const wrapper = await mountSection({ configured: true })
		answer({ success: true, results: { entitiesCreated: 3, entitiesUpdated: 4, usersCreated: 1, usersUpdated: 2, duration: '2s', errors: [] } })
		answer({ configured: true }) // the reload after a successful sync
		await wrapper.findAll('button').find((b) => b.text().includes('Sync Now')).trigger('click')
		await flushPromises()
		const result = wrapper.find('.sync-result')
		expect([result.find('[data-note="success"]').exists(), result.find('[data-testid="ok"]').exists()]).toEqual([true, true])
		expect(result.find('.sync-statistics').text()).toContain('3')
		expect(result.find('.sync-statistics').text()).toContain('Duration: 2s')
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Manual synchronization completed successfully')
		expect(fetchMock).toHaveBeenCalledTimes(3)
	})

	it('shows a failed manual sync with its error list', async () => {
		const wrapper = await mountSection({ configured: true })
		answer({ success: true, results: { entitiesCreated: 0, entitiesUpdated: 0, usersCreated: 0, usersUpdated: 0, duration: '1s', errors: ['bad row 1', 'bad row 2'] } })
		answer({ configured: true })
		await wrapper.findAll('button').find((b) => b.text().includes('Sync Now')).trigger('click')
		await flushPromises()
		const errors = wrapper.find('.sync-errors')
		expect(errors.findAll('li').map((li) => li.text())).toEqual(['bad row 1', 'bad row 2'])
	})

	it('previews the organisation sync, then applies it with the button relabelled', async () => {
		const wrapper = await mountSection({ configured: true })
		const orgButton = () => wrapper.findAll('button').find((b) => ['Preview Sync', 'Sync Organisations'].includes(b.text()))
		expect(orgButton().text()).toBe('Preview Sync')
		answer({ success: true, results: { processed_count: 10, failed_count: 0 } })
		await orgButton().trigger('click')
		await flushPromises()
		expect(fetchMock).toHaveBeenLastCalledWith(
			'/index.php/apps/stackiq/api/settings/sync/organisations',
			expect.objectContaining({ method: 'POST', body: JSON.stringify({ dry_run: true, batch_size: 500 }) }),
		)
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Organisation sync preview completed')
		expect(wrapper.find('.organisation-voorzieningen-sync .sync-result').exists()).toBe(true)

		await wrapper.find('.sync-options input[type="checkbox"]').setValue(false)
		expect(orgButton().text()).toBe('Sync Organisations')
		answer({ success: true, results: { created_count: 7, failed_count: 0 } })
		await orgButton().trigger('click')
		await flushPromises()
		expect(fetchMock).toHaveBeenLastCalledWith(
			'/index.php/apps/stackiq/api/settings/sync/organisations',
			expect.objectContaining({ body: JSON.stringify({ dry_run: false, batch_size: 500 }) }),
		)
		expect(dialogs.showSuccess).toHaveBeenLastCalledWith('Organisation sync completed successfully')
	})

	it('reports a failed organisation sync', async () => {
		const wrapper = await mountSection({ configured: true })
		answer({ success: false, message: 'register missing' })
		await wrapper.findAll('button').find((b) => ['Preview Sync', 'Sync Organisations'].includes(b.text())).trigger('click')
		await flushPromises()
		expect(dialogs.showError).toHaveBeenCalledWith('Failed to perform organisation sync: register missing')
		expect(wrapper.find('.organisation-voorzieningen-sync [data-note="error"]').exists()).toBe(true)
	})
})
