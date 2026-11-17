// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Custom reports (insight-exports-and-custom-reports): the page lists only the
 * caller's own profiles over the catalogue register, also for an admin to whom
 * OpenRegister returns every profile; a new report keeps its fields in the
 * order chosen and its filter rows as a field-to-value object; Run downloads
 * OpenRegister's file and Delete removes the profile.
 *
 * @spec openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-004-a-user-shall-define-run-and-delete-their-own-reports-over-one-catalogue-schema
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = { get: vi.fn(), post: vi.fn(), delete: vi.fn() }
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: (...args) => http.get(...args),
		post: (...args) => http.post(...args),
		delete: (...args) => http.delete(...args),
	},
}))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'anna' }) }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) =>
		'/index.php' + path.replace(/\{(\w+)\}/g, (_, key) => params[key]),
}))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('@nextcloud/vue', () => {
	const stub = (name, template) => ({ name, props: ['href', 'variant', 'disabled', 'name', 'description', 'type', 'size'], template })
	return {
		NcButton: { ...stub('NcButton', '<a class="nc-button" :href="href" @click="$emit(\'click\')"><slot /></a>'), emits: ['click'] },
		NcEmptyContent: stub('NcEmptyContent', '<div class="nc-empty">{{ name }}</div>'),
		NcLoadingIcon: stub('NcLoadingIcon', '<span class="nc-loading" />'),
		NcNoteCard: stub('NcNoteCard', '<div class="nc-note"><slot /></div>'),
		NcSelect: { name: 'NcSelect', props: ['modelValue', 'options'], emits: ['update:modelValue'], template: '<div class="nc-select" />' },
	}
})
vi.mock('../../src/modals/reports/CustomReportDialog.vue', () => ({
	default: { name: 'CustomReportDialog', props: ['registerId', 'schema', 'schemaLabel'], emits: ['created', 'close'], template: '<div class="report-dialog" />' },
}))

const {
	filtersFromRows,
	ownReports,
	profileFromForm,
	reportFormSchema,
	reportableFields,
	rowsOf,
	runUrl,
} = await import('../../src/views/reports/customReports.js')
const { default: CustomReportsView } = await import('../../src/views/reports/CustomReportsView.vue')

const moduleSchema = {
	id: 12,
	slug: 'module',
	title: 'Module',
	properties: {
		name: { type: 'string', title: 'Name' },
		provider: { type: 'string', title: 'Provider' },
		licentietype: { type: 'string' },
		logo: { type: 'file', title: 'Logo' },
		internal: { type: 'string', visible: false },
	},
}

const profiles = [
	{ id: 1, owner: 'anna', registerId: 7, schemaId: 12, name: 'Applications per supplier', format: 'csv' },
	{ id: 2, owner: 'bert', registerId: 7, schemaId: 12, name: 'Bert report', format: 'csv' },
	{ id: 3, owner: 'anna', registerId: 99, schemaId: 50, name: 'Other register', format: 'json' },
]

describe('customReports helpers', () => {
	it('keeps only the caller own profiles over the catalogue register', () => {
		expect(ownReports(profiles, { userId: 'anna', registerId: '7' }).map((profile) => profile.id)).toEqual([1])
		expect(ownReports(profiles, { userId: '', registerId: 7 })).toEqual([])
		expect(ownReports(null, { userId: 'anna', registerId: 7 })).toEqual([])
	})

	it('offers the schema properties in order, without files and hidden ones', () => {
		expect(reportableFields(moduleSchema)).toEqual([
			{ key: 'name', label: 'Name' },
			{ key: 'provider', label: 'Provider' },
			{ key: 'licentietype', label: 'licentietype' },
		])
		expect(reportableFields(null)).toEqual([])
	})

	it('builds the form over the schema fields', () => {
		const form = reportFormSchema(moduleSchema, (text) => text)
		expect(form.properties.fields.items.enum).toEqual(['name', 'provider', 'licentietype'])
		expect(form.properties.filters['x-widget']).toBe('sub-objects')
		expect(form.properties.filters.items.properties.field.enum).toEqual(['name', 'provider', 'licentietype'])
		expect(form.properties.format.enum).toEqual(['csv', 'json'])
		expect(form.required).toEqual(['name', 'fields', 'valueMode', 'format'])
	})

	it('stores the fields in the order chosen and the filter rows as an object', () => {
		const profile = profileFromForm(
			{
				name: '  Applications per supplier ',
				fields: ['name', 'provider', 'licentietype'],
				valueMode: 'rendered',
				format: 'csv',
				filters: [{ field: 'bbnLevel', value: 'BBN2' }, { field: '', value: 'x' }, { field: 'dpia', value: '' }],
			},
			{ registerId: '7', schemaId: 12 },
		)
		expect(profile).toEqual({
			name: 'Applications per supplier',
			registerId: 7,
			schemaId: 12,
			fields: ['name', 'provider', 'licentietype'],
			valueMode: 'rendered',
			format: 'csv',
			filters: { bbnLevel: 'BBN2' },
		})
		expect(profileFromForm({ format: 'xlsx', valueMode: 'raw' }, { registerId: 7, schemaId: 12 })).toMatchObject({
			format: 'csv',
			valueMode: 'stored',
			fields: [],
			filters: null,
		})
		expect(filtersFromRows('nope')).toBeNull()
	})

	it('names the run URL and unwraps list answers', () => {
		expect(runUrl((path, params) => path.replace('{id}', params.id), 5)).toBe('/apps/openregister/api/export-profiles/5/run')
		expect(rowsOf({ results: [1] })).toEqual([1])
		expect(rowsOf([2])).toEqual([2])
		expect(rowsOf(null)).toEqual([])
	})
})

describe('CustomReportsView', () => {
	beforeEach(() => {
		http.get.mockReset()
		http.delete.mockReset()
		http.get.mockImplementation((url) => Promise.resolve({
			data: url.includes('export-profiles') ? { results: profiles } : { results: [moduleSchema] },
		}))
	})

	const mountView = () => mount(CustomReportsView, {
		props: { register: 7, schemas: [{ slug: 'module', label: 'Applications' }, { slug: 'nothere', label: 'Missing' }] },
		global: { mocks: { t: (app, text) => text } },
	})

	it('lists only the caller own reports, with Run pointing at OpenRegister', async () => {
		const wrapper = mountView()
		await flushPromises()

		const rows = wrapper.findAll('[data-testid="custom-report-row"]')
		expect(rows).toHaveLength(1)
		expect(rows[0].text()).toContain('Applications per supplier')
		expect(rows[0].text()).toContain('Applications')
		expect(rows[0].find('[data-testid="custom-report-run"]').attributes('href')).toBe('/index.php/apps/openregister/api/export-profiles/1/run')
		expect(wrapper.vm.schemaOptions).toEqual([{ slug: 'module', label: 'Applications' }])
	})

	it('deletes a report and drops it from the list', async () => {
		http.delete.mockResolvedValue({ data: { deleted: true } })
		const wrapper = mountView()
		await flushPromises()

		await wrapper.find('[data-testid="custom-report-delete"]').trigger('click')
		await flushPromises()

		expect(http.delete).toHaveBeenCalledWith('/index.php/apps/openregister/api/export-profiles/1')
		expect(wrapper.findAll('[data-testid="custom-report-row"]')).toHaveLength(0)
	})

	it('shows an error when loading fails', async () => {
		http.get.mockRejectedValue(new Error('500'))
		const wrapper = mountView()
		await flushPromises()

		expect(wrapper.find('.nc-note').text()).toBe('The reports could not be loaded.')
	})
})
