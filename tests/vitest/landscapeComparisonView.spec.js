// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The comparison page compares the plan for the organisation the roadmap
 * passed; on a scenario page it compares the scenario and offers Apply to
 * landscape only for an adopted scenario that was not applied. The roadmap's
 * Compare with the plan opens the page for its organisation.
 *
 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-002-an-information-manager-shall-compare-todays-landscape-with-the-plan-on-a-date
 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-004-a-scenario-shall-move-through-a-declared-lifecycle-and-an-adopted-scenario-shall-be-applied-to-the-landscape
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({
	CnDataTable: {
		name: 'CnDataTable',
		props: ['rows', 'columns', 'rowKey', 'emptyText'],
		template: '<table class="table-stub"><tr v-for="row in rows" :key="row.id"><td v-for="c in columns" :key="c.key">{{ row[c.key] }}</td></tr></table>',
	},
}))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('../../src/composables/useLiveCollections.js', () => ({ useLiveCollections: vi.fn() }))
vi.mock('../../src/utils/objectTypes.js', () => ({
	ensureObjectTypeOrSlug: vi.fn(),
	loadObjectTypeCollection: vi.fn(async () => undefined),
}))
vi.mock('../../src/utils/objectTypeCollection.js', () => ({ fetchObjectTypeCollection: vi.fn() }))
const store = vi.hoisted(() => ({
	objectStore: {
		collections: {},
		getCollection(type) { return this.collections[type] },
		fetchObject: null,
		saveObject: null,
		fetchCollectionForOptions: null,
	},
	navigationStore: {},
}))
vi.mock('../../src/store/store.js', () => store)

const { default: axios } = await import('@nextcloud/axios')
const { default: LandscapeComparisonView } = await import('../../src/views/architecture/LandscapeComparisonView.vue')
const { default: LifecycleRoadmapView } = await import('../../src/views/LifecycleRoadmapView.vue')
const { objectStore } = store

const REPORT = {
	today: '2026-11-21',
	date: '2027-06-30',
	landscapes: { today: [{ module: 'm1', moduleName: 'Zaaksysteem A' }], future: [{ module: 'm2', moduleName: 'Zaaksysteem B' }] },
	applications: [{ usage: 'u1', module: 'm1', moduleName: 'Zaaksysteem A', change: 'replaced', replacedBy: 'Zaaksysteem B', source: 'plan', sources: ['plan'], date: '2027-03-01' }],
	coverage: [],
	notDated: 1,
}

const NcSelectStub = {
	name: 'NcSelect',
	props: ['modelValue', 'options'],
	emits: ['update:modelValue'],
	template: '<div class="select-stub" />',
}

/**
 * Mount the comparison and let it load.
 *
 * @param {object} props  The props.
 * @param {object} query  The route query.
 * @return {Promise<object>} The wrapper.
 */
