// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the modal switch, mounted: which object modal renders for
 * each navigation-store modal key, and which object type the generic edit
 * modal is opened for.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

const store = vi.hoisted(() => ({ navigationStore: null }))

vi.mock('../../src/store/store.js', async () => {
	const { reactive: makeReactive } = await import('vue')
	store.navigationStore = makeReactive({ modal: null })
	return { navigationStore: store.navigationStore }
})

/**
 * Mock one child modal as a component rendering a marker element.
 *
 * @param {string} name The component name.
 * @return {object} The module factory result.
 */
async function marker(name) {
	const { defineComponent, h } = await import('vue')
	return {
		default: defineComponent({
			name,
			props: { objectTypeKey: { type: String, default: null } },
			setup(props) {
				return () => h('div', { class: 'modal-marker', 'data-name': name, 'data-type': props.objectTypeKey })
			},
		}),
	}
}

vi.mock('../../src/modals/object/LockObject.vue', () => marker('LockObject'))
vi.mock('../../src/modals/object/MergeObject.vue', () => marker('MergeObject'))
vi.mock('../../src/modals/object/MigrationObject.vue', () => marker('MigrationObject'))
vi.mock('../../src/modals/object/ObjectModal.vue', () => marker('ObjectModal'))
vi.mock('../../src/modals/object/UploadObject.vue', () => marker('UploadObject'))
vi.mock('../../src/modals/object/ViewObject.vue', () => marker('ViewObject'))

const { default: Modals } = await import('../../src/modals/Modals.vue')

/**
 * The rendered markers for a modal key, as name:type strings.
 *
 * @param {*} modal The navigation-store modal value.
 * @return {Promise<Array<string>>} The rendered markers.
 */
async function rendered(modal) {
	store.navigationStore.modal = modal
	const wrapper = mount(Modals)
	const markers = wrapper.findAll('.modal-marker').map((m) => m.attributes('data-name') + ':' + (m.attributes('data-type') ?? ''))
	wrapper.unmount()
	return markers
}

describe('Modals', () => {
	it('renders nothing without a modal key', async () => {
		expect(await rendered(null)).toEqual([])
		expect(await rendered('')).toEqual([])
		expect(await rendered(42)).toEqual([])
		expect(await rendered('unknownModal')).toEqual([])
	})

	it('renders the organisation modals for their keys', async () => {
		expect(await rendered('organization')).toEqual(['ObjectModal:organization'])
		expect(await rendered('viewOrganisatie')).toEqual(['ViewObject:'])
		expect(await rendered('uploadOrganisatie')).toEqual(['UploadObject:'])
		expect(await rendered('lockOrganisatie')).toEqual(['LockObject:'])
		expect(await rendered('migrationOrganisatie')).toEqual(['MigrationObject:'])
		expect(await rendered('mergeOrganisatie')).toEqual(['MergeObject:'])
		expect(await rendered('viewContactpersoon')).toEqual(['ViewObject:'])
	})

	it('opens the generic edit modal for each generic object type', async () => {
		for (const type of ['contactPerson', 'module', 'software-review', 'sector']) {
			expect(await rendered(type)).toEqual(['ObjectModal:' + type])
		}
	})

	it('follows the store when the modal key changes', async () => {
		store.navigationStore.modal = 'module'
		const wrapper = mount(Modals)
		expect(wrapper.findAll('.modal-marker')).toHaveLength(1)
		store.navigationStore.modal = 'lockOrganisatie'
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.modal-marker').attributes('data-name')).toBe('LockObject')
	})
})
