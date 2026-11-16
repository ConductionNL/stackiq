// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The new-report dialog (insight-exports-and-custom-reports) stores the form
 * as the caller's OpenRegister export profile over the chosen schema, and
 * reports a refusal in the dialog.
 *
 * @spec openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-004-a-user-shall-define-run-and-delete-their-own-reports-over-one-catalogue-schema
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const post = vi.fn()
const setResult = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { post: (...args) => post(...args) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnFormDialog: {
		name: 'CnFormDialog',
		props: ['item', 'schema', 'dialogTitle', 'successText', 'recoverDraft'],
		emits: ['create', 'close'],
		methods: { setResult: (...args) => setResult(...args) },
		template: '<div class="form-dialog" />',
	},
}))

const { default: CustomReportDialog } = await import('../../src/modals/reports/CustomReportDialog.vue')

const schema = { id: 12, slug: 'module', properties: { name: { type: 'string' }, provider: { type: 'string' } } }

describe('CustomReportDialog', () => {
	beforeEach(() => {
		post.mockReset()
		setResult.mockReset()
	})

	it('renders the form over the schema fields and stores the report as a profile', async () => {
		post.mockResolvedValue({ data: { id: 9, name: 'R' } })
		const wrapper = mount(CustomReportDialog, { props: { registerId: '7', schema, schemaLabel: 'Applications' } })

		const dialog = wrapper.findComponent({ name: 'CnFormDialog' })
		expect(dialog.props('schema').properties.fields.items.enum).toEqual(['name', 'provider'])
		expect(dialog.props('dialogTitle')).toBe('New report over {schema}')

		dialog.vm.$emit('create', { name: 'R', fields: ['provider', 'name'], valueMode: 'stored', format: 'json', filters: [] })
		await flushPromises()

		expect(post).toHaveBeenCalledWith('/index.php/apps/openregister/api/export-profiles', {
			name: 'R',
			registerId: 7,
			schemaId: 12,
			fields: ['provider', 'name'],
			valueMode: 'stored',
			format: 'json',
			filters: null,
		})
		expect(setResult).toHaveBeenCalledWith({ success: true })
		expect(wrapper.emitted('created')[0]).toEqual([{ id: 9, name: 'R' }])
	})

	it('shows the refusal OpenRegister gives', async () => {
		post.mockRejectedValue({ response: { data: { error: 'name is required' } } })
		const wrapper = mount(CustomReportDialog, { props: { registerId: 7, schema } })

		wrapper.findComponent({ name: 'CnFormDialog' }).vm.$emit('create', {})
		await flushPromises()

		expect(setResult).toHaveBeenCalledWith({ error: 'name is required' })
		expect(wrapper.emitted('created')).toBeUndefined()
	})
})
