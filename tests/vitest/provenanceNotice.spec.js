// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Mirrored entries show where they came from: the organisation page of a
 * mirror says "From: <catalogue>" with a link to the original and is read
 * only, and the Organisations cards carry a source badge and offer a mirror
 * no edit or delete. This catalogue's own records show none of it.
 *
 * @spec openspec/changes/federation-pull-fetches-the-peer/specs/federated-catalog-sync/spec.md#requirement-mirrored-entries-show-where-they-came-from-req-fed-011
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { h, markRaw } from 'vue'
import { evaluateVisibleWhenLocal } from '../../node_modules/@conduction/nextcloud-vue/src/utils/visibleWhen.js'
import base from '../../src/manifest.json'
import { PEER_LISTING_PATH, provenanceOf } from '../../src/utils/provenance.js'

const store = vi.hoisted(() => ({ settings: {}, fetchObject: vi.fn() }))

vi.mock('../../src/store/store.js', () => ({ objectStore: store }))
vi.mock('../../src/utils/objectTypes.js', () => ({ ensureObjectTypeOrSlug: vi.fn() }))
vi.mock('../../src/components/ContactpersonenList.vue', () => ({ default: { name: 'ContactpersonenList', template: '<div />' } }))
vi.mock('@nextcloud/l10n', () => {
	const fill = (text, vars = {}) => text.replace(/\{(\w+)\}/g, (match, key) => (key in vars ? vars[key] : match))
	return {
		translate: (app, text, vars) => fill(text, vars),
		translatePlural: (app, one, many, count) => (count === 1 ? one : many).replace('%n', String(count)),
	}
})
vi.mock('@nextcloud/vue', () => ({
	NcNoteCard: { render() { return h('div', { 'data-testid': 'provenance-notice' }, this.$slots.default?.()) } },
	NcActions: { render() { return h('div', this.$slots.default?.()) } },
	NcActionButton: { render() { return h('button', { class: 'card-action' }, this.$slots.default?.()) } },
	NcButton: { render() { return h('button', this.$slots.default?.()) } },
}))

const { default: ProvenanceNotice } = await import('../../src/components/federation/ProvenanceNotice.vue')
const { default: OrganisatieCard } = await import('../../src/components/cards/OrganisatieCard.vue')

const MIRROR = {
	name: 'Gemeente Dordrecht',
	_source: {
		instance: 'https://catalogus.drechtsteden.nl/',
		organisation: 'Softwarecatalogus Drechtsteden',
		peerEntryId: 'a b/1',
	},
}

async function showNotice(record) {
	store.fetchObject.mockImplementation(record instanceof Error ? () => Promise.reject(record) : () => Promise.resolve({ object: record }))
	const wrapper = mount(ProvenanceNotice, { props: { objectId: 'org-1' } })
	await flushPromises()
	return wrapper
}

function mountCard(item) {
	return mount(OrganisatieCard, {
		props: {
			item,
			objectActions: [{ id: 'view', label: 'View' }, { id: 'edit', label: 'Edit' }, { id: 'delete', label: 'Delete' }],
			cardIcon: markRaw({ template: '<i />' }),
		},
		global: { directives: { tooltip: {} } },
	})
}

describe('provenance of a mirrored entry', () => {
	afterEach(() => vi.clearAllMocks())

	it('names the peer catalogue and links to the entry at the peer', () => {
		expect(provenanceOf(MIRROR)).toEqual({
			name: 'Softwarecatalogus Drechtsteden',
			url: 'https://catalogus.drechtsteden.nl' + PEER_LISTING_PATH + '/a%20b%2F1',
		})
		expect(provenanceOf({ _source: { instance: 'https://apps.wshd.nl' + PEER_LISTING_PATH, peerEntryId: 7 } })).toEqual({
			name: 'apps.wshd.nl',
			url: 'https://apps.wshd.nl' + PEER_LISTING_PATH + '/7',
		})
		expect(provenanceOf({ _source: { instance: 'peer-without-scheme', organisation: 'peer-without-scheme' } })).toEqual({ name: 'peer-without-scheme', url: null })
	})

	it('is null for this catalogue\'s own records', () => {
		for (const record of [null, {}, { _source: null }, { _source: { instance: '  ' } }, { _source: 'x' }]) {
			expect(provenanceOf(record)).toBeNull()
		}
	})

	it('says "From:" with the link on the page of a mirror', async () => {
		const wrapper = await showNotice(MIRROR)

		expect(store.fetchObject).toHaveBeenCalledWith('organization', 'org-1')
		expect(wrapper.find('[data-testid="provenance-notice"]').text()).toContain('From: Softwarecatalogus Drechtsteden')
		const link = wrapper.find('a.provenance-link')
		expect(link.attributes('href')).toBe('https://catalogus.drechtsteden.nl' + PEER_LISTING_PATH + '/a%20b%2F1')
		expect(link.attributes('rel')).toBe('noopener noreferrer')
	})

	it('shows nothing for an own or unreadable record, or without an id', async () => {
		expect((await showNotice({ name: 'Eigen' })).find('[data-testid="provenance-notice"]').exists()).toBe(false)
		expect((await showNotice(new Error('404'))).find('[data-testid="provenance-notice"]').exists()).toBe(false)

		store.fetchObject.mockClear()
		const wrapper = mount(ProvenanceNotice, { props: { objectId: '' } })
		await flushPromises()
		expect(store.fetchObject).not.toHaveBeenCalled()
		expect(wrapper.html()).not.toContain('From:')
	})

	it('keeps the organisation page of a mirror read only and shows the notice first', () => {
		const detail = base.pages.find((page) => page.id === 'OrganisatieDetail').config
		const widget = detail.bodyWidgets.find((w) => w.id === 'org-provenance')

		expect(widget).toMatchObject({ component: 'ProvenanceNotice', placement: 'before-body', props: { objectId: '@objectId' } })
		expect(evaluateVisibleWhenLocal(detail.readOnlyWhen, MIRROR)).toBe(true)
		expect(evaluateVisibleWhenLocal(detail.readOnlyWhen, { name: 'Eigen' })).toBe(false)
	})

	it('badges a mirror on the Organisations cards and offers it no edit or delete', () => {
		const mirror = mountCard(MIRROR)
		expect(mirror.find('[data-testid="organisatie-source"]').text()).toBe('From: Softwarecatalogus Drechtsteden')
		expect(mirror.findAll('.card-action').map((b) => b.text())).toEqual(['View'])

		const own = mountCard({ name: 'Eigen' })
		expect(own.find('[data-testid="organisatie-source"]').exists()).toBe(false)
		expect(own.findAll('.card-action').map((b) => b.text())).toEqual(['View', 'Edit', 'Delete'])
	})
})
