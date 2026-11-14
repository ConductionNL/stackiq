// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * SbomComponentsPanel rendered: a loading icon until the collections are in,
 * then the summary tiles, the provenance line, the upload control with the
 * chosen file, the import notes, and either the empty state or the component
 * table with its license cell and match badges.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const stubs = vi.hoisted(() => ({ collections: {}, pending: [] }))

vi.mock('@conduction/nextcloud-vue', () => ({ CnDataTable: { name: 'CnDataTable', render: () => null } }))
vi.mock('../../src/store/store.js', () => ({
	objectStore: {
		settings: {},
		getActiveObject: () => null,
		getCollection: (type) => stubs.collections[type] ?? { results: [] },
		fetchCollection: vi.fn().mockResolvedValue(undefined),
	},
}))
vi.mock('../../src/utils/objectTypeCollection.js', () => ({
	fetchObjectTypeCollection: () => new Promise((resolve) => {
		stubs.pending.push(resolve)
		if (stubs.collections.__ready === true) {
			resolve()
		}
	}),
}))

const Panel = (await import('../../src/components/sbom/SbomComponentsPanel.vue')).default

/** A table stub that renders each row's license and match cells through the panel's own slots. */
const TableStub = defineComponent({
	name: 'CnDataTable',
	props: { rows: { type: Array, default: () => [] } },
	setup(props, { slots }) {
		return () => h('table', { class: 'table-stub' }, props.rows.map((row) => h('tr', { 'data-row': row.id }, [
			h('td', { class: 'cell-name' }, row.name),
			h('td', { class: 'cell-licenses' }, slots['column-licenses']({ row })),
			h('td', { class: 'cell-match' }, slots['column-match']({ row })),
		])))
	},
})

const VERSION = 'mv-1'

/**
 * Mount the panel and let it load.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountPanel() {
	const wrapper = mount(Panel, {
		props: { objectId: VERSION },
		global: {
			stubs: {
				CnDataTable: TableStub,
				NcSelect: { name: 'NcSelect', template: '<div class="select-stub" />' },
				TrayArrowUp: true,
				PackageVariantClosed: true,
			},
		},
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	stubs.pending = []
	stubs.collections = {
		__ready: true,
		moduleVersion: { results: [{ uuid: VERSION, object: { module: 'mod-1', sbomLastImportedAt: 'vandaag', sbomFileName: 'bom.json' } }] },
		sbomComponent: {
			results: [
				{ uuid: 'c-1', object: { moduleVersion: VERSION, name: 'axios', licenses: ['MIT'], vexCveIds: ['CVE-1'] } },
				{ uuid: 'c-2', object: { moduleVersion: VERSION, name: 'zlib' } },
			],
		},
		vulnerability: { results: [{ uuid: 'v-1', object: { cveCode: 'CVE-1', name: 'axios redirect' } }, { uuid: 'v-2', object: { name: 'zlib overflow', modules: ['mod-1'] } }] },
	}
})

describe('SbomComponentsPanel rendered', () => {
	it('shows a loading icon until the collections are in', async () => {
		stubs.collections.__ready = false
		const wrapper = mount(Panel, { props: { objectId: VERSION }, global: { stubs: { CnDataTable: TableStub, NcSelect: true, TrayArrowUp: true, PackageVariantClosed: true } } })
		await flushPromises()

		expect(wrapper.find('[data-testid="sbom-summary"]').exists()).toBe(false)

		expect(stubs.pending).toHaveLength(3)
		stubs.pending.forEach((resolve) => resolve())
		await flushPromises()
		expect(wrapper.find('[data-testid="sbom-summary"]').exists()).toBe(true)
	})

	it('shows the summary tiles, provenance and the table with license text and match badges', async () => {
		const wrapper = await mountPanel()

		expect(wrapper.findAll('.sbom-summary__value').map((n) => n.text())).toEqual(['2', '1', '2'])
		expect(wrapper.findAll('.sbom-summary__tile')[2].classes()).toContain('sbom-summary__tile--warn')
		expect(wrapper.find('[data-testid="sbom-provenance"]').text()).toBe('Last imported vandaag from bom.json (CycloneDX)')
		expect(wrapper.find('[data-testid="sbom-empty"]').exists()).toBe(false)

		const axios = wrapper.find('[data-row="c-1"]')
		expect(axios.find('.cell-licenses').text()).toBe('MIT')
		expect(axios.find('[data-testid="sbom-match-confirmed"]').text()).toBe('Confirmed match')
		expect(axios.find('[data-testid="sbom-match-possible"]').exists()).toBe(false)

		const zlib = wrapper.find('[data-row="c-2"]')
		expect(zlib.find('.cell-licenses').text()).toBe('—')
		expect(zlib.find('[data-testid="sbom-match-possible"]').text()).toBe('Possible match')
	})

	it('shows the empty state and no warning without components or provenance', async () => {
		stubs.collections.sbomComponent = { results: [] }
		stubs.collections.moduleVersion = { results: [] }

		const wrapper = await mountPanel()

		expect(wrapper.find('[data-testid="sbom-empty"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="sbom-component-table"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="sbom-provenance"]').exists()).toBe(false)
		expect(wrapper.findAll('.sbom-summary__value').map((n) => n.text())).toEqual(['0', '0', '0'])
		expect(wrapper.findAll('.sbom-summary__tile')[2].classes()).not.toContain('sbom-summary__tile--warn')
	})

	it('names the chosen file with its size and enables the import only then', async () => {
		const wrapper = await mountPanel()
		const button = () => wrapper.find('[data-testid="sbom-import-button"]')
		expect(wrapper.find('.sbom-upload__file-label').text()).toContain('Choose an SBOM JSON file')
		expect(button().attributes('disabled')).toBeDefined()

		const input = wrapper.find('[data-testid="sbom-file-input"]')
		Object.defineProperty(input.element, 'files', { value: [{ name: 'bom.json', size: 2048 }] })
		await input.trigger('change')

		expect(wrapper.find('.sbom-upload__file-label').text()).toContain('bom.json')
		expect(wrapper.find('.sbom-upload__file-size').text()).toBe('2 KB')
		expect(button().attributes('disabled')).toBeUndefined()
	})

	it('shows the import notes', async () => {
		const wrapper = await mountPanel()

		await wrapper.setData({ uploadError: 'Not a CycloneDX document', uploadSuccessMessage: 'Imported 3 components.' })

		expect(wrapper.find('[data-testid="sbom-upload-error"]').text()).toBe('Not a CycloneDX document')
		expect(wrapper.find('[data-testid="sbom-upload-success"]').text()).toBe('Imported 3 components.')
	})
})
