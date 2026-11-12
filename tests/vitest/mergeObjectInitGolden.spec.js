// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for how the merge dialog picks its default value per
 * property and builds the options for each property. Recorded with
 * RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'

vi.mock('../../src/store/store.js', () => ({
	catalogStore: {},
	navigationStore: {},
	objectStore: {},
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {},
	NcCheckboxRadioSwitch: {},
	NcDialog: {},
	NcEmptyContent: {},
	NcLoadingIcon: {},
	NcNoteCard: {},
	NcSelect: {},
	NcTextField: {},
}))

const { default: MergeObject } =
	await import('../../src/modals/object/MergeObject.vue')

const GOLDEN = path.resolve(import.meta.dirname, 'golden/mergeObjectInit.json')

const VALUES = [undefined, null, '', 0, false, 'text', ['a'], { a: 1 }, 'custom']

/**
 * Build the cases: every combination of source and target value for one property, plus an id.
 *
 * @return {object} The cases keyed by name.
 */
function cases() {
	const out = {}
	VALUES.forEach((source, i) => {
		VALUES.forEach((target, j) => {
			out['source ' + i + ' target ' + j] = { source, target }
		})
	})
	return out
}

/**
 * Initialise the merge data for one case and record the state.
 *
 * @param {object} setup The source and target values.
 * @return {object} The merged data, custom values, selections and options.
 */
function record(setup) {
	const sourceObject = { id: 'src', name: setup.source }
	const selectedTargetObject = { id: 'tgt', name: setup.target }
	const vm = {
		sourceObject,
		selectedTargetObject,
		mergeableProperties: ['id', 'name'],
		mergedData: { stale: true },
		customValues: { stale: true },
		propertySelections: { stale: true },
	}
	for (const [name, fn] of Object.entries(MergeObject.methods)) {
		vm[name] = fn.bind(vm)
	}
	vm.initializeMergeData()
	return {
		mergedData: vm.mergedData,
		customValues: vm.customValues,
		propertySelections: vm.propertySelections,
		options: vm.getMergeOptions('name'),
		hasName: Object.hasOwn(vm.mergedData, 'name'),
	}
}

describe('MergeObject initializeMergeData golden', () => {
	it('matches the recorded outcomes', () => {
		vi.spyOn(console, 'log').mockImplementation(() => {})
		const actual = {}
		for (const [name, setup] of Object.entries(cases())) {
			actual[name] = JSON.parse(
				JSON.stringify(record(setup), (k, v) =>
					v === undefined ? '__undefined__' : v,
				),
			)
		}
		if (process.env.RECORD_GOLDEN === '1') {
			const lines = Object.entries(actual).map(
				([name, row]) => JSON.stringify(name) + ': ' + JSON.stringify(row),
			)
			writeFileSync(GOLDEN, '{\n' + lines.join(',\n') + '\n}\n')
		}
		expect(actual).toEqual(JSON.parse(readFileSync(GOLDEN, 'utf8')))
	})
})
