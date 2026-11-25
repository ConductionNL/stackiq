// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A contract's supplier panel (REQ-CLR-014): the supplier is the provider of
 * the service the contract is for, read through that service and linked to
 * the supplier's organisation page.
 *
 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/vendor-product-master-data/spec.md#requirement-a-contract-shows-its-supplier-through-the-service-it-is-for-req-clr-014
 */

import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const mockGet = vi.hoisted(() => vi.fn())

vi.mock('@nextcloud/axios', () => ({
	default: { get: (...a) => mockGet(...a) },
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params) =>
		String(url).replace(/\{(\w+)\}/g, (_, key) => String(params?.[key] ?? '')),
}))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		String(text).replace(/\{(\w+)\}/g, (_, key) =>
			String(vars?.[key] ?? `{${key}}`),
		),
}))

function stub(name, props = []) {
	return defineComponent({
		name,
		props: ['name', 'size', 'type', 'title', 'to', ...props],
		render() {
			return h('div', { class: name }, [
				this.$slots.default?.() ?? [],
				this.$slots['title-icon']?.() ?? [],
			])
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

const { default: ContractSupplierPanel, referenceId } =
	await import('../../src/components/contracts/ContractSupplierPanel.vue')

const RouterLink = stub('RouterLink')

const BASE = '/apps/openregister/api/objects/stackiq'

/**
 * Answer the object reads from a map of url → body; anything else 404s.
 *
 * @param {Record<string, object>} bodies The bodies by url.
 */
function answer(bodies) {
	mockGet.mockImplementation(async (url) => {
		if (url in bodies) {
			return { data: bodies[url] }
		}
		throw new Error(`404 ${url}`)
	})
}

function mountPanel(objectId = 'contract-1') {
	return mount(ContractSupplierPanel, {
		props: { objectId },
		global: {
			components: { RouterLink },
			stubs: { 'router-link': RouterLink },
		},
	})
}

beforeEach(() => {
	mockGet.mockReset()
})

describe('ContractSupplierPanel', () => {
	it('shows the provider of the contract\'s service, linking to its organisation page', async () => {
		answer({
			[`${BASE}/catalogContract/contract-1`]: { '@self': { id: 'contract-1' }, service: 'service-1' },
			[`${BASE}/catalogService/service-1`]: { '@self': { id: 'service-1' }, name: 'Hosting Zaaksysteem X', provider: 'org-1' },
			[`${BASE}/organization/org-1`]: { '@self': { id: 'org-1' }, name: 'Leverancier B.V.' },
		})

		const wrapper = mountPanel()
		await flushPromises()

		const link = wrapper.findComponent(RouterLink)
		expect(link.exists()).toBe(true)
		expect(link.props('to')).toEqual({ name: 'OrganisatieDetail', params: { id: 'org-1' } })
		expect(wrapper.get('[data-testid="contract-supplier-name"]').text()).toBe('Leverancier B.V.')
		expect(wrapper.text()).toContain('Hosting Zaaksysteem X')
		expect(mockGet).toHaveBeenCalledTimes(3)
	})

	it('follows a service and provider that come back expanded', async () => {
		answer({
			[`${BASE}/catalogContract/contract-1`]: { object: { service: { id: 'service-2' } } },
			[`${BASE}/catalogService/service-2`]: { object: { name: 'Hosting', provider: { '@self': { id: 'org-2' } } } },
			[`${BASE}/organization/org-2`]: { object: { name: 'Supplier Two' } },
		})

		const wrapper = mountPanel()
		await flushPromises()

		expect(wrapper.findComponent(RouterLink).props('to').params.id).toBe('org-2')
		expect(wrapper.text()).toContain('Supplier Two')
	})

	it('says the contract names no service when it has none, without reading further', async () => {
		answer({ [`${BASE}/catalogContract/contract-1`]: { '@self': { id: 'contract-1' } } })

		const wrapper = mountPanel()
		await flushPromises()

		expect(wrapper.get('[data-testid="contract-supplier-empty"]').text())
			.toBe('This contract names no service, so its supplier is not known.')
		expect(wrapper.findComponent(RouterLink).exists()).toBe(false)
		expect(mockGet).toHaveBeenCalledTimes(1)
	})

	it('says the service names no supplier when its provider is empty', async () => {
		answer({
			[`${BASE}/catalogContract/contract-1`]: { service: 'service-1' },
			[`${BASE}/catalogService/service-1`]: { name: 'Hosting', provider: null },
		})

		const wrapper = mountPanel()
		await flushPromises()

		expect(wrapper.get('[data-testid="contract-supplier-empty"]').text())
			.toBe('The service of this contract names no supplier.')
	})

	it('shows an error line when a read fails', async () => {
		answer({ [`${BASE}/catalogContract/contract-1`]: { service: 'service-1' } })

		const wrapper = mountPanel()
		await flushPromises()

		expect(wrapper.findComponent({ name: 'NcNoteCard' }).text()).toBe('The supplier could not be loaded.')
	})

	it('reads nothing without an object id', async () => {
		const wrapper = mountPanel('')
		await flushPromises()

		expect(mockGet).not.toHaveBeenCalled()
		expect(wrapper.find('[data-testid="contract-supplier-empty"]').exists()).toBe(true)
	})
})

describe('referenceId', () => {
	it('reads a uuid string, an expanded object and nothing', () => {
		expect(referenceId(' abc ')).toBe('abc')
		expect(referenceId({ id: 'x' })).toBe('x')
		expect(referenceId({ '@self': { id: 'y' } })).toBe('y')
		expect(referenceId({ uuid: 'z' })).toBe('z')
		expect(referenceId(null)).toBe('')
		expect(referenceId(undefined)).toBe('')
		expect(referenceId({})).toBe('')
	})
})

describe('ContractDetail manifest', () => {
	it('places the supplier panel on the contract page', () => {
		const manifest = JSON.parse(readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'))
		const page = manifest.pages.find((p) => p.id === 'ContractDetail')
		const widget = page.config.bodyWidgets.find((w) => w.id === 'ct-supplier')
		expect(widget).toMatchObject({ component: 'ContractSupplierPanel', props: { objectId: '@objectId' } })
	})
})
