// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * landscape-change-entry-type: the allowed changes, the dropped-field rows,
 * the type-change API calls and the dialog's Confirm rule.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const axiosGet = vi.fn()
const axiosPost = vi.fn()

vi.mock('@nextcloud/axios', () => ({ default: { get: (...a) => axiosGet(...a), post: (...a) => axiosPost(...a) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path, params) => '/index.php' + path.replace('{uuid}', params?.uuid ?? '') }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text, vars) => text.replace(/\{(\w+)\}/g, (m, k) => vars?.[k] ?? m) }))

const { changeType, droppedRows, moduleEntryType, previewTypeChange, targetsOf } = await import('../../src/components/entries/entryTypes.js')
const { default: ChangeEntryTypeDialog } = await import('../../src/dialogs/ChangeEntryTypeDialog.vue')

describe('entry types', () => {
	it('reads the type of a module and the changes it may make', () => {
		expect(moduleEntryType({ type: 'System software' })).toBe('systemSoftware')
		expect(moduleEntryType({ type: 'Application' })).toBe('application')
		expect(moduleEntryType(null)).toBe('application')
		expect(targetsOf('application')).toEqual(['service', 'systemSoftware'])
		expect(targetsOf('systemSoftware')).toEqual(['application'])
		expect(targetsOf('unknown')).toEqual([])
	})

	it('shows a dropped value as text', () => {
		expect(droppedRows({ licentietype: 'Closed source', standards: ['A', 'B'] })).toEqual([
			{ field: 'licentietype', value: 'Closed source' },
			{ field: 'standards', value: '["A","B"]' },
		])
	})

	it('calls the type-change routes', async () => {
		axiosGet.mockResolvedValue({ data: { carried: ['name'] } })
		axiosPost.mockResolvedValue({ data: { moved: true } })

		expect(await previewTypeChange('u-1', 'service')).toEqual({ carried: ['name'] })
		expect(axiosGet).toHaveBeenCalledWith('/index.php/apps/stackiq/api/entries/u-1/type-change', { params: { target: 'service' } })
		expect(await changeType('u-1', 'service', ['SaaS'])).toEqual({ moved: true })
		expect(axiosPost).toHaveBeenCalledWith('/index.php/apps/stackiq/api/entries/u-1/type-change', { target: 'service', serviceTypes: ['SaaS'] })
	})
})

describe('ChangeEntryTypeDialog', () => {
	beforeEach(() => {
		axiosGet.mockReset()
		axiosPost.mockReset()
	})

	const mountDialog = () => mount(ChangeEntryTypeDialog, {
		props: { show: true, uuid: 'u-1', currentType: 'application' },
		global: {
			stubs: {
				NcDialog: { template: '<div><slot /><slot name="actions" /></div>' },
				NcButton: { props: ['disabled'], template: '<button :disabled="disabled"><slot /></button>' },
				NcSelect: true,
				NcLoadingIcon: true,
				NcNoteCard: { template: '<div class="note"><slot /></div>' },
			},
		},
	})

	it('lists the dropped licence type with its value and needs a service type before Confirm', async () => {
		axiosGet.mockResolvedValue({ data: { carried: ['name', 'provider'], dropped: { licentietype: 'Closed source' }, blockers: [], serviceTypes: ['SaaS'] } })

		const wrapper = mountDialog()
		await flushPromises()

		expect(axiosGet.mock.calls[0][1]).toEqual({ params: { target: 'service' } })
		expect(wrapper.text()).toContain('licentietype: Closed source')
		expect(wrapper.vm.canConfirm).toBe(false)
		wrapper.vm.serviceTypes = ['SaaS']
		expect(wrapper.vm.canConfirm).toBe(true)
	})

	it('names the blockers and keeps Confirm disabled', async () => {
		axiosGet.mockResolvedValue({ data: { carried: [], dropped: {}, blockers: [{ schema: 'usage', field: 'module', count: 2 }], serviceTypes: ['SaaS'] } })

		const wrapper = mountDialog()
		await flushPromises()
		wrapper.vm.serviceTypes = ['SaaS']

		expect(wrapper.text()).toContain('2 × usage (module)')
		expect(wrapper.text()).toContain('Other entries still point at this one')
		expect(wrapper.vm.canConfirm).toBe(false)
	})
})
