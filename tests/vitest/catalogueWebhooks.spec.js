// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit test for the catalogue webhooks (REQ-PLAT-005): only webhooks on the
 * catalogue register are listed, with address, events and last delivery; a
 * new one goes to OpenRegister's webhooks API filtered to the catalogue
 * register and the chosen schemas; edit and remove reach OpenRegister; no
 * webhook shows an empty state with "Add webhook".
 *
 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
	catalogueScope,
	formProblem,
	isCatalogueWebhook,
	lastDelivery,
	webhookBody,
	webhookForm,
} from '../../src/utils/catalogueWebhooks.js'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }))
const admin = vi.hoisted(() => ({ apiRequest: vi.fn() }))
const dialogs = vi.hoisted(() => ({ showSuccess: vi.fn(), showError: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) => `/index.php${path.replace(/\{(\w+)\}/g, (m, k) => params[k] ?? m)}`,
}))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m)),
}))
vi.mock('../../src/utils/adminApi.js', () => admin)
vi.mock('../../src/components/AlwaysVisibleSection.vue', () => ({
	default: { props: ['name', 'loading'], template: '<section :data-loading="String(loading)"><slot /></section>' },
}))

const { default: CatalogueWebhooks } = await import('../../src/views/settings/sections/CatalogueWebhooks.vue')
const { default: EditWebhookModal } = await import('../../src/modals/webhook/EditWebhookModal.vue')

const UPDATED = 'OCA\\OpenRegister\\Event\\ObjectUpdatedEvent'
const CREATED = 'OCA\\OpenRegister\\Event\\ObjectCreatedEvent'
const DELETED = 'OCA\\OpenRegister\\Event\\ObjectDeletedEvent'
const CONFIG = { register: 4, module_schema: 21, contract_schema: '22', gebruik_schema: 23, organisatie_schema: 30 }
const SCOPE = { register: '4', schemas: { module: '21', contract: '22', usage: '23' } }

const ours = {
	id: 7,
	name: 'Zaaksysteem',
	url: 'https://zaken.example.nl/hook',
	method: 'POST',
	enabled: true,
	events: [UPDATED],
	filters: { register: ['4'], schema: ['21', '22'] },
	lastTriggeredAt: '2026-11-01T10:00:00+00:00',
	lastSuccessAt: '2026-11-01T10:00:00+00:00',
	lastFailureAt: null,
}
const ourFailing = { ...ours, id: 8, name: '', url: 'https://cmdb.example.nl/in', enabled: false, events: [CREATED], filters: { register: 4, schema: 23 }, lastFailureAt: '2026-11-02T09:00:00+00:00', lastTriggeredAt: '2026-11-02T09:00:00+00:00' }
const otherApp = { ...ours, id: 9, name: 'Decisions', filters: { register: ['12'], schema: ['5'] } }
const unfiltered = { ...ours, id: 10, name: 'Everything', filters: [] }

describe('catalogueWebhooks helpers', () => {
	it('reads the catalogue register and the schema of each subject', () => {
		expect(catalogueScope(CONFIG)).toEqual(SCOPE)
		expect(catalogueScope({ register: '0' })).toEqual({ register: '', schemas: { module: '', contract: '', usage: '' } })
	})

	it('counts a webhook as the catalogue\'s only when it is filtered to the catalogue register', () => {
		expect([ours, ourFailing, otherApp, unfiltered].map((webhook) => isCatalogueWebhook(webhook, SCOPE))).toEqual([true, true, false, false])
		expect(isCatalogueWebhook(ours, catalogueScope({}))).toBe(false)
	})

	it('turns a webhook into its form and back, keeping its other settings', () => {
		const form = webhookForm(ours, SCOPE)
		expect(form).toEqual({ name: 'Zaaksysteem', url: 'https://zaken.example.nl/hook', enabled: true, actions: ['updated'], subjects: ['module', 'contract'] })
		expect(webhookForm(null, SCOPE)).toEqual({ name: '', url: '', enabled: true, actions: ['updated'], subjects: [] })

		const body = webhookBody({ ...form, actions: ['deleted', 'created'], subjects: ['usage'] }, SCOPE, { ...ours, method: 'PUT', filters: { register: ['4'], schema: ['21'], 'object.status': 'Active' } })
		expect(body).toEqual({
			name: 'Zaaksysteem',
			url: 'https://zaken.example.nl/hook',
			method: 'PUT',
			enabled: true,
			events: [CREATED, DELETED],
			filters: { register: ['4'], schema: ['23'], 'object.status': 'Active' },
		})
		expect(webhookBody({ name: ' ', url: ' https://x.nl ', enabled: false, actions: ['updated'], subjects: ['module'] }, SCOPE)).toMatchObject({ name: 'https://x.nl', url: 'https://x.nl', method: 'POST', enabled: false })
	})

	it('names what keeps a form from being saved', () => {
		const good = { url: 'https://x.nl/hook', actions: ['updated'], subjects: ['module'] }
		expect(formProblem(good, SCOPE)).toBe('')
		expect(formProblem({ ...good, url: 'ftp://x.nl' }, SCOPE)).toBe('url')
		expect(formProblem({ ...good, url: 'https://' }, SCOPE)).toBe('url')
		expect(formProblem({ ...good, actions: [] }, SCOPE)).toBe('actions')
		expect(formProblem({ ...good, subjects: [] }, SCOPE)).toBe('subjects')
		expect(formProblem(good, { register: '4', schemas: { module: '' } })).toBe('scope')
	})

	it('reads the last delivery and whether it arrived', () => {
		expect(lastDelivery(ours)).toEqual({ at: '2026-11-01T10:00:00+00:00', ok: true })
		expect(lastDelivery(ourFailing)).toEqual({ at: '2026-11-02T09:00:00+00:00', ok: false })
		expect(lastDelivery({ lastTriggeredAt: null })).toBeNull()
	})
})

