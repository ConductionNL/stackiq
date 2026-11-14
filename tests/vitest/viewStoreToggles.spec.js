/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The view store's enrichment toggles: each setter flips only its own flag,
 * and the next fetch asks for exactly the flags that are on.
 *
 * @spec openspec/specs/view-enrichment-api/spec.md#requirement-frontend-must-call-enrichment-api-for-views
 */

import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const get = vi.fn(async () => ({ data: { views: [], view: null } }))

vi.mock('@nextcloud/axios', () => ({ default: { get: (...args) => get(...args) } }))

const { useViewStore } = await import('../../src/store/modules/view.js')

describe('view store toggles', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		get.mockClear()
	})

	it('starts with every toggle off', () => {
		const store = useViewStore()
		expect([store.includeGebruik, store.includeDeelnamesGebruik, store.includeProducts]).toEqual([false, false, false])
	})

	it('setIncludeGebruik flips only the gebruik flag', () => {
		const store = useViewStore()
		store.setIncludeGebruik(true)
		expect([store.includeGebruik, store.includeDeelnamesGebruik, store.includeProducts]).toEqual([true, false, false])
		store.setIncludeGebruik(false)
		expect(store.includeGebruik).toBe(false)
	})

	it('setIncludeDeelnamesGebruik flips only the deelnames flag', () => {
		const store = useViewStore()
		store.setIncludeDeelnamesGebruik(true)
		expect([store.includeGebruik, store.includeDeelnamesGebruik, store.includeProducts]).toEqual([false, true, false])
	})

	it('setIncludeProducts flips only the products flag', () => {
		const store = useViewStore()
		store.setIncludeProducts(true)
		expect([store.includeGebruik, store.includeDeelnamesGebruik, store.includeProducts]).toEqual([false, false, true])
	})

	it('a fetch after the setters sends the parameters of the flags that are on', async () => {
		const store = useViewStore()
		store.setIncludeDeelnamesGebruik(true)
		store.setIncludeProducts(true)
		await store.fetchViews()
		expect(get).toHaveBeenCalledTimes(1)
		expect(get.mock.calls[0][1]).toEqual({
			params: { include_deelnames_gebruik: true, include_products: true },
		})

		store.setIncludeProducts(false)
		store.setIncludeGebruik(true)
		await store.fetchView('v 1')
		expect(get.mock.calls[1][0]).toContain('/apps/stackiq/api/views/v%201')
		expect(get.mock.calls[1][1]).toEqual({
			params: { include_gebruik: true, include_deelnames_gebruik: true },
		})
	})
})
