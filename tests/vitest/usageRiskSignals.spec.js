// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The risk signals widget, opened straight from a link: it reads the app
 * settings first, so the object types resolve before the first read.
 *
 * @spec openspec/specs/application-value-assessment/spec.md#requirement-req-ava-002-the-usage-page-shows-the-risk-signals-next-to-the-risk-score
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const order = vi.hoisted(() => [])
const store = vi.hoisted(() => ({
	settings: null,
	objectTypeRegistry: {},
	fetchSettings: null,
	registerObjectType: null,
	getSchemaConfig: null,
	fetchObject: null,
	fetchCollectionForOptions: null,
}))

vi.mock('../../src/store/store.js', () => ({ objectStore: store }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, singular) => singular,
}))
vi.mock('@nextcloud/vue', () => ({
	NcLoadingIcon: { template: '<span />' },
	NcNoteCard: { template: '<div class="note"><slot /></div>' },
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnIcon: { template: '<i />' },
	CnWidgetWrapper: { template: '<div><slot /></div>' },
}))

const UsageRiskSignals = (
	await import('../../src/components/portfolio/UsageRiskSignals.vue')
).default

describe('UsageRiskSignals', () => {
	beforeEach(() => {
		order.length = 0
		store.settings = null
		store.objectTypeRegistry = {}
		store.fetchSettings = vi.fn(async () => {
			order.push('fetchSettings')
			store.settings = { loaded: true }
		})
		store.getSchemaConfig = vi.fn(() => ({ register: 1, schema: 2 }))
		store.registerObjectType = vi.fn()
		store.fetchObject = vi.fn(async () => {
			order.push('fetchObject')
			return { riskScore: 3 }
		})
		store.fetchCollectionForOptions = vi.fn(async () => [])
	})

	it('reads the settings before its first object read', async () => {
		const wrapper = mount(UsageRiskSignals, { props: { objectId: 'u1' } })
		await flushPromises()

		expect(store.fetchSettings).toHaveBeenCalledTimes(1)
		expect(order).toEqual(['fetchSettings', 'fetchObject'])
		expect(wrapper.find('[data-testid="usage-risk-score"]').text()).toBe('3 / 5')
	})

	it('does not read the settings again when the app already has them', async () => {
		store.settings = { loaded: true }
		mount(UsageRiskSignals, { props: { objectId: 'u1' } })
		await flushPromises()

		expect(store.fetchSettings).not.toHaveBeenCalled()
		expect(store.fetchObject).toHaveBeenCalled()
	})
})
