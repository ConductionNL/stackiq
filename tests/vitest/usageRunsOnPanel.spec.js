// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the Runs on panel on an application in use shows: the components it
 * runs on with their end of support and support state, the ones one level
 * down, and the worst state over them (operations-technology-components task 3).
 *
 * @spec openspec/changes/operations-technology-components/specs/technology-components/spec.md#requirement-req-tco-003-an-application-in-use-shall-record-the-components-it-runs-on-and-show-their-support-state
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const mockFetchObject = vi.hoisted(() => vi.fn())
const mockEnsure = vi.hoisted(() => vi.fn())

vi.mock('../../src/store/store.js', () => ({
	objectStore: { fetchObject: (...a) => mockFetchObject(...a) },
}))
vi.mock('../../src/utils/objectTypes.js', () => ({
	ensureObjectTypeOrSlug: (...a) => mockEnsure(...a),
}))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		String(text).replace(/\{(\w+)\}/g, (_, key) => String(vars?.[key] ?? `{${key}}`)),
}))

function stub(name, props = []) {
	return defineComponent({
		name,
		props: ['name', 'size', 'type', 'title', 'to', ...props],
		render() {
			return h('div', { class: name }, [this.$slots.default?.() ?? [], this.$slots['title-icon']?.() ?? []])
		},
	})
}

vi.mock('@nextcloud/vue', () => ({
	NcLoadingIcon: stub('NcLoadingIcon'),
	NcNoteCard: stub('NcNoteCard'),
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnIcon: stub('CnIcon'),
	CnWidgetWrapper: stub('CnWidgetWrapper'),
}))

const { default: UsageRunsOnPanel } = await import('../../src/components/usages/UsageRunsOnPanel.vue')

const RouterLink = stub('RouterLink')

function mountPanel(objectId = 'usage-1') {
	return mount(UsageRunsOnPanel, {
		props: { objectId },
		global: { components: { RouterLink }, stubs: { 'router-link': RouterLink } },
	})
}

const objects = {
	usage: {
		'usage-1': { runsOn: ['db', 'vm'] },
		'usage-2': { runsOn: ['new'] },
		'usage-3': { runsOn: [] },
	},
	technologyComponent: {
		db: { name: 'Database 01', endOfSupport: '2026-03-03', runsOn: ['vm'] },
		vm: { name: 'App server 01', runsOn: ['host'] },
		host: { name: 'Host 01', endOfSupport: '2099-12-31' },
		new: { name: 'Host 02', endOfSupport: '2099-12-31' },
	},
}

beforeEach(() => {
	mockFetchObject.mockReset()
	mockEnsure.mockReset()
	mockFetchObject.mockImplementation(async (type, id) => objects[type]?.[id] ?? null)
})

describe('UsageRunsOnPanel', () => {
	it('lists the demo database as past its end of support, with what it runs on underneath', async () => {
		const wrapper = mountPanel()
		await flushPromises()

		expect(mockEnsure).toHaveBeenCalledWith('technologyComponent')
		expect(mockFetchObject).toHaveBeenCalledWith('usage', 'usage-1')
		const rows = wrapper.findAll('tbody tr')
		expect(rows.map((row) => row.attributes('data-state'))).toEqual(['ended', 'unknown', 'unknown', 'supported'])
		expect(rows[0].findAll('td').map((cell) => cell.text())).toEqual(['Database 01', '2026-03-03', 'Support ended'])
		expect(rows[1].find('td').text()).toContain('Under Database 01:')
		expect(rows[3].findAll('td').map((cell) => cell.text())).toEqual(['Under App server 01:Host 01', '2099-12-31', 'Supported'])
		expect(wrapper.find('[data-testid="usage-runs-on-worst"]').text()).toBe('A component it runs on is past its end of support')
		expect(wrapper.findAllComponents(RouterLink)[0].props('to')).toEqual({ name: 'TechnologieDetail', params: { id: 'db' } })
	})

	it('says every component is supported when they are', async () => {
		const wrapper = mountPanel('usage-2')
		await flushPromises()

		expect(wrapper.find('[data-testid="usage-runs-on-worst"]').text()).toBe('Every component it runs on is supported')
		expect(wrapper.findAll('tbody tr')).toHaveLength(1)
	})

	it('says so when the application in use runs on nothing recorded', async () => {
		const wrapper = mountPanel('usage-3')
		await flushPromises()

		expect(wrapper.find('table').exists()).toBe(false)
		expect(wrapper.text()).toContain('No technology component recorded for this application in use')
	})

	it('shows an error when the usage cannot be loaded', async () => {
		mockFetchObject.mockRejectedValue(new Error('500'))
		const wrapper = mountPanel()
		await flushPromises()

		expect(wrapper.text()).toContain('The components could not be loaded.')
		expect(wrapper.find('table').exists()).toBe(false)
	})
})
