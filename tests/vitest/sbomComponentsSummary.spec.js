// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation test for the SBOM panel's component list and licence
 * count: the components of this module version (envelope or plain data,
 * uuid relations as strings or objects) sorted by name, and the distinct,
 * non-empty licence strings across them.
 *
 * @spec openspec/specs/sbom-import/spec.md#requirement-the-module-version-detail-page-shows-imported-components-with-summary-counts
 */

import { describe, expect, it, vi } from 'vitest'

const store = vi.hoisted(() => ({ collections: {} }))

vi.mock('../../src/store/store.js', () => ({
	objectStore: { getCollection: (type) => store.collections[type] },
}))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDataTable: {} }))

const { default: SbomComponentsPanel } =
	await import('../../src/components/sbom/SbomComponentsPanel.vue')

const COMPONENTS = [
	{
		id: 'c1',
		object: { name: 'zlib', moduleVersion: 'mv-1', licenses: ['Zlib', ''] },
	},
	{
		id: 'c2',
		name: 'Alpha',
		moduleVersion: { uuid: ' mv-1 ' },
		licenses: ['MIT', 'Apache-2.0'],
	},
	{ id: 'c3', name: 'other', moduleVersion: 'mv-2', licenses: ['GPL-3.0'] },
	{ id: 'c4', object: { moduleVersion: 'mv-1', licenses: 'MIT' } },
	{ id: 'c5', name: 'beta', moduleVersion: 'mv-1', licenses: ['MIT', 7, null] },
	{ id: 'c6', name: 'Beta', moduleVersion: 'mv-1' },
]

/**
 * One computed property of the panel for a module version.
 *
 * @param {string} name The computed property.
 * @param {object} state The panel state.
 * @return {*} The value.
 */
function computed(name, state) {
	const vm = { ...state }
	return SbomComponentsPanel.computed[name].call(vm)
}

describe('SbomComponentsPanel components', () => {
	it('lists this module version components by name', () => {
		store.collections = { sbomComponent: { results: COMPONENTS } }
		const list = computed('components', { objectId: 'mv-1' })
		expect(list.map((c) => c.id)).toEqual(['c4', 'c2', 'c5', 'c6', 'c1'])
		expect(computed('components', { objectId: 'mv-9' })).toEqual([])
		store.collections = {}
		expect(computed('components', { objectId: 'mv-1' })).toEqual([])
	})
})

describe('SbomComponentsPanel distinctLicenseCount', () => {
	it('counts distinct non-empty licence strings', () => {
		expect(computed('distinctLicenseCount', { components: COMPONENTS })).toBe(4)
		expect(
			computed('distinctLicenseCount', { components: COMPONENTS.slice(3) }),
		).toBe(1)
		expect(computed('distinctLicenseCount', { components: [] })).toBe(0)
	})
})
