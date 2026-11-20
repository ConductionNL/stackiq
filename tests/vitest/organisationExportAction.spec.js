// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The organisation detail page's Export as ArchiMate action
 * (insight-exports-and-custom-reports): it shows only on the server's yes,
 * hides on a no or a failure, and links to the organisation's own export.
 *
 * @spec openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-003-a-member-of-an-organisation-must-be-able-to-export-that-organisation-as-archimate-from-its-detail-page-and-only-that-organisation
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const get = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { get: (...args) => get(...args) } }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) =>
		'/index.php' + path.replace(/\{(\w+)\}/g, (_, key) => params[key]),
}))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: ['href', 'variant'],
		template: '<a class="nc-button" :href="href"><slot name="icon" /><slot /></a>',
	},
}))
vi.mock('vue-material-design-icons/Download.vue', () => ({ default: { render: () => null } }))

const { default: OrganisationExportAction } = await import('../../src/components/organisations/OrganisationExportAction.vue')
const {
	mayExportFrom,
	organisationExportAccessUrl,
	organisationExportUrl,
	organisationUuidOf,
} = await import('../../src/components/organisations/organisationExport.js')

const url = (path, params = {}) => '/index.php' + path.replace(/\{(\w+)\}/g, (_, key) => params[key])

describe('organisationExport helpers', () => {
	it('takes the route id first, else the object id', () => {
		expect(organisationUuidOf({ id: 'from-object' }, 'from-route')).toBe('from-route')
		expect(organisationUuidOf({ '@self': { id: 'self-id' }, id: 'plain' }, '')).toBe('self-id')
		expect(organisationUuidOf({ uuid: 'u-1' }, '')).toBe('u-1')
		expect(organisationUuidOf(null, '')).toBe('')
	})

	it('builds the export and the access URLs for one organisation', () => {
		expect(organisationExportUrl(url, 'org-a')).toBe('/index.php/apps/stackiq/api/archimate/export/organization/org-a')
		expect(organisationExportAccessUrl(url, 'org-a')).toBe('/index.php/apps/stackiq/api/archimate/export/organization/org-a/access')
	})

	it('shows only on a plain yes', () => {
		expect(mayExportFrom({ mayExport: true })).toBe(true)
		expect(mayExportFrom({ mayExport: false })).toBe(false)
		expect(mayExportFrom({ mayExport: 'true' })).toBe(false)
		expect(mayExportFrom(null)).toBe(false)
	})
})

describe('OrganisationExportAction', () => {
	beforeEach(() => {
		get.mockReset()
	})

	it('shows the export of the page organisation when the server says yes', async () => {
		get.mockResolvedValue({ data: { mayExport: true } })

		const wrapper = mount(OrganisationExportAction, { props: { objectId: 'org-a', object: { id: 'org-a' } } })
		await flushPromises()

		expect(get).toHaveBeenCalledWith('/index.php/apps/stackiq/api/archimate/export/organization/org-a/access')
		const button = wrapper.find('[data-testid="organisation-export-archimate"]')
		expect(button.exists()).toBe(true)
		expect(button.attributes('href')).toBe('/index.php/apps/stackiq/api/archimate/export/organization/org-a')
		expect(button.text()).toContain('Export as ArchiMate')
	})

	it('hides on another organisation the server refuses', async () => {
		get.mockResolvedValue({ data: { mayExport: false } })

		const wrapper = mount(OrganisationExportAction, { props: { objectId: 'org-b' } })
		await flushPromises()

		expect(wrapper.find('[data-testid="organisation-export-archimate"]').exists()).toBe(false)
	})

	it('hides when the probe fails, and asks nothing without an organisation', async () => {
		get.mockRejectedValue(new Error('403'))
		const failed = mount(OrganisationExportAction, { props: { objectId: 'org-a' } })
		await flushPromises()
		expect(failed.find('[data-testid="organisation-export-archimate"]').exists()).toBe(false)

		get.mockReset()
		const none = mount(OrganisationExportAction, { props: {} })
		await flushPromises()
		expect(get).not.toHaveBeenCalled()
		expect(none.find('[data-testid="organisation-export-archimate"]').exists()).toBe(false)
	})
})
