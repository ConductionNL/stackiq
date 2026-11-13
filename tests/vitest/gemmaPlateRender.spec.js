// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for what the GEMMA plate renders: the empty, error and no-model
 * states, the download link, the truncation warning and hint, the filters
 * and the detail panel of a picked component.
 *
 * @spec openspec/changes/architecture-reference-component-coverage/specs/reference-component-coverage/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn() }))
const store = vi.hoisted(() => ({ organisations: [] }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m)),
}))
vi.mock('../../src/composables/useLiveCollections.js', () => ({ useLiveCollections: vi.fn() }))
vi.mock('../../src/store/store.js', () => ({
	objectStore: { getCollection: () => ({ results: store.organisations }) },
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnKpiGrid: { template: '<div class="kpi"><slot /></div>' },
	CnStatsBlock: { props: ['title', 'count', 'countLabel'], template: '<div class="stat">{{ title }}={{ count }}<span v-if="countLabel"> ({{ countLabel }})</span></div>' },
	CnStatusBadge: { props: ['label', 'variant'], template: '<span class="badge" :data-variant="variant">{{ label }}</span>' },
}))

const { default: GemmaPlate } = await import('../../src/views/organisaties/GemmaPlate.vue')

const stubs = {
	NcButton: {
		props: ['href', 'variant', 'pressed'],
		emits: ['click'],
		template: '<a v-if="href" :href="href"><slot /></a><button v-else :class="variant" @click="$emit(\'click\')"><slot /></button>',
	},
	NcEmptyContent: { props: ['name', 'description'], template: '<div class="empty">{{ name }}</div>' },
	NcLoadingIcon: { template: '<i class="loading" />' },
	NcNoteCard: { props: ['type'], template: '<div class="note" :data-type="type"><slot /></div>' },
	NcSelect: true,
	Download: true,
}

const coverage = {
	truncated: true,
	total: 250,
	summary: { components: 3, covered: 1, gap: 1, fillable: 1, overlap: 1, usagesWithoutComponent: 4 },
	components: [
		{ id: 'c1', name: 'Zaakregistratie', group: 'Bedrijfsvoering', state: 'covered', applications: [{ moduleName: 'Zaaksysteem' }] },
		{ id: 'c2', name: 'Archief', group: 'Bedrijfsvoering', state: 'gap', fillable: true, fillableBy: ['DMS', 'ECM'], documentation: 'Duurzaam bewaren', applications: [] },
		{ id: 'c3', name: 'Portaal', state: 'overlap', applications: [{ moduleName: 'A' }, { moduleName: '' }, { moduleName: 'B' }] },
	],
}

/**
 * Mount the plate with one organisation, answering the coverage request.
 *
 * @param {Function} answer Resolves or rejects the request.
 * @return {Promise<object>} The wrapper.
 */
async function mountWith(answer) {
	store.organisations = [{ uuid: 'org-1', name: 'Gemeente A' }]
	http.get.mockImplementation(answer)
	const wrapper = mount(GemmaPlate, { global: { stubs } })
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	http.get.mockReset()
	store.organisations = []
})

describe('GemmaPlate rendering', () => {
	it('asks for an organisation when none is chosen', async () => {
		const wrapper = mount(GemmaPlate, { global: { stubs } })
		await flushPromises()

		expect(http.get).not.toHaveBeenCalled()
		expect(wrapper.find('.empty').text()).toBe('Select an organisation')
		expect(wrapper.find('[data-testid="gemma-plate-download"]').exists()).toBe(false)
	})

	it('shows the server message of a failed load', async () => {
		const wrapper = await mountWith(async () => { throw { response: { data: { error: 'Forbidden organisation' } } } })

		expect(wrapper.find('.note[data-type="error"]').text()).toBe('Forbidden organisation')
		expect(wrapper.find('.loading').exists()).toBe(false)
	})

	it('says the GEMMA model is missing when there are no components', async () => {
		const wrapper = await mountWith(async () => ({ data: { ...coverage, components: [] } }))

		expect(wrapper.find('[data-testid="gemma-plate-no-model"]').text()).toBe('No GEMMA model')
	})

	it('renders the summary, warnings, download link and grouped components', async () => {
		const wrapper = await mountWith(async () => ({ data: coverage }))

		expect(http.get).toHaveBeenCalledWith('/index.php/apps/stackiq/api/reference-component-coverage', { params: { organisation: 'org-1' } })
		expect(wrapper.find('a').attributes('href')).toBe('/index.php/apps/stackiq/api/reference-component-coverage?organisation=org-1&format=csv')
		expect(wrapper.find('.note[data-type="warning"]').text()).toBe('The plate covers the first applications in use of 250.')
		expect(wrapper.findAll('.stat').map((s) => s.text())).toEqual([
			'Reference components=3', 'Covered=1', 'Gaps=1 (1 fillable with an application in use)', 'Overlap=1',
		])
		expect(wrapper.find('.gemma-plate__hint').text()).toBe('4 applications in use name no reference component, so they cover nothing on this plate.')
		expect(wrapper.findAll('.gemma-plate__group-name').map((h) => h.text())).toEqual(['Bedrijfsvoering', 'Other'])
		expect(wrapper.findAll('.gemma-plate__component-apps').map((s) => s.text())).toEqual(['Zaaksysteem', 'No application', 'A, B'])
		expect(wrapper.findAll('.gemma-plate__component .badge').map((b) => [b.text(), b.attributes('data-variant')])).toEqual([
			['Covered', 'success'], ['Gap, fillable', 'error'], ['Overlap', 'warning'],
		])
	})

	it('filters components and marks the active filter', async () => {
		const wrapper = await mountWith(async () => ({ data: coverage }))
		const names = () => wrapper.findAll('.gemma-plate__component-name').map((s) => s.text())

		await wrapper.find('[data-testid="gemma-plate-filter-overlap"]').trigger('click')
		expect(names()).toEqual(['Portaal'])
		expect(wrapper.find('[data-testid="gemma-plate-filter-overlap"]').classes()).toContain('primary')

		await wrapper.find('[data-testid="gemma-plate-filter-fillable"]').trigger('click')
		expect(names()).toEqual(['Archief'])

		await wrapper.find('[data-testid="gemma-plate-filter-all"]').trigger('click')
		expect(names()).toEqual(['Zaakregistratie', 'Archief', 'Portaal'])
	})

	it('shows the detail of a picked component with who could fill it', async () => {
		const wrapper = await mountWith(async () => ({ data: coverage }))

		expect(wrapper.find('[data-testid="gemma-plate-detail"]').exists()).toBe(false)
		await wrapper.findAll('.gemma-plate__component')[1].trigger('click')

		const detail = wrapper.find('[data-testid="gemma-plate-detail"]')
		expect(detail.find('h3').text()).toBe('Archief')
		expect(detail.text()).toContain('Duurzaam bewaren')
		expect(detail.text()).toContain('Applications in use that offer it: DMS, ECM')
		expect(wrapper.findAll('.gemma-plate__component')[1].attributes('aria-pressed')).toBe('true')
		expect(wrapper.findAll('.gemma-plate__component')[0].attributes('aria-pressed')).toBe('false')
	})
})
