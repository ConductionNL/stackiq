// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Granting and revoking organisation access, and the account actions on a
 * contact person (making an account, enabling and disabling it, its groups
 * and the password dialog), each run against a plain component context with
 * the organisation store doubled.
 *
 * @spec openspec/specs/fe-shell-navigation/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const toasts = vi.hoisted(() => [])
vi.mock('@nextcloud/dialogs', () => ({
	showError: (m) => toasts.push(['error', m]),
	showSuccess: (m) => toasts.push(['success', m]),
}))
vi.mock('../../src/dialogs/ChangePasswordDialog.vue', () => ({ default: {} }))
vi.mock('../../src/dialogs/ManageUserGroupsDialog.vue', () => ({ default: {} }))
const organisatie = vi.hoisted(() => ({}))
vi.mock('../../src/store/modules/organisatie.js', () => ({ useOrganisatieStore: () => organisatie }))
vi.mock('../../src/store/store.js', () => ({ organisatieStore: organisatie }))

const { default: GrantOrganisationAccessModal } = await import('../../src/modals/GrantOrganisationAccessModal.vue')
const { default: ContactpersonenList } = await import('../../src/components/ContactpersonenList.vue')

const t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))

/**
 * A component context: props and extras, methods bound, data, and computed
 * values as getters.
 *
 * @param {object} comp The component definition.
 * @param {object} [props] Its props.
 * @return {object} The context.
 */
function context(comp, props = {}) {
	const ctx = { $emit: vi.fn(), t, ...props }
	for (const [name, fn] of Object.entries(comp.methods || {})) {
		ctx[name] = fn.bind(ctx)
	}
	Object.assign(ctx, comp.data ? comp.data.call(ctx) : {})
	for (const [name, def] of Object.entries(comp.computed || {})) {
		Object.defineProperty(ctx, name, { get: () => def.call(ctx), configurable: true })
	}
	return ctx
}

beforeEach(() => {
	toasts.length = 0
	for (const key of Object.keys(organisatie)) {
		delete organisatie[key]
	}
	Object.assign(organisatie, {
		fetchMembers: vi.fn(async () => ['ann', 'bob']),
		grantAccess: vi.fn(),
		revokeAccess: vi.fn(),
		convertToUser: vi.fn(),
		disableUser: vi.fn(),
		enableUser: vi.fn(),
		fetchAvailableGroups: vi.fn(),
		fetchContactpersonen: vi.fn(),
		getBulkUserInfo: vi.fn(async () => ({})),
		getAvailableGroups: [{ id: 'gebruik-beheerder' }],
		getContactpersonen: ['from-store'],
		isLoading: false,
		getError: null,
	})
	vi.spyOn(console, 'info').mockImplementation(() => {})
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	vi.restoreAllMocks()
})

describe('GrantOrganisationAccessModal', () => {
	const modal = (organisationUuid = 'org1') => context(GrantOrganisationAccessModal, { open: false, organisationUuid, organisationName: 'Gemeente A' })

	it('loads the members when it opens for an organisation', async () => {
		const m = modal()
		GrantOrganisationAccessModal.watch.open.call(m, false)
		GrantOrganisationAccessModal.watch.open.call(modal(null), true)
		expect(organisatie.fetchMembers).not.toHaveBeenCalled()
		GrantOrganisationAccessModal.watch.open.call(m, true)
		await new Promise((resolve) => setTimeout(resolve, 0))
		expect([organisatie.fetchMembers.mock.calls[0], m.members, m.loading]).toEqual([['org1'], ['ann', 'bob'], false])
		organisatie.fetchMembers.mockRejectedValueOnce(new Error('No access'))
		await m.loadMembers()
		expect([m.errorMessage, m.members]).toEqual(['No access', ['ann', 'bob']])
	})

	it('grants access to the chosen user and reloads, nothing without one', async () => {
		const m = modal()
		await m.onGrant()
		expect(organisatie.grantAccess).not.toHaveBeenCalled()
		m.selectedUser = { id: 'eve' }
		await m.onGrant()
		expect([organisatie.grantAccess.mock.calls[0], m.selectedUser, organisatie.fetchMembers.mock.calls.length, m.granting]).toEqual([['org1', 'eve'], null, 1, false])
		m.selectedUser = 'zoe'
		organisatie.grantAccess.mockRejectedValueOnce(new Error('Unknown user'))
		await m.onGrant()
		expect([m.errorMessage, m.selectedUser]).toEqual(['Unknown user', 'zoe'])
	})

	it('revokes access and drops the member, keeping the list on failure', async () => {
		const m = modal()
		m.members = ['ann', 'bob']
		await m.onRevoke('ann')
		expect([organisatie.revokeAccess.mock.calls[0], m.members, m.revokingUserId]).toEqual([['org1', 'ann'], ['bob'], null])
		organisatie.revokeAccess.mockRejectedValueOnce(new Error('Last admin'))
		await m.onRevoke('bob')
		expect([m.members, m.errorMessage]).toEqual([['bob'], 'Last admin'])
		m.onClose()
		expect([m.errorMessage, m.$emit.mock.calls[0]]).toEqual(['', ['update:open', false]])
	})
})

