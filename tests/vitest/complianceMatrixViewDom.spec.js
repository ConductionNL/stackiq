// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * ComplianceMatrixView, mounted: the filter-first empty states, the table a
 * selection renders (verified / claimed / none cells, the BIO-only BBN and
 * DPIA columns, the data-quality warnings), and the controls wired in the
 * template (refresh, the column and organisation pickers, the scope radios
 * and opening a cell's record).
 *
 * The stores and the type registration are doubled; the matrix itself is
 * built by the real complianceMatrix utilities.
 *
 * @spec openspec/specs/module-compliance-assessment/spec.md
 * @spec openspec/specs/bio-compliance-assessment/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/vue', async () => {
	const { h } = await import('vue')
	return {
		NcButton: {
			emits: ['click'],
			render() {
				return h('button', { class: 'nc-button', onClick: () => this.$emit('click') }, [this.$slots.icon?.(), this.$slots.default?.()])
			},
		},
		NcLoadingIcon: { render: () => h('i', { class: 'loading' }) },
		NcEmptyContent: {
			props: ['name', 'description'],
			render() {
				return h('div', { class: 'empty' }, [h('h3', this.name), h('p', this.description), this.$slots.icon?.()])
			},
		},
		NcNoteCard: { render() { return h('div', { class: 'note' }, this.$slots.default?.()) } },
		NcCheckboxRadioSwitch: {
			props: ['modelValue', 'value'],
			emits: ['update:modelValue'],
			render() {
				return h('label', { class: 'radio', 'data-value': this.value, onClick: () => this.$emit('update:modelValue', this.value) }, this.$slots.default?.())
			},
		},
		NcSelect: {
			name: 'NcSelect',
			props: ['modelValue', 'options', 'inputLabel', 'placeholder'],
			emits: ['update:modelValue'],
			render() {
				return h('div', { class: 'select', 'data-label': this.inputLabel, 'data-placeholder': this.placeholder })
			},
		},
	}
})

const store = vi.hoisted(() => ({
	objectStore: {
		collections: {},
		settings: {},
		getCollection(type) { return this.collections[type] },
		fetchCollection: null,
	},
	navigationStore: { setSelected: null },
}))
vi.mock('../../src/store/store.js', () => store)
vi.mock('../../src/composables/useLiveCollections.js', () => ({ useLiveCollections: vi.fn() }))
vi.mock('../../src/utils/objectTypeCollection.js', () => ({ registerObjectTypeFromConfig: vi.fn() }))

const { default: ComplianceMatrixView } = await import('../../src/views/ComplianceMatrixView.vue')
const { objectStore, navigationStore } = store

function COLLECTIONS () {
  return {
	module: {
		results: [
			{ uuid: 'm1', name: 'Zaaksysteem', bbnLevel: 'BBN2', dpiaStatus: 'executed' },
			{ uuid: 'm2', object: { name: 'DMS', dpiaStatus: 'required' } },
			{ uuid: 'm3', name: 'Portaal', dpiaStatus: 'not required' },
			{ uuid: 'm4', name: 'Legacy' },
		],
	},
	element: { results: [{ uuid: 's1', gemmaType: 'standard_version', name: 'StUF 3.01' }] },
	bioMeasure: { results: [{ uuid: 'b1', name: 'BIO 9.1' }] },
	usage: { results: [] },
	organization: { results: [] },
	compliancy: {
		results: [
			{ id: 'c1', module: 'm1', standard_version: 's1', evidenceReference: '/Files/proof.pdf' },
			{ id: 'c2', module: 'm2', standard_version: 's1' },
			{ id: 'c3', module: 'm1', bioMeasure: 'b1', url: 'https://example.org/audit' },
			{ id: 'c4', module: 'm3', standardGemma: 'Some standard' },
			{ id: 'c5', module: 'm4', standard_version: 's1', bioMeasure: 'b1' },
		],
	},
}
}

/**
 * Mount the view and let its load settle.
 *
 * @return {Promise<object>} The wrapper.
 */
async function open() {
	const wrapper = mount(ComplianceMatrixView)
	await flushPromises()
	return wrapper
}

const headers = (wrapper) => wrapper.findAll('thead th').map((th) => th.text())
const row = (wrapper, label) => wrapper.findAll('tbody tr').find((tr) => tr.find('th').text() === label)

