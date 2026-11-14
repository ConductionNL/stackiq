/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The organisatie store's getters and reset actions, driven through the
 * fetches that fill the state.
 *
 * @spec openspec/specs/fe-stores/spec.md
 */

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params = {}) => url.replace(/\{(\w+)\}/g, (_, key) => params[key]),
}))

const { useOrganisatieStore } = await import('../../src/store/modules/organisatie.js')

/**
 * A fetch answer with a JSON body.
 *
 * @param {object} body The body.
 * @param {boolean} ok Whether the answer is a success.
 * @return {object} The answer.
 */
function answer(body, ok = true) {
	return { ok, status: ok ? 200 : 500, statusText: ok ? 'OK' : 'Server Error', json: async () => body }
}

describe('organisatie store getters', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		globalThis.OC = { requestToken: 'token' }
		vi.spyOn(console, 'error').mockImplementation(() => {})
	})

	afterEach(() => {
		vi.unstubAllGlobals()
		vi.restoreAllMocks()
		delete globalThis.OC
	})

	it('reports the initial state through every getter', () => {
		const store = useOrganisatieStore()
		expect(store.getContactpersonen).toEqual([])
		expect(store.isLoading).toBe(false)
		expect(store.getError).toBeNull()
		expect(store.getAvailableGroups).toEqual([])
	})

	it('getContactpersonen returns the fetched contactpersonen, and clearContactpersonen empties them', async () => {
		vi.stubGlobal('fetch', vi.fn(async () => answer({ success: true, contactpersonen: [{ id: 'c1' }] })))
		const store = useOrganisatieStore()
		await store.fetchContactpersonen('org-1')
		expect(fetch).toHaveBeenCalledWith(
			'/apps/stackiq/api/contactpersonen/organisation/org-1',
			expect.objectContaining({ method: 'GET' }),
		)
		expect(store.getContactpersonen).toEqual([{ id: 'c1' }])
		store.clearContactpersonen()
		expect(store.getContactpersonen).toEqual([])
	})

	it('isLoading is true while a fetch runs and false after', async () => {
		let release
		vi.stubGlobal('fetch', vi.fn(() => new Promise((resolve) => {
			release = () => resolve(answer({ success: true, contactpersonen: [] }))
		})))
		const store = useOrganisatieStore()
		const pending = store.fetchContactpersonen('org-1')
		expect(store.isLoading).toBe(true)
		release()
		await pending
		expect(store.isLoading).toBe(false)
	})

	it('getError returns the failure message, and clearError resets it', async () => {
		vi.stubGlobal('fetch', vi.fn(async () => answer({ success: false, message: 'Organisatie onbekend' })))
		const store = useOrganisatieStore()
		await store.fetchContactpersonen('org-1')
		expect(store.getError).toBe('Organisatie onbekend')
		expect(store.getContactpersonen).toEqual([])
		store.clearError()
		expect(store.getError).toBeNull()
	})

	it('getAvailableGroups returns the groups the user info answer lists', async () => {
		vi.stubGlobal('fetch', vi.fn(async () => answer({ success: true, availableGroups: ['admin', 'stackiq'], userInfo: { uid: 'u' } })))
		const store = useOrganisatieStore()
		await expect(store.fetchUserInfo('cp-1')).resolves.toEqual({ uid: 'u' })
		expect(store.getAvailableGroups).toEqual(['admin', 'stackiq'])
	})
})
