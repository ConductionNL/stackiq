// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation test for the shareable selection of the compliance
 * matrix: the URL written for a selection (column source, standards, BIO
 * measures, organisation; empty parts removed, other parameters kept) and
 * the selection restored from a URL (known column sources only, uuids
 * trimmed and matched against the options, an unknown organisation as null).
 *
 * @spec openspec/specs/module-compliance-assessment/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({}))
vi.mock('../../src/store/store.js', () => ({ navigationStore: {}, objectStore: {} }))
vi.mock('../../src/composables/useLiveCollections.js', () => ({
	useLiveCollections: () => ({}),
}))

const { default: ComplianceMatrixView } =
	await import('../../src/views/ComplianceMatrixView.vue')

/**
 * A stand-in for the view with the given state and every method bound to it.
 *
 * @param {object} state The view state.
 * @return {object} The stand-in.
 */
function viewModel(state) {
	const vm = { ...state }
	for (const [name, fn] of Object.entries(ComplianceMatrixView.methods)) {
		if (typeof fn === 'function' && !(name in vm)) {
			vm[name] = fn.bind(vm)
		}
	}
	return vm
}

/**
 * Puts the browser at a path and query.
 *
 * @param {string} query The query string.
 */
function at(query) {
	window.history.replaceState({}, '', '/apps/stackiq/matrix' + query)
}

beforeEach(() => {
	at('')
})

describe('ComplianceMatrixView onSelectionChange', () => {
	it('writes the whole selection', () => {
		at('?keep=1&standards=old')
		viewModel({
			columnSource: 'bioMeasure',
			selectedStandards: [{ uuid: 's1' }, { uuid: '' }, { uuid: 's2' }],
			selectedBioMeasures: [{ uuid: 'b1' }],
			selectedOrganisation: { uuid: 'o1' },
		}).onSelectionChange()
		expect(window.location.search).toBe(
			'?keep=1&standards=s1%2Cs2&columnSource=bioMeasure&bioMeasures=b1&org=o1',
		)
	})

	it('removes the empty parts', () => {
		at('?standards=a&bioMeasures=b&org=c')
		viewModel({
			columnSource: 'standard_version',
			selectedStandards: [{ uuid: null }],
			selectedBioMeasures: [],
			selectedOrganisation: { uuid: '' },
		}).onSelectionChange()
		expect(window.location.search).toBe('?columnSource=standard_version')
		viewModel({
			columnSource: 'x',
			selectedStandards: [],
			selectedBioMeasures: [],
			selectedOrganisation: null,
		}).onSelectionChange()
		expect(window.location.search).toBe('?columnSource=x')
	})
})

describe('ComplianceMatrixView restoreSelectionFromUrl', () => {
	const OPTIONS = {
		standardOptions: [{ uuid: 's1' }, { uuid: 's2' }, { uuid: 's3' }],
		bioMaatregelOptions: [{ uuid: 'b1' }, { uuid: 'b2' }],
		organisationOptions: [{ uuid: 'o1' }, { uuid: 'o2' }],
	}
	const before = {
		columnSource: 'standard_version',
		selectedStandards: 'unchanged',
		selectedBioMeasures: 'unchanged',
		selectedOrganisation: 'unchanged',
	}
	const restored = (query) => {
		at(query)
		const vm = viewModel({ ...OPTIONS, ...before })
		vm.restoreSelectionFromUrl()
		return {
			columnSource: vm.columnSource,
			selectedStandards: vm.selectedStandards,
			selectedBioMeasures: vm.selectedBioMeasures,
			selectedOrganisation: vm.selectedOrganisation,
		}
	}

	it('restores every part', () => {
		expect(
			restored(
				'?columnSource=bioMeasure&standards=%20s3%20,,s1,sX&bioMeasures=b2&org=o2',
			),
		).toEqual({
			columnSource: 'bioMeasure',
			selectedStandards: [{ uuid: 's1' }, { uuid: 's3' }],
			selectedBioMeasures: [{ uuid: 'b2' }],
			selectedOrganisation: { uuid: 'o2' },
		})
	})

	it('keeps what the URL does not name', () => {
		expect(restored('?columnSource=other&standards=&org=')).toEqual(before)
		expect(restored('')).toEqual(before)
		expect(
			restored('?columnSource=standard_version&bioMeasures=bX&org=oX'),
		).toEqual({
			columnSource: 'standard_version',
			selectedStandards: 'unchanged',
			selectedBioMeasures: [],
			selectedOrganisation: null,
		})
	})
})