const stubs = {
	NcNoteCard: { props: ['type'], template: '<div class="note" :data-type="type"><slot /></div>' },
	NcEmptyContent: { props: ['name'], template: '<div class="empty" v-bind="$attrs">{{ name }}<slot name="action" /></div>' },
	NcButton: { props: ['disabled', 'variant'], emits: ['click'], template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' },
	NcActions: { template: '<div class="actions"><slot /></div>' },
	NcActionButton: { emits: ['click'], template: '<button class="action" v-bind="$attrs" @click="$emit(\'click\')"><slot /></button>' },
	EditWebhookModal: { name: 'EditWebhookModal', props: ['show', 'webhook', 'scope', 'saving'], emits: ['save', 'close'], template: '<div v-if="show" class="modal" :data-editing="webhook ? webhook.id : \'new\'" />' },
	Pencil: true,
	Delete: true,
}

/**
 * Mount the section with these webhooks on OpenRegister.
 *
 * @param {Array<object>} webhooks The webhooks.
 * @return {Promise<object>} The wrapper.
 */
async function mountSection(webhooks) {
	admin.apiRequest.mockResolvedValue({ success: true, config: CONFIG })
	http.get.mockResolvedValue({ data: { results: webhooks, total: webhooks.length } })
	const wrapper = mount(CatalogueWebhooks, { global: { stubs } })
	await flushPromises()
	return wrapper
}

describe('CatalogueWebhooks', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('lists only the catalogue webhooks with address, events and last delivery', async () => {
		const wrapper = await mountSection([ours, otherApp, ourFailing, unfiltered])

		expect(admin.apiRequest).toHaveBeenCalledWith('voorzieningen/config')
		expect(http.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/webhooks')
		const rows = wrapper.findAll('tbody tr')
		expect(rows.map((row) => row.attributes('data-testid'))).toEqual(['webhook-7', 'webhook-8'])
		const cells = rows[0].findAll('td').map((cell) => cell.text())
		expect(cells[0]).toContain('Zaaksysteem')
		expect(cells[0]).toContain('https://zaken.example.nl/hook')
		expect(cells[1]).toBe('Changed: Applications, Contracts')
		expect(cells[2]).toBe(`${new Date('2026-11-01T10:00:00+00:00').toLocaleString()}, delivered`)
		const failing = rows[1].findAll('td').map((cell) => cell.text())
		expect(failing[0]).toContain('Inactive')
		expect(failing[1]).toBe('Created: Applications in use')
		expect(failing[2]).toContain(', failed')
	})

	it('shows an empty state with Add webhook when there is none', async () => {
		const wrapper = await mountSection([otherApp])

		expect(wrapper.find('[data-testid="webhooks-empty"]').text()).toContain('No webhooks')
		await wrapper.find('[data-testid="webhooks-empty"] button').trigger('click')
		expect(wrapper.find('.modal').attributes('data-editing')).toBe('new')
	})

	it('creates a webhook through OpenRegister, filtered to the module and contract schemas', async () => {
		const wrapper = await mountSection([])
		http.post.mockResolvedValue({ data: { id: 11 } })
		await wrapper.find('[data-testid="webhooks-empty"] button').trigger('click')

		wrapper.findComponent({ name: 'EditWebhookModal' }).vm.$emit('save', { name: '', url: 'https://zaken.example.nl/hook', enabled: true, actions: ['updated'], subjects: ['module', 'contract'] })
		await flushPromises()

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/openregister/api/webhooks', {
			name: 'https://zaken.example.nl/hook',
			url: 'https://zaken.example.nl/hook',
			method: 'POST',
			enabled: true,
			events: [UPDATED],
			filters: { register: ['4'], schema: ['21', '22'] },
		})
		expect(dialogs.showSuccess).toHaveBeenCalledWith('The webhook is saved.')
		expect(http.get).toHaveBeenCalledTimes(2)
		expect(wrapper.find('.modal').exists()).toBe(false)
	})

	it('edits and removes a listed webhook from its actions menu', async () => {
		const wrapper = await mountSection([ours])
		http.put.mockResolvedValue({ data: ours })
		http.delete.mockResolvedValue({ data: {} })

		await wrapper.find('[data-testid="webhook-edit-7"]').trigger('click')
		expect(wrapper.find('.modal').attributes('data-editing')).toBe('7')
		wrapper.findComponent({ name: 'EditWebhookModal' }).vm.$emit('save', { ...webhookForm(ours, SCOPE), subjects: ['module'] })
		await flushPromises()
		expect(http.put).toHaveBeenCalledWith('/index.php/apps/openregister/api/webhooks/7', expect.objectContaining({ events: [UPDATED], filters: { register: ['4'], schema: ['21'] } }))

		await wrapper.find('[data-testid="webhook-remove-7"]').trigger('click')
		await flushPromises()
		expect(http.delete).toHaveBeenCalledWith('/index.php/apps/openregister/api/webhooks/7')
		expect(dialogs.showSuccess).toHaveBeenLastCalledWith('The webhook is removed.')
	})

	it('says what OpenRegister refused, and warns when the catalogue is not configured', async () => {
		admin.apiRequest.mockResolvedValue({ success: true, config: {} })
		http.get.mockRejectedValue({ response: { data: { error: 'Forbidden' } } })
		const wrapper = mount(CatalogueWebhooks, { global: { stubs } })
		await flushPromises()
		expect(wrapper.find('.note[data-type="error"]').text()).toBe('Forbidden')

		http.get.mockResolvedValue({ data: { results: [ours] } })
		await wrapper.vm.load()
		expect(wrapper.find('.note[data-type="warning"]').text()).toBe('The catalogue register and its schemas are not configured yet.')
		expect(wrapper.find('[data-testid="webhooks-empty"] button').attributes('disabled')).toBeDefined()

		http.get.mockResolvedValue({ data: { results: [] } })
		admin.apiRequest.mockResolvedValue({ success: true, config: CONFIG })
		await wrapper.vm.load()
		http.post.mockRejectedValue({ response: { data: { error: 'Invalid URL' } } })
		await wrapper.vm.save({ name: 'x', url: 'https://x.nl', enabled: true, actions: ['updated'], subjects: ['usage'] })
		expect(dialogs.showError).toHaveBeenCalledWith('Invalid URL')
	})
})

