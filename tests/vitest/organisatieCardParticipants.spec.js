// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A cooperation shows its participants: "Cooperation · N participants" on its
 * card on Organisations, and the participant organisations, each linked, on
 * its own page (shared-usage-visible-to-participants task 5).
 *
 * @spec openspec/changes/shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-cooperation-shows-its-participants-req-dlg-012
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, markRaw } from 'vue'

const mockFetchObject = vi.hoisted(() => vi.fn())

vi.mock('../../src/components/ContactpersonenList.vue', () => ({
	default: { name: 'ContactpersonenList', props: ['organisationId', 'organisationData'], template: '<div />' },
}))
vi.mock('../../src/store/store.js', () => ({
	objectStore: { fetchObject: (...a) => mockFetchObject(...a) },
}))
vi.mock('../../src/utils/objectTypes.js', () => ({ ensureObjectTypeOrSlug: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one, many, count) => (count === 1 ? one : many).replace('%n', String(count)),
}))

function stub(name) {
	return defineComponent({
		name,
		props: ['name', 'size', 'type', 'title', 'to'],
		render() {
			return h('div', { class: name }, [this.$slots.default?.() ?? [], this.$slots['title-icon']?.() ?? []])
		},
	})
}

vi.mock('@conduction/nextcloud-vue', () => ({
	CnIcon: stub('CnIcon'),
	CnWidgetWrapper: stub('CnWidgetWrapper'),
}))

const { default: OrganisatieCard } = await import('../../src/components/cards/OrganisatieCard.vue')
const { default: OrganisationParticipantsList } = await import('../../src/components/organisations/OrganisationParticipantsList.vue')

const RouterLink = defineComponent({
	name: 'RouterLink',
	props: ['to'],
	render() {
		return h('a', { 'data-to': JSON.stringify(this.to) }, this.$slots.default?.())
	},
})

function mountCard(item) {
	return mount(OrganisatieCard, {
		props: { item, objectActions: [], cardIcon: markRaw({ template: '<i />' }) },
		global: {
			stubs: { NcActions: true, NcActionButton: true, NcButton: true, DotsHorizontal: true, Globe: true, Email: true, Phone: true, Certificate: true, AccountMultiple: true },
			directives: { tooltip: {} },
			mocks: { t: (app, text) => text },
		},
	})
}

describe('OrganisatieCard participants', () => {
	beforeEach(() => {
		vi.spyOn(console, 'info').mockImplementation(() => {})
	})

	it('reads "Cooperation · 4 participants" for a cooperation', () => {
		const wrapper = mountCard({ id: 'org-dz', name: 'Samenwerking Drechtsteden-Zuid', type: 'Collaboration', participants: ['a', 'b', { id: 'c' }, 'd', 'a'] })

		expect(wrapper.get('[data-testid="organisatie-participants"]').text()).toBe('Cooperation · 4 participants')
	})

	it('uses the singular for one participant and counts none as zero', () => {
		expect(mountCard({ type: 'Samenwerking', participants: ['a'] }).get('[data-testid="organisatie-participants"]').text()).toBe('Cooperation · 1 participant')
		expect(mountCard({ type: 'Collaboration' }).get('[data-testid="organisatie-participants"]').text()).toBe('Cooperation · 0 participants')
	})

	it('shows no participant line for a municipality', () => {
		expect(mountCard({ type: 'Municipality', participants: ['a'] }).find('[data-testid="organisatie-participants"]').exists()).toBe(false)
	})
})

describe('OrganisationParticipantsList', () => {
	beforeEach(() => {
		mockFetchObject.mockReset()
	})

	function mountList() {
		return mount(OrganisationParticipantsList, {
			props: { objectId: 'org-dz' },
			global: { components: { RouterLink }, stubs: { 'router-link': RouterLink } },
		})
	}

	it('links each participant organisation by name', async () => {
		mockFetchObject.mockImplementation(async (type, id) => {
			if (id === 'org-dz') {
				return { id, type: 'Collaboration', participants: ['org-alb', { id: 'org-pap', name: 'Papendrecht' }, 'org-hidden'] }
			}
			if (id === 'org-alb') {
				return { id, name: 'Alblasserdam' }
			}
			throw new Error('not readable')
		})
		const wrapper = mountList()
		await flushPromises()

		const items = wrapper.findAll('[data-testid="organisation-participant"]')
		expect(items.map((item) => item.text())).toEqual(['Alblasserdam', 'Papendrecht', 'org-hidden'])
		expect(JSON.parse(items[0].get('a').attributes('data-to'))).toEqual({ name: 'OrganisatieDetail', params: { id: 'org-alb' } })
		expect(mockFetchObject).not.toHaveBeenCalledWith('organization', 'org-pap')
	})

	it('renders nothing for an organisation that is not a cooperation', async () => {
		mockFetchObject.mockResolvedValue({ id: 'org-dz', type: 'Municipality', participants: ['org-alb'] })
		const wrapper = mountList()
		await flushPromises()

		expect(wrapper.find('[data-testid="organisation-participants"]').exists()).toBe(false)
	})

	it('reports a failed load', async () => {
		mockFetchObject.mockRejectedValue(new Error('down'))
		const wrapper = mountList()
		await flushPromises()

		expect(wrapper.text()).toContain('The participants could not be loaded.')
	})
})
