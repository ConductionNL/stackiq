/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the navigation store's consume-once transferData handoff.
 *
 * @spec openspec/specs/fe-stores/spec.md
 */

import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { useNavigationStore } from '../../src/store/modules/navigation.js'

describe('navigation store transferData', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('hands the data over once and then clears it', () => {
		const store = useNavigationStore()
		const payload = { organisatie: 'org-1' }

		store.setTransferData(payload)

		expect(store.getTransferData()).toEqual({ organisatie: 'org-1' })
		expect(store.transferData).toBeNull()
		expect(store.getTransferData()).toBeNull()
	})

	it('a later handoff replaces an unread one', () => {
		const store = useNavigationStore()

		store.setTransferData('first')
		store.setTransferData('second')

		expect(store.getTransferData()).toBe('second')
	})
})
