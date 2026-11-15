// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation test for the settings page's register auto-selection:
 * the publication register when there is one, else the first register when
 * none is selected yet, with its schema options loaded and its schemas
 * matched only when it lists them.
 *
 * @spec openspec/specs/fe-settings-ui/spec.md
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('../../src/store/store.js', () => ({
	navigationStore: {},
	objectStore: {},
	settingsStore: {},
}))

const { default: Settings } = await import('../../src/views/settings/Settings.vue')

/**
 * Runs the auto-selection and records what it did.
 *
 * @param {Array} registers The available registers.
 * @param {object|null} selectedRegister The register selected before.
 * @return {object} The selection and the calls made.
 */
function run(registers, selectedRegister = null) {
	const calls = []
	const vm = {
		settings: { availableRegisters: registers },
		selectedRegister,
		updateSchemaOptions: (id) => calls.push(['updateSchemaOptions', id]),
		autoSelectMatchingSchemas: (register) =>
			calls.push(['autoSelectMatchingSchemas', register.id]),
	}
	for (const [name, fn] of Object.entries(Settings.methods)) {
		if (!(name in vm)) {
			vm[name] = fn.bind(vm)
		}
	}
	vm.autoSelectOpenCatalogiRegister()
	return { selectedRegister: vm.selectedRegister, calls }
}

describe('Settings autoSelectOpenCatalogiRegister', () => {
	it('selects the publication register, even over a selected one', () => {
		const registers = [
			{ id: 1, title: 'Other', schemas: [] },
			{ id: 2, title: 'Open Publications', schemas: [{ id: 5 }] },
		]
		expect(run(registers, { label: 'Kept', value: '9' })).toEqual({
			selectedRegister: { label: 'Open Publications', value: '2' },
			calls: [
				['updateSchemaOptions', '2'],
				['autoSelectMatchingSchemas', 2],
			],
		})
		expect(run([{ id: 3, title: 'PUBLICATION' }])).toEqual({
			selectedRegister: { label: 'PUBLICATION', value: '3' },
			calls: [['updateSchemaOptions', '3']],
		})
	})

	it('falls back to the first register when none is selected', () => {
		const registers = [
			{ id: 7, title: 'First', schemas: [] },
			{ id: 8, title: 'Second' },
		]
		expect(run(registers)).toEqual({
			selectedRegister: { label: 'First', value: '7' },
			calls: [
				['updateSchemaOptions', '7'],
				['autoSelectMatchingSchemas', 7],
			],
		})
		expect(run([{ id: 8, title: 'Second' }])).toEqual({
			selectedRegister: { label: 'Second', value: '8' },
			calls: [['updateSchemaOptions', '8']],
		})
	})

	it('keeps a selection and does nothing without registers', () => {
		const kept = { label: 'Kept', value: '9' }
		expect(run([{ id: 7, title: 'First' }], kept)).toEqual({
			selectedRegister: kept,
			calls: [],
		})
		expect(run([])).toEqual({ selectedRegister: null, calls: [] })
	})
})
