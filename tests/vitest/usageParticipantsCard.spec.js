// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Participants card on an application in use and the Shared cell on the
 * list: the owner, each participant with the date it joined, the read-only
 * note for a participant, and "owner, count" in the list
 * (shared-usage-visible-to-participants task 3).
 *
 * @spec openspec/changes/archive/2026-11-24-shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-participant-reads-the-usages-shared-with-it-and-their-connections-req-dlg-010
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const mockFetchObject = vi.hoisted(() => vi.fn())

vi.mock('../../src/store/store.js', () => ({
	objectStore: { fetchObject: (...a) => mockFetchObject(...a) },
}))
vi.mock('../../src/utils/objectTypes.js', () => ({
	ensureObjectTypeOrSlug: vi.fn(),
}))
vi.mock('@nextcloud/l10n', () => {
	const fill = (text, vars) => String(text).replace(/\{(\w+)\}/g, (_, key) => String(vars?.[key] ?? `{${key}}`))
	return {
		translate: (app, text, vars) => fill(text, vars),
		translatePlural: (app, one, many, count, vars) => fill(count === 1 ? one : many, vars),
	}
})

function stub(name) {
	return defineComponent({
		name,
		props: ['name', 'size', 'type', 'title', 'to'],
		render() {
			return h('div', { class: name }, [this.$slots.default?.() ?? [], this.$slots['title-icon']?.() ?? []])
		},
	})
}

vi.mock('@nextcloud/vue', () => ({
	NcLoadingIcon: stub('NcLoadingIcon'),
	NcNoteCard: stub('NcNoteCard'),
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnIcon: stub('CnIcon'),
	CnWidgetWrapper: stub('CnWidgetWrapper'),
}))

const { default: UsageParticipantsCard } = await import('../../src/components/usages/UsageParticipantsCard.vue')
const { default: SharedUsageCell } = await import('../../src/components/cells/SharedUsageCell.vue')

const RouterLink = stub('RouterLink')
const organisations = { 'org-zd': 'Drechtsteden-Zuid', 'org-alb': 'Alblasserdam', 'org-pap': 'Papendrecht' }

function serve(usage) {
	mockFetchObject.mockImplementation(async (type, id) => {
		if (type === 'usage') {
			return usage
		}
		if (organisations[id]) {
			return { id, name: organisations[id] }
		}
		throw new Error('not readable')
	})
}

function mountCard() {
	return mount(UsageParticipantsCard, {
		props: { objectId: 'u-mozard' },
		global: { components: { RouterLink }, stubs: { 'router-link': RouterLink } },
	})
}

describe('UsageParticipantsCard', () => {
	beforeEach(() => {
		mockFetchObject.mockReset()
	})

	it('names the owner and each participant with the date it joined', async () => {
		serve({ consumer: 'org-zd', participants: [{ id: 'org-alb', since: '2025-03-01' }, 'org-pap'] })
		const wrapper = mountCard()
		await flushPromises()

		expect(wrapper.get('[data-testid="usage-participants-owner"]').text()).toBe('Run by Drechtsteden-Zuid for 3 organisations')
		const rows = wrapper.findAll('[data-testid="usage-participant"]')
		expect(rows.map((row) => row.findAll('td')[0].text())).toEqual(['Alblasserdam', 'Papendrecht'])
		expect(rows[0].findAll('td')[1].text()).toBe(new Date('2025-03-01').toLocaleDateString())
		expect(rows[1].findAll('td')[1].text()).toBe('Not recorded')
		expect(wrapper.find('[data-testid="usage-participants-read-only"]').exists()).toBe(false)
		expect(mockFetchObject).toHaveBeenCalledWith('usage', 'u-mozard')
	})

	it('tells a participant it may not change the usage', async () => {
		serve({ consumer: 'org-zd', participants: ['org-alb'], '@self': { can: { update: false } } })
		const wrapper = mountCard()
		await flushPromises()

		expect(wrapper.find('[data-testid="usage-participants-read-only"]').exists()).toBe(true)
	})

	it('keeps the uuid of an organisation it cannot read', async () => {
		serve({ consumer: 'org-hidden', participants: ['org-unknown'] })
		const wrapper = mountCard()
		await flushPromises()

		expect(wrapper.get('[data-testid="usage-participants-owner"]').text()).toBe('Run by org-hidden for 2 organisations')
		expect(wrapper.get('[data-testid="usage-participant"]').text()).toContain('org-unknown')
	})

	it('says when the usage is not shared', async () => {
		serve({ consumer: 'org-zd', participants: [] })
		const wrapper = mountCard()
		await flushPromises()

		expect(wrapper.text()).toContain('This application in use is not shared with other organisations')
		expect(wrapper.find('[data-testid="usage-participant"]').exists()).toBe(false)
	})

	it('reports a usage it cannot load', async () => {
		mockFetchObject.mockRejectedValue(new Error('down'))
		const wrapper = mountCard()
		await flushPromises()

		expect(wrapper.text()).toContain('The participants could not be loaded.')
	})
})

describe('SharedUsageCell', () => {
	beforeEach(() => {
		mockFetchObject.mockReset()
	})

	it('shows the owner and the number of organisations', async () => {
		serve({})
		const wrapper = mount(SharedUsageCell, { props: { value: ['org-alb', 'org-pap'], row: { consumer: 'org-zd' } } })
		await flushPromises()

		expect(wrapper.text()).toBe('Drechtsteden-Zuid, 3')
		expect(wrapper.get('[data-testid="shared-usage-cell"]').attributes('title')).toBe('Shared by 3 organisations')
	})

	it('uses an extended owner name without fetching', async () => {
		const wrapper = mount(SharedUsageCell, { props: { value: ['org-alb'], row: { consumer: { id: 'org-zd', name: 'DZ' } } } })
		await flushPromises()

		expect(wrapper.text()).toBe('DZ, 2')
		expect(mockFetchObject).not.toHaveBeenCalled()
	})

	it('is empty for a usage without participants', () => {
		const wrapper = mount(SharedUsageCell, { props: { value: [], row: { consumer: 'org-zd' } } })

		expect(wrapper.find('[data-testid="shared-usage-cell"]').exists()).toBe(false)
	})
})
