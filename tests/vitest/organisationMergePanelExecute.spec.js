// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation test for the merge panel's confirmed merge and what
 * follows it: the store call with both ids as strings, the tombstone state
 * and success toast, the inline error on a failure, the guard without a
 * target, navigating to the surviving organisation, and the computed
 * tombstone and admin flags.
 *
 * @spec openspec/specs/organisation-merge/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const store = vi.hoisted(() => ({ executeMerge: null, isAdmin: false }))
const toasts = vi.hoisted(() => [])

vi.mock('@nextcloud/axios', () => ({ default: { get: async () => ({ data: {} }) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => '[' + text + ']' }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: (message) => toasts.push(message) }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {},
	NcLoadingIcon: {},
	NcNoteCard: {},
	NcSelect: {},
}))
vi.mock('@conduction/nextcloud-vue', () => ({ CnIcon: {}, CnWidgetWrapper: {} }))
vi.mock('vue-material-design-icons/ArrowRight.vue', () => ({ default: {} }))
vi.mock('vue-material-design-icons/Eye.vue', () => ({ default: {} }))
vi.mock('../../src/modals/object/MergeOrganisationConfirmDialog.vue', () => ({ default: {} }))
vi.mock('../../src/store/store.js', () => ({
	organisatieStore: {
		executeMerge: (...args) => store.executeMerge(...args),
	},
	settingsStore: {
		get getIsAdmin() {
			return store.isAdmin
		},
	},
}))

const { default: OrganisationMergePanel } =
	await import('../../src/components/organisations/OrganisationMergePanel.vue')

/**
 * A panel instance with its data, methods and computeds bound, as Vue binds them.
 *
 * @param {object} overrides Data and props to set.
 * @return {object} The instance.
 */
function panel(overrides = {}) {
	const vm = {
		...OrganisationMergePanel.data.call({}),
		objectId: 42,
		register: 'stackiq',
		schema: 'organization',
		$router: { push: vi.fn() },
		...overrides,
	}
	for (const [name, fn] of Object.entries(OrganisationMergePanel.methods)) {
		vm[name] = fn.bind(vm)
	}
	for (const [name, fn] of Object.entries(OrganisationMergePanel.computed)) {
		Object.defineProperty(vm, name, { get: fn.bind(vm) })
	}
	return vm
}

beforeEach(() => {
	store.executeMerge = vi.fn(async () => ({ merged: true }))
	store.isAdmin = false
	toasts.length = 0
})

describe('OrganisationMergePanel execute', () => {
	it('merges into the selected target and tombstones the source', async () => {
		const vm = panel({ selectedTarget: { value: 7, label: 'Gemeente B' }, showConfirm: true, confirmError: 'old' })

		await vm.execute()

		expect(store.executeMerge).toHaveBeenCalledWith('42', '7')
		expect(vm.showConfirm).toBe(false)
		expect(vm.success).toBe(true)
		expect(vm.status).toBe('merged')
		expect(vm.mergedInto).toBe('7')
		expect(vm.confirmError).toBe('')
		expect(vm.busy).toBe(false)
		expect(vm.isTombstoned).toBe(true)
		expect(toasts).toEqual(['[Organisation successfully merged.]'])
	})

	it('keeps the dialog open with the error when the merge fails', async () => {
		store.executeMerge = vi.fn(async () => {
			throw new Error('Target is merged')
		})
		const vm = panel({ selectedTarget: { value: 'org-b' }, showConfirm: true, status: 'active' })

		await vm.execute()

		expect(vm.confirmError).toBe('Target is merged')
		expect(vm.showConfirm).toBe(true)
		expect(vm.success).toBe(false)
		expect(vm.status).toBe('active')
		expect(vm.isTombstoned).toBe(false)
		expect(vm.busy).toBe(false)
		expect(toasts).toEqual([])
	})

	it('falls back to its own sentence for a failure without a message', async () => {
		store.executeMerge = vi.fn(async () => {
			throw new Error('')
		})
		const vm = panel({ selectedTarget: { value: 'org-b' } })

		await vm.execute()

		expect(vm.confirmError).toBe('[Could not merge the organisations.]')
	})

	it('does nothing without a selected target', async () => {
		const vm = panel()

		await vm.execute()

		expect(store.executeMerge).not.toHaveBeenCalled()
		expect(vm.busy).toBe(false)
		expect(vm.success).toBe(false)
	})
})

describe('OrganisationMergePanel goToTarget', () => {
	it('opens the surviving organisation', () => {
		const vm = panel({ mergedInto: 'org-b' })

		vm.goToTarget()

		expect(vm.$router.push).toHaveBeenCalledWith({ name: 'OrganisatieDetail', params: { id: 'org-b' } })
	})

	it('goes nowhere when the source was not merged', () => {
		const vm = panel()

		vm.goToTarget()

		expect(vm.$router.push).not.toHaveBeenCalled()
	})
})

describe('OrganisationMergePanel computed', () => {
	it('reads the admin flag from the settings store as a boolean', () => {
		expect(panel().isAdmin).toBe(false)
		store.isAdmin = 'yes'
		expect(panel().isAdmin).toBe(true)
	})

	it('is tombstoned only in the merged status', () => {
		expect(panel({ status: 'merged' }).isTombstoned).toBe(true)
		expect(panel({ status: 'active' }).isTombstoned).toBe(false)
		expect(panel().isTombstoned).toBe(false)
	})
})
