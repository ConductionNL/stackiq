// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * ModerationQueue as rendered: the empty state, one row per pending entry
 * with its title and subtitle, the approve/reject buttons posting the
 * decision for that row (and disabled for it while it runs), and the
 * section's refresh reloading the queue.
 *
 * @spec openspec/specs/catalog-ratings/spec.md#requirement-review-moderation-must-reuse-the-existing-moderation-queue-mechanism-not-a-second-one
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({ answers: [], calls: [], hold: null }))
const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))

vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/{(\w+)}/g, (match, key) => (key in vars ? vars[key] : match)),
}))
vi.mock('../../src/utils/adminApi.js', () => ({
	apiRequest: async (route, init) => {
		api.calls.push(init === undefined ? [route] : [route, init])
		if (init && api.hold) {
			await api.hold
		}
		return api.answers.shift() ?? { items: [] }
	},
}))

const { default: ModerationQueue } = await import('../../src/views/settings/sections/ModerationQueue.vue')

const stubs = {
	AlwaysVisibleSection: {
		props: ['name', 'loading'],
		emits: ['refresh'],
		template: '<section :data-loading="String(loading)"><h2>{{ name }}</h2><button data-testid="refresh" @click="$emit(\'refresh\')" /><slot /></section>',
	},
	NcEmptyContent: { props: ['name', 'description'], template: '<div data-testid="empty">{{ name }} {{ description }}</div>' },
	NcButton: {
		props: ['disabled', 'variant'],
		emits: ['click'],
		template: '<button :data-variant="variant" :disabled="disabled" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>',
	},
	NcLoadingIcon: { template: '<i data-testid="spinner" />' },
	Check: true,
	Close: true,
	CheckCircle: true,
}

async function mountQueue(props = {}) {
	const wrapper = mount(ModerationQueue, { props, global: { stubs } })
	await flushPromises()
	return wrapper
}

const rows = (wrapper) => wrapper.findAll('li.moderation-item')

beforeEach(() => {
	api.answers = []
	api.calls = []
	api.hold = null
	vi.clearAllMocks()
})

describe('ModerationQueue mounted', () => {
	it('shows the empty state when nothing is pending', async () => {
		const wrapper = await mountQueue()

		expect(api.calls).toEqual([['moderation/pending?type=organization']])
		expect(wrapper.find('[data-testid="empty"]').text())
			.toBe('Nothing to moderate There are no pending registrations right now.')
		expect(rows(wrapper)).toHaveLength(0)
	})

	it('renders one row per pending entry with its title and subtitle', async () => {
		api.answers.push({
			items: [
				{ id: 'o1', name: 'Gemeente X', email: 'info@x.nl' },
				{ id: 'o2', name: 'Gemeente Y' },
			],
		})
		const wrapper = await mountQueue()

		expect(wrapper.find('[data-testid="empty"]').exists()).toBe(false)
		expect(rows(wrapper).map((r) => r.find('.moderation-title').text())).toEqual(['Gemeente X', 'Gemeente Y'])
		expect(rows(wrapper)[0].find('.moderation-subtitle').text()).toBe('info@x.nl')
		expect(rows(wrapper)[1].find('.moderation-subtitle').exists()).toBe(false)
	})

	it('approves the clicked row only, disables it while running, then reloads', async () => {
		api.answers.push({ items: [{ id: 'o1', name: 'X' }, { id: 'o2', name: 'Y' }] })
		const wrapper = await mountQueue({ type: 'beoordeeling', entityLabel: 'review' })
		let release
		api.hold = new Promise((resolve) => { release = resolve })

		await rows(wrapper)[1].find('[data-variant="success"]').trigger('click')

		const [first, second] = rows(wrapper)
		expect(second.find('[data-variant="success"]').attributes('disabled')).toBeDefined()
		expect(second.find('[data-variant="error"]').attributes('disabled')).toBeDefined()
		expect(second.find('[data-variant="success"] [data-testid="spinner"]').exists()).toBe(true)
		expect(second.find('[data-variant="error"] [data-testid="spinner"]').exists()).toBe(false)
		expect(first.find('[data-variant="success"]').attributes('disabled')).toBeUndefined()

		api.answers.push({}, { items: [{ id: 'o1', name: 'X' }] })
		release()
		await flushPromises()

		expect(api.calls.slice(1)).toEqual([
			['moderation/o2/approve?type=beoordeeling', { method: 'POST' }],
			['moderation/pending?type=beoordeeling'],
		])
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Review approved and published')
		expect(rows(wrapper)).toHaveLength(1)
		expect(rows(wrapper)[0].find('[data-variant="success"]').attributes('disabled')).toBeUndefined()
	})

	it('rejects the clicked row', async () => {
		api.answers.push({ items: [{ id: 'o1', name: 'X' }] })
		const wrapper = await mountQueue()

		await rows(wrapper)[0].find('[data-variant="error"]').trigger('click')
		await flushPromises()

		expect(api.calls[1]).toEqual(['moderation/o1/reject?type=organization', { method: 'POST' }])
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Registration rejected')
	})

	it('reloads the queue on the section refresh', async () => {
		const wrapper = await mountQueue({ type: 'contact person' })
		api.answers.push({ items: [{ id: 'c1', name: 'Jan' }] })

		await wrapper.find('[data-testid="refresh"]').trigger('click')
		await flushPromises()

		expect(api.calls).toEqual([
			['moderation/pending?type=contact%20person'],
			['moderation/pending?type=contact%20person'],
		])
		expect(rows(wrapper)).toHaveLength(1)
	})
})
