// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The name on a contact person's page, and what it says when there is none:
 * not linked, in an address book the user cannot read, or a failed request.
 *
 * @spec openspec/specs/application-usage-pages/spec.md#requirement-req-uap-005-a-contact-persons-name-shows-only-to-who-can-read-the-contact
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('@nextcloud/vue', () => ({
	NcLoadingIcon: { template: '<span class="loading" />' },
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnIcon: { template: '<i />' },
	CnWidgetWrapper: { template: '<div><slot /></div>' },
}))

const { clearContactNames } = await import('../../src/utils/contactNames.js')
const ContactPersonName = (
	await import('../../src/components/organisations/ContactPersonName.vue')
).default

const A = '00000000-0000-0000-0000-00000000000a'
async function mountIt(props) {
	const wrapper = mount(ContactPersonName, {
		props: { objectId: A, loadedId: A, ...props },
	})
	await flushPromises()
	return wrapper
}

describe('ContactPersonName', () => {
	beforeEach(() => {
		clearContactNames()
		http.get.mockReset()
	})

	it('shows the name', async () => {
		http.get.mockResolvedValue({ data: { names: { [A]: 'Anna' } } })
		const wrapper = await mountIt({ contactsUid: 'uid-1' })
		expect(wrapper.text()).toContain('Anna')
		expect(
			wrapper.find('[data-testid="contact-person-name-hint"]').exists(),
		).toBe(false)
	})

	it('says the contact person is not linked when there is no contactsUid', async () => {
		http.get.mockResolvedValue({ data: { names: {} } })
		const wrapper = await mountIt({})
		const hint = wrapper.get('[data-testid="contact-person-name-hint"]').text()
		expect(hint).toBe(
			'This contact person is not linked to a Nextcloud contact.',
		)
	})

	it('names the address book only after an answer without a name', async () => {
		http.get.mockResolvedValue({ data: { names: {} } })
		const wrapper = await mountIt({ contactsUid: 'uid-1' })
		const hint = wrapper.get('[data-testid="contact-person-name-hint"]').text()
		expect(hint).toContain('in an address book you cannot read')
	})

	it('says the name could not be loaded when the request failed', async () => {
		http.get.mockRejectedValue(new Error('429'))
		const wrapper = await mountIt({ contactsUid: 'uid-1' })
		const hint = wrapper.get('[data-testid="contact-person-name-hint"]').text()
		expect(hint).toContain('could not be loaded')
		expect(hint).not.toContain('address book')
	})

	it('shows no hint while the object is not loaded yet', async () => {
		http.get.mockResolvedValue({ data: { names: {} } })
		const wrapper = await mountIt({ loadedId: '' })
		expect(
			wrapper.find('[data-testid="contact-person-name-hint"]').exists(),
		).toBe(false)
		expect(wrapper.find('.loading').exists()).toBe(true)

		await wrapper.setProps({ loadedId: A })
		expect(
			wrapper.get('[data-testid="contact-person-name-hint"]').text(),
		).toContain('not linked')
	})
})
