// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The organisation pickers on the Data quality page and the GEMMA plate load
 * the organisations themselves. Opened straight from a link, nothing else has
 * fetched that collection, and the picker answered "No results".
 *
 * @spec openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-003-a-data-quality-page-shows-how-complete-and-current-an-organisation-s-register-is
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const store = vi.hoisted(() => ({ objectStore: {} }))

vi.mock('../../src/store/store.js', () => store)

const { loadObjectTypeCollection } = await import('../../src/utils/objectTypes.js')

/**
 * A store double that records what it was asked.
 *
 * @param {object} options What the store already has.
 * @return {object} The store and its calls.
 */
function makeStore({ settings = null, registry = {}, config = null } = {}) {
	const calls = []
	const objectStore = {
		settings,
		objectTypeRegistry: registry,
		fetchSettings: vi.fn(async () => {
			calls.push(['fetchSettings'])
			objectStore.settings = { voorzieningen: { register: '25' } }
		}),
		getSchemaConfig: () => config,
		registerObjectType: (...args) => calls.push(['registerObjectType', ...args]),
		fetchCollection: async (...args) => calls.push(['fetchCollection', ...args]),
	}
	return { objectStore, calls }
}

describe('loadObjectTypeCollection', () => {
	beforeEach(() => {
		store.objectStore = {}
	})

	it('loads the settings, registers the type by slug and fetches its collection', async () => {
		const { objectStore, calls } = makeStore()
		store.objectStore = objectStore

		await loadObjectTypeCollection('organization')

		expect(calls).toEqual([
			['fetchSettings'],
			['registerObjectType', 'organization', 'organization', '25'],
			['fetchCollection', 'organization', { _limit: 1000 }],
		])
	})

	it('uses the schema configuration and loaded settings when they are there', async () => {
		const { objectStore, calls } = makeStore({
			settings: { voorzieningen: { register: '25' } },
			config: { register: '25', schema: '155' },
		})
		store.objectStore = objectStore

		await loadObjectTypeCollection('organization')

		expect(calls).toEqual([
			['registerObjectType', 'organization', '155', '25'],
			['fetchCollection', 'organization', { _limit: 1000 }],
		])
	})

	it('fetches a type the store already knows without registering it again', async () => {
		const { objectStore, calls } = makeStore({
			settings: {},
			registry: { organization: {} },
		})
		store.objectStore = objectStore

		await loadObjectTypeCollection('organization')

		expect(calls).toEqual([['fetchCollection', 'organization', { _limit: 1000 }]])
	})
})
