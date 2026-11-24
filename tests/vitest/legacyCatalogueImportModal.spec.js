// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The dialog that brings over an export from the previous Softwarecatalogus:
 * the file step posts for a preview and writes nothing, the preview starts
 * with every row it may bring over checked, the rows unchecked are sent as
 * `leftOut`, and the result shows the counts and the skipped rows.
 *
 * @spec openspec/changes/archive/2026-11-24-landscape-migrate-from-previous-catalogue/tasks.md#task-4
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import LegacyCatalogueImportModal from '../../src/modals/import/LegacyCatalogueImportModal.vue'
import {
	decisionLabel,
	leftOutIds,
	legacyImportError,
	legacyImportForm,
	selectableIds,
	skipReasonLabel,
} from '../../src/utils/legacyImport.js'

vi.mock(
	'@nextcloud/axios',
	() => ({
		__esModule: true,
		default: { get: vi.fn(), post: vi.fn() },
	}),
)
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url) => url,
}))
vi.mock(
	'@nextcloud/vue',
	async () => {
		const { h } = await import('vue')
		return {
			NcDialog: {
				props: { name: { default: '' } },
				render() {
					return h('div', { role: 'dialog', 'aria-label': this.name }, this.$slots.default?.())
				},
			},
			NcButton: {
				props: { disabled: { default: false }, type: { default: 'button' } },
				emits: ['click'],
				render() {
					return h(
						'button',
						{ disabled: this.disabled, type: this.type, onClick: (event) => this.$emit('click', event) },
						this.$slots.default?.(),
					)
				},
			},
			NcCheckboxRadioSwitch: {
				props: { modelValue: { default: () => [] }, value: { default: '' } },
				emits: ['update:modelValue'],
				render() {
					return h('input', {
						type: 'checkbox',
						checked: this.modelValue.includes(this.value),
						onChange: (event) => this.$emit(
							'update:modelValue',
							event.target.checked
								? [...this.modelValue, this.value]
								: this.modelValue.filter((id) => id !== this.value),
						),
					})
				},
			},
			NcLoadingIcon: { render: () => null },
		}
	},
)

const ROWS = [
	{
		sheet: 'Applicaties', row: 2, legacyId: 'swc-1', skipped: false, errors: [],
		supplier: { name: 'Voorbeeld Software B.V.', decision: 'matched' },
		product: { name: 'Zaaksysteem Voorbeeld', decision: 'matched' },
		version: { number: '4.2', decision: 'create' },
	},
	{
		sheet: 'Applicaties', row: 3, legacyId: 'swc-2', skipped: false, errors: [],
		supplier: { name: 'Nieuw B.V.', decision: 'create' },
		product: { name: 'Nieuw Pakket', decision: 'create' },
		version: { number: '', decision: 'none' },
	},
	{
		sheet: 'Applicaties', row: 4, legacyId: 'swc-3', skipped: true,
		errors: ['Required source field "Leverancier" is missing or empty'],
		supplier: { name: '', decision: 'none' },
		product: { name: 'Zonder', decision: 'none' },
		version: { number: '', decision: 'none' },
	},
]

/**
 * Mount the open dialog and pick a file.
 *
 * @return {Promise<object>} The wrapper.
 */
async function openWithFile() {
	const wrapper = mount(LegacyCatalogueImportModal, { props: { show: true } })
	const input = wrapper.find('input[type="file"]')
	const file = new File(['x'], 'export.xlsx')
	Object.defineProperty(input.element, 'files', { value: [file] })
	await input.trigger('change')
	return wrapper
}

afterEach(() => {
	vi.clearAllMocks()
})

describe('legacyImport helpers', () => {
	it('offers only rows the preview did not skip, and leaves out the unchecked ones', () => {
		expect(selectableIds(ROWS)).toEqual(['swc-1', 'swc-2'])
		expect(leftOutIds(ROWS, ['swc-2'])).toEqual(['swc-1'])
	})

	it('sends leftOut as a JSON array only when applying', () => {
		const file = new File(['x'], 'export.xlsx')
		expect(legacyImportForm(file).has('leftOut')).toBe(false)
		expect(legacyImportForm(file, ['swc-1']).get('leftOut')).toBe('["swc-1"]')
	})

	it('names the missing column, and words decisions and reasons', () => {
		const error = { response: { data: { error: 'MISSING_COLUMN', details: { column: 'Leverancier' } } } }
		expect(legacyImportError(error)).toContain('Leverancier is missing')
		expect(decisionLabel('matched')).toBe('matched')
		expect(decisionLabel('none')).toBe('—')
		expect(skipReasonLabel('left-out')).toBe('Left out in the preview')
		expect(skipReasonLabel('usage-not-saved')).toBe('The record could not be saved.')
	})
})

describe('LegacyCatalogueImportModal', () => {
	it('previews, sends the unchecked rows as leftOut and shows the result', async () => {
		axios.post
			.mockResolvedValueOnce({ data: { rows: ROWS, summary: {} } })
			.mockResolvedValueOnce({
				data: {
					created: 1,
					updated: 0,
					skipped: 2,
					skippedRows: [{ row: 2, legacyId: 'swc-1', reason: 'left-out' }, { row: 4, legacyId: 'swc-3', reason: 'mapping' }],
				},
			})
		const wrapper = await openWithFile()

		await wrapper.find('form').trigger('submit')
		await flushPromises()
		expect(axios.post.mock.calls[0][0]).toBe('/apps/stackiq/api/legacy-import/preview')
		expect(axios.post.mock.calls[0][1].has('leftOut')).toBe(false)

		const preview = wrapper.find('[data-testid="legacy-import-preview"]')
		expect(preview.text()).toContain('matched')
		expect(preview.text()).toContain('create')
		const boxes = preview.findAll('input[type="checkbox"]')
		expect(boxes).toHaveLength(2)
		await boxes[0].setValue(false)

		const importButton = wrapper.findAll('button').find((button) => button.text() === 'Import')
		await importButton.trigger('click')
		await flushPromises()

		expect(axios.post.mock.calls[1][0]).toBe('/apps/stackiq/api/legacy-import/apply')
		expect(axios.post.mock.calls[1][1].get('leftOut')).toBe('["swc-1"]')
		const result = wrapper.find('[data-testid="legacy-import-result"]')
		expect(result.text()).toContain('created 1')
		expect(result.text()).toContain('skipped 2')
		expect(result.text()).toContain('swc-3: A required value is empty')
		expect(wrapper.emitted('imported')).toHaveLength(1)
	})

	it('shows a refused file as an alert and stays on the file step', async () => {
		axios.post.mockRejectedValueOnce({ response: { data: { error: 'MISSING_COLUMN', details: { column: 'Leverancier' } } } })
		const wrapper = await openWithFile()

		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toContain('missing')
		expect(wrapper.find('[data-testid="legacy-import-preview"]').exists()).toBe(false)
		expect(wrapper.find('input[type="file"]').exists()).toBe(true)
	})
})
