/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * ensureObjectType() on a page opened straight from a link: the settings are
 * not loaded yet, and the store's getSchemaConfig() throws "Settings not
 * loaded" until they are. Found on a live instance: the application page's
 * Roadmap, Connection map and Contracts panels all said "could not be loaded".
 *
 * @spec openspec/specs/application-page/spec.md#requirement-req-apg-003-the-application-page-lists-the-contracts-behind-the-application
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const store = vi.hoisted(() => ({ objectStore: {} }))

vi.mock('../../src/store/store.js', () => store)

const { ensureObjectType } = await import('../../src/utils/objectTypes.js')

/**
 * A store like the real one: getSchemaConfig throws until the settings are loaded.
 *
 * @param {Array} trace Calls are appended here.
 * @param {object} [options] Overrides.
 * @param {boolean} [options.canFetch] Whether the store can fetch its settings.
 * @return {object} The fake store.
 */
function storeWithoutSettings(trace, { canFetch = true } = {}) {
	const fake = {
		settings: null,
		objectTypeRegistry: {},
		registerObjectType: (...args) => trace.push(['registerObjectType', ...args]),
		getSchemaConfig(type) {
			trace.push(['getSchemaConfig', type])
			if (!this.settings) {
				throw new Error('Settings not loaded')
			}
			return { register: '26', schema: '49' }
		},
	}
	if (canFetch) {
		fake.fetchSettings = async () => {
			trace.push(['fetchSettings'])
			fake.settings = { voorzieningen: { register: '26' } }
		}
	}
	return fake
}

describe('ensureObjectType without loaded settings', () => {
	let trace

	beforeEach(() => {
		trace = []
	})

	it('loads the settings first, then registers the type', async () => {
		store.objectStore = storeWithoutSettings(trace)

		await ensureObjectType('connection')

		expect(trace).toEqual([
			['fetchSettings'],
			['getSchemaConfig', 'connection'],
			['registerObjectType', 'connection', '49', '26'],
		])
	})

	it('does not throw when the settings cannot be loaded', async () => {
		store.objectStore = storeWithoutSettings(trace, { canFetch: false })

		await expect(ensureObjectType('connection')).resolves.toBeUndefined()
		expect(trace).toEqual([['getSchemaConfig', 'connection']])
	})

	it('does nothing for a type the store already knows', async () => {
		store.objectStore = storeWithoutSettings(trace)
		store.objectStore.objectTypeRegistry = { connection: { schema: '49', register: '26' } }

		await ensureObjectType('connection')

		expect(trace).toEqual([])
	})
})
