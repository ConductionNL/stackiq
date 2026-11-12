/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * ManageUserGroupsDialog: it reads the user's current groups on mount (falling
 * back to the contactpersoon's own groups when that read fails), toggles them
 * locally without duplicates, and writes them back through the organisatie
 * store, reporting the outcome by toast and `saved`.
 *
 * @spec openspec/specs/fe-organizations/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const toast = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
const organisatie = vi.hoisted(() => ({}))

vi.mock('@nextcloud/dialogs', () => toast)
vi.mock('../../src/store/modules/organisatie.js', () => ({
	useOrganisatieStore: () => organisatie,
}))

const { default: ManageUserGroupsDialog } =
	await import('../../src/dialogs/ManageUserGroupsDialog.vue')

function t (app, text, vars = {}) {
  return String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
}

/**
 * A component context: props, methods bound, data and computed as getters.
 *
 * @param {object} props The component props.
 * @return {object} The context.
 */
function context(props) {
	const comp = ManageUserGroupsDialog
	const ctx = { $emit: vi.fn(), t, ...props }
	for (const [name, fn] of Object.entries(comp.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	Object.assign(ctx, comp.data.call(ctx))
	for (const [name, def] of Object.entries(comp.computed)) {
		Object.defineProperty(ctx, name, { get: () => def.call(ctx), configurable: true })
	}
	return ctx
}

const PERSON = { id: 42, user: { username: 'jdoe', groups: ['old'] } }

beforeEach(() => {
	vi.clearAllMocks()
	for (const key of Object.keys(organisatie)) delete organisatie[key]
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

describe('ManageUserGroupsDialog', () => {
	it('exposes the store and its available groups', () => {
		organisatie.getAvailableGroups = [{ id: 'admin', name: 'Admin' }]
		const ctx = context({ contactPerson: PERSON })

		expect(ctx.organisatieStore).toBe(organisatie)
		expect(ctx.availableGroups).toEqual([{ id: 'admin', name: 'Admin' }])
		expect(ctx.selectedGroups).toEqual([])
		expect(ctx.groupsLoading).toBe(false)
	})

	it('loads the current groups on mount and reports them', async () => {
		organisatie.fetchUserInfo = vi.fn().mockResolvedValue({ groups: ['a', 'b'] })
		const ctx = context({ contactPerson: PERSON })

		await ManageUserGroupsDialog.mounted.call(ctx)

		expect(organisatie.fetchUserInfo).toHaveBeenCalledWith(42)
		expect(ctx.selectedGroups).toEqual(['a', 'b'])
		expect(ctx.$emit).toHaveBeenCalledWith('groups-loaded', ['a', 'b'])
	})

	it('treats missing groups in the user info as none', async () => {
		organisatie.fetchUserInfo = vi.fn().mockResolvedValue({})
		const ctx = context({ contactPerson: PERSON })

		await ManageUserGroupsDialog.mounted.call(ctx)

		expect(ctx.selectedGroups).toEqual([])
		expect(ctx.$emit).toHaveBeenCalledWith('groups-loaded', [])
	})

	it('falls back to the contactpersoon groups when the read fails', async () => {
		organisatie.fetchUserInfo = vi.fn().mockRejectedValue(new Error('down'))
		const ctx = context({ contactPerson: PERSON })

		await ManageUserGroupsDialog.mounted.call(ctx)

		expect(ctx.selectedGroups).toEqual(['old'])
		expect(ctx.selectedGroups).not.toBe(PERSON.user.groups)
		expect(ctx.$emit).not.toHaveBeenCalled()
	})

	it('adds a checked group once and removes an unchecked one', () => {
		const ctx = context({ contactPerson: PERSON })

		ctx.toggleGroup('a', true)
		ctx.toggleGroup('b', true)
		ctx.toggleGroup('a', true)
		expect(ctx.selectedGroups).toEqual(['a', 'b'])

		ctx.toggleGroup('a', false)
		expect(ctx.selectedGroups).toEqual(['b'])

		ctx.toggleGroup('missing', false)
		expect(ctx.selectedGroups).toEqual(['b'])
	})

	it('saves the selection by username and emits a copy of it', async () => {
		organisatie.updateUserGroups = vi.fn().mockResolvedValue(undefined)
		const ctx = context({ contactPerson: PERSON })
		ctx.selectedGroups = ['x', 'y']

		const pending = ctx.saveGroups()
		expect(ctx.groupsLoading).toBe(true)
		await pending

		expect(organisatie.updateUserGroups).toHaveBeenCalledWith('jdoe', ['x', 'y'])
		expect(toast.showSuccess).toHaveBeenCalledWith('User groups updated successfully')
		expect(ctx.$emit).toHaveBeenCalledWith('saved', ['x', 'y'])
		expect(ctx.$emit.mock.calls[0][1]).not.toBe(ctx.selectedGroups)
		expect(ctx.groupsLoading).toBe(false)
	})

	it('shows the error and emits nothing when saving fails', async () => {
		organisatie.updateUserGroups = vi.fn().mockRejectedValue(new Error('denied'))
		const ctx = context({ contactPerson: PERSON })

		await ctx.saveGroups()

		expect(toast.showError).toHaveBeenCalledWith('Failed to update user groups: denied')
		expect(toast.showSuccess).not.toHaveBeenCalled()
		expect(ctx.$emit).not.toHaveBeenCalled()
		expect(ctx.groupsLoading).toBe(false)
	})
})
