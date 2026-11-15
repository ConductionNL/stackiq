// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * FederationSettings as rendered: the availability banners, the Pull now
 * gate, one row per peer with its health badge and failure count, adding a
 * peer (button and Enter, trimmed, refused when blank), removing the clicked
 * peer, and the pull summary.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({ status: {}, calls: [], fail: null }))
const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))

vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@nextcloud/l10n', () => {
	const fill = (text, vars = {}) => text.replace(/{(\w+)}/g, (match, key) => (key in vars ? vars[key] : match))
	return {
		translate: (app, text, vars) => fill(text, vars),
		translatePlural: (app, one, many, count, vars) => fill(count === 1 ? one : many, vars),
	}
})
vi.mock('../../src/utils/adminApi.js', async (importOriginal) => ({
	...(await importOriginal()),
	apiRequest: async (route, init) => {
		api.calls.push(init === undefined ? [route] : [route, init])
		if (api.fail && init) {
			throw api.fail
		}
		if (route === 'federation/pull') {
			return { peers: ['a', 'b'] }
		}
		return route === 'federation/status' ? api.status : {}
	},
}))

const { default: FederationSettings } = await import('../../src/views/settings/sections/FederationSettings.vue')

const stubs = {
	AlwaysVisibleSection: {
		emits: ['refresh'],
		template: '<section><div data-testid="header"><slot name="header-actions" /></div><slot /></section>',
	},
	NcNoteCard: { props: ['type'], template: '<div :data-note="type"><slot /></div>' },
	NcEmptyContent: { props: ['name'], template: '<div data-testid="empty">{{ name }}</div>' },
	NcButton: {
		props: ['disabled', 'variant'],
		emits: ['click'],
		template: '<button v-bind="$attrs" :data-variant="variant" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcTextField: {
		props: ['modelValue', 'disabled'],
		emits: ['update:modelValue'],
		template: '<input data-testid="peer-url" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
	},
	NcLoadingIcon: true,
	Sync: true,
	Plus: true,
	Delete: true,
	LanDisconnect: true,
}

const PEERS = [
	{ url: 'https://ok.example', failures: 0 },
	{ url: 'https://stale.example', failures: 4, stale: true },
	{ url: 'https://blocked.example', failures: 1, allowed: false },
]

async function mountSettings(status) {
	api.status = status
	const wrapper = mount(FederationSettings, { global: { stubs } })
	await flushPromises()
	return wrapper
}

const pullButton = (wrapper) => wrapper.find('[data-testid="header"] button')
const addButton = (wrapper) => wrapper.find('[data-variant="secondary"]')

beforeEach(() => {
	api.calls = []
	api.fail = null
	vi.clearAllMocks()
})

