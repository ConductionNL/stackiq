// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * The peers table: each peer shows its catalogue, its address, the last
 * successful pull, the number of mirrored objects and, when the last pull
 * failed, the error; Pull now stays in the header.
 *
 * @spec openspec/specs/federated-catalog-sync/spec.md#requirement-mirrored-entries-show-where-they-came-from-req-fed-011
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({ status: {} }))

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/l10n', () => {
	const fill = (text, vars = {}) => text.replace(/\{(\w+)\}/g, (match, key) => (key in vars ? vars[key] : match))
	return {
		translate: (app, text, vars) => fill(text, vars),
		translatePlural: (app, one, many, count, vars) => fill(count === 1 ? one : many, vars),
	}
})
vi.mock('../../src/utils/adminApi.js', async (importOriginal) => ({
	...(await importOriginal()),
	apiRequest: async (route) => (route === 'federation/status' ? api.status : {}),
}))

const { default: FederationSettings } = await import('../../src/views/settings/sections/FederationSettings.vue')

const stubs = {
	AlwaysVisibleSection: { template: '<section><div data-testid="header"><slot name="header-actions" /></div><slot /></section>' },
	NcNoteCard: { template: '<div><slot /></div>' },
	NcEmptyContent: true,
	NcButton: { props: ['disabled'], template: '<button :disabled="disabled"><slot /></button>' },
	NcTextField: true,
	NcLoadingIcon: true,
	Sync: true,
	Plus: true,
	Delete: true,
	LanDisconnect: true,
}

async function rows(peers) {
	api.status = { available: true, enabled: true, peers }
	const wrapper = mount(FederationSettings, { global: { stubs } })
	await flushPromises()
	return {
		wrapper,
		rows: wrapper.findAll('li.peer-item').map((li) => ({
			name: li.find('.peer-name').text(),
			url: li.find('.peer-url').text(),
			pulled: li.find('.peer-last-pulled').text(),
			objects: li.find('.peer-objects').text(),
			error: li.find('.peer-error').exists() ? li.find('.peer-error').text() : null,
		})),
	}
}

describe('the peers table', () => {
	it('shows catalogue, address, last pull and objects for a healthy peer', async () => {
		const { rows: [row], wrapper } = await rows([
			{ url: 'https://catalogus.drechtsteden.nl', name: 'Softwarecatalogus Drechtsteden', lastPulledAt: '2026-11-20T08:05:00+00:00', objectCount: 1204, failures: 0 },
		])

		expect(row).toEqual({
			name: 'Softwarecatalogus Drechtsteden',
			url: 'https://catalogus.drechtsteden.nl',
			pulled: 'Last pulled 2026-11-20 08:05 UTC',
			objects: '1204 objects',
			error: null,
		})
		expect(wrapper.find('[data-testid="header"] button').text()).toContain('Pull now')
	})

	it('shows the error state of a peer that did not answer', async () => {
		const { rows: [row] } = await rows([
			{ url: 'https://apps.wshd.nl', name: 'apps.wshd.nl', lastPulledAt: null, objectCount: 1, lastError: 'not reachable', failures: 2 },
		])

		expect(row).toEqual({
			name: 'apps.wshd.nl',
			url: 'https://apps.wshd.nl',
			pulled: 'Never pulled',
			objects: '1 object',
			error: 'Error: not reachable',
		})
	})

	it('names a blocked peer and shows any other reason as given', async () => {
		const { rows: found } = await rows([
			{ url: 'http://10.0.0.1', lastError: 'blocked', allowed: false },
			{ url: 'https://peer.example', lastError: 'peer answered HTTP 503', lastPulledAt: 'yesterday' },
		])

		expect(found.map(({ error }) => error)).toEqual(['Error: blocked by the SSRF guard', 'Error: peer answered HTTP 503'])
		expect(found[0].name).toBe('http://10.0.0.1')
		expect(found[1].pulled).toBe('Last pulled yesterday')
	})
})
