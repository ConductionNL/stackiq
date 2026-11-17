// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * MergedRecordBanner, mounted: a merged application's page says it was
 * merged and links to the survivor by name; an active one shows nothing,
 * and a survivor that cannot be read is linked by its uuid.
 *
 * @spec openspec/changes/operations-record-reconciliation/specs/record-reconciliation/spec.md#requirement-req-rrc-004-merged-applications-and-services-shall-leave-the-lists-and-point-readers-to-the-survivor
 */

import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const store = vi.hoisted(() => ({ fetchObject: vi.fn() }))
const types = vi.hoisted(() => ({ ensureObjectType: vi.fn() }))

vi.mock('../../src/store/store.js', () => ({ objectStore: store }))
vi.mock('../../src/utils/objectTypes.js', () => types)
vi.mock('@nextcloud/vue', async () => {
	const { h } = await import('vue')
	return {
		NcNoteCard: {
			props: { type: { default: '' } },
			render() {
				return h('div', { class: 'note-' + this.type }, this.$slots.default?.())
			},
		},
	}
})

const { default: MergedRecordBanner } = await import('../../src/components/merge/MergedRecordBanner.vue')

const RECORDS = {
	'u-1': { id: 'u-1', name: 'Zaaksysteem (dubbel)', recordStatus: 'Merged', mergedInto: 'u-2' },
	'u-2': { id: 'u-2', name: 'Zaaksysteem', recordStatus: 'Active' },
	'u-3': { id: 'u-3', name: 'DMS', recordStatus: 'Active' },
}

/**
 * Mount the banner for a record and wait for its loads.
 *
 * @param {string} objectId The record.
 * @return {Promise<object>} The wrapper.
 */
async function open(objectId) {
	const wrapper = mount(MergedRecordBanner, {
		props: { objectId },
		global: { stubs: { RouterLink: RouterLinkStub } },
	})
	await flushPromises()
	return wrapper
}

describe('MergedRecordBanner', () => {
	beforeEach(() => {
		store.fetchObject.mockReset().mockImplementation(async (type, id) => RECORDS[id] ?? null)
		types.ensureObjectType.mockReset()
	})

	it('links a merged record to its survivor by name', async () => {
		const wrapper = await open('u-1')

		expect(types.ensureObjectType).toHaveBeenCalledWith('module')
		expect(store.fetchObject.mock.calls).toEqual([['module', 'u-1'], ['module', 'u-2']])
		expect(wrapper.find('.note-warning').text()).toContain('This entry was merged into another one.')
		const link = wrapper.findComponent(RouterLinkStub)
		expect(link.props('to')).toEqual({ name: 'ModuleDetail', params: { id: 'u-2' } })
		expect(link.text()).toBe('Merged into Zaaksysteem')
	})

	it('shows nothing for an active record and reads no survivor', async () => {
		const wrapper = await open('u-3')

		expect(wrapper.find('.note-warning').exists()).toBe(false)
		expect(store.fetchObject).toHaveBeenCalledTimes(1)
	})

	it('links by uuid when the survivor cannot be read', async () => {
		store.fetchObject.mockImplementation(async (type, id) => {
			if (id === 'u-2') {
				throw new Error('forbidden')
			}
			return RECORDS[id]
		})

		const wrapper = await open('u-1')

		expect(wrapper.findComponent(RouterLinkStub).text()).toBe('Merged into u-2')
	})

	it('shows nothing when the record cannot be read', async () => {
		store.fetchObject.mockRejectedValue(new Error('down'))

		const wrapper = await open('u-1')

		expect(wrapper.find('.note-warning').exists()).toBe(false)
	})

	it('follows the page to another record', async () => {
		const wrapper = await open('u-1')

		await wrapper.setProps({ objectId: 'u-3' })
		await flushPromises()

		expect(wrapper.find('.note-warning').exists()).toBe(false)
	})
})
