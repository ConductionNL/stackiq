// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * CronjobConfiguration as rendered: one card per background job with its
 * switch, interval and configured/not-configured status, the organisation
 * select labelled from the organisation list, the Save and Run Now buttons'
 * enablement and wiring, and the empty state when there are no jobs.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => dialogs)

const { default: CronjobConfiguration } = await import('../../src/views/settings/sections/CronjobConfiguration.vue')

const stubs = {
	AlwaysVisibleSection: {
		props: ['loading'],
		emits: ['refresh'],
		template: '<section :data-loading="String(loading)"><button data-testid="refresh" @click="$emit(\'refresh\')" /><slot /></section>',
	},
	NcNoteCard: { props: ['type'], template: '<div :data-note="type"><slot /></div>' },
	NcEmptyContent: { props: ['name', 'description'], template: '<div data-testid="empty">{{ name }} | {{ description }}</div>' },
	NcCheckboxRadioSwitch: {
		props: ['modelValue'],
		emits: ['update:modelValue'],
		template: '<label data-testid="switch"><input type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)"><slot /></label>',
	},
	NcSelect: {
		props: ['modelValue', 'options', 'disabled', 'loading'],
		emits: ['update:modelValue'],
		template: '<select data-testid="org" :data-value="modelValue ? modelValue.label : \'\'" :data-options="options.map(o => o.label).join(\',\')" :disabled="disabled" @change="$emit(\'update:modelValue\', options[Number($event.target.value)])"><option v-for="(o, i) in options" :key="i" :value="i" /></select>',
	},
	NcButton: {
		props: ['disabled'],
		emits: ['click'],
		template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>',
	},
	NcLoadingIcon: { template: '<i data-testid="spinner" />' },
	Clock: true,
	Check: { template: '<i data-testid="ok" />' },
	AlertCircle: { template: '<i data-testid="alert" />' },
	Cog: true,
	ContentSave: true,
	Play: true,
}

const JOBS = {
	sync: { id: 'sync', name: 'Organisation sync', description: 'Syncs organisations', interval: 3600, enabled: true, organisationUuid: 'u1' },
	mail: { id: 'mail', name: 'Mail digest', description: 'Sends digests', interval: 86400, enabled: false, organisationUuid: null },
}

/**
 * Answer the cronjob list and the organisation list.
 *
 * @param {object} cronjobs The jobs keyed by id.
 * @return {void}
 */
function answers(cronjobs) {
	http.get.mockImplementation(async (url) => (url.endsWith('/api/settings/cronjobs')
		? { data: { success: true, cronjobs } }
		: { data: { results: [{ uuid: 'u1', name: 'Gemeente A' }, { uuid: 'u2', name: 'Gemeente B' }] } }))
}

async function mountSection() {
	const wrapper = mount(CronjobConfiguration, { global: { stubs, mocks: { t: (app, text) => text } } })
	await flushPromises()
	return wrapper
}

const cards = (wrapper) => wrapper.findAll('.cronjob-card')
const button = (card, label) => card.findAll('button').find((b) => b.text() === label)

beforeEach(() => {
	vi.clearAllMocks()
	vi.spyOn(console, 'error').mockImplementation(() => {})
	answers(JOBS)
	http.post.mockResolvedValue({ data: { success: true } })
})

