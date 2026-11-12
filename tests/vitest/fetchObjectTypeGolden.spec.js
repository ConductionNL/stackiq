// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the `fetchType(type)` of the six views and panels that
 * load an object type collection: when the type is registered from its
 * schema config, and how the collection is fetched. Recorded with
 * RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/sbom-import/spec.md#requirement-the-module-version-detail-page-shows-imported-components-with-summary-counts
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'

const trace = []
const store = vi.hoisted(() => ({ objectStore: {} }))

vi.mock('../../src/store/store.js', () => ({
	objectStore: store.objectStore,
	navigationStore: {},
	catalogStore: {},
}))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDataTable: {}, CnDetailCard: {}, CnIndexPage: {} }))

const components = {
	SbomComponentsPanel: (await import('../../src/components/sbom/SbomComponentsPanel.vue')).default,
	VulnerabilityExposurePanel: (await import('../../src/components/vulnerabilities/VulnerabilityExposurePanel.vue')).default,
	LifecycleRoadmapView: (await import('../../src/views/LifecycleRoadmapView.vue')).default,
	LicensePostureView: (await import('../../src/views/LicensePostureView.vue')).default,
	KwetsbaarhedenView: (await import('../../src/views/KwetsbaarhedenView.vue')).default,
	ComplianceMatrixView: (await import('../../src/views/ComplianceMatrixView.vue')).default,
}

const GOLDEN = path.resolve(import.meta.dirname, 'golden/fetchObjectTypeGolden.json')

const stores = {
	'unregistered, config resolves': { registry: {}, config: { register: 'r', schema: 's' } },
	'already registered': { registry: { thing: {} }, config: { register: 'r', schema: 's' } },
	'no registry yet': { registry: undefined, config: { register: 'r', schema: 's' } },
	'config throws': { registry: {}, config: 'throw' },
	'config without schema': { registry: {}, config: { register: 'r' } },
	'no getSchemaConfig': { registry: {}, config: 'absent' },
	'no registerObjectType': { registry: {}, config: { register: 'r', schema: 's' }, noRegister: true },
	'no fetchCollection': { registry: {}, config: { register: 'r', schema: 's' }, noFetch: true },
	'fetchCollection rejects': { registry: {}, config: { register: 'r', schema: 's' }, fetchRejects: true },
}

/**
 * Set up the store for one scenario.
 *
 * @param {object} scenario The scenario.
 * @return {void}
 */
function setUp(scenario) {
	for (const key of Object.keys(store.objectStore)) {
		delete store.objectStore[key]
	}
	store.objectStore.objectTypeRegistry = scenario.registry
	if (scenario.config !== 'absent') {
		store.objectStore.getSchemaConfig = (type) => {
			trace.push(['getSchemaConfig', type])
			if (scenario.config === 'throw') {
				throw new Error('no schema')
			}
			return scenario.config
		}
	}
	if (!scenario.noRegister) {
		store.objectStore.registerObjectType = (...args) => trace.push(['registerObjectType', ...args])
	}
	if (!scenario.noFetch) {
		store.objectStore.fetchCollection = async (...args) => {
			trace.push(['fetchCollection', ...args])
			if (scenario.fetchRejects) {
				throw new Error('fetch failed')
			}
		}
	}
}

describe('fetchType of the collection views', () => {
	for (const [componentName, component] of Object.entries(components)) {
		for (const [scenarioName, scenario] of Object.entries(stores)) {
			const name = `${componentName}, ${scenarioName}`
			it(name, async () => {
				trace.length = 0
				setUp(scenario)
				const warn = vi.spyOn(console, 'warn').mockImplementation((...args) => trace.push(['console.warn', String(args[0]), String(args[1])]))
				let outcome = 'resolved'
				try {
					await component.methods.fetchType.call({}, 'thing')
				} catch (error) {
					outcome = 'rejected: ' + error.message
				}
				warn.mockRestore()
				const actual = { outcome, trace: [...trace] }
				if (process.env.RECORD_GOLDEN) {
					const all = { ...readGolden(), [name]: actual }
					writeFileSync(GOLDEN, JSON.stringify(all, null, '\t') + '\n')
				}
				expect(actual).toEqual(readGolden()[name])
			})
		}
	}
})

/**
 * The recorded golden, or an empty object while recording the first case.
 *
 * @return {object} Results by case name.
 */
function readGolden() {
	try {
		return JSON.parse(readFileSync(GOLDEN, 'utf8'))
	} catch (error) {
		if (process.env.RECORD_GOLDEN) {
			return {}
		}
		throw error
	}
}
