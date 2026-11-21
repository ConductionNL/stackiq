// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Webhooks section: only the catalogue register's OpenRegister webhooks
 * are listed, with address, events and last delivery; adding one creates an
 * OpenRegister webhook filtered to the chosen schemas; edit and remove go
 * through the same API; without webhooks it shows an empty state.
 *
 * @spec openspec/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import { catalogueTarget, lastDelivery, OBJECT_EVENTS, webhookBody, webhookEvents } from '../../src/utils/catalogueWebhooks.js'

vi.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url, params = {}) => url.replace(/\{(\w+)\}/g, (match, key) => params[key]) }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (match, key) => (key in vars ? vars[key] : match)),
}))

const slot = (name, tag = 'div') => ({ name, render() { return h(tag, { ...this.$attrs }, [this.$slots.default?.(), this.$slots.action?.()]) } })
vi.mock('@nextcloud/vue', () => ({
	NcButton: { inheritAttrs: false, props: ['disabled'], emits: ['click'], render() { return h('button', { ...this.$attrs, disabled: this.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.()) } },
	NcActionButton: { inheritAttrs: false, emits: ['click'], render() { return h('button', { ...this.$attrs, onClick: () => this.$emit('click') }, this.$slots.default?.()) } },
	NcActions: slot('NcActions'),
	NcEmptyContent: { props: ['name'], render() { return h('div', { 'data-testid': 'empty' }, [this.name, this.$slots.action?.()]) } },
	NcNoteCard: slot('NcNoteCard', 'p'),
	NcDialog: slot('NcDialog'),
	NcTextField: { props: ['modelValue', 'label'], emits: ['update:modelValue'], render() { return h('input', { 'data-label': this.label, value: this.modelValue, onInput: (e) => this.$emit('update:modelValue', e.target.value) }) } },
	NcCheckboxRadioSwitch: { inheritAttrs: false, props: ['modelValue'], emits: ['update:modelValue'], render() { return h('input', { ...this.$attrs, type: 'checkbox', checked: this.modelValue, onChange: (e) => this.$emit('update:modelValue', e.target.checked) }) } },
}))

vi.mock('../../src/components/AlwaysVisibleSection.vue', () => ({
	default: { name: 'AlwaysVisibleSection', template: '<section><div data-testid="header"><slot name="header-actions" /></div><slot /></section>' },
}))

const { default: CatalogueWebhooks } = await import('../../src/views/settings/sections/CatalogueWebhooks.vue')

const CONFIG = { register: 7, module_schema: 11, contract_schema: 12, gebruik_schema: 13 }
const TARGET = { register: '7', schemas: { module: '11', catalogContract: '12' } }
const OURS = { id: 1, url: 'https://cmdb.example.nl/hook', filters: { register: '7', schema: ['11', '12'] }, lastSuccessAt: '2026-11-19T10:00:00+00:00' }
const OTHER = { id: 2, url: 'https://elsewhere.example', filters: { register: 99 } }
const UNFILTERED = { id: 3, url: 'https://all.example', filters: [] }

const stubs = {
	AlwaysVisibleSection: { template: '<section><div data-testid="header"><slot name="header-actions" /></div><slot /></section>' },
	Plus: true,
	Pencil: true,
	Delete: true,
	Webhook: true,
}

function answer(webhooks) {
	axios.get.mockImplementation(async (url) => (url.endsWith('/voorzieningen/config') ? { data: { config: CONFIG } } : { data: { results: webhooks } }))
}

async function mountSection() {
	const wrapper = mount(CatalogueWebhooks, { global: { stubs } })
	await flushPromises()
	return wrapper
}

beforeEach(() => vi.clearAllMocks())

describe('catalogue webhooks', () => {
	it('reads the catalogue from the voorzieningen config and the events from the schema filter', () => {
		expect(catalogueTarget(CONFIG)).toEqual(TARGET)
		expect(catalogueTarget({})).toBeNull()
		expect(webhookEvents(OURS, TARGET)).toEqual(['module', 'catalogContract'])
		expect(webhookEvents({ filters: { schema: 12 } }, TARGET)).toEqual(['catalogContract'])
		expect(webhookEvents({ filters: {} }, TARGET)).toEqual([])
	})

	it('builds a webhook on the object events, filtered to the register and the chosen schemas', () => {
		expect(webhookBody({ name: '', url: ' https://cmdb.example.nl/hook ', events: ['module', 'catalogContract'] }, TARGET)).toEqual({
			name: 'https://cmdb.example.nl/hook',
			url: 'https://cmdb.example.nl/hook',
			method: 'POST',
			enabled: true,
			events: [...OBJECT_EVENTS],
			filters: { register: '7', schema: ['11', '12'] },
		})
	})

	it('takes the later of the last success and the last failure as the last delivery', () => {
		expect(lastDelivery({})).toBeNull()
		expect(lastDelivery(OURS)).toEqual({ at: OURS.lastSuccessAt, ok: true })
		expect(lastDelivery({ lastSuccessAt: '2026-11-19T10:00:00Z', lastFailureAt: '2026-11-20T10:00:00Z' })).toEqual({ at: '2026-11-20T10:00:00Z', ok: false })
		expect(lastDelivery({ lastFailureAt: '2026-11-20T10:00:00Z' })).toEqual({ at: '2026-11-20T10:00:00Z', ok: false })
	})

	it('lists only the catalogue register\'s webhooks with address, events and last delivery', async () => {
		answer([OURS, OTHER, UNFILTERED])
		const wrapper = await mountSection()

		expect(wrapper.findAll('li.webhook-item')).toHaveLength(1)
		const row = wrapper.find('[data-testid="webhook-1"]')
		expect(row.find('.webhook-url').text()).toBe('https://cmdb.example.nl/hook')
		expect(row.find('.webhook-events').text()).toBe('Application changed, Contract changed')
		expect(row.find('.webhook-delivery').text()).toContain('Last delivered')
	})

	it('shows an empty state with Add webhook, and adds one through OpenRegister', async () => {
		answer([])
		axios.post.mockResolvedValue({ data: { id: 4 } })
		const wrapper = await mountSection()

		expect(wrapper.find('[data-testid="empty"]').text()).toContain('No webhooks')
		await wrapper.find('[data-testid="webhook-add"]').trigger('click')
		await wrapper.find('[data-label="Address"]').setValue('https://cmdb.example.nl/hook')
		expect(wrapper.find('[data-testid="webhook-save"]').attributes('disabled')).toBeDefined()
		await wrapper.find('[data-event="module"]').setValue(true)
		await wrapper.find('[data-event="catalogContract"]').setValue(true)
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith('/apps/openregister/api/webhooks', expect.objectContaining({
			url: 'https://cmdb.example.nl/hook',
			events: [...OBJECT_EVENTS],
			filters: { register: '7', schema: ['11', '12'] },
		}))
		expect(wrapper.find('form').exists()).toBe(false)
	})

	it('edits and removes a webhook from its actions', async () => {
		answer([OURS])
		axios.put.mockResolvedValue({ data: OURS })
		axios.delete.mockResolvedValue({})
		const wrapper = await mountSection()

		await wrapper.find('[data-testid="webhook-edit-1"]').trigger('click')
		expect(wrapper.find('[data-label="Address"]').element.value).toBe('https://cmdb.example.nl/hook')
		await wrapper.find('[data-event="module"]').setValue(false)
		await wrapper.find('form').trigger('submit')
		await flushPromises()
		expect(axios.put).toHaveBeenCalledWith('/apps/openregister/api/webhooks/1', expect.objectContaining({ filters: { register: '7', schema: ['12'] } }))

		await wrapper.find('[data-testid="webhook-remove-1"]').trigger('click')
		await flushPromises()
		expect(axios.delete).toHaveBeenCalledWith('/apps/openregister/api/webhooks/1')
	})

	it('says so when the catalogue register is not configured', async () => {
		axios.get.mockResolvedValue({ data: { config: {} } })
		const wrapper = await mountSection()

		expect(wrapper.text()).toContain('The catalogue register is not configured')
		expect(axios.get).toHaveBeenCalledTimes(1)
	})
})