describe('CronjobConfiguration mounted', () => {
	it('renders a card per job with its state, interval and configured status', async () => {
		const wrapper = await mountSection()
		expect(wrapper.find('section').attributes('data-loading')).toBe('false')
		expect(wrapper.find('[data-note="info"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="empty"]').exists()).toBe(false)
		const [sync, mail] = cards(wrapper)
		expect([sync.find('h4').text(), sync.find('[data-testid="switch"]').text(), sync.find('.cronjob-description').text(), sync.find('.cronjob-interval').text()])
			.toEqual(['Organisation sync', 'Enabled', 'Syncs organisations', 'Runs every ' + wrapper.vm.formatInterval(3600)])
		expect([sync.find('[data-testid="org"]').attributes('data-value'), sync.find('[data-testid="org"]').attributes('data-options'), sync.find('[data-testid="org"]').attributes('disabled')])
			.toEqual(['Gemeente A', 'Gemeente A,Gemeente B', undefined])
		expect([sync.find('.config-status').classes('success'), sync.find('[data-testid="ok"]').exists(), sync.find('.status-text').text()])
			.toEqual([true, true, 'Configured - Job will run with proper authorization'])
		expect([button(sync, 'Save Configuration').attributes('disabled'), button(sync, 'Run Now').attributes('disabled')]).toEqual([undefined, undefined])

		expect([mail.find('[data-testid="switch"]').text(), mail.find('[data-testid="org"]').attributes('disabled'), mail.find('[data-testid="org"]').attributes('data-value')])
			.toEqual(['Disabled', '', ''])
		expect([mail.find('.config-status').classes('warning'), mail.find('[data-testid="alert"]').exists(), mail.find('.status-text').text()])
			.toEqual([true, true, 'Not configured - Job may encounter RBAC errors'])
		expect([button(mail, 'Save Configuration').attributes('disabled'), button(mail, 'Run Now').attributes('disabled')]).toEqual(['', ''])
	})

	it('saves the job with its switch flipped, and saves a newly chosen organisation', async () => {
		const wrapper = await mountSection()
		const mail = () => cards(wrapper)[1]
		await mail().find('[data-testid="switch"] input').setValue(true)
		await flushPromises()
		expect(http.post).toHaveBeenLastCalledWith('/index.php/apps/stackiq/api/settings/cronjobs', { jobId: 'mail', organisationUuid: null, enabled: true })
		expect(mail().find('[data-testid="switch"]').text()).toBe('Enabled')
		expect(mail().find('[data-testid="org"]').attributes('disabled')).toBeUndefined()

		await mail().find('[data-testid="org"]').setValue('1')
		expect(button(mail(), 'Save Configuration').attributes('disabled')).toBeUndefined()
		await button(mail(), 'Save Configuration').trigger('click')
		await flushPromises()
		expect(http.post).toHaveBeenLastCalledWith('/index.php/apps/stackiq/api/settings/cronjobs', { jobId: 'mail', organisationUuid: 'u2', enabled: true })
		expect(dialogs.showSuccess).toHaveBeenLastCalledWith('Cronjob configuration saved')
		expect(mail().find('.status-text').text()).toBe('Configured - Job will run with proper authorization')
		expect(button(mail(), 'Run Now').attributes('disabled')).toBeUndefined()
	})

	it('runs a configured job, showing Running... while it is busy', async () => {
		let finish
		http.post.mockImplementation(() => new Promise((resolve) => { finish = resolve }))
		const wrapper = await mountSection()
		await button(cards(wrapper)[0], 'Run Now').trigger('click')
		const running = cards(wrapper)[0].findAll('button')[1]
		expect([running.text(), running.attributes('disabled'), running.find('[data-testid="spinner"]').exists()]).toEqual(['Running...', '', true])
		expect(http.post).toHaveBeenCalledWith('/index.php/apps/stackiq/api/settings/sync')
		finish({ data: { success: true } })
		await flushPromises()
		expect(button(cards(wrapper)[0], 'Run Now').exists()).toBe(true)
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Background job executed successfully')
	})

	it('shows Saving... on the card being saved', async () => {
		const wrapper = await mountSection()
		wrapper.vm.savingJob = 'sync'
		await wrapper.vm.$nextTick()
		const save = cards(wrapper)[0].findAll('button')[0]
		expect([save.text(), save.attributes('disabled'), save.find('[data-testid="spinner"]').exists(), cards(wrapper)[0].find('[data-testid="org"]').attributes('disabled')])
			.toEqual(['Saving...', '', true, ''])
		expect(cards(wrapper)[1].findAll('button')[0].text()).toBe('Save Configuration')
	})

	it('shows the empty state without jobs, and reloads on refresh', async () => {
		answers({})
		const wrapper = await mountSection()
		expect(cards(wrapper)).toHaveLength(0)
		expect(wrapper.find('[data-testid="empty"]').text()).toBe('No background jobs configured | There are no background jobs available for configuration.')
		answers(JOBS)
		await wrapper.find('[data-testid="refresh"]').trigger('click')
		await flushPromises()
		expect(cards(wrapper)).toHaveLength(2)
		expect(wrapper.find('[data-testid="empty"]').exists()).toBe(false)
	})
})
