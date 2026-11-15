// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * FederationSettings while a request runs: Pull now, Add and a peer's Remove
 * each swap their icon for a spinner and are disabled until their request
 * answers, and the empty peer list shows its disconnected icon.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({ status: {}, pending: [] }))

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
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
		if (init) {
			// A write waits until the test releases it.
			await new Promise((resolve) => api.pending.push(resolve))
			return route === 'federation/pull' ? { peers: [] } : {}
		}
		return api.status
	},
}))

const { default: FederationSettings } = await import('../../src/views/settings/sections/FederationSettings.vue')

const stubs = {
	AlwaysVisibleSection: { template: '<section><slot name="header-actions" /><slot /></section>' },
	NcNoteCard: { template: '<div><slot /></div>' },
	NcEmptyContent: { props: ['name'], template: '<div data-testid="empty"><slot name="icon" />{{ name }}</div>' },
	NcButton: {
		props: ['disabled', 'variant'],
		emits: ['click'],
		template: '<button :data-variant="variant" :disabled="disabled" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>',
	},
	NcTextField: {
		props: ['modelValue', 'disabled'],
		emits: ['update:modelValue'],
		template: '<input data-testid="peer-url" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
	},
	NcLoadingIcon: { template: '<i class="spinner" />' },
	Sync: { template: '<i class="sync" />' },
	Plus: { template: '<i class="plus" />' },
	Delete: { template: '<i class="delete" />' },
	LanDisconnect: { template: '<i class="disconnected" />' },
}

async function mountSettings(status) {
	api.status = status
	const wrapper = mount(FederationSettings, { global: { stubs } })
	await flushPromises()
	return wrapper
}

const button = (wrapper, variant) => wrapper.find(`button[data-variant="${variant}"]`)

/**
 * Release every waiting write and let the answers settle.
 *
 * @return {Promise<void>}
 */
async function releaseAll() {
	api.pending.splice(0).forEach((resolve) => resolve())
	await flushPromises()
}

beforeEach(() => {
	api.pending = []
})

describe('FederationSettings while a request runs', () => {
	it('shows the disconnected icon on an empty peer list', async () => {
		const wrapper = await mountSettings({ available: true, enabled: true, peers: [] })
		expect(wrapper.find('[data-testid="empty"] .disconnected').exists()).toBe(true)
	})

	it('spins Pull now until the pull answers', async () => {
		const wrapper = await mountSettings({ available: true, enabled: true, peers: [{ url: 'https://a.example', failures: 0 }] })
		expect(button(wrapper, 'primary').find('.sync').exists()).toBe(true)

		await button(wrapper, 'primary').trigger('click')
		expect(button(wrapper, 'primary').find('.spinner').exists()).toBe(true)
		expect(button(wrapper, 'primary').attributes('disabled')).toBe('')

		await releaseAll()
		expect(button(wrapper, 'primary').find('.sync').exists()).toBe(true)
	})

	it('spins Add until the peer is stored', async () => {
		const wrapper = await mountSettings({ available: true, enabled: true, peers: [] })
		await wrapper.find('[data-testid="peer-url"]').setValue('https://b.example')
		expect(button(wrapper, 'secondary').find('.plus').exists()).toBe(true)

		await button(wrapper, 'secondary').trigger('click')
		expect(button(wrapper, 'secondary').find('.spinner').exists()).toBe(true)

		await releaseAll()
		expect(button(wrapper, 'secondary').find('.plus').exists()).toBe(true)
		expect(wrapper.find('[data-testid="peer-url"]').element.value).toBe('')
	})

	it('spins only the Remove of the peer being removed', async () => {
		const wrapper = await mountSettings({
			available: true,
			enabled: true,
			peers: [{ url: 'https://a.example', failures: 0 }, { url: 'https://b.example', failures: 0 }],
		})
		const removes = () => wrapper.findAll('button[data-variant="tertiary"]')

		await removes()[1].trigger('click')
		expect(removes().map((b) => b.find('.spinner').exists())).toEqual([false, true])
		expect(removes().map((b) => b.attributes('disabled'))).toEqual([undefined, ''])

		await releaseAll()
		expect(wrapper.findAll('.spinner')).toHaveLength(0)
	})
})
