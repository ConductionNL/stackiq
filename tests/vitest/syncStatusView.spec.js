// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The Synchronisation page: the schedule and the last run, a running sync
 * with its phase and percentage, polling every three seconds while it runs
 * and stopping when it ends, and the refusal for other users.
 *
 * @spec openspec/changes/operations-sync-status-and-progress/specs/sync-status-and-progress/spec.md#requirement-req-ssp-004-the-synchronisation-page-shall-show-the-schedule-the-last-run-and-a-running-sync-to-nextcloud-admins-and-functional-administrators
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('@conduction/nextcloud-vue', async () => {
	const { h } = await import('vue')
	return {
		CnProgressBar: {
			props: ['items', 'showPercentage'],
			render() {
				const item = this.items[0]
				return h('div', { class: 'bar', 'data-percentage': item.percentage }, item.label)
			},
		},
		CnWidgetWrapper: {
			props: ['title'],
			render() {
				return h('section', [h('h3', this.title), this.$slots.default?.()])
			},
		},
	}
})

const { default: SyncStatusView, POLL_INTERVAL_MS } = await import('../../src/views/sync/SyncStatusView.vue')

const URL = '/index.php/apps/stackiq/api/sync/status'

const lastRun = {
	startedAt: '2026-11-14 10:00:00',
	finishedAt: '2026-11-14 10:01:30',
	trigger: 'scheduled',
	organizationsProcessed: 12,
	entitiesCreated: 2,
	entitiesUpdated: 5,
	contactPersonsProcessed: 30,
	usersCreated: 1,
	errorCount: 0,
}

/**
 * The text of one fact.
 *
 * @param {object} wrapper The mounted page.
 * @param {string} key The data-fact attribute.
 * @return {string} Its text.
 */
const fact = (wrapper, key) => wrapper.find(`[data-fact="${key}"]`).text()

beforeEach(() => {
	vi.useFakeTimers()
	http.get.mockReset()
})

afterEach(() => {
	vi.useRealTimers()
})

describe('SyncStatusView', () => {
	it('shows the interval, On and the last run with its time, trigger and counts', async () => {
		http.get.mockResolvedValue({ data: { interval: 300, enabled: true, lastRun, current: null } })
		const wrapper = mount(SyncStatusView)
		await flushPromises()

		expect(http.get).toHaveBeenCalledWith(URL)
		expect(fact(wrapper, 'interval')).toBe('Every 5 minutes')
		expect(fact(wrapper, 'enabled')).toBe('On')
		expect(fact(wrapper, 'finishedAt')).toBe('2026-11-14 10:01:30')
		expect(fact(wrapper, 'startedAt')).toBe('2026-11-14 10:00:00')
		expect(fact(wrapper, 'trigger')).toBe('Schedule')
		expect(fact(wrapper, 'organizationsProcessed')).toBe('12')
		expect(fact(wrapper, 'contactPersonsProcessed')).toBe('30')
		expect(fact(wrapper, 'usersCreated')).toBe('1')
		expect(fact(wrapper, 'errorCount')).toBe('0')
		expect(wrapper.find('[data-section="running"]').exists()).toBe(false)

		vi.advanceTimersByTime(POLL_INTERVAL_MS * 3)
		await flushPromises()
		expect(http.get).toHaveBeenCalledTimes(1)
	})

	it('says Off and that the sync never ran', async () => {
		http.get.mockResolvedValue({ data: { interval: 3600, enabled: false, lastRun: null, current: null } })
		const wrapper = mount(SyncStatusView)
		await flushPromises()

		expect(fact(wrapper, 'interval')).toBe('Every hour')
		expect(fact(wrapper, 'enabled')).toBe('Off')
		expect(wrapper.find('[data-section="no-last-run"]').text()).toBe('The sync has not run yet.')
	})

	it('names a run started by hand and a run that only kept its time', async () => {
		http.get.mockResolvedValue({ data: { interval: 300, enabled: true, lastRun: { finishedAt: '2026-11-13 09:00:00', trigger: 'manual', errorCount: 3 }, current: null } })
		const wrapper = mount(SyncStatusView)
		await flushPromises()

		expect(fact(wrapper, 'trigger')).toBe('Started by hand')
		expect(fact(wrapper, 'errorCount')).toBe('3')
		expect(wrapper.find('[data-fact="errorCount"]').classes()).toContain('sync-status__errors')
		expect(wrapper.find('[data-fact="organizationsProcessed"]').exists()).toBe(false)
	})

	it('polls every three seconds while a run is going and stops when it ends', async () => {
		const running = (percentage, phase) => ({ data: { interval: 300, enabled: true, lastRun: null, current: { status: 'running', phase, percentage, processed_items: 4, total_items: 10 } } })
		http.get
			.mockResolvedValueOnce(running(20, 'processing_organizations'))
			.mockResolvedValueOnce(running(60.4, 'processing_contacts'))
			.mockResolvedValueOnce({ data: { interval: 300, enabled: true, lastRun, current: null } })
		const wrapper = mount(SyncStatusView)
		await flushPromises()

		expect(wrapper.find('.bar').text()).toBe('Processing organisations')
		expect(wrapper.find('.bar').attributes('data-percentage')).toBe('20')
		expect(wrapper.text()).toContain('4 of 10 items')

		vi.advanceTimersByTime(POLL_INTERVAL_MS - 1)
		await flushPromises()
		expect(http.get).toHaveBeenCalledTimes(1)

		vi.advanceTimersByTime(1)
		await flushPromises()
		expect(http.get).toHaveBeenCalledTimes(2)
		expect(wrapper.find('.bar').text()).toBe('Processing contact persons')
		expect(wrapper.find('.bar').attributes('data-percentage')).toBe('60')

		vi.advanceTimersByTime(POLL_INTERVAL_MS)
		await flushPromises()
		expect(http.get).toHaveBeenCalledTimes(3)
		expect(wrapper.find('[data-section="running"]').exists()).toBe(false)
		expect(fact(wrapper, 'finishedAt')).toBe('2026-11-14 10:01:30')

		vi.advanceTimersByTime(POLL_INTERVAL_MS * 5)
		await flushPromises()
		expect(http.get).toHaveBeenCalledTimes(3)
	})

	it('stops polling when the page is left', async () => {
		http.get.mockResolvedValue({ data: { interval: 300, enabled: true, lastRun: null, current: { status: 'running', phase: 'processing_users', percentage: 90 } } })
		const wrapper = mount(SyncStatusView)
		await flushPromises()
		expect(wrapper.find('.bar').text()).toBe('Processing users')

		wrapper.unmount()
		vi.advanceTimersByTime(POLL_INTERVAL_MS * 3)
		await flushPromises()
		expect(http.get).toHaveBeenCalledTimes(1)
	})

	it('explains a refusal for a regular user', async () => {
		http.get.mockRejectedValue({ response: { status: 403 } })
		const wrapper = mount(SyncStatusView)
		await flushPromises()

		expect(wrapper.text()).toContain('Only Nextcloud admins and functional administrators can see the synchronisation.')
		expect(wrapper.find('[data-fact="interval"]').exists()).toBe(false)
	})

	it('reports any other failure', async () => {
		http.get.mockRejectedValue(new Error('down'))
		const wrapper = mount(SyncStatusView)
		await flushPromises()

		expect(wrapper.text()).toContain('The synchronisation status could not be loaded.')
	})
})
