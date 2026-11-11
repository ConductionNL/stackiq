// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for switching the active organisation: the switch is allowed
 * only through OpenRegister's endpoint and only reloads the page when the
 * server accepts it; it is refused (no request) for the active organisation
 * and while a switch runs, and a refused or failed request shows the
 * server's error or the fallback and leaves the page as it was.
 *
 * @spec openspec/specs/multi-org-membership/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params) => url + ' ' + JSON.stringify(params),
}))
vi.mock('@nextcloud/vue', () => ({
	NcActionButton: {},
	NcActions: {},
	NcActionSeparator: {},
	NcActionText: {},
	NcLoadingIcon: {},
	NcNoteCard: {},
}))
vi.mock('../../src/modals/GrantOrganisationAccessModal.vue', () => ({ default: {} }))

const { default: OrganisationSwitcher } =
	await import('../../src/components/organisations/OrganisationSwitcher.vue')

let reload

beforeEach(() => {
	globalThis.OC = { requestToken: 'token-1' }
	reload = vi.fn()
	Object.defineProperty(window, 'location', {
		value: { reload },
		configurable: true,
		writable: true,
	})
})

afterEach(() => {
	vi.unstubAllGlobals()
})

/**
 * Runs one switch and records what happened.
 *
 * @param {string} uuid The organisation to switch to.
 * @param {object} setup The fetch answer and the switcher state.
 * @return {Promise<object>} The requests, the reload and the switcher state.
 */
async function run(uuid, setup = {}) {
	const fetch = vi.fn(async () => {
		if (setup.throws) throw new Error(setup.throws)
		return setup.response
	})
	vi.stubGlobal('fetch', fetch)
	const vm = {
		activeOrganisationUuid: 'org-active',
		switching: false,
		errorMessage: 'old',
		t: (app, text) => '[' + text + ']',
		...setup.state,
	}
	for (const [name, fn] of Object.entries(OrganisationSwitcher.methods)) {
		if (!(name in vm)) {
			vm[name] = fn.bind(vm)
		}
	}
	await vm.switchTo(uuid)
	return {
		requests: fetch.mock.calls,
		reloaded: reload.mock.calls.length,
		switching: vm.switching,
		errorMessage: vm.errorMessage,
	}
}

describe('OrganisationSwitcher switchTo', () => {
	it('allows a switch the server accepts and reloads', async () => {
		expect(
			await run('org-b', { response: { ok: true, json: async () => ({}) } }),
		).toEqual({
			requests: [
				[
					'/apps/openregister/api/organisations/{uuid}/set-active {"uuid":"org-b"}',
					{ method: 'POST', headers: { requesttoken: 'token-1' } },
				],
			],
			reloaded: 1,
			switching: true,
			errorMessage: '',
		})
	})

	it('refuses the active organisation and a switch while one runs', async () => {
		expect(await run('org-active')).toEqual({
			requests: [],
			reloaded: 0,
			switching: false,
			errorMessage: 'old',
		})
		expect(await run('org-b', { state: { switching: true } })).toEqual({
			requests: [],
			reloaded: 0,
			switching: true,
			errorMessage: 'old',
		})
	})

	it('refuses a switch the server rejects, with its error or the fallback', async () => {
		expect(
			await run('org-b', {
				response: {
					ok: false,
					json: async () => ({ error: 'Not a member' }),
				},
			}),
		).toEqual({
			requests: [expect.any(Array)],
			reloaded: 0,
			switching: false,
			errorMessage: 'Not a member',
		})
		expect(
			(await run('org-b', { response: { ok: false, json: async () => ({}) } }))
				.errorMessage,
		).toBe('[Failed to switch organisation]')
		expect(
			(
				await run('org-b', {
					response: {
						ok: false,
						json: async () => {
							throw new Error('not json')
						},
					},
				})
			).errorMessage,
		).toBe('[Failed to switch organisation]')
	})

	it('refuses when the request fails', async () => {
		expect(await run('org-b', { throws: 'offline' })).toEqual({
			requests: [expect.any(Array)],
			reloaded: 0,
			switching: false,
			errorMessage: 'offline',
		})
	})
})