describe('EditWebhookModal', () => {
	const modalStubs = {
		NcModal: { template: '<div class="nc-modal"><slot /></div>' },
		NcTextField: { props: ['modelValue', 'label'], emits: ['update:modelValue'], template: '<input :data-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">' },
		NcCheckboxRadioSwitch: {
			props: ['modelValue', 'value', 'type'],
			emits: ['update:modelValue'],
			template: '<input type="checkbox" v-bind="$attrs" :checked="Array.isArray(modelValue) ? modelValue.includes(value) : modelValue" @change="$emit(\'update:modelValue\', Array.isArray(modelValue) ? ($event.target.checked ? [...modelValue, value] : modelValue.filter((v) => v !== value)) : $event.target.checked)">',
		},
		NcNoteCard: { props: ['type'], template: '<div class="note"><slot /></div>' },
		NcButton: { props: ['type'], emits: ['click'], template: '<button :type="type || \'button\'" v-bind="$attrs" @click="$emit(\'click\')"><slot /></button>' },
		NcLoadingIcon: true,
	}

	it('refuses an incomplete form and emits a complete one', async () => {
		const wrapper = mount(EditWebhookModal, { props: { show: false, scope: SCOPE }, global: { stubs: modalStubs } })
		await wrapper.setProps({ show: true })

		await wrapper.find('form').trigger('submit')
		expect(wrapper.emitted('save')).toBeUndefined()
		expect(wrapper.find('.note').text()).toBe('Enter an address that starts with http:// or https://.')

		await wrapper.find('[data-label="Address"]').setValue('https://zaken.example.nl/hook')
		await wrapper.find('form').trigger('submit')
		expect(wrapper.find('.note').text()).toBe('Choose the catalogue entries the webhook follows.')

		await wrapper.find('[data-testid="webhook-subject-module"]').setValue(true)
		await wrapper.find('[data-testid="webhook-subject-contract"]').setValue(true)
		await wrapper.find('form').trigger('submit')
		expect(wrapper.emitted('save')).toEqual([[{ name: '', url: 'https://zaken.example.nl/hook', enabled: true, actions: ['updated'], subjects: ['module', 'contract'] }]])
	})

	it('opens on the webhook it edits', async () => {
		const wrapper = mount(EditWebhookModal, { props: { show: false, webhook: ours, scope: SCOPE }, global: { stubs: modalStubs } })
		await wrapper.setProps({ show: true })

		expect(wrapper.find('h2').text()).toBe('Edit webhook')
		expect(wrapper.find('[data-label="Address"]').element.value).toBe('https://zaken.example.nl/hook')
		expect(wrapper.find('[data-testid="webhook-action-updated"]').element.checked).toBe(true)
		expect(wrapper.find('[data-testid="webhook-subject-usage"]').element.checked).toBe(false)
	})
})
