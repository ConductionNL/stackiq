// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for how the portfolio, roadmap, maintenance and contract
 * widgets register an object type with the store, and for how the usage
 * risk widget loads its usage, version and vulnerabilities. Recorded with
 * RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/application-value-assessment/spec.md#requirement-req-ava-002-the-usage-page-shows-the-risk-signals-next-to-the-risk-score
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'

const store = vi.hoisted(() => ({ objectStore: {} }))

vi.mock('../../src/store/store.js', () => store)
vi.mock('@conduction/nextcloud-vue', () => ({
	CnIcon: {},
	CnTimelineView: {},
	CnWidgetWrapper: {},
}))
vi.mock('@nextcloud/vue', () => ({ NcLoadingIcon: {}, NcNoteCard: {} }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one, many, count) => (count === 1 ? one : many),
}))

const COMPONENTS = {
	UsageRiskSignals: (
		await import('../../src/components/portfolio/UsageRiskSignals.vue')
	).default,
	ProductRoadmap: (await import('../../src/components/roadmap/ProductRoadmap.vue'))
		.default,
	UpcomingMaintenanceWidget: (
		await import('../../src/components/maintenance/UpcomingMaintenanceWidget.vue')
	).default,
	ApplicationContractsPanel: (
		await import('../../src/components/contracts/ApplicationContractsPanel.vue')
	).default,
}

const GOLDEN = path.resolve(
	import.meta.dirname,
	'golden/objectTypeRegistration.json',
)

const CONFIG = { register: 'r1', schema: 's1' }

const STORES = {
	'no register function': {},
	'already known': { registry: { usage: true } },
	'no schema config function': { register: true },
	'config found': { register: true, config: () => CONFIG },
	'config without schema': { register: true, config: () => ({ register: 'r1' }) },
	'config throws': {
		register: true,
		config: () => {
			throw new Error('no config')
		},
	},
	'voorzieningen fallback': {
		register: true,
		config: () => null,
		settings: { voorzieningen: { register: 'vz' } },
	},
	'voorzieningenConfig fallback': {
		register: true,
		config: () => null,
		settings: { voorzieningenConfig: { register: 'vzc' } },
	},
	'voorzieningen without register': {
		register: true,
		config: () => null,
		settings: { voorzieningen: {} },
	},
}

/**
 * Build the fake object store for a scenario.
 *
 * @param {object} setup The scenario.
 * @param {Array} trace The trace to append calls to.
 * @return {object} The fake store.
 */
function fakeStore(setup, trace) {
	const fake = { objectTypeRegistry: setup.registry, settings: setup.settings }
	if (setup.register) {
		fake.registerObjectType = (...args) =>
			trace.push(['registerObjectType', ...args])
	}
	if (setup.config) {
		fake.getSchemaConfig = (type) => {
			trace.push(['getSchemaConfig', type])
			return setup.config(type)
		}
	}
	return fake
}

/**
 * Run ensureType('usage') of one component against one store.
 *
 * @param {object} component The component.
 * @param {object} setup The scenario.
 * @return {Array} The trace, ending with the error if one was thrown.
 */
function recordEnsure(component, setup) {
	const trace = []
	store.objectStore = fakeStore(setup, trace)
	try {
		component.methods.ensureType.call({}, 'usage')
	} catch (e) {
		trace.push(['threw', e.message])
	}
	return trace
}

const LOADS = {
	'full usage': {
		usage: {
			object: { riskScore: 7, module: 'm1', moduleVersion: { id: 'v1' } },
		},
	},
	'plain usage': { usage: { riskScore: 7.5, module: { uuid: 'm1' } } },
	'no module': { usage: { moduleVersion: 'v1' } },
	'null usage': { usage: null },
	'needs settings': { usage: {}, needsSettings: true },
	'fetch fails': { fail: true },
}

/**
 * Run UsageRiskSignals.load() for one scenario.
 *
 * @param {object} setup The scenario.
 * @return {Promise<object>} The trace and the widget state.
 */
async function recordLoad(setup) {
	const trace = []
	const fake = fakeStore({ register: true, config: () => CONFIG }, trace)
	fake.settings = setup.needsSettings ? null : {}
	fake.fetchSettings = async () => trace.push(['fetchSettings'])
	fake.fetchObject = async (type, id) => {
		trace.push(['fetchObject', type, id])
		if (setup.fail) throw new Error('down')
		return type === 'usage'
			? structuredClone(setup.usage)
			: { id, endOfSupport: '2000-01-01' }
	}
	fake.fetchCollectionForOptions = async (type, options) => {
		trace.push(['fetchCollectionForOptions', type, options])
		return [{ id: 'cve-1', severity: 'high', modules: ['m1'] }]
	}
	store.objectStore = fake
	const component = COMPONENTS.UsageRiskSignals
	const vm = { ...component.data.call({}), objectId: 'u1' }
	for (const [name, fn] of Object.entries(component.methods)) {
		vm[name] = fn.bind(vm)
	}
	await vm.load()
	return {
		trace,
		loading: vm.loading,
		error: vm.error,
		riskScore: vm.riskScore,
		signals: vm.signals,
	}
}

describe('object type registration golden', () => {
	it('matches the recorded outcomes', async () => {
		const actual = { ensureType: {}, load: {} }
		for (const [name, component] of Object.entries(COMPONENTS)) {
			for (const [scenario, setup] of Object.entries(STORES)) {
				actual.ensureType[name + ' ' + scenario] = recordEnsure(
					component,
					setup,
				)
			}
		}
		for (const [scenario, setup] of Object.entries(LOADS)) {
			actual.load[scenario] = await recordLoad(setup)
		}
		if (process.env.RECORD_GOLDEN === '1') {
			writeFileSync(GOLDEN, JSON.stringify(actual, null, '\t') + '\n')
		}
		expect(actual).toEqual(JSON.parse(readFileSync(GOLDEN, 'utf8')))
	})
})