describe('ContactpersonenList account actions', () => {
	const rows = () => [{ id: 'c1', data: { voornaam: 'Ann', achternaam: 'Smit' }, user: { groups: [], disabled: false } }, { uuid: 'c2', data: { name: 'Bob' } }]
	const list = (organisationData = { contactpersonen: rows() }) => context(ContactpersonenList, { organisationId: 'org1', organisationData })

	it('reads the store when the organisation brings no contact persons', async () => {
		const empty = list({})
		await empty.loadData()
		expect([empty.contactpersonen, organisatie.fetchContactpersonen.mock.calls[0], empty.loading, empty.error, empty.availableGroups]).toEqual([['from-store'], ['org1'], false, null, [{ id: 'gebruik-beheerder' }]])
		const given = list()
		await given.loadData()
		expect([organisatie.fetchContactpersonen.mock.calls.length, given.contactpersonen.map((c) => c.id)]).toEqual([1, ['c1', 'c2']])
		organisatie.fetchAvailableGroups.mockRejectedValueOnce(new Error('x'))
		await given.loadData()
		expect(console.error).toHaveBeenCalled()
	})

	it('makes a user account for a contact person and replaces its row', async () => {
		const l = list()
		l.refreshUserData = vi.fn()
		organisatie.convertToUser.mockResolvedValueOnce({ contactPerson: { '@self': { id: 'c1' }, voornaam: 'Ann', username: 'ann', groups: ['g'] } })
		await l.convertToUser({ id: 'c1' })
		expect(organisatie.convertToUser).toHaveBeenCalledWith('c1')
		expect([l.organisationData.contactpersonen[0], toasts, l.refreshUserData.mock.calls.length]).toEqual([{ id: 'c1', uuid: 'c1', data: { voornaam: 'Ann', achternaam: undefined, 'e-mailadres': undefined, name: undefined, organization: undefined, username: 'ann', groups: ['g'] }, loading: false }, [['success', 'User account created successfully']], 1])
	})

	it('refuses an unknown or malformed row and reports a failed conversion', async () => {
		const l = list({ contactpersonen: [{ id: 'c1' }, 'broken'] })
		l.refreshUserData = vi.fn()
		await l.convertToUser({ id: 'nobody' })
		await l.convertToUser({ id: undefined })
		organisatie.convertToUser.mockRejectedValueOnce(new Error('Email in use'))
		await l.convertToUser({ id: 'c1' })
		expect([toasts.map((e) => e[1]), l.organisationData.contactpersonen[0].loading, organisatie.convertToUser.mock.calls.length]).toEqual([['Contactpersoon not found in organisation data', 'Invalid contact person data structure', 'Failed to create user account: Email in use'], false, 1])
	})

	it('disables and enables an account and keeps the row in step', async () => {
		const l = list()
		await l.disableUser({ id: 'c1' })
		expect([organisatie.disableUser.mock.calls[0], l.organisationData.contactpersonen[0].user.disabled, l.organisationData.contactpersonen[0].data.disabled]).toEqual([['c1'], true, true])
		await l.enableUser({ id: 'c1' })
		expect(l.organisationData.contactpersonen[0].user.disabled).toBe(false)
		organisatie.disableUser.mockRejectedValueOnce(new Error('Admin'))
		await l.disableUser({ id: 'c1' })
		organisatie.enableUser.mockRejectedValueOnce(new Error('Gone'))
		await l.enableUser({ id: 'c1' })
		expect(toasts.map((e) => e[1])).toEqual(['User disabled successfully', 'User enabled successfully', 'Failed to disable user: Admin', 'Failed to enable user: Gone'])
		l.updateContactpersoonStatus('missing', true)
		list({}).updateContactpersoonStatus('c1', true)
		expect(l.organisationData.contactpersonen[1].user).toBeUndefined()
	})

	it('opens the dialogs and saves the groups onto the row', () => {
		const l = list()
		l.openPasswordDialog({ id: 'c1' })
		expect([l.showPasswordDialog, l.selectedContactpersoon]).toEqual([true, { id: 'c1' }])
		l.closePasswordDialog()
		l.onGroupsLoaded(['ignored'])
		expect([l.showPasswordDialog, l.organisationData.contactpersonen[0].user.groups]).toEqual([false, []])
		l.openGroupsDialog({ id: 'c1' })
		l.onGroupsLoaded(['gebruik-beheerder'])
		expect(l.organisationData.contactpersonen[0].data.groups).toEqual(['gebruik-beheerder'])
		l.onGroupsSaved(['aanbod-beheerder', 'other'])
		expect([l.organisationData.contactpersonen[0].user.groups, l.showGroupsDialog, l.selectedContactpersoon]).toEqual([['aanbod-beheerder', 'other'], false, null])
		l.onGroupsSaved(['x'])
		expect(l.organisationData.contactpersonen[0].user.groups).toEqual(['aanbod-beheerder', 'other'])
	})

	it('names a contact person and shows only the groups it knows', () => {
		const l = list()
		expect([l.getContactpersoonName({ data: { name: 'Bob' } }), l.getContactpersoonName({ data: { voornaam: 'Ann', achternaam: 'Smit' } })]).toEqual(['Bob', 'Ann Smit'])
		expect([l.getFilteredGroups({ user: { groups: ['gebruik-beheerder', 'secret'] } }), l.getFilteredGroups({ user: {} })]).toEqual([['gebruik-beheerder'], []])
		expect([l.formatGroupName('gebruik-raadpleger'), l.formatGroupName('custom')]).toEqual(['Gebruik Raadpleger', 'custom'])
	})
})
