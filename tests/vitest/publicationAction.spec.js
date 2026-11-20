// @vitest-environment jsdom
/**
 * PublicationAction.vue — Publish (now or on a date) and Withdraw on an
 * entry's page, with the state badge (publish-and-lock-from-the-list,
 * REQ-ODP-011).
 *
 * @spec openspec/changes/publish-and-lock-from-the-list/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const publishEntry = vi.fn()
const withdrawEntry = vi.fn()
const showError = vi.fn()
const showSuccess = vi.fn()

vi.mock('@nextcloud/dialogs', () => ({ showError: (...a) => showError(...a), showSuccess: (...a) => showSuccess(...a) }))
vi.mock('../../src/services/publicationActions.js', async (importOriginal) => ({
	...(await importOriginal()),
	publishEntry: (...a) => publishEntry(...a),
	withdrawEntry: (...a) => withdrawEntry(...a),
}))

const { default: PublicationAction } = await import('../../src/components/publication/PublicationAction.vue')

const stubs = {
	NcActions: defineComponent({ name: 'NcActions', render() { return h('div', { class: 'actions' }, this.$slots.default?.()) } }),
	NcActionButton: defineComponent({
		name: 'NcActionButton',
		emits: ['click'],
		render() { return h('button', { ...this.$attrs, onClick: () => this.$emit('click') }, this.$slots.default?.()) },
	}),
	NcLoadingIcon: true,
	PublishDateModal: defineComponent({ name: 'PublishDateModal', emits: ['close', 'submit'], render: () => h('div', { class: 'date-modal' }) }),
}

const PAST = '2026-01-01T00:00:00Z'

function mountAction(props = {}) {
	return mount(PublicationAction, {
		props: {
			object: { id: 'u-1', name: 'Zaaksysteem' },
			schema: { slug: 'module' },
			objectType: '7-module',
			canEditRecord: true,
			...props,
		},
		global: { stubs },
	})
}

const flush = () => new Promise((r) => setTimeout(r, 0))

beforeEach(() => {
	publishEntry.mockReset()
	withdrawEntry.mockReset()
	showError.mockReset()
	showSuccess.mockReset()
})

describe('PublicationAction', () => {
	it('offers Publish now and on a date for an unpublished entry, not Withdraw', () => {
		const wrapper = mountAction()

		expect(wrapper.get('[data-testid="publication-badge"]').text()).toBe('Not published')
		expect(wrapper.find('[data-testid="publication-publish-now"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="publication-publish-date"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="publication-withdraw"]').exists()).toBe(false)
	})

	it('offers Withdraw for a published entry, not Publish', () => {
		const wrapper = mountAction({ object: { id: 'u-1', publicationDate: PAST } })

		expect(wrapper.get('[data-testid="publication-badge"]').text()).toBe('Published')
		expect(wrapper.find('[data-testid="publication-withdraw"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="publication-publish-now"]').exists()).toBe(false)
	})

	it('publishes now through the endpoint and shows the Published badge', async () => {
		publishEntry.mockResolvedValue({ ok: true, publicationDate: PAST })
		const wrapper = mountAction()

		await wrapper.get('[data-testid="publication-publish-now"]').trigger('click')
		await flush()

		expect(publishEntry).toHaveBeenCalledWith('module', 'u-1', { when: null })
		expect(wrapper.get('[data-testid="publication-badge"]').text()).toBe('Published')
		expect(showSuccess).toHaveBeenCalledTimes(1)
	})

	it('publishes on the date the dialog returns', async () => {
		publishEntry.mockResolvedValue({ ok: true, publicationDate: '2999-01-01T00:00:00+00:00' })
		const wrapper = mountAction()

		await wrapper.get('[data-testid="publication-publish-date"]').trigger('click')
		wrapper.findComponent({ name: 'PublishDateModal' }).vm.$emit('submit', '2999-01-01T00:00:00.000Z')
		await flush()

		expect(publishEntry).toHaveBeenCalledWith('module', 'u-1', { when: '2999-01-01T00:00:00.000Z' })
		expect(wrapper.get('[data-testid="publication-badge"]').text()).toBe('Scheduled')
	})

	it('withdraws through the endpoint and shows Not published', async () => {
		withdrawEntry.mockResolvedValue({ ok: true })
		const wrapper = mountAction({ object: { id: 'u-1', publicationDate: PAST } })

		await wrapper.get('[data-testid="publication-withdraw"]').trigger('click')
		await flush()

		expect(withdrawEntry).toHaveBeenCalledWith('module', 'u-1')
		expect(wrapper.get('[data-testid="publication-badge"]').text()).toBe('Not published')
	})

	it('shows a refusal and keeps the state', async () => {
		publishEntry.mockRejectedValue(Object.assign(new Error('403'), { response: { data: { message: 'You may not publish this entry' } } }))
		const wrapper = mountAction()

		await wrapper.get('[data-testid="publication-publish-now"]').trigger('click')
		await flush()

		expect(wrapper.get('[data-testid="publication-error"]').text()).toBe('You may not publish this entry')
		expect(showError).toHaveBeenCalledWith('You may not publish this entry')
		expect(wrapper.get('[data-testid="publication-badge"]').text()).toBe('Not published')
	})

	it('offers no actions to a reader the page does not let edit', () => {
		const wrapper = mountAction({ canEditRecord: false })

		expect(wrapper.find('[data-testid="publication-badge"]').exists()).toBe(true)
		expect(wrapper.find('.actions').exists()).toBe(false)
	})

	it('offers no actions on a read-only copy from another instance', () => {
		const wrapper = mountAction({ object: { id: 'u-1', _source: { instance: 'https://other.example' } } })

		expect(wrapper.find('.actions').exists()).toBe(false)
	})

	it('renders nothing for a type the endpoint does not publish', () => {
		const wrapper = mountAction({ schema: { slug: 'usage' }, objectType: '7-usage' })

		expect(wrapper.find('[data-testid="publication-action"]').exists()).toBe(false)
	})

	it('falls back to the object type when the schema has no slug', () => {
		const wrapper = mountAction({ schema: null, objectType: '7-catalogService' })

		expect(wrapper.vm.publicationType).toBe('catalogService')
	})
})
