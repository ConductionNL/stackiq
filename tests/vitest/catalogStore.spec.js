/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The catalog store: its getters follow the state its actions set, and reset
 * returns it to the initial state.
 *
 * @spec openspec/specs/fe-stores/spec.md
 */

import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { useCatalogStore } from '../../src/store/modules/catalog.js'

describe('catalog store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('starts empty and idle', () => {
		const store = useCatalogStore()

		expect(store.catalogs).toEqual([])
		expect(store.getCurrentCatalog).toBeNull()
		expect(store.isLoading).toBe(false)
		expect(store.getError).toBeNull()
	})

	it('reflects what its actions set', () => {
		const store = useCatalogStore()
		const catalog = { id: 'c-1' }

		store.setLoading(true)
		store.setError('Offline')
		store.setCurrentCatalog(catalog)

		expect(store.isLoading).toBe(true)
		expect(store.getError).toBe('Offline')
		expect(store.getCurrentCatalog).toEqual(catalog)
	})

	it('clears only the error', () => {
		const store = useCatalogStore()
		store.setLoading(true)
		store.setError('Offline')

		store.clearError()

		expect(store.getError).toBeNull()
		expect(store.isLoading).toBe(true)
	})

	it('resets everything', () => {
		const store = useCatalogStore()
		store.catalogs = [{ id: 'c-1' }]
		store.setCurrentCatalog({ id: 'c-1' })
		store.setLoading(true)
		store.setError('x')

		store.reset()

		expect(store.$state).toEqual({ catalogs: [], currentCatalog: null, loading: false, error: null })
	})
})
