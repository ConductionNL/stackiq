// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Mounts the organisation card and pins what it renders: the badges, the
 * description fallbacks, the contact details and their links, the actions
 * menu, the contact person toggle and the keyboard activation of the card.
 *
 * @spec openspec/specs/fe-shell-navigation/spec.md
 */

import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { markRaw } from 'vue'

vi.mock('../../src/components/ContactpersonenList.vue', () => ({
	default: { name: 'ContactpersonenList', props: ['organisationId', 'organisationData'], template: '<div class="contactpersonen-list-stub" />' },
}))

const { default: OrganisatieCard } = await import('../../src/components/cards/OrganisatieCard.vue')

/**
 * A stub that renders its icon and default slots and re-emits clicks.
 *
 * @param {string} name The component name.
 * @param {string} [tag] The element to render.
 * @return {object} The stub.
 */
function slotStub(name, tag = 'div') {
	return {
	name,
	inheritAttrs: false,
	emits: ['click'],
	template: `<${tag} class="${name}-stub" :data-variant="$attrs.variant" :data-disabled="String(!!$attrs.disabled)" @click="$emit('click')"><slot name="icon" /><slot /></${tag}>`,
	}
}

/**
 * Mount the card for an item.
 *
 * @param {object} item The organisation.
 * @param {Array<object>} [objectActions] The actions menu.
 * @return {import('@vue/test-utils').VueWrapper} The wrapper.
 */
function mountCard(item, objectActions = []) {
	return mount(OrganisatieCard, {
		props: { item, objectActions, cardIcon: markRaw({ template: '<i class="card-icon" />' }) },
		global: {
			stubs: {
				NcActions: slotStub('NcActions'),
				NcActionButton: slotStub('NcActionButton', 'button'),
				NcButton: slotStub('NcButton', 'button'),
				DotsHorizontal: true,
				Globe: true,
				Email: true,
				Phone: true,
				Certificate: true,
				AccountMultiple: true,
			},
			directives: { tooltip: {} },
			mocks: { t: (app, text) => text },
		},
	})
}

beforeEach(() => {
	vi.spyOn(console, 'info').mockImplementation(() => {})
})

afterEach(() => {
	vi.restoreAllMocks()
})

describe('OrganisatieCard render', () => {
	it('renders the title, badges, short description and every contact detail', () => {
		const wrapper = mountCard({
			name: 'Gemeente Tilburg',
			type: 'Gemeente',
			status: 'Actief',
			shortDescription: 'Een gemeente in Brabant',
			longDescription: 'Lang verhaal',
			website: 'tilburg.nl',
			'e-mailadres': 'info@tilburg.nl',
			telefoonnummer: '14 013',
			kvkNummer: '12345678',
		})

		expect(wrapper.find('h2').text()).toBe('Gemeente Tilburg')
		expect(wrapper.find('.organisatieCard').attributes('aria-label')).toBe('Open Gemeente Tilburg')
		expect(wrapper.find('.typeBadge').classes()).toContain('type-gemeente')
		expect(wrapper.find('.statusBadge').classes()).toContain('status-actief')
		expect(wrapper.find('.shortDescription').text()).toBe('Een gemeente in Brabant')
		expect(wrapper.find('.longDescription').exists()).toBe(false)

		const links = wrapper.findAll('.detailItem a')
		expect(links.map((link) => link.attributes('href'))).toEqual(['https://tilburg.nl', 'mailto:info@tilburg.nl'])
		expect(links[0].attributes('rel')).toBe('noopener')
		expect(wrapper.text()).toContain('14 013')
		expect(wrapper.text()).toContain('KvK: 12345678')
		expect(wrapper.find('.contactCountRow').exists()).toBe(false)
	})

	it('falls back to a shortened long description, then to a placeholder', () => {
		const long = 'woord '.repeat(40)
		const withLong = mountCard({ name: 'A', longDescription: long })
		expect(withLong.find('.longDescription').text()).toBe(long.substring(0, 150).trim() + '...')

		const bare = mountCard({})
		expect(bare.find('h2').text()).toBe('Unknown Organisation')
		expect(bare.find('.noDescription').text()).toBe('No description available')
		expect(bare.find('.typeBadge').exists()).toBe(false)
		expect(bare.find('.statusBadge').exists()).toBe(false)
		expect(bare.findAll('.detailItem')).toHaveLength(0)
	})

	it('lists the actions, disables those whose condition fails and runs the handler', async () => {
		const handler = vi.fn()
		const item = { name: 'A', status: 'merged' }
		const wrapper = mountCard(item, [
			{ id: 'edit', label: 'Bewerken', icon: { template: '<i />' }, handler },
			{ id: 'merge', label: 'Samenvoegen', icon: { template: '<i />' }, condition: (org) => org.status !== 'merged' },
		])

		const buttons = wrapper.findAll('.NcActionButton-stub')
		expect(buttons.map((button) => button.text())).toEqual(['Bewerken', 'Samenvoegen'])
		expect(buttons.map((button) => button.attributes('data-disabled'))).toEqual(['false', 'true'])

		await buttons[0].trigger('click')
		expect(handler).toHaveBeenCalledWith(item)
		expect(wrapper.emitted('click')).toBeUndefined()
	})

	it('emits the item on a click and on Enter or Space', async () => {
		const item = { name: 'A' }
		const wrapper = mountCard(item)

		const card = wrapper.find('.organisatieCard')
		expect(card.attributes('role')).toBe('button')
		expect(card.attributes('tabindex')).toBe('0')

		await card.trigger('click')
		await card.trigger('keydown.enter')
		await card.trigger('keydown.space')

		// The card declares no emits, so the native click is recorded too; count the item payloads.
		expect(wrapper.emitted('click').filter(([payload]) => payload?.name === item.name)).toHaveLength(3)
	})

	it('toggles between the organisation and its contact persons', async () => {
		const item = { id: 'org-1', name: 'A', adres: 'Stationsplein 1', plaats: 'Tilburg', contactpersonen: [{}, {}] }
		const wrapper = mountCard(item)

		expect(wrapper.find('.contactCount').text()).toBe('2 contactpersonen')
		const toggle = wrapper.find('.viewToggleContainer .NcButton-stub')
		expect(toggle.text()).toBe('Bekijk contactpersonen')
		expect(toggle.attributes('data-variant')).toBe('secondary')

		await toggle.trigger('click')

		expect(wrapper.find('.organisatieBadges').exists()).toBe(false)
		const list = wrapper.findComponent({ name: 'ContactpersonenList' })
		expect(list.props('organisationId')).toBe('org-1')
		expect(list.props('organisationData')).toEqual(item)
		const back = wrapper.find('.contactpersonenView .NcButton-stub')
		expect(back.text()).toBe('Bekijk organisatie')
		expect(back.attributes('data-variant')).toBe('secondary')
		expect(wrapper.emitted('click')).toBeUndefined()

		await back.trigger('click')
		expect(wrapper.find('.organisatieBadges').exists()).toBe(true)
	})

	it('passes the uuid to the contact person list when the item has no id', async () => {
		const wrapper = mountCard({ uuid: 'uuid-9', name: 'A', contactpersonen: [{}] })

		await wrapper.find('.viewToggleContainer .NcButton-stub').trigger('click')

		expect(wrapper.findComponent({ name: 'ContactpersonenList' }).props('organisationId')).toBe('uuid-9')
	})
})
