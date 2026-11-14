// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * GemmaPlate as rendered. The logic spec drives loadCoverage and the helpers
 * on a bare context; this mounts the plate and pins what a user sees: with a
 * single organisation it loads at once and shows the summary and the grouped
 * components with their applications and state, a filter narrows the plate,
 * a component opens its detail (with who could fill a gap), a truncated
 * answer warns, a model-less answer says so, and a failed load shows why.
 *
 * @spec openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md#requirement-req-rcc-002-a-gemma-plate-shall-show-the-components-with-their-applications-with-filters-for-gaps-and-overlaps
 */

import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnKpiGrid: { template: '<div class="kpis"><slot /></div>' },
	CnStatsBlock: { props: ['title', 'count'], template: '<div class="stat">{{ title }}={{ count }}</div>' },
	CnStatusBadge: { props: ['label', 'variant'], template: '<span class="badge" :data-variant="variant">{{ label }}</span>' },
}))
vi.mock('../../src/composables/useLiveCollections.js', () => ({ useLiveCollections: vi.fn() }))
const store = vi.hoisted(() => ({ objectStore: { collections: {}, getCollection(type) { return this.collections[type] } } }))
vi.mock('../../src/store/store.js', () => store)

const { default: GemmaPlate } = await import('../../src/views/organisaties/GemmaPlate.vue')

const stubs = {
	NcButton: {
		props: ['variant', 'href'],
		emits: ['click'],
		template: '<button :data-variant="variant" :data-href="href" v-bind="$attrs" @click="$emit(\'click\')"><slot /></button>',
	},
	NcSelect: { template: '<div class="org-select" />' },
	NcLoadingIcon: { template: '<i class="loading" />' },
	NcNoteCard: { props: ['type'], template: '<div class="note" :data-type="type"><slot /></div>' },
	NcEmptyContent: { props: ['name'], template: '<div class="empty" v-bind="$attrs">{{ name }}</div>' },
}

const COVERAGE = {
	truncated: false,
	total: 3,
	summary: { components: 3, covered: 1, gap: 1, fillable: 1, overlap: 1, usagesWithoutComponent: 2 },
	components: [
		{ id: 'c1', name: 'Zaakregistratie', group: 'Zaak', state: 'covered', applications: [{ moduleName: 'Zaaksysteem' }] },
		{ id: 'c2', name: 'Archief', group: 'Zaak', state: 'gap', fillable: true, fillableBy: ['DMS', 'ECM'], applications: [], documentation: 'Keeps records.' },
		{ id: 'c3', name: 'Portaal', state: 'overlap', applications: [{ moduleName: 'A' }, { moduleName: 'B' }] },
	],
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

/**
 * Mount with one organisation so the plate loads at once.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountPlate() {
	const wrapper = mount(GemmaPlate, { global: { stubs } })
	await flush()
	await wrapper.vm.$nextTick()
	return wrapper
}

const componentNames = (wrapper) => wrapper.findAll('.gemma-plate__component-name').map((n) => n.text())

describe('GemmaPlate rendering, single-organisation cases', () => {
	beforeEach(() => {
		store.objectStore.collections = { organization: { results: [{ uuid: 'org-1', name: 'Gemeente A' }] } }
		http.get.mockReset()
		http.get.mockResolvedValue({ data: structuredClone(COVERAGE) })
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('loads the only organisation at once and shows its summary and grouped plate', async () => {
		const wrapper = await mountPlate()

		expect(http.get).toHaveBeenCalledWith('/index.php/apps/stackiq/api/reference-component-coverage', { params: { organisation: 'org-1' } })
		expect(wrapper.findAll('.stat').map((s) => s.text())).toEqual([
			'Reference components=3', 'Covered=1', 'Gaps=1', 'Overlap=1',
		])
		expect(wrapper.find('.gemma-plate__hint').text()).toBe('2 applications in use name no reference component, so they cover nothing on this plate.')
		expect(wrapper.findAll('.gemma-plate__group-name').map((g) => g.text())).toEqual(['Zaak', 'Other'])
		expect(componentNames(wrapper)).toEqual(['Zaakregistratie', 'Archief', 'Portaal'])
		expect(wrapper.findAll('.gemma-plate__component-apps').map((a) => a.text())).toEqual(['Zaaksysteem', 'No application', 'A, B'])
		expect(wrapper.findAll('.gemma-plate__component .badge').map((b) => [b.text(), b.attributes('data-variant')])).toEqual([
			['Covered', 'success'], ['Gap, fillable', 'error'], ['Overlap', 'warning'],
		])
		expect(wrapper.find('[data-testid="gemma-plate-download"]').attributes('data-href'))
			.toBe('/index.php/apps/stackiq/api/reference-component-coverage?organisation=org-1&format=csv')
	})

	it('narrows the plate with a filter', async () => {
		const wrapper = await mountPlate()

		await wrapper.find('[data-testid="gemma-plate-filter-overlap"]').trigger('click')
		expect(componentNames(wrapper)).toEqual(['Portaal'])
		expect(wrapper.find('[data-testid="gemma-plate-filter-overlap"]').attributes('data-variant')).toBe('primary')

		await wrapper.find('[data-testid="gemma-plate-filter-fillable"]').trigger('click')
		expect(componentNames(wrapper)).toEqual(['Archief'])
	})

	it('opens a component in the detail pane with who could fill the gap', async () => {
		const wrapper = await mountPlate()
		expect(wrapper.find('[data-testid="gemma-plate-detail"]').exists()).toBe(false)

		const archief = wrapper.findAll('.gemma-plate__component')[1]
		await archief.trigger('click')

		const detail = wrapper.find('[data-testid="gemma-plate-detail"]')
		expect(detail.find('h3').text()).toBe('Archief')
		expect(detail.text()).toContain('Keeps records.')
		expect(detail.text()).toContain('Applications in use that offer it: DMS, ECM')
		expect(archief.attributes('aria-pressed')).toBe('true')
		expect(wrapper.findAll('.gemma-plate__component')[0].attributes('aria-pressed')).toBe('false')
	})

	it('warns when the plate was truncated', async () => {
		http.get.mockResolvedValue({ data: { ...structuredClone(COVERAGE), truncated: true, total: 900 } })

		const wrapper = await mountPlate()

		expect(wrapper.find('.note[data-type="warning"]').text()).toBe('The plate covers the first applications in use of 900.')
	})

	it('says so when no GEMMA model is imported', async () => {
		http.get.mockResolvedValue({ data: { ...structuredClone(COVERAGE), components: [] } })

		const wrapper = await mountPlate()

		expect(wrapper.find('[data-testid="gemma-plate-no-model"]').text()).toBe('No GEMMA model')
		expect(wrapper.find('[data-testid="gemma-plate-groups"]').exists()).toBe(false)
	})

	it('shows why a load failed, and asks for an organisation when there are several', async () => {
		http.get.mockRejectedValue({ response: { data: { message: 'Not a member' } } })
		const failed = await mountPlate()
		expect(failed.find('.note[data-type="error"]').text()).toBe('Not a member')
		expect(failed.find('[data-testid="gemma-plate-download"]').exists()).toBe(false)

		http.get.mockClear()
		store.objectStore.collections = { organization: { results: [{ uuid: 'a', name: 'A' }, { uuid: 'b', name: 'B' }] } }
		const several = await mountPlate()
		expect(http.get).not.toHaveBeenCalled()
		expect(several.find('.empty').text()).toBe('Select an organisation')
	})
})
