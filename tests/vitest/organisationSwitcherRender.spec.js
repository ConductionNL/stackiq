// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The organisation switcher as it renders: one entry per other organisation,
 * the "Manage members" entry only for a beheerder, what a click on an entry
 * posts, and a refused switch shown inline without a reload.
 *
 * @spec openspec/specs/multi-org-membership/spec.md#requirement-switching-the-active-organisation-must-be-verified-against-server-side-membership-never-a-client-supplied-claim-req-001
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('../../src/modals/GrantOrganisationAccessModal.vue', () => ({ default: { name: 'GrantOrganisationAccessModal', render: () => null } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path, params = {}) => '/index.php' + path.replace(/\{(\w+)\}/g, (m, k) => params[k] ?? m) }))

const { default: OrganisationSwitcher } = await import('../../src/components/organisations/OrganisationSwitcher.vue')

const ORGANISATIONS = [
	{ uuid: 'o-1', name: 'Gemeente Oost' },
	{ uuid: 'o-2', name: 'Gemeente West' },
	{ uuid: 'o-3', name: 'Samenwerking Noord' },
]

/**
 * Mount the switcher.
 *
 * @param {object} props Its props.
 * @return {object} The wrapper.
 */
function mountSwitcher(props) {
	return mount(OrganisationSwitcher, {
		props: { organisations: ORGANISATIONS, activeOrganisationUuid: 'o-1', ...props },
		global: {
			mocks: { t: (app, text) => text },
			stubs: {
				NcActions: {
					props: ['menuName'],
					render() {
						return h('div', { class: 'actions', 'data-menu': this.menuName }, [this.$slots.icon?.(), this.$slots.default?.()])
					},
				},
				NcActionButton: {
					emits: ['click'],
					render() {
						return h('button', { onClick: () => this.$emit('click') }, this.$slots.default?.())
					},
				},
				NcActionSeparator: { render: () => h('hr') },
				GrantOrganisationAccessModal: {
					props: ['open', 'organisationUuid', 'organisationName'],
					render() {
						return h('div', { class: 'grant-modal', 'data-open': String(this.open), 'data-org': this.organisationUuid })
					},
				},
			},
		},
	})
}

const entries = (wrapper) => wrapper.findAll('button').map((b) => b.text())

let reload

beforeEach(() => {
	globalThis.OC = { requestToken: 'tok' }
	reload = vi.fn()
	Object.defineProperty(window, 'location', { value: { reload }, configurable: true })
})

afterEach(() => {
	vi.unstubAllGlobals()
	delete globalThis.OC
})

describe('OrganisationSwitcher rendering', () => {
	it('names the active organisation and lists the others without member management', () => {
		const wrapper = mountSwitcher({ isBeheerder: false })

		expect(wrapper.find('.actions').attributes('data-menu')).toBe('Gemeente Oost')
		expect(entries(wrapper)).toEqual(['Gemeente West', 'Samenwerking Noord'])
		expect(wrapper.find('hr').exists()).toBe(false)
	})

	it('adds a separator and "Manage members" for a beheerder, opening the modal for the active organisation', async () => {
		const wrapper = mountSwitcher({ isBeheerder: true })

		expect(entries(wrapper)).toEqual(['Gemeente West', 'Samenwerking Noord', 'Manage members'])
		expect(wrapper.find('hr').exists()).toBe(true)
		expect(wrapper.find('.grant-modal').attributes('data-open')).toBe('false')

		await wrapper.findAll('button')[2].trigger('click')

		expect(wrapper.find('.grant-modal').attributes('data-open')).toBe('true')
		expect(wrapper.find('.grant-modal').attributes('data-org')).toBe('o-1')
	})

	it('falls back to a prompt when no organisation is active', () => {
		const wrapper = mountSwitcher({ activeOrganisationUuid: null })

		expect(wrapper.find('.actions').attributes('data-menu')).toBe('Select an organisation')
		expect(entries(wrapper)).toEqual(['Gemeente Oost', 'Gemeente West', 'Samenwerking Noord'])
	})

	it('posts the switch for the clicked organisation and reloads on success', async () => {
		const fetchMock = vi.fn().mockResolvedValue({ ok: true })
		vi.stubGlobal('fetch', fetchMock)
		const wrapper = mountSwitcher({})

		await wrapper.findAll('button')[0].trigger('click')
		await flushPromises()

		expect(fetchMock).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/organisations/o-2/set-active',
			{ method: 'POST', headers: { requesttoken: 'tok' } },
		)
		expect(reload).toHaveBeenCalledTimes(1)
	})

	it('shows a refused switch inline and does not reload', async () => {
		vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, json: () => Promise.resolve({ error: 'Not a member' }) }))
		const wrapper = mountSwitcher({})

		await wrapper.findAll('button')[1].trigger('click')
		await flushPromises()

		expect(wrapper.find('.organisation-switcher__error').text()).toBe('Not a member')
		expect(reload).not.toHaveBeenCalled()
		expect(entries(wrapper)).toEqual(['Gemeente West', 'Samenwerking Noord'])
	})

	it('falls back to a generic message when the refusal has no readable body', async () => {
		vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, json: () => Promise.reject(new Error('not json')) }))
		const wrapper = mountSwitcher({})

		await wrapper.findAll('button')[0].trigger('click')
		await flushPromises()

		expect(wrapper.find('.organisation-switcher__error').text()).toBe('Failed to switch organisation')
	})
})
