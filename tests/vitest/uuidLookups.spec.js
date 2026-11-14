// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation test for the uuid lookups of the license posture and
 * lifecycle roadmap views and the SBOM and vulnerability panels: the
 * organisation and module indexes (uuid, id or @self id, names with their
 * fallbacks, records without a uuid left out), the organisation options,
 * the record the panels inspect, and the vendor rows' licence mix.
 *
 * @spec openspec/specs/software-license-posture/spec.md
 */

import { describe, expect, it, vi } from 'vitest'

const store = vi.hoisted(() => ({ collections: {}, active: {} }))

vi.mock('../../src/store/store.js', () => ({
	navigationStore: {},
	objectStore: {
		getCollection: (type) => store.collections[type],
		getActiveObject: (type) => store.active[type],
	},
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDataTable: {},
	CnDetailCard: {},
	CnIndexPage: {},
}))
vi.mock('../../src/composables/useLiveCollections.js', () => ({
	useLiveCollections: () => ({}),
}))

const views = {
	LicensePostureView: (await import('../../src/views/LicensePostureView.vue'))
		.default,
	LifecycleRoadmapView: (await import('../../src/views/LifecycleRoadmapView.vue'))
		.default,
	SbomComponentsPanel: (
		await import('../../src/components/sbom/SbomComponentsPanel.vue')
	).default,
	VulnerabilityExposurePanel: (
		await import('../../src/components/vulnerabilities/VulnerabilityExposurePanel.vue')
	).default,
}

const RECORDS = [
	{ uuid: ' u1 ', name: 'Named', title: 'Titled' },
	{ id: 'i2', title: 'Only title' },
	{ '@self': { id: 's3' }, object: { name: 'Object name' } },
	{ uuid: { uuid: 'nested' } },
	{ uuid: null, id: 0, name: 'Zero id' },
	{},
	'plain-uuid',
]

/**
 * One computed property of a component for a state.
 *
 * @param {string} view The component.
 * @param {string} name The computed property.
 * @param {object} state The component state.
 * @return {*} The value.
 */
function computed(view, name, state = {}) {
	const vm = { ...state }
	return views[view].computed[name].call(vm)
}

describe('uuid lookups', () => {
	it('builds the license posture indexes', () => {
		store.collections = { organization: { results: RECORDS } }
		expect(computed('LicensePostureView', 'organisatieIndex')).toEqual({
			u1: 'Named',
			i2: 'Only title',
			s3: 'Object name',
			nested: 'nested',
			'plain-uuid': 'plain-uuid',
		})
		expect(
			computed('LicensePostureView', 'moduleNameIndex', { modules: RECORDS }),
		).toEqual(computed('LicensePostureView', 'organisatieIndex'))
		store.collections = {}
		expect(computed('LicensePostureView', 'organisatieIndex')).toEqual({})
	})

	it('builds the lifecycle roadmap indexes and options', () => {
		store.collections = {
			moduleVersion: { results: RECORDS },
			module: { results: RECORDS },
			organization: { results: RECORDS },
		}
		const index = computed('LifecycleRoadmapView', 'moduleVersieIndex')
		expect(Object.keys(index)).toEqual([
			'u1',
			'i2',
			's3',
			'nested',
			'plain-uuid',
		])
		expect(index.u1).toBe(RECORDS[0])
		expect(computed('LifecycleRoadmapView', 'moduleIndex')).toEqual(index)
		expect(computed('LifecycleRoadmapView', 'organisationOptions')).toEqual([
			{ uuid: 'u1', label: 'Named' },
			{ uuid: 'i2', label: 'Only title' },
			{ uuid: 's3', label: '' },
			{ uuid: 'nested', label: 'nested' },
			{ uuid: '', label: 'Zero id' },
			{ uuid: '', label: '' },
			{ uuid: 'plain-uuid', label: '' },
		])
		store.collections = {}
		expect(computed('LifecycleRoadmapView', 'moduleIndex')).toEqual({})
		expect(computed('LifecycleRoadmapView', 'organisationOptions')).toEqual([])
	})

	it('finds the module version the SBOM panel inspects', () => {
		const active = { uuid: 'mv-1' }
		store.collections = {
			moduleVersion: {
				results: [{ id: 'mv-2' }, { '@self': { id: 'mv-3' } }],
			},
		}
		store.active = { moduleVersion: active }
		expect(
			computed('SbomComponentsPanel', 'moduleVersion', { objectId: 'mv-1' }),
		).toBe(active)
		expect(
			computed('SbomComponentsPanel', 'moduleVersion', { objectId: 'mv-3' }),
		).toEqual({ '@self': { id: 'mv-3' } })
		expect(
			computed('SbomComponentsPanel', 'moduleVersion', { objectId: 'mv-9' }),
		).toBe(active)
		store.active = {}
		expect(
			computed('SbomComponentsPanel', 'moduleVersion', { objectId: 'mv-9' }),
		).toBe(null)
		expect(
			computed('SbomComponentsPanel', 'moduleVersion', { objectId: 'mv-2' }),
		).toEqual({ id: 'mv-2' })
	})

	it('finds the vulnerability the exposure panel inspects', () => {
		const active = { uuid: 'v-1' }
		store.collections = {
			vulnerability: { results: [{ id: 'v-2' }, { uuid: ' v-3 ' }] },
		}
		store.active = { vulnerability: active }
		expect(
			computed('VulnerabilityExposurePanel', 'vulnerability', {
				objectId: 'other',
			}),
		).toBe(active)
		store.active = {}
		expect(
			computed('VulnerabilityExposurePanel', 'vulnerability', {
				objectId: 'v-3',
			}),
		).toEqual({ uuid: ' v-3 ' })
		expect(
			computed('VulnerabilityExposurePanel', 'vulnerability', {
				objectId: 'v-9',
			}),
		).toBe(null)
		expect(
			computed('VulnerabilityExposurePanel', 'vulnerability', {
				objectId: '',
			}),
		).toBe(null)
		store.collections = {}
		expect(
			computed('VulnerabilityExposurePanel', 'vulnerability', {
				objectId: 'v-2',
			}),
		).toBe(null)
	})
	it('builds the exposure panel index of a display field', () => {
		store.collections = { module: { results: RECORDS } }
		const vm = {}
		const buildIndex =
			views.VulnerabilityExposurePanel.methods.buildIndex.bind(vm)
		expect(buildIndex('module', 'name')).toEqual({
			u1: 'Named',
			i2: 'Only title',
			s3: 'Object name',
			nested: 'nested',
			'plain-uuid': 'plain-uuid',
		})
		expect(buildIndex('module', 'missing')).toEqual({
			u1: 'Titled',
			i2: 'Only title',
			s3: 's3',
			nested: 'nested',
			'plain-uuid': 'plain-uuid',
		})
		expect(buildIndex('absent', 'name')).toEqual({})
	})
})
