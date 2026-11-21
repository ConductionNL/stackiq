// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The catalogue role mapping in the admin User groups settings: the store
 * reads it from the user groups config, posts it back on save, and the Role
 * mapping tab edits the source groups, the organisation type defaults and the
 * review interval.
 *
 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-001-a-nextcloud-admin-shall-map-each-catalogue-role-onto-groups-of-their-choice
 */

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { applyRoleMapping, roleMappingSavePayload, useSettingsStore } from '../../src/store/modules/settings.js'

const CONFIG = {
	generic: ['software-catalog-users'],
	organizationAdmin: [],
	superUser: ['admin'],
	allGroups: [{ id: 'Inkoop' }, { id: 'gebruik-beheerder' }, { id: 'Lezers' }],
	roleMapping: {
		'Aanbod-beheerder': { group: 'aanbod-beheerder', sourceGroups: [] },
		'Gebruik-beheerder': { group: 'gebruik-beheerder', sourceGroups: ['Inkoop'] },
	},
	organisationTypeRoles: { Municipality: 'Gebruik-beheerder', Supplier: 'Aanbod-beheerder' },
	accessReviewIntervalDays: 365,
}

describe('settings store role mapping', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		globalThis.OC = { requestToken: 'tok' }
	})

	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('reads the mapping as copies, and leaves it unloaded when the answer has none', () => {
		const store = { roleMapping: {}, organisationTypeRoles: {}, accessReviewIntervalDays: null }
		applyRoleMapping(store, { generic: [] })
		expect(store.roleMapping).toEqual({})

		applyRoleMapping(store, CONFIG)
		expect(store.roleMapping['Gebruik-beheerder']).toEqual({ group: 'gebruik-beheerder', sourceGroups: ['Inkoop'] })
		expect(store.roleMapping['Gebruik-beheerder'].sourceGroups).not.toBe(CONFIG.roleMapping['Gebruik-beheerder'].sourceGroups)
		expect(store.organisationTypeRoles).toEqual({ Municipality: 'Gebruik-beheerder', Supplier: 'Aanbod-beheerder' })
		expect(store.accessReviewIntervalDays).toBe(365)
	})

	it('posts the source groups per role without blanks, the type defaults and the interval', () => {
		expect(roleMappingSavePayload({ roleMapping: {} })).toBeNull()

		const store = {
			roleMapping: { 'Gebruik-beheerder': { group: 'gebruik-beheerder', sourceGroups: ['Inkoop', ' ', ''] } },
			organisationTypeRoles: { Municipality: null },
			accessReviewIntervalDays: '180',
		}
		expect(roleMappingSavePayload(store)).toEqual({
			roleMapping: { 'Gebruik-beheerder': ['Inkoop'] },
			organisationTypeRoles: { Municipality: null },
			accessReviewIntervalDays: 180,
		})
	})

	it('loads the mapping with the user groups and sends it back on save', async () => {
		const posted = []
		vi.stubGlobal('fetch', vi.fn(async (url, init) => {
			if (init?.method === 'POST') {
				posted.push([url, JSON.parse(init.body)])
			}
			return { ok: true, json: async () => ({ success: true, config: CONFIG }) }
		}))
		const store = useSettingsStore()

		await store.loadUserGroupsOnly()
		expect(Object.keys(store.roleMapping)).toEqual(['Aanbod-beheerder', 'Gebruik-beheerder'])

		store.roleMapping['Gebruik-beheerder'].sourceGroups = ['Inkoop', 'Lezers']
		store.accessReviewIntervalDays = 180
		await store.saveConfiguration().catch(() => {})

		const userGroups = posted.find(([url]) => url.endsWith('/api/user-groups/config'))
		expect(userGroups[1]).toMatchObject({
			generic: ['software-catalog-users'],
			superUser: ['admin'],
			roleMapping: { 'Aanbod-beheerder': [], 'Gebruik-beheerder': ['Inkoop', 'Lezers'] },
			accessReviewIntervalDays: 180,
		})
	})
})

describe('UserGroupsConfiguration role mapping tab', () => {
	let UserGroupsConfiguration
	let settingsStore

	beforeEach(async () => {
		vi.resetModules()
		settingsStore = {}
		vi.doMock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
		vi.doMock('../../src/components/AlwaysVisibleSection.vue', () => ({ default: {} }))
		vi.doMock('../../src/components/StandardTabs.vue', () => ({ default: {} }))
		vi.doMock('../../src/store/store.js', () => ({ settingsStore }))
		UserGroupsConfiguration = (await import('../../src/views/settings/sections/UserGroupsConfiguration.vue')).default
		Object.assign(settingsStore, {
			loading: false,
			genericUserGroups: [],
			organizationAdminGroups: [],
			superUserGroups: [],
			allGroups: CONFIG.allGroups,
			roleMapping: JSON.parse(JSON.stringify(CONFIG.roleMapping)),
			organisationTypeRoles: { ...CONFIG.organisationTypeRoles },
			accessReviewIntervalDays: 365,
		})
	})

	/**
	 * The section's `this`, with methods bound and computed values as getters.
	 *
	 * @return {object} The context.
	 */
	function section() {
		const comp = UserGroupsConfiguration
		const ctx = { store: settingsStore, t: (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => vars[k] ?? m) }
		for (const [name, fn] of Object.entries(comp.methods)) {
			ctx[name] = fn.bind(ctx)
		}
		for (const [name, def] of Object.entries(comp.computed)) {
			const get = typeof def === 'function' ? def : def.get
			Object.defineProperty(ctx, name, { get: () => get.call(ctx), configurable: true })
		}
		return ctx
	}

	it('lists the roles and offers every group but the role groups', () => {
		const s = section()
		expect(s.roleNames).toEqual(['Aanbod-beheerder', 'Gebruik-beheerder'])
		expect(s.organisationTypes).toEqual(['Municipality', 'Supplier'])
		expect(s.groupOptions).toEqual(['Inkoop', 'Lezers'])
		expect(s.hasChanges).toBe(true)
	})

	it('maps a role onto groups, trimmed and without duplicates', () => {
		const s = section()
		s.setSourceGroups('Aanbod-beheerder', ['Inkoop', { id: 'Lezers' }, ' Inkoop ', ''])
		expect(settingsStore.roleMapping['Aanbod-beheerder']).toEqual({ group: 'aanbod-beheerder', sourceGroups: ['Inkoop', 'Lezers'] })
	})

	it('sets and clears an organisation type default and the interval', () => {
		const s = section()
		s.setOrganisationTypeRole('Supplier', null)
		s.setOrganisationTypeRole('Municipality', 'Aanbod-beheerder')
		expect(settingsStore.organisationTypeRoles).toEqual({ Municipality: 'Aanbod-beheerder', Supplier: null })

		s.setReviewInterval('180')
		expect(settingsStore.accessReviewIntervalDays).toBe(180)
		s.setReviewInterval('')
		expect(settingsStore.accessReviewIntervalDays).toBeNull()
	})

	it('labels the organisation types, keeping an unknown type as stored', () => {
		const s = section()
		expect([s.organisationTypeLabel('Municipality'), s.organisationTypeLabel('Other')]).toEqual(['Municipality', 'Other'])
	})
})
