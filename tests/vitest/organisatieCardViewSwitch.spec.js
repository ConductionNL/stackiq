// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation test for the organisation card: switching to the contact
 * person view loads the list's user info after a short delay, only when the
 * view really changes to it and the list can load; and the summary text
 * with its fallbacks.
 *
 * @spec openspec/specs/fe-organizations/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/dialogs', () => ({ showError: () => {}, showSuccess: () => {} }))
vi.mock('../../src/store/modules/organisatie.js', () => ({
	useOrganisatieStore: () => ({}),
}))
vi.mock('../../src/components/ContactpersonenList.vue', () => ({ default: {} }))

const { default: OrganisatieCard } =
	await import('../../src/components/cards/OrganisatieCard.vue')

let info

beforeEach(() => {
	vi.useFakeTimers()
	info = vi.spyOn(console, 'info').mockImplementation(() => {})
})

afterEach(() => {
	vi.useRealTimers()
	vi.restoreAllMocks()
})

/**
 * Switches the card's view and records what it loaded.
 *
 * @param {string} newView The new view.
 * @param {string} oldView The old view.
 * @param {object|undefined} list The contact person list ref.
 * @return {Promise<object>} The loads before and after the delay and the log.
 */
async function switchView(newView, oldView, list) {
	const vm = {
		$refs: { contactpersonenList: list },
		$nextTick: async () => {},
	}
	for (const [name, fn] of Object.entries(OrganisatieCard.methods)) {
		vm[name] = fn.bind(vm)
	}
	await OrganisatieCard.watch.currentView.handler.call(vm, newView, oldView)
	const before = list?.loadUserInfoAndGroups?.mock?.calls.length ?? 0
	await vi.advanceTimersByTimeAsync(100)
	const after = list?.loadUserInfoAndGroups?.mock?.calls.length ?? 0
	return { before, after, logged: info.mock.calls.length }
}

describe('OrganisatieCard currentView handler', () => {
	it('loads user info after switching to the contact persons', async () => {
		expect(
			await switchView('contactpersonen', 'overview', {
				loadUserInfoAndGroups: vi.fn(async () => {}),
			}),
		).toEqual({
			before: 0,
			after: 1,
			logged: 1,
		})
	})

	it('does nothing for other switches', async () => {
		const list = () => ({ loadUserInfoAndGroups: vi.fn(async () => {}) })
		expect(await switchView('overview', 'contactpersonen', list())).toEqual({
			before: 0,
			after: 0,
			logged: 0,
		})
		expect(
			await switchView('contactpersonen', 'contactpersonen', list()),
		).toEqual({ before: 0, after: 0, logged: 0 })
		expect(await switchView('contactpersonen', '', list())).toEqual({
			before: 0,
			after: 0,
			logged: 0,
		})
		expect(await switchView('contactpersonen', undefined, list())).toEqual({
			before: 0,
			after: 0,
			logged: 0,
		})
	})

	it('skips the load when the list cannot load', async () => {
		expect(await switchView('contactpersonen', 'overview', undefined)).toEqual({
			before: 0,
			after: 0,
			logged: 1,
		})
		expect(await switchView('contactpersonen', 'overview', {})).toEqual({
			before: 0,
			after: 0,
			logged: 2,
		})
	})

	it('skips the delayed load when the list is gone by then', async () => {
		const list = { loadUserInfoAndGroups: vi.fn(async () => {}) }
		const vm = {
			$refs: { contactpersonenList: list },
			$nextTick: async () => {},
		}
		for (const [name, fn] of Object.entries(OrganisatieCard.methods)) {
			vm[name] = fn.bind(vm)
		}
		await OrganisatieCard.watch.currentView.handler.call(
			vm,
			'contactpersonen',
			'overview',
		)
		vm.$refs.contactpersonenList = null
		await vi.advanceTimersByTimeAsync(100)
		expect(list.loadUserInfoAndGroups).not.toHaveBeenCalled()
	})
})

describe('OrganisatieCard getOrganisatieSummary', () => {
	it('falls back from the descriptions to the type', () => {
		const summary = (item) =>
			OrganisatieCard.methods.getOrganisatieSummary.call({}, item)
		expect([
			summary({
				shortDescription: 'S',
				longDescription: 'L',
				type: 'T',
				name: 'N',
			}),
			summary({ longDescription: 'L', type: 'T' }),
			summary({ type: 'Gemeente', name: 'N' }),
			summary({ type: 'Gemeente' }),
			summary({ name: 'N' }),
			summary(null),
			summary(undefined),
			summary({ type: '', name: 'N' }),
		]).toEqual(['S', 'L', 'Gemeente organisatie', 'Gemeente', '', '', '', ''])
	})
})