describe('ComplianceMatrixView mounted', () => {
	beforeEach(() => {
		window.history.replaceState({}, '', '/')
		objectStore.collections = COLLECTIONS()
		objectStore.fetchCollection = vi.fn(async () => {})
		navigationStore.setSelected = vi.fn()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('asks for standards first, and says so when none are imported', async () => {
		const wrapper = await open()
		expect(wrapper.find('.empty h3').text()).toBe('Select standards to compare')
		expect(wrapper.find('table').exists()).toBe(false)
		expect(wrapper.find('.loading').exists()).toBe(false)

		objectStore.collections = { ...COLLECTIONS(), element: { results: [] } }
		const bare = await open()
		expect(bare.find('.empty h3').text()).toBe('No standards imported')
	})

	it('names the BIO catalogue in the empty states of the BIO scope', async () => {
		objectStore.collections = { ...COLLECTIONS(), bioMeasure: { results: [] } }
		window.history.replaceState({}, '', '/?columnSource=bioMeasure')
		const wrapper = await open()
		expect(wrapper.find('.empty h3').text()).toBe('No BIO measures seeded')
		expect(wrapper.find('.select').attributes('data-label')).toBe('BIO measures')
		expect(wrapper.find('.select').attributes('data-placeholder')).toBe('Select one or more BIO measures')
	})

	it('renders verified, claimed and none cells for the selected standard', async () => {
		window.history.replaceState({}, '', '/?standards=s1')
		const wrapper = await open()

		expect(headers(wrapper)).toEqual(['Module', 'StUF 3.01'])
		expect(row(wrapper, 'Zaaksysteem').find('td').classes()).toContain('cmv-cell--verified')
		expect(row(wrapper, 'Zaaksysteem').find('button').attributes('aria-label')).toBe('Zaaksysteem — StUF 3.01: Verified')
		expect(row(wrapper, 'DMS').find('.cmv-cellText').text()).toBe('Claimed')
		const none = row(wrapper, 'Portaal').find('.cmv-cellNone')
		expect([none.exists(), none.attributes('aria-label')]).toEqual([true, 'Portaal — StUF 3.01: Unknown'])
		expect(row(wrapper, 'Portaal').find('button').exists()).toBe(false)

		const notes = wrapper.findAll('.note').map((n) => n.text())
		expect(notes).toHaveLength(2)
		expect(notes[0]).toMatch(/could not be matched to a standard version\. They are excluded from the matrix\. \(1\)$/)
		expect(notes[1]).toMatch(/both a standard and a BIO measure.*\(1\)$/)
	})

	it('adds the BBN level and DPIA status columns in the BIO scope', async () => {
		window.history.replaceState({}, '', '/?columnSource=bioMeasure&bioMeasures=b1')
		const wrapper = await open()

		expect(headers(wrapper)).toEqual(['Module', 'BBN level', 'DPIA status', 'BIO 9.1'])
		const meta = (label) => row(wrapper, label).findAll('.cmv-cell--meta').map((td) => td.text())
		expect(meta('Zaaksysteem')).toEqual(['BBN2', 'Executed'])
		expect(meta('DMS')).toEqual(['Not set', 'Required'])
		expect(meta('Portaal')).toEqual(['Not set', 'Not required'])
		expect(meta('Legacy')).toEqual(['Not set', 'Not set'])
		expect(row(wrapper, 'Zaaksysteem').find('button .cmv-cellText').text()).toBe('Verified')
		expect(wrapper.find('.cmv-intro').text()).toMatch(/^Which applications support which BIO 2\.0 measures/)
	})

	it('opens the compliance list when a cell with a record is clicked', async () => {
		window.history.replaceState({}, '', '/?standards=s1')
		const wrapper = await open()

		await row(wrapper, 'DMS').find('button').trigger('click')
		expect(navigationStore.setSelected).toHaveBeenCalledWith('komplianties')
	})

	it('reloads every collection from the refresh button', async () => {
		const wrapper = await open()
		expect(objectStore.fetchCollection).toHaveBeenCalledTimes(6)

		await wrapper.find('.nc-button').trigger('click')
		await flushPromises()
		expect(objectStore.fetchCollection).toHaveBeenCalledTimes(12)
		expect(objectStore.fetchCollection).toHaveBeenLastCalledWith('organization', { _limit: 1000 })
	})

	it('switches to the BIO scope from its radio and writes it to the URL', async () => {
		const wrapper = await open()

		await wrapper.find('.radio[data-value="bioMeasure"]').trigger('click')
		await flushPromises()
		expect(new URL(window.location.href).searchParams.get('columnSource')).toBe('bioMeasure')
		expect(wrapper.find('.empty h3').text()).toBe('Select BIO measures to compare')
	})

	it('keeps a picked column and organisation in the URL', async () => {
		objectStore.collections.organization = { results: [{ uuid: 'o1', name: 'Gemeente A' }] }
		objectStore.collections.usage = { results: [{ consumer: 'o1', module: 'm2' }] }
		const wrapper = await open()
		const [columns, organisation] = wrapper.findAllComponents({ name: 'NcSelect' })
		const vm = wrapper.vm
		columns.vm.$emit('update:modelValue', [vm.standardOptions[0]])
		organisation.vm.$emit('update:modelValue', vm.organisationOptions[0])
		await flushPromises()

		const params = new URL(window.location.href).searchParams
		expect([params.get('standards'), params.get('org')]).toEqual(['s1', 'o1'])
		expect(wrapper.findAll('tbody tr').map((tr) => tr.find('th').text())).toEqual(['DMS'])
	})
})
