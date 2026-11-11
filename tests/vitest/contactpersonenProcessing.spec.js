// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation test for how the contact person list shapes contact
 * persons from the organisation data (id, data, user details with their
 * fallbacks, loading flag, the debug log) and merges bulk user info into
 * them (user and data fields, the log, contacts without info left alone).
 *
 * @spec openspec/specs/fe-organizations/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/dialogs', () => ({ showError: () => {}, showSuccess: () => {} }))
vi.mock('../../src/store/modules/organisatie.js', () => ({
	useOrganisatieStore: () => ({}),
}))

const { default: ContactpersonenList } =
	await import('../../src/components/ContactpersonenList.vue')

let info

beforeEach(() => {
	info = vi.spyOn(console, 'info').mockImplementation(() => {})
})

describe('ContactpersonenList processContactpersonen', () => {
	it('shapes each contact person', () => {
		const raw = [
			{
				id: 'c1',
				data: { username: 'ann', groups: ['g-data'], disabled: true },
				user: { groups: ['g-api'], disabled: false },
				loading: true,
			},
			{ uuid: 'u2', username: 'bob', groups: ['g2'] },
			{ id: 'c3', data: { name: 'No user' }, user: { disabled: true } },
			{ data: {} },
		]
		const out = ContactpersonenList.methods.processContactpersonen.call({}, raw)
		expect(out).toEqual([
			{
				id: 'c1',
				data: raw[0].data,
				user: {
					hasUser: true,
					username: 'ann',
					groups: ['g-api'],
					disabled: true,
				},
				loading: true,
			},
			{
				id: 'u2',
				data: raw[1],
				user: {
					hasUser: true,
					username: 'bob',
					groups: ['g2'],
					disabled: false,
				},
				loading: false,
			},
			{
				id: 'c3',
				data: raw[2].data,
				user: { hasUser: false, username: '', groups: [], disabled: true },
				loading: false,
			},
			{
				id: undefined,
				data: {},
				user: { hasUser: false, username: '', groups: [], disabled: false },
				loading: false,
			},
		])
		expect(Object.keys(out[0].user)).toEqual([
			'hasUser',
			'username',
			'groups',
			'disabled',
		])
		expect(info.mock.calls[0]).toEqual([
			'Processing contactPerson:',
			{
				contactId: 'c1',
				data: raw[0].data,
				hasUser: true,
				userFromAPI: raw[0].user,
				usernameFromData: 'ann',
				disabledFromAPI: false,
				disabledFromData: true,
				groupsFromAPI: ['g-api'],
				groupsFromData: ['g-data'],
			},
		])
		expect(Object.keys(info.mock.calls[0][1])).toEqual([
			'contactId',
			'data',
			'hasUser',
			'userFromAPI',
			'usernameFromData',
			'disabledFromAPI',
			'disabledFromData',
			'groupsFromAPI',
			'groupsFromData',
		])
		expect(info).toHaveBeenCalledTimes(4)
	})
})

describe('ContactpersonenList updateContactpersonenWithUserInfo', () => {
	it('merges bulk user info into the contact persons', () => {
		const withData = { id: 'c1', data: { name: 'A' }, user: { extra: 1 } }
		const withoutUser = { uuid: 'u2' }
		const untouched = { id: 'c3', data: { name: 'C' } }
		const contactpersonen = [withData, withoutUser, untouched]
		const vm = { organisationData: { contactpersonen } }
		ContactpersonenList.methods.updateContactpersonenWithUserInfo.call(vm, {
			c1: {
				hasUser: true,
				username: 'ann',
				groups: ['g'],
				enabled: true,
				displayName: 'Ann',
				lastLogin: 5,
			},
			u2: { hasUser: false, username: '', enabled: false },
		})
		expect(withData).toEqual({
			id: 'c1',
			data: { name: 'A', disabled: false, groups: ['g'], username: 'ann' },
			user: {
				extra: 1,
				hasUser: true,
				username: 'ann',
				groups: ['g'],
				disabled: false,
				displayName: 'Ann',
				lastLogin: 5,
			},
		})
		expect(withoutUser).toEqual({
			uuid: 'u2',
			user: {
				hasUser: false,
				username: '',
				groups: [],
				disabled: true,
				displayName: undefined,
				lastLogin: undefined,
			},
		})
		expect(untouched).toEqual({ id: 'c3', data: { name: 'C' } })
		expect(vm.organisationData.contactpersonen).toEqual([
			withData,
			withoutUser,
			untouched,
		])
		expect(info.mock.calls).toEqual([
			[
				'Updating contactpersoon c1 with user info:',
				expect.objectContaining({ username: 'ann' }),
			],
			[
				'Updating contactpersoon u2 with user info:',
				expect.objectContaining({ enabled: false }),
			],
		])
	})

	it('does nothing without contact persons', () => {
		const vm = { organisationData: {} }
		ContactpersonenList.methods.updateContactpersonenWithUserInfo.call(vm, {
			c1: {},
		})
		expect(vm.organisationData).toEqual({})
		expect(info).not.toHaveBeenCalled()
	})
})
