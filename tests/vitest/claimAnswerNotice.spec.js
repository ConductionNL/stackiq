// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The claiming organisation sees the supplier's answer: the usage page carries
 * the answer notice, which names the decline with its date and shows nothing
 * for an accepted, unanswered or unreadable usage.
 *
 * @spec openspec/changes/supplier-accepts-claimed-usage/specs/aanbod-listings/spec.md#requirement-a-supplier-answers-the-usages-claimed-of-its-products-req-010
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import ClaimAnswerNotice from '../../src/components/portfolio/ClaimAnswerNotice.vue'
import register from '../../lib/Settings/softwarecatalogus_register.json'
import usages from '../../src/manifest.d/usages.json'

const store = vi.hoisted(() => ({ settings: {}, fetchObject: vi.fn() }))

vi.mock('../../src/store/store.js', () => ({ objectStore: store }))
vi.mock('../../src/utils/objectTypes.js', () => ({ ensureObjectTypeOrSlug: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (match, key) => vars[key] ?? match),
}))
vi.mock('@nextcloud/vue', () => ({
	NcNoteCard: { render() { return h('p', { 'data-testid': 'claim-declined-notice' }, this.$slots.default?.()) } },
}))

async function show (usage) {
	store.fetchObject.mockImplementation(usage instanceof Error ? () => Promise.reject(usage) : () => Promise.resolve(usage))
	const wrapper = mount(ClaimAnswerNotice, { props: { objectId: 'usage-1' } })
	await flushPromises()
	return wrapper
}

describe('the answer notice on a usage page', () => {
	afterEach(() => vi.clearAllMocks())

	it('sits on the usage detail page and reads the page\'s usage', () => {
		const detail = usages.pages.find((page) => page.id === 'GebruikDetail')
		const widget = detail.config.bodyWidgets.find((w) => w.id === 'gb-claim-answer')

		expect(detail.config.schema).toBe('usage')
		expect(widget).toMatchObject({ component: 'ClaimAnswerNotice', placement: 'after-data', props: { objectId: '@objectId' } })
	})

	it('reads fields the usage schema has', () => {
		const properties = register.components.schemas.usage.properties

		expect(properties.providerResponse.enum).toEqual(expect.arrayContaining(['accepted', 'declined']))
		expect(properties.providerResponseAt.format).toBe('date-time')
	})

	it('says the supplier does not recognise a declined usage, with the date', async () => {
		const wrapper = await show({ object: { providerResponse: 'declined', providerResponseAt: '2026-10-03T08:00:00+00:00' } })

		expect(store.fetchObject).toHaveBeenCalledWith('usage', 'usage-1')
		expect(wrapper.find('[data-testid="claim-declined-notice"]').text()).toBe('The supplier does not recognise this usage (declined on 2026-10-03).')
	})

	it('shows nothing for an accepted, unanswered or unreadable usage', async () => {
		for (const usage of [{ providerResponse: 'accepted', providerResponseAt: '2026-10-03T08:00:00+00:00' }, {}, new Error('404')]) {
			const wrapper = await show(usage)
			expect(wrapper.find('[data-testid="claim-declined-notice"]').exists()).toBe(false)
		}
	})

	it('reads nothing without a usage id', async () => {
		const wrapper = mount(ClaimAnswerNotice, { props: { objectId: '' } })
		await flushPromises()

		expect(store.fetchObject).not.toHaveBeenCalled()
		expect(wrapper.find('[data-testid="claim-declined-notice"]').exists()).toBe(false)
	})
})
