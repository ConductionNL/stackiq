// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Dialogs.vue renders exactly the object dialog the navigation store names,
 * and nothing for any other value.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h, nextTick } from 'vue'

const nav = vi.hoisted(() => ({ store: null }))

/**
 * A stub dialog that renders a marker element.
 *
 * @param {string} name The marker.
 * @return {object} A module with the stub as default export.
 */
function stub(name) {
	return { default: defineComponent({ name, render: () => h('i', { 'data-dialog': name }) }) }
}

vi.mock('../../src/store/store.js', async () => {
	const { reactive: r } = await import('vue')
	nav.store = r({ dialog: null })
	return { navigationStore: nav.store }
})
vi.mock('../../src/modals/object/ChangeOrganisatieStatusDialog.vue', () => stub('changeOrganisatieStatus'))
vi.mock('../../src/modals/object/DeleteObject.vue', () => stub('deleteObject'))
vi.mock('../../src/modals/object/DownloadObject.vue', () => stub('downloadObject'))
vi.mock('../../src/modals/object/MassDeleteObject.vue', () => stub('massDeleteObjects'))
vi.mock('../../src/modals/object/MassDepublishObjects.vue', () => stub('massDepublishObjects'))
vi.mock('../../src/modals/object/MassLockObjects.vue', () => stub('massLockObjects'))
vi.mock('../../src/modals/object/MassPublishObjects.vue', () => stub('massPublishObjects'))
vi.mock('../../src/modals/object/MassUnlockObjects.vue', () => stub('massUnlockObjects'))
vi.mock('../../src/modals/object/MassValidateObjects.vue', () => stub('massValidateObjects'))

const { default: Dialogs } = await import('../../src/dialogs/Dialogs.vue')

const NAMES = [
	'deleteObject',
	'downloadObject',
	'changeOrganisatieStatus',
	'massDeleteObjects',
	'massPublishObjects',
	'massDepublishObjects',
	'massLockObjects',
	'massUnlockObjects',
	'massValidateObjects',
]

/**
 * The dialog markers currently rendered.
 *
 * @param {object} wrapper The mounted wrapper.
 * @return {Array<string>} The markers.
 */
function rendered(wrapper) {
	return wrapper.findAll('[data-dialog]').map((el) => el.attributes('data-dialog'))
}

describe('Dialogs', () => {
	it('renders nothing without a dialog', () => {
		nav.store.dialog = null
		expect(rendered(mount(Dialogs))).toEqual([])
	})

	it.each(NAMES)('renders only the %s dialog when it is selected', async (name) => {
		nav.store.dialog = null
		const wrapper = mount(Dialogs)
		nav.store.dialog = name
		await nextTick()
		expect(rendered(wrapper)).toEqual([name])
	})

	it('renders nothing for an unknown dialog', () => {
		nav.store.dialog = 'somethingElse'
		expect(rendered(mount(Dialogs))).toEqual([])
	})
})
