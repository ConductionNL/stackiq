// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Shared with column on Connections: the organisations a connection is
 * shared with, "nobody", or nothing when the lookup is refused, from one
 * lookup per page load (shared-usage-visible-to-participants task 4).
 *
 * @spec openspec/changes/archive/2026-11-24-shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-the-connections-list-says-with-whom-a-connection-is-shared-req-dlg-011
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const mockGet = vi.hoisted(() => vi.fn())
const mockFetchObject = vi.hoisted(() => vi.fn())

vi.mock('@nextcloud/axios', () => ({ default: { get: (...a) => mockGet(...a) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('../../src/store/store.js', () => ({
	objectStore: { fetchObject: (...a) => mockFetchObject(...a) },
}))
vi.mock('../../src/utils/objectTypes.js', () => ({ ensureObjectTypeOrSlug: vi.fn() }))

const { default: SharedWithCell } = await import('../../src/components/connections/SharedWithCell.vue')
const { resetSharedConnections, sharedWithOf } = await import('../../src/utils/sharedConnections.js')

const names = { 'org-alb': 'Alblasserdam', 'org-pap': 'Papendrecht' }

describe('SharedWithCell', () => {
	beforeEach(() => {
		resetSharedConnections()
		mockGet.mockReset()
		mockFetchObject.mockReset()
		mockFetchObject.mockImplementation(async (type, id) => {
			if (names[id]) {
				return { id, name: names[id] }
			}
			throw new Error('not readable')
		})
	})

	it('lists the organisations a connection is shared with, by name', async () => {
		mockGet.mockResolvedValue({ data: { connections: { 'c-iburger': { sharedWith: ['org-alb', 'org-pap'], usages: ['u-1'] } } } })
		const wrapper = mount(SharedWithCell, { props: { row: { id: 'c-iburger' } } })
		await flushPromises()

		expect(wrapper.text()).toBe('Alblasserdam, Papendrecht')
		expect(mockGet).toHaveBeenCalledWith('/apps/stackiq/api/gebruik/shared-connections')
	})

	it('says nobody for a connection no shared usage holds', async () => {
		mockGet.mockResolvedValue({ data: { connections: {} } })
		const wrapper = mount(SharedWithCell, { props: { row: { id: 'c-alone' } } })
		await flushPromises()

		expect(wrapper.text()).toBe('nobody')
		expect(mockFetchObject).not.toHaveBeenCalled()
	})

	it('keeps the uuid of an organisation it cannot read', async () => {
		mockGet.mockResolvedValue({ data: { connections: { 'c-1': { sharedWith: ['org-hidden'] } } } })
		const wrapper = mount(SharedWithCell, { props: { row: { id: 'c-1' } } })
		await flushPromises()

		expect(wrapper.text()).toBe('org-hidden')
	})

	it('shows nothing when the lookup is refused, and asks again next time', async () => {
		mockGet.mockRejectedValueOnce(new Error('403'))
		const wrapper = mount(SharedWithCell, { props: { row: { id: 'c-1' } } })
		await flushPromises()

		expect(wrapper.find('[data-testid="shared-with-cell"]').exists()).toBe(false)

		mockGet.mockResolvedValue({ data: { connections: {} } })
		const again = mount(SharedWithCell, { props: { row: { id: 'c-1' } } })
		await flushPromises()
		expect(again.text()).toBe('nobody')
		expect(mockGet).toHaveBeenCalledTimes(2)
	})

	it('reads the lookup once for every row', async () => {
		mockGet.mockResolvedValue({ data: { connections: { 'c-1': { sharedWith: ['org-alb'] } } } })
		const rows = [mount(SharedWithCell, { props: { row: { id: 'c-1' } } }), mount(SharedWithCell, { props: { row: { id: 'c-2' } } })]
		await flushPromises()

		expect(rows.map((row) => row.text())).toEqual(['Alblasserdam', 'nobody'])
		expect(mockGet).toHaveBeenCalledTimes(1)
	})
})

describe('sharedWithOf', () => {
	it('answers unknown, nobody or the organisations', () => {
		expect(sharedWithOf(null, 'c-1')).toBeNull()
		expect(sharedWithOf({}, 'c-1')).toEqual([])
		expect(sharedWithOf({ 'c-1': { sharedWith: ['org-a', '', 7] } }, 'c-1')).toEqual(['org-a'])
	})
})