describe('FederationSettings mounted', () => {
	it('warns when federation is unavailable, and offers no pull', async () => {
		const wrapper = await mountSettings({ available: false, peers: PEERS })

		expect(wrapper.find('[data-note="warning"]').text()).toContain('Federation is unavailable')
		expect(pullButton(wrapper).attributes('disabled')).toBeDefined()
		expect(wrapper.text()).toContain('No directory configured')
	})

	it('warns when federation is disabled, and offers no pull', async () => {
		const wrapper = await mountSettings({ available: true, enabled: false, peers: PEERS })

		expect(wrapper.find('[data-note="warning"]').text()).toContain('Federation is available but disabled')
		expect(pullButton(wrapper).attributes('disabled')).toBeDefined()
	})

	it('offers no pull without peers, and shows the empty state', async () => {
		const wrapper = await mountSettings({ available: true, enabled: true, peers: [] })

		expect(wrapper.find('[data-note="warning"]').exists()).toBe(false)
		expect(pullButton(wrapper).attributes('disabled')).toBeDefined()
		expect(wrapper.find('[data-testid="empty"]').text()).toBe('No peers subscribed')
	})

	it('lists every peer with its health and failure count', async () => {
		const wrapper = await mountSettings({
			available: true,
			enabled: true,
			directoryUrl: 'https://directory.example',
			staleAfter: 5,
			peers: PEERS,
		})

		expect(wrapper.text()).toContain('https://directory.example')
		const rows = wrapper.findAll('li.peer-item').map((li) => ({
			url: li.find('.peer-url').text(),
			badge: li.find('.peer-badge').text(),
			failures: li.find('.peer-failures').text(),
		}))
		expect(rows).toEqual([
			{ url: 'https://ok.example', badge: 'Healthy', failures: '0 consecutive failures (stale after 5)' },
			{ url: 'https://stale.example', badge: 'Stale', failures: '4 consecutive failures (stale after 5)' },
			{ url: 'https://blocked.example', badge: 'Blocked by SSRF guard', failures: '1 consecutive failure (stale after 5)' },
		])
		expect(pullButton(wrapper).attributes('disabled')).toBeUndefined()
	})

	it('adds the trimmed peer, clears the field and reloads', async () => {
		const wrapper = await mountSettings({ available: true, enabled: true, peers: [] })
		expect(addButton(wrapper).attributes('disabled')).toBeDefined()

		await wrapper.find('[data-testid="peer-url"]').setValue('  https://new.example  ')
		expect(addButton(wrapper).attributes('disabled')).toBeUndefined()
		await addButton(wrapper).trigger('click')
		await flushPromises()

		expect(api.calls.slice(1)).toEqual([
			['federation/peers', { method: 'POST', body: { peerUrl: 'https://new.example' } }],
			['federation/status'],
		])
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Peer added')
		expect(wrapper.find('[data-testid="peer-url"]').element.value).toBe('')
	})

	it('adds on Enter, and ignores a blank Enter', async () => {
		const wrapper = await mountSettings({ available: true, enabled: true, peers: [] })

		await wrapper.find('[data-testid="peer-url"]').setValue('   ')
		await wrapper.find('[data-testid="peer-url"]').trigger('keydown.enter')
		await flushPromises()
		expect(api.calls).toHaveLength(1)

		await wrapper.find('[data-testid="peer-url"]').setValue('https://enter.example')
		await wrapper.find('[data-testid="peer-url"]').trigger('keydown.enter')
		await flushPromises()
		expect(api.calls[1]).toEqual(['federation/peers', { method: 'POST', body: { peerUrl: 'https://enter.example' } }])
	})

	it('keeps the typed peer and says why when adding fails', async () => {
		const wrapper = await mountSettings({ available: true, enabled: true, peers: [] })
		api.fail = new Error('blocked host')

		await wrapper.find('[data-testid="peer-url"]').setValue('https://10.0.0.1')
		await addButton(wrapper).trigger('click')
		await flushPromises()

		expect(dialogs.showError).toHaveBeenCalledWith('Could not add peer: blocked host')
		expect(wrapper.find('[data-testid="peer-url"]').element.value).toBe('https://10.0.0.1')
		expect(addButton(wrapper).attributes('disabled')).toBeUndefined()
	})

	it('removes the clicked peer and reloads', async () => {
		const wrapper = await mountSettings({ available: true, enabled: true, peers: PEERS })

		await wrapper.findAll('li.peer-item')[1].find('button').trigger('click')
		await flushPromises()

		expect(api.calls.slice(1)).toEqual([
			['federation/peers', { method: 'DELETE', body: { peerUrl: 'https://stale.example' } }],
			['federation/status'],
		])
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Peer removed')
	})

	it('pulls, shows the summary and reloads', async () => {
		const wrapper = await mountSettings({ available: true, enabled: true, peers: PEERS })

		await pullButton(wrapper).trigger('click')
		await flushPromises()

		expect(api.calls.slice(1)).toEqual([['federation/pull', { method: 'POST' }], ['federation/status']])
		expect(wrapper.find('[data-note="success"]').text()).toBe('Pulled 2 peer(s).')
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Federation pull completed')
	})
})
