// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The process page's step flow: loading, the empty state, and the steps
 * drawn on the graph canvas with their type, risk and compliance labels, the
 * attention mark for a high risk or a failed check, and the names of the
 * applications that support each step. It reloads when the process changes.
 *
 * @spec openspec/changes/architecture-process-mapping/specs/business-process-mapping/spec.md#requirement-req-bpm-004-the-process-page-shall-show-the-steps-as-a-read-only-flow
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const store = vi.hoisted(() => ({
	fetchCollectionForOptions: vi.fn(),
	fetchObject: vi.fn(),
}))

vi.mock('../../src/store/store.js', () => ({ objectStore: store }))
vi.mock('../../src/utils/objectTypes.js', () => ({ ensureObjectTypeOrSlug: vi.fn(), ensureSettingsLoaded: vi.fn(async () => {}) }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (_, key) => String(vars[key] ?? '')),
}))
vi.mock('@nextcloud/vue', () => ({
	NcLoadingIcon: { props: ['name'], template: '<span class="loading">{{ name }}</span>' },
	NcEmptyContent: { props: ['name', 'description'], template: '<div class="empty">{{ name }} {{ description }}</div>' },
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnIcon: { template: '<i />' },
	CnWidgetWrapper: { template: '<div><slot /></div>' },
	CnGraphCanvas: {
		props: ['nodes', 'edges', 'readOnly', 'showMiniMap'],
		template: '<div class="canvas"><div v-for="node in nodes" :key="node.id" class="node"><slot name="node" :node="node" /></div></div>',
	},
}))

const ProcessStepFlow = (await import('../../src/views/architecture/ProcessStepFlow.vue')).default

const STEPS = [
	{ id: 's2', name: 'Check', position: 2, stepType: 'decision', riskLevel: 'high', complianceCheck: 'compliant', usages: ['u1', 'u2'] },
	{ id: 's1', name: 'Receive', position: 1, stepType: 'event', riskLevel: 'low', complianceCheck: 'not checked', usages: [] },
	{ id: 's3', name: 'Archive', position: 3, stepType: 'custom', complianceCheck: 'not compliant', usages: ['u1'] },
]

/**
 * Mount the flow for a process and let it load.
 *
 * @param {string} objectId The process id.
 * @return {Promise<object>} The wrapper.
 */
async function mountIt(objectId = 'p1') {
	const wrapper = mount(ProcessStepFlow, { props: { objectId } })
	await flushPromises()
	return wrapper
}

describe('ProcessStepFlow', () => {
	beforeEach(() => {
		store.fetchCollectionForOptions.mockReset()
		store.fetchObject.mockReset()
	})

	it('draws the steps in order with their labels, attention marks and applications', async () => {
		store.fetchCollectionForOptions.mockResolvedValue(STEPS)
		// u2 is not visible to this reader: its name is left out, not shown as an id.
		store.fetchObject.mockImplementation(async (type, id) => (id === 'u1' ? { name: 'Zaaksysteem' } : null))

		const wrapper = await mountIt('p1')

		expect(store.fetchCollectionForOptions).toHaveBeenCalledWith('processStep', { process: 'p1', _limit: 500 })
		expect(store.fetchObject.mock.calls).toEqual([['usage', 'u1'], ['usage', 'u2']])
		const nodes = wrapper.findAll('.node')
		expect(nodes.map((node) => node.find('strong').text())).toEqual(['Receive', 'Check', 'Archive'])

		const [receive, check, archive] = nodes
		expect(receive.text()).toContain('Event')
		expect(receive.text()).toContain('Risk: Low')
		expect(receive.text()).toContain('Compliance: Not checked')
		expect(receive.find('.process-step-flow__attention').exists()).toBe(false)
		expect(receive.find('.process-step-flow__applications').exists()).toBe(false)

		expect(check.text()).toContain('Decision')
		expect(check.text()).toContain('Risk: High')
		expect(check.find('.process-step-flow__node--attention').exists()).toBe(true)
		expect(check.find('.process-step-flow__attention').text()).toBe('Needs attention')
		expect(check.find('.process-step-flow__applications').text()).toBe('Zaaksysteem')

		// An unknown type is shown as it is; a missing risk reads as not assessed.
		expect(archive.text()).toContain('custom')
		expect(archive.text()).toContain('Risk: Not assessed')
		expect(archive.text()).toContain('Compliance: Not compliant')
		expect(archive.find('.process-step-flow__attention').exists()).toBe(true)
		expect(wrapper.find('.loading').exists()).toBe(false)
	})

	it('says there are no steps yet for a process without steps', async () => {
		store.fetchCollectionForOptions.mockResolvedValue([])

		const wrapper = await mountIt()

		expect(wrapper.find('.empty').text()).toContain('No steps yet')
		expect(wrapper.find('.canvas').exists()).toBe(false)
		expect(store.fetchObject).not.toHaveBeenCalled()
	})

	it('shows the loading state until the steps arrive, and stops loading after a failure', async () => {
		let arrive
		store.fetchCollectionForOptions.mockReturnValueOnce(new Promise((resolve) => { arrive = resolve }))
		const wrapper = mount(ProcessStepFlow, { props: { objectId: 'p1' } })
		await flushPromises()
		expect(wrapper.find('.loading').text()).toBe('Loading the steps')

		arrive([])
		await flushPromises()
		expect(wrapper.find('.loading').exists()).toBe(false)

		store.fetchCollectionForOptions.mockRejectedValueOnce(new Error('offline'))
		await expect(wrapper.vm.load()).rejects.toThrow('offline')
		await flushPromises()
		expect(wrapper.find('.loading').exists()).toBe(false)
		expect(wrapper.find('.empty').exists()).toBe(true)
	})

	it('reloads when the process changes', async () => {
		store.fetchCollectionForOptions.mockResolvedValue([])
		const wrapper = await mountIt('p1')

		await wrapper.setProps({ objectId: 'p2' })
		await flushPromises()

		expect(store.fetchCollectionForOptions.mock.calls.map((call) => call[1].process)).toEqual(['p1', 'p2'])
	})
})
