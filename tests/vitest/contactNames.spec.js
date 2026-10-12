// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The owner column of the usage list: contact persons shown by the name of
 * their Nextcloud contact, read in one batched request, with the uuid kept
 * when the signed-in user has no name for it.
 *
 * @spec openspec/specs/application-usage-pages/spec.md#requirement-req-uap-003-a-usage-names-a-business-owner-and-a-technical-owner
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))

const { clearContactNames, contactName, contactPersonId, MAX_IDS_PER_REQUEST } =
	await import('../../src/utils/contactNames.js')
const ContactNameCell = (
	await import('../../src/components/cells/ContactNameCell.vue')
).default
const cellWidgets = (await import('../../src/cellWidgets.js')).default

const A = '00000000-0000-0000-0000-00000000000a'
const B = '00000000-0000-0000-0000-00000000000b'

describe('contactNames', () => {
	beforeEach(() => {
		clearContactNames()
		http.get.mockReset()
	})

	it('asks once for every id of the same tick and answers each', async () => {
		http.get.mockResolvedValue({ data: { names: { [A]: 'Teamleider' } } })

		const [a, b, again] = await Promise.all([
			contactName(A),
			contactName(B),
			contactName(A),
		])

		expect(http.get).toHaveBeenCalledTimes(1)
		expect(http.get).toHaveBeenCalledWith('/apps/stackiq/api/contact-names', {
			params: { ids: [A, B] },
		})
		expect(a).toBe('Teamleider')
		expect(again).toBe('Teamleider')
		expect(b).toBeNull()

		await contactName(A)
		expect(http.get).toHaveBeenCalledTimes(1)
	})

	it('splits a batch at the endpoint limit', async () => {
		http.get.mockResolvedValue({ data: { names: {} } })
		const ids = Array.from(
			{ length: MAX_IDS_PER_REQUEST + 1 },
			(_, i) => `00000000-0000-0000-0000-${String(i).padStart(12, '0')}`,
		)

		await Promise.all(ids.map((id) => contactName(id)))

		expect(http.get).toHaveBeenCalledTimes(2)
		expect(http.get.mock.calls[0][1].params.ids).toHaveLength(
			MAX_IDS_PER_REQUEST,
		)
		expect(http.get.mock.calls[1][1].params.ids).toHaveLength(1)
	})

	it('gives no name when the request fails', async () => {
		http.get.mockRejectedValue(new Error('offline'))
		expect(await contactName(A)).toBeNull()
	})

	it('reads the uuid from a string or a related object', () => {
		expect(contactPersonId(` ${A} `)).toBe(A)
		expect(contactPersonId({ id: A })).toBe(A)
		expect(contactPersonId({ uuid: B })).toBe(B)
		expect(contactPersonId(null)).toBe('')
	})
})

describe('ContactNameCell', () => {
	beforeEach(() => {
		clearContactNames()
		http.get.mockReset()
	})

	it('is registered as the contactName cell widget', () => {
		expect(cellWidgets.contactName).toBe(ContactNameCell)
	})

	it('shows the contact name instead of the uuid', async () => {
		http.get.mockResolvedValue({ data: { names: { [A]: 'Teamleider' } } })
		const wrapper = mount(ContactNameCell, { props: { value: A } })
		await flushPromises()
		expect(wrapper.text()).toBe('Teamleider')
	})

	it('keeps the uuid when the signed-in user has no name for it', async () => {
		http.get.mockResolvedValue({ data: { names: {} } })
		const wrapper = mount(ContactNameCell, { props: { value: B } })
		await flushPromises()
		expect(wrapper.text()).toBe(B)
	})
})
