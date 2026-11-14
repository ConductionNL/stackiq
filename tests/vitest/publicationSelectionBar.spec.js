// @vitest-environment jsdom
/**
 * The selection strip of the Applications and Services lists offers Publish,
 * Withdraw and Lock for the selected rows, and the mass dialog lists each
 * refused entry with the endpoint's reason (publish-and-lock-from-the-list,
 * REQ-ODP-010).
 *
 * @spec openspec/changes/publish-and-lock-from-the-list/tasks.md#task-1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h, nextTick, reactive } from 'vue'

const stores = vi.hoisted(() => ({
	navigationStore: null,
	objectStore: { selectedObjects: null, setSelectedObjects: null },
}))

vi.mock('../../src/store/store.js', async () => {
	const { reactive: r } = await import('vue')
	stores.navigationStore = r({
		dialog: false,
		setDialog(value) {
			this.dialog = value
		},
	})
	stores.objectStore.setSelectedObjects = vi.fn((objects) => {
		stores.objectStore.selectedObjects = objects
	})
	return {
		catalogStore: { fetchPublications: vi.fn() },
		navigationStore: stores.navigationStore,
		objectStore: stores.objectStore,
	}
})

const LOADED = [
	{ id: 'a', name: 'Zaaksysteem', '@self': { id: 'a', register: 1, schema: 2 } },
	{ id: 'b', name: 'Burgerzaken', '@self': { id: 'b', register: 1, schema: 2 } },
	{ id: 'c', name: 'Belastingen', '@self': { id: 'c', register: 1, schema: 2 } },
]

// CnIndexPage renders its selection strip slot for rows a and c.
vi.mock('@conduction/nextcloud-vue', () => ({
	CnFacetSidebar: defineComponent({ name: 'CnFacetSidebar', render: () => h('div') }),
	CnIndexPage: defineComponent({
		name: 'CnIndexPage',
		data: () => ({ effectiveObjects: LOADED }),
		computed: {
			selectedObjects() {
				return LOADED.filter((o) => ['a', 'c'].includes(o.id))
			},
		},
		render() {
			return h('div', { class: 'index-page-stub' }, this.$slots['selection-actions']?.({ count: 2, selectedIds: ['c', 'a'] }))
		},
	}),
}))
vi.mock('../../src/modals/SaveFacetViewModal.vue', () => ({
	default: defineComponent({ name: 'SaveFacetViewModal', render: () => h('div') }),
}))

const { default: FacetedCatalogIndexView } = await import('../../src/views/FacetedCatalogIndexView.vue')
const { publicationTarget } = await import('../../src/services/publicationActions.js')
const { default: MassObjectDialog } = await import('../../src/modals/object/MassObjectDialog.vue')
const { runMassObjectAction } = await import('../../src/utils/massObjectAction.js')

const flush = () => new Promise((r) => setTimeout(r, 0))

function mountView(schema = 'module') {
	setActivePinia(createPinia())
	return mount(FacetedCatalogIndexView, {
		props: { schema, register: 'voorzieningen', title: 'Applications' },
		global: {
			mocks: {
				$route: { query: {} },
				$router: { push: vi.fn(), replace: vi.fn(async () => {}) },
			},
			stubs: {
				NcActions: true,
				NcActionButton: true,
				NcActionCaption: true,
				NcButton: defineComponent({
					name: 'NcButton',
					emits: ['click'],
					render() {
						return h('button', { ...this.$attrs, onClick: () => this.$emit('click') }, this.$slots.default?.())
					},
				}),
			},
			plugins: [(app) => { app.config.globalProperties.t = (a, text) => text }],
		},
	})
}

describe('selection strip of the catalogue lists', () => {
	it('shows Publish, Withdraw and Lock for a selection', () => {
		const wrapper = mountView()

		expect(wrapper.find('[data-testid="selection-publish"]').text()).toBe('Publish')
		expect(wrapper.find('[data-testid="selection-withdraw"]').text()).toBe('Withdraw')
		expect(wrapper.find('[data-testid="selection-lock"]').text()).toBe('Lock')
	})

	it('opens the publish dialog for the selected objects of this list type', async () => {
		const wrapper = mountView('catalogService')

		await wrapper.find('[data-testid="selection-publish"]').trigger('click')
		await flush()

		expect(stores.objectStore.setSelectedObjects).toHaveBeenLastCalledWith([LOADED[2], LOADED[0]])
		expect(stores.navigationStore.dialog).toBe('massPublishObjects')
		expect(publicationTarget()).toEqual({ objectType: 'catalogService' })
	})

	it('opens the withdraw and lock dialogs', async () => {
		const wrapper = mountView()

		await wrapper.find('[data-testid="selection-withdraw"]').trigger('click')
		await flush()
		expect(stores.navigationStore.dialog).toBe('massDepublishObjects')

		stores.navigationStore.setDialog(false)
		await wrapper.find('[data-testid="selection-lock"]').trigger('click')
		await flush()
		expect(stores.navigationStore.dialog).toBe('massLockObjects')
	})

	it('reloads the list once the dialog closes', async () => {
		const wrapper = mountView()
		const before = wrapper.vm.listRemount

		await wrapper.find('[data-testid="selection-publish"]').trigger('click')
		await flush()
		expect(wrapper.vm.listRemount).toBe(before)

		stores.navigationStore.setDialog(false)
		await nextTick()
		expect(wrapper.vm.listRemount).toBe(before + 1)
	})
})

describe('mass dialog refusals', () => {
	it('keeps each refused entry with its reason, and none when the failures carry no reason', async () => {
		const dialog = reactive({ success: null, loading: false, error: false, refused: [], closeDialog: vi.fn() })
		const refusedEntry = { entry: { id: 'c', name: 'Belastingen' }, message: 'You may not publish this entry' }

		await runMassObjectAction(dialog, {
			run: async () => ({ successful: [{ id: 'a' }], failed: [refusedEntry] }),
			failedText: (n) => `Failed to publish ${n} objects`,
			errorText: () => 'error',
		})

		expect(dialog.success).toBe(true)
		expect(dialog.error).toBe('Failed to publish 1 objects')
		expect(dialog.refused).toEqual([refusedEntry])

		const plain = reactive({ success: null, loading: false, error: false, refused: [], closeDialog: vi.fn() })
		await runMassObjectAction(plain, {
			run: async () => ({ successful: [], failed: [{ id: 'x' }] }),
			failedText: () => 'failed',
			errorText: () => 'error',
		})
		expect(plain.refused).toEqual([])
	})

	it('names a refused entry by name, else by id', () => {
		const name = MassObjectDialog.methods.refusedName
		expect(name({ entry: { name: 'Belastingen', id: 'c' } })).toBe('Belastingen')
		expect(name({ entry: { '@self': { id: 'd' } } })).toBe('d')
		expect(name({})).toBe('')
	})
})
