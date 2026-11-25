// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A process step's supporting applications: the visible ones as links to
 * their usage page, how many linked applications the reader may not see, and
 * the empty sentence only when nothing is linked at all.
 *
 * @spec openspec/changes/architecture-process-mapping/specs/business-process-mapping/spec.md#requirement-req-bpm-002-a-step-shall-name-the-applications-in-use-that-support-it
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const store = vi.hoisted(() => ({ fetchObject: vi.fn() }))

vi.mock('../../src/store/store.js', () => ({ objectStore: store }))
vi.mock('../../src/utils/objectTypes.js', () => ({ ensureObjectTypeOrSlug: vi.fn(), ensureSettingsLoaded: vi.fn(async () => {}) }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one, many, count) => (count === 1 ? one : many).replace('%n', String(count)),
}))
vi.mock('@nextcloud/vue', () => ({
	NcLoadingIcon: { props: ['name'], template: '<span class="loading">{{ name }}</span>' },
	NcNoteCard: { props: ['type'], template: '<div class="note"><slot /></div>' },
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnIcon: { template: '<i />' },
	CnWidgetWrapper: { template: '<div><slot /></div>' },
}))

const ProcessStepUsages = (await import('../../src/views/architecture/ProcessStepUsages.vue')).default

const RouterLink = { props: ['to'], template: '<a class="link" :data-to="JSON.stringify(to)"><slot /></a>' }

/**
 * Mount the step's applications and let them load.
 *
 * @param {object} rows The objects by "type:id"; a missing one is not visible.
 * @return {Promise<object>} The wrapper.
 */
async function mountIt(rows) {
	store.fetchObject.mockImplementation(async (type, id) => {
		if (rows[type + ':' + id] === 'error') {
			throw new Error('403')
		}
		return rows[type + ':' + id] ?? null
	})
	const wrapper = mount(ProcessStepUsages, {
		props: { objectId: 'step-1' },
		global: { components: { RouterLink } },
	})
	await flushPromises()
	return wrapper
}

describe('ProcessStepUsages', () => {
	beforeEach(() => {
		store.fetchObject.mockReset()
	})

	it('links each visible application and counts the ones the reader may not see', async () => {
		const wrapper = await mountIt({
			'processStep:step-1': { usages: ['u1', { id: 'u2' }, 'u3', 'u1', ''] },
			'usage:u1': { '@self': { name: 'Zaaksysteem' } },
			'usage:u2': { title: 'Documentbeheer' },
			'usage:u3': 'error',
		})

		expect(store.fetchObject.mock.calls).toEqual([['processStep', 'step-1'], ['usage', 'u1'], ['usage', 'u2'], ['usage', 'u3']])
		const links = wrapper.findAll('.link')
		expect(links.map((link) => link.text())).toEqual(['Zaaksysteem', 'Documentbeheer'])
		expect(JSON.parse(links[0].attributes('data-to'))).toEqual({ name: 'GebruikDetail', params: { id: 'u1' } })
		expect(wrapper.find('.note').text()).toBe('1 linked application is not visible to you')
		expect(wrapper.find('.process-step-usages__empty').exists()).toBe(false)
		expect(wrapper.find('.loading').exists()).toBe(false)
	})

	it('says nothing supports the step when nothing is linked', async () => {
		const wrapper = await mountIt({ 'processStep:step-1': { usages: [] } })

		expect(wrapper.find('.process-step-usages__empty').text()).toBe('No application in use supports this step yet.')
		expect(wrapper.find('.note').exists()).toBe(false)
		expect(wrapper.findAll('.link')).toHaveLength(0)
	})

	it('does not call a step empty when every linked application is hidden', async () => {
		const wrapper = await mountIt({ 'processStep:step-1': { usages: ['u1', 'u2'] } })

		expect(wrapper.find('.process-step-usages__empty').exists()).toBe(false)
		expect(wrapper.find('.note').text()).toBe('2 linked applications are not visible to you')
	})

	it('shows the loading state, stops it after a failure, and reloads for another step', async () => {
		let arrive
		store.fetchObject.mockReturnValueOnce(new Promise((resolve) => { arrive = resolve }))
		const wrapper = mount(ProcessStepUsages, { props: { objectId: 'step-1' }, global: { components: { RouterLink } } })
		await flushPromises()
		expect(wrapper.find('.loading').text()).toBe('Loading the applications')

		arrive(null)
		await flushPromises()
		expect(wrapper.find('.loading').exists()).toBe(false)

		store.fetchObject.mockRejectedValueOnce(new Error('offline'))
		await expect(wrapper.vm.load()).rejects.toThrow('offline')
		expect(wrapper.find('.loading').exists()).toBe(false)

		store.fetchObject.mockResolvedValue(null)
		await wrapper.setProps({ objectId: 'step-2' })
		await flushPromises()
		expect(store.fetchObject).toHaveBeenLastCalledWith('processStep', 'step-2')
	})
})
