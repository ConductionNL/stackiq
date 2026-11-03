/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the initial state of the settings store and the stackiq
 * store plugin, and for the settings statistics rows. Initial states are
 * compared as JSON text so key order counts, and every call must hand out
 * fresh objects. Recorded with RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/fe-stores/spec.md
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { createPinia, setActivePinia } from 'pinia'
import { afterAll, describe, expect, it, vi } from 'vitest'
import { useSettingsStore } from '../../src/store/modules/settings.js'

vi.mock('@conduction/nextcloud-vue', () => ({
	buildHeaders: () => ({}),
	buildQueryString: () => '',
}))

const { stackiqPlugin } = await import('../../src/store/plugins/stackiqPlugin.js')

const GOLDEN = path.resolve(import.meta.dirname, 'golden/storeStateFactories.json')
const recorded = {}
const expected = process.env.RECORD_GOLDEN
	? {}
	: JSON.parse(readFileSync(GOLDEN, 'utf8'))

/**
 * Compare a case with the golden file as JSON text, or record it.
 *
 * @param {string} key The case name
 * @param {object} value What the case observed
 */
function check(key, value) {
	const text = JSON.stringify(value)
	recorded[key] = JSON.parse(text)
	if (!process.env.RECORD_GOLDEN) {
		expect(text).toBe(JSON.stringify(expected[key]))
	}
}

const STATISTICS = {
	'nothing configured': {
		voorzieningen: {
			configured: false,
			object_counts: { totalSectorObjects: 4 },
		},
		amef: { configured: false, object_counts: { totalElementObjects: 5 } },
	},
	'voorzieningen only': {
		voorzieningen: {
			configured: true,
			object_counts: {
				totalOrganisatieObjects: 3,
				totalSectorObjects: 0,
				totalReviewObjects: '7',
				stray: 9,
			},
		},
		amef: { configured: false, object_counts: {} },
	},
	'amef only': {
		voorzieningen: { configured: false, object_counts: {} },
		amef: {
			configured: true,
			object_counts: {
				totalElementObjects: 5,
				totalRelationshipsObjects: 2,
				totalPropertyObjects: null,
			},
		},
	},
	both: {
		voorzieningen: {
			configured: 1,
			object_counts: { totalModuleVersieObjects: 11 },
		},
		amef: {
			configured: 'yes',
			object_counts: { totalModelObjects: 1, totalViewObjects: 6 },
		},
	},
}

describe('store initial state and statistics rows', () => {
	afterAll(() => {
		if (process.env.RECORD_GOLDEN) {
			writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
		}
	})

	it('plugin state', () => {
		const first = stackiqPlugin().state()
		const second = stackiqPlugin().state()
		first.metadata.name.enabled = 'changed'
		first.metadata.extra = {}
		expect(second.metadata.name.enabled).toBe(true)
		expect(second.metadata.extra).toBeUndefined()
		expect(stackiqPlugin().state().metadata).not.toBe(second.metadata)
		check('plugin state', second)
	})

	it('settings state', () => {
		setActivePinia(createPinia())
		const first = useSettingsStore()
		first.emailSettings.smtpHost = 'changed'
		first.settings.availableRegisters.push('x')
		setActivePinia(createPinia())
		const second = useSettingsStore()
		check('settings state', second.$state)
	})

	for (const [name, statistics] of Object.entries(STATISTICS)) {
		it('formattedStatistics: ' + name, () => {
			setActivePinia(createPinia())
			const store = useSettingsStore()
			store.statistics = structuredClone(statistics)
			check('formattedStatistics: ' + name, store.formattedStatistics)
		})
	}
})
