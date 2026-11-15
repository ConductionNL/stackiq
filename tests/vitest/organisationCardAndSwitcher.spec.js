// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The organisation card (title, summary, links, the contact person view and
 * its user info) and the organisation switcher (switching the active
 * organisation and explaining a refusal), each run against a plain component
 * context.
 *
 * @spec openspec/specs/fe-shell-navigation/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (path, params = {}) => '/index.php' + path.replace(/\{(\w+)\}/g, (m, k) => params[k] ?? m) }))
vi.mock('../../src/components/ContactpersonenList.vue', () => ({ default: {} }))
vi.mock('../../src/modals/GrantOrganisationAccessModal.vue', () => ({ default: {} }))

const { default: OrganisatieCard } = await import('../../src/components/cards/OrganisatieCard.vue')
const { default: OrganisationSwitcher } = await import('../../src/components/organisations/OrganisationSwitcher.vue')

const t = (app, text) => text

/**
 * A component context: props and extras, methods bound, data, and computed
 * values as getters.
 *
 * @param {object} comp The component definition.
 * @param {object} [props] Its props.
 * @param {object} [extra] Anything else on `this`.
 * @return {object} The context.
 */
function context(comp, props = {}, extra = {}) {
	const ctx = { $emit: vi.fn(), $nextTick: async () => {}, $refs: {}, t, ...props, ...extra }
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
	globalThis.OC = { requestToken: 'tok' }
	vi.spyOn(console, 'info').mockImplementation(() => {})
})

afterEach(() => {
	vi.restoreAllMocks()
	vi.unstubAllGlobals()
	vi.useRealTimers()
})

describe('OrganisatieCard', () => {
	const card = (item = {}) => context(OrganisatieCard, { item, objectActions: [], cardIcon: 'icon' })

	it('titles and summarises the organisation from what it has', () => {
		const c = card()
		expect([c.getOrganisatieTitle({ name: 'A' }), c.getOrganisatieTitle({ '@self': { name: 'B' } }), c.getOrganisatieTitle(null)]).toEqual(['A', 'B', 'Unknown Organisation'])
		expect([
			c.getOrganisatieSummary({ shortDescription: 's', longDescription: 'l' }),
			c.getOrganisatieSummary({ longDescription: 'l' }),
			c.getOrganisatieSummary({ type: 'Gemeente', name: 'A' }),
			c.getOrganisatieSummary({ type: 'Gemeente' }),
			c.getOrganisatieSummary(undefined),
		]).toEqual(['s', 'l', 'Gemeente organisatie', 'Gemeente', ''])
	})

	it('makes a website link and shortens long text at a word', () => {
		const c = card()
		expect([c.formatWebsiteUrl(''), c.formatWebsiteUrl('http://a.nl'), c.formatWebsiteUrl('https://b.nl'), c.formatWebsiteUrl('c.nl')]).toEqual(['#', 'http://a.nl', 'https://b.nl', 'https://c.nl'])
		expect([c.truncateText(''), c.truncateText('short'), c.truncateText('a'.repeat(150)), c.truncateText('word '.repeat(40), 12)]).toEqual(['', 'short', 'a'.repeat(150), 'word word wo...'])
	})

	it('runs an action handler, emits a click and counts contact persons', () => {
		const item = { id: 'o1', contactpersonen: [1, 2] }
		const c = card(item)
		const handler = vi.fn()
		c.executeObjectAction({ handler }, item)
		c.executeObjectAction({}, item)
		c.handleCardClick()
		expect([handler.mock.calls[0], c.$emit.mock.calls[0], c.getContactpersonenCount(), card({}).getContactpersonenCount()]).toEqual([[item], ['click', item], 2, 0])
	})

	it('switches between the organisation and its contact persons', async () => {
		const c = card()
		await c.toggleView()
		expect(c.currentView).toBe('contactpersonen')
		await c.toggleView()
		expect(c.currentView).toBe('organization')
	})

	it('loads the contact persons user info shortly after switching to them', async () => {
		vi.useFakeTimers()
		const c = card()
		const load = vi.fn()
		c.$refs.contactpersonenList = { loadUserInfoAndGroups: load }
		const handler = OrganisatieCard.watch.currentView.handler
		await handler.call(c, 'contactpersonen', undefined)
		await handler.call(c, 'organization', 'contactpersonen')
		await handler.call(c, 'contactpersonen', 'organization')
		expect(load).not.toHaveBeenCalled()
		await vi.advanceTimersByTimeAsync(100)
		expect(load).toHaveBeenCalledTimes(1)
		c.$refs = {}
		await handler.call(c, 'contactpersonen', 'organization')
		await vi.advanceTimersByTimeAsync(100)
		expect(load).toHaveBeenCalledTimes(1)
	})

	it('writes the address with postcode only beside a place', () => {
		expect([card({ adres: 'Dorp 1', plaats: 'Utrecht', postcode: '1234 AB' }).getOrganisatieAdres(), card({ plaats: 'Utrecht', postcode: '1234 AB' }).getOrganisatieAdres(), card({}).getOrganisatieAdres()]).toEqual(['Dorp 1, 1234 AB Utrecht', 'Utrecht', 'Geen adres beschikbaar'])
	})
})

describe('OrganisationSwitcher', () => {
	const organisations = [{ uuid: 'o1', name: 'Gemeente A' }, { uuid: 'o2', name: 'Gemeente B' }]
	const switcher = (active = 'o1') => context(OrganisationSwitcher, { organisations, activeOrganisationUuid: active, isBeheerder: true })

	it('names the active organisation and offers the others', () => {
		const s = switcher()
		expect([s.activeOrganisationName, s.otherOrganisations.map((o) => o.uuid)]).toEqual(['Gemeente A', ['o2']])
		expect(switcher(null).activeOrganisationName).toBe('Select an organisation')
	})

	it('switches to another organisation and reloads the page', async () => {
		const fetchSpy = vi.fn(async () => ({ ok: true }))
		const reload = vi.fn()
		vi.stubGlobal('fetch', fetchSpy)
		vi.stubGlobal('location', { reload })
		const s = switcher()
		await s.switchTo('o1')
		s.switching = true
		await s.switchTo('o2')
		expect(fetchSpy).not.toHaveBeenCalled()
		s.switching = false
		await s.switchTo('o2')
		expect([fetchSpy.mock.calls[0], reload.mock.calls.length, s.switching]).toEqual([['/index.php/apps/openregister/api/organisations/o2/set-active', { method: 'POST', headers: { requesttoken: 'tok' } }], 1, true])
	})

	it('explains a refused switch and lets the user try again', async () => {
		vi.stubGlobal('location', { reload: vi.fn() })
		vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, json: async () => ({ error: 'Not a member' }) })))
		const s = switcher()
		await s.switchTo('o2')
		expect([s.errorMessage, s.switching]).toEqual(['Not a member', false])
		vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, json: async () => { throw new Error('not json') } })))
		await s.switchTo('o2')
		expect(s.errorMessage).toBe('Failed to switch organisation')
		vi.stubGlobal('fetch', vi.fn(async () => { throw new Error('offline') }))
		await s.switchTo('o2')
		expect(s.errorMessage).toBe('offline')
		expect(window.location.reload).not.toHaveBeenCalled()
	})
})