async function mountComparison(props = {}, query = {}) {
	const wrapper = mount(LandscapeComparisonView, {
		props,
		global: { stubs: { NcSelect: NcSelectStub }, mocks: { $route: { query } } },
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	objectStore.collections = { organization: { results: [{ uuid: 'o1', name: 'Gemeente A' }, { uuid: 'o2', name: 'Gemeente B' }] } }
	objectStore.settings = {}
	objectStore.fetchObject = vi.fn()
	objectStore.saveObject = vi.fn()
	objectStore.fetchCollectionForOptions = vi.fn()
	axios.get.mockReset()
	axios.get.mockResolvedValue({ data: REPORT })
})

describe('the comparison page', () => {
	it('compares the plan for the organisation the roadmap passed', async () => {
		const wrapper = await mountComparison({}, { organisation: 'o2' })

		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/stackiq/api/landscape-comparison', { params: { organisation: 'o2' } })
		expect(wrapper.get('[data-testid="landscape-today"]').text()).toContain('Zaaksysteem A')
		expect(wrapper.get('[data-testid="landscape-future"]').text()).toContain('Zaaksysteem B')
		expect(wrapper.get('[data-testid="landscape-applications"]').text()).toContain('Replaced by Zaaksysteem B')
		expect(wrapper.get('[data-testid="landscape-applications"]').text()).toContain('Plan')
		expect(wrapper.get('[data-testid="landscape-not-dated"]').text()).toContain('1 application in use has no lifecycle date')
		expect(wrapper.find('[data-testid="apply-scenario"]').exists()).toBe(false)
	})

	it('waits for an organisation when several are offered and none is passed', async () => {
		const wrapper = await mountComparison()

		expect(axios.get).not.toHaveBeenCalled()
		expect(wrapper.get('.empty-content-stub').attributes('name')).toBe('Select an organisation')
	})

	it('shows the refusal of the endpoint', async () => {
		axios.get.mockRejectedValue({ response: { data: { message: 'Not authorised for this organisation' } } })
		const wrapper = await mountComparison({}, { organisation: 'o1' })

		expect(wrapper.get('.note-card-stub').text()).toBe('Not authorised for this organisation')
	})
})

describe('the comparison on a scenario page', () => {
	it('compares the scenario of its organisation and offers the apply when adopted', async () => {
		objectStore.fetchObject.mockResolvedValue({ id: 's1', organisation: { id: 'o1' }, status: 'adopted', targetDate: '2027-06-30' })
		const wrapper = await mountComparison({ objectId: 's1' })

		expect(objectStore.fetchObject).toHaveBeenCalledWith('scenario', 's1')
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/stackiq/api/landscape-comparison', { params: { organisation: 'o1', scenario: 's1' } })
		expect(wrapper.find('.select-stub').exists()).toBe(false)
		expect(wrapper.find('[data-testid="apply-scenario"]').exists()).toBe(true)
	})

	it('offers no apply for a scenario that is proposed or applied', async () => {
		objectStore.fetchObject.mockResolvedValue({ id: 's1', organisation: 'o1', status: 'adopted', appliedAt: '2026-11-20T10:00:00Z' })
		const applied = await mountComparison({ objectId: 's1' })
		expect(applied.find('[data-testid="apply-scenario"]').exists()).toBe(false)

		objectStore.fetchObject.mockResolvedValue({ id: 's1', organisation: 'o1', status: 'proposed' })
		const proposed = await mountComparison({ objectId: 's1' })
		expect(proposed.find('[data-testid="apply-scenario"]').exists()).toBe(false)
	})

	it('applies the changes, reports the count and compares again', async () => {
		const scenario = { id: 's1', organisation: 'o1', status: 'adopted', targetDate: '2027-06-30' }
		objectStore.fetchObject.mockImplementation(async (type, id) => (type === 'scenario'
			? { ...scenario }
			: { id, consumer: 'o1', module: 'm1', status: 'In production' }))
		objectStore.fetchCollectionForOptions.mockResolvedValue([{ id: 'c1', action: 'phase out', usage: 'u1' }])
		objectStore.saveObject.mockImplementation(async (type, data) => {
			if (type === 'scenario') {
				Object.assign(scenario, data)
			}
			return data
		})
		const wrapper = await mountComparison({ objectId: 's1' })

		await wrapper.get('[data-testid="apply-scenario"]').trigger('click')
		await flushPromises()

		expect(objectStore.fetchCollectionForOptions).toHaveBeenCalledWith('scenarioChange', { scenario: 's1', _limit: 500 })
		expect(objectStore.saveObject).toHaveBeenCalledWith('usage', expect.objectContaining({ id: 'u1', startDateOutPhased: '2027-06-30' }))
		expect(objectStore.saveObject).toHaveBeenCalledWith('scenarioChange', expect.objectContaining({ id: 'c1', appliedTo: 'u1' }))
		expect(wrapper.text()).toContain('Applied 1 change to the landscape.')
		expect(axios.get).toHaveBeenCalledTimes(2)
		expect(wrapper.find('[data-testid="apply-scenario"]').exists()).toBe(false)
	})

	it('names the change a failed apply stopped at', async () => {
		objectStore.fetchObject.mockImplementation(async (type) => (type === 'scenario'
			? { id: 's1', organisation: 'o1', status: 'adopted', targetDate: '2027-06-30' }
			: null))
		objectStore.fetchCollectionForOptions.mockResolvedValue([{ id: 'c1', action: 'phase out', usage: 'u1', note: 'Schuldhulp' }])
		const wrapper = await mountComparison({ objectId: 's1' })

		await wrapper.get('[data-testid="apply-scenario"]').trigger('click')
		await flushPromises()

		expect(wrapper.text()).toContain('Applying stopped at the change "Schuldhulp"')
		expect(objectStore.saveObject).not.toHaveBeenCalled()
	})
})

describe('the roadmap button', () => {
	it('opens the comparison for the chosen organisation', async () => {
		const push = vi.fn()
		const wrapper = mount(LifecycleRoadmapView, {
			global: { stubs: { NcSelect: NcSelectStub }, mocks: { $router: { push } } },
		})
		await flushPromises()
		const button = wrapper.get('[data-testid="compare-with-plan"]')
		expect(button.attributes('disabled')).toBeDefined()

		const select = wrapper.findComponent(NcSelectStub)
		select.vm.$emit('update:modelValue', select.props('options')[1])
		await flushPromises()
		await wrapper.get('[data-testid="compare-with-plan"]').trigger('click')

		expect(push).toHaveBeenCalledWith({ name: 'LandscapeComparison', query: { organisation: 'o2' } })
	})
})
