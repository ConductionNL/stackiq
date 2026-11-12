// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the organisation modal: its initial form, the reset, the
 * form loaded from an organisation in each mode, and saving (invalid form,
 * nothing changed, an update, a create or copy, a failure): the store
 * calls, the errors shown, the console lines, the emitted events after the
 * timers ran, and the state afterwards. Recorded with RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/fe-organizations/spec.md
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const trace = []
const failures = { save: null }

vi.mock('@nextcloud/dialogs', () => ({
	showError: (message) => trace.push(['showError', message]),
}))
vi.mock('../../src/store/store.js', () => ({
	objectStore: {
		getSchemaConfig: (type) => {
			trace.push(['getSchemaConfig', type])
			return { register: 'reg-1', schema: 'schema-1' }
		},
		patchObject: async (...args) => {
			trace.push(['patchObject', ...structuredClone(args)])
			if (failures.save) throw new Error(failures.save)
		},
		saveObject: async (...args) => {
			trace.push(['saveObject', ...structuredClone(args)])
			if (failures.save) throw new Error(failures.save)
		},
	},
	navigationStore: {
		setTransferData: (data) => trace.push(['setTransferData', data]),
	},
}))

const { default: OrganisationModal } =
	await import('../../src/modals/OrganisationModal.vue')

const GOLDEN = path.resolve(import.meta.dirname, 'golden/organisationModal.json')

const ORGANISATIONS = {
	full: {
		id: 'org-1',
		name: 'Gemeente Test',
		website: 'https://example.org',
		type: 'Municipality',
		shortDescription: 'Short',
		'e-mailadres': 'org@example.org',
		telefoonnummer: '0101234567',
		oin: '0000001',
		cbs: '0363',
		status: 'Active',
		participants: ['p1'],
		contactpersonen: ['c1'],
		extra: 'kept out',
	},
	sparse: { id: 'org-2', name: 'Alleen naam', type: 'Unknown type' },
	'falsy values': {
		id: 'org-3',
		name: '',
		website: 0,
		status: '',
		participants: null,
		contactpersonen: null,
	},
}

/**
 * The component bound to a plain object, as Vue would expose it.
 *
 * @param {object} props The props.
 * @return {object} The fake instance.
 */
function instance(props) {
	const vm = {
		...structuredClone(props),
		t: (app, text, vars) => (vars ? `${text} ${JSON.stringify(vars)}` : text),
		$emit: (...args) => trace.push(['$emit', ...args]),
	}
	Object.assign(vm, OrganisationModal.data.call(vm))
	for (const [name, getter] of Object.entries(OrganisationModal.computed)) {
		Object.defineProperty(vm, name, { get: getter.bind(vm), configurable: true })
	}
	for (const [name, fn] of Object.entries(OrganisationModal.methods)) {
		vm[name] = fn.bind(vm)
	}
	return vm
}

/**
 * The state the modal methods change.
 *
 * @param {object} vm The fake instance.
 * @return {object} The state.
 */
function state(vm) {
	return {
		formData: vm.formData,
		selectedType: vm.selectedType,
		loading: vm.loading,
		success: vm.success,
		successMessage: vm.successMessage,
		countdown: vm.countdown,
		countdownRunning: vm.countdownInterval !== null,
	}
}

/**
 * Run a scenario and record what it did, before and after the timers.
 *
 * @param {Function} scenario Acts on the fake instance.
 * @param {object} props The props.
 * @return {Promise<object>} The trace and states.
 */
async function record(scenario, props) {
	trace.length = 0
	const vm = instance(props)
	await scenario(vm)
	const during = structuredClone(state(vm))
	const traceBefore = structuredClone(trace)
	await vi.advanceTimersByTimeAsync(5000)
	return {
		trace: traceBefore,
		during,
		traceAfterTimers: structuredClone(trace.slice(traceBefore.length)),
		after: structuredClone(state(vm)),
	}
}

/**
 * Every scenario.
 *
 * @return {object} Scenario name to [scenario, props, failure].
 */
function scenarios() {
	const out = {
		'initial data': [
			() => {},
			{ show: true, organisation: null, mode: 'create' },
		],
		'reset after edits': [
			(vm) => {
				vm.formData.name = 'changed'
				vm.selectedType = { value: 'x' }
				vm.loading = true
				vm.success = true
				vm.successMessage = 'm'
				vm.countdown = 1
				vm.countdownInterval = setInterval(() => trace.push(['tick']), 100)
				vm.resetForm()
			},
			{ show: true, organisation: null, mode: 'create' },
		],
		'load without organisation': [
			(vm) => vm.loadOrganisationData(),
			{ show: true, organisation: null, mode: 'edit' },
		],
		'save invalid form': [
			(vm) => vm.saveOrganisation(),
			{ show: true, organisation: null, mode: 'create' },
		],
	}
	for (const [orgName, organisation] of Object.entries(ORGANISATIONS)) {
		for (const mode of ['create', 'edit', 'copy']) {
			const props = { show: true, organisation, mode }
			out[`load | ${orgName} | ${mode}`] = [
				(vm) => vm.loadOrganisationData(),
				props,
			]
			out[`save unchanged | ${orgName} | ${mode}`] = [
				async (vm) => {
					vm.loadOrganisationData()
					vm.formData.name = vm.formData.name || 'Naam'
					await vm.saveOrganisation()
				},
				props,
			]
			out[`save changed | ${orgName} | ${mode}`] = [
				async (vm) => {
					vm.loadOrganisationData()
					vm.formData.name = 'Nieuwe naam'
					vm.formData.participants = ['p1', 'p2']
					await vm.saveOrganisation()
				},
				props,
			]
			out[`save fails | ${orgName} | ${mode}`] = [
				async (vm) => {
					vm.loadOrganisationData()
					vm.formData.name = 'Nieuwe naam'
					await vm.saveOrganisation()
				},
				props,
				'server down',
			]
		}
	}
	return out
}

describe('organisation modal golden', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		vi.spyOn(console, 'info').mockImplementation((...args) =>
			trace.push(['console.info', ...structuredClone(args)]),
		)
		vi.spyOn(console, 'error').mockImplementation((...args) =>
			trace.push([
				'console.error',
				...args.map((arg) => (arg instanceof Error ? arg.message : arg)),
			]),
		)
	})

	afterEach(() => {
		vi.useRealTimers()
		vi.restoreAllMocks()
		failures.save = null
	})

	it('records the form, the store calls, the messages and the events of every scenario', async () => {
		const out = {}
		for (const [name, [scenario, props, failure]] of Object.entries(
			scenarios(),
		)) {
			failures.save = failure ?? null
			out[name] = await record(scenario, props)
		}
		if (process.env.RECORD_GOLDEN === '1') {
			writeFileSync(GOLDEN, JSON.stringify(out, null, '\t') + '\n')
		}
		expect(out).toEqual(JSON.parse(readFileSync(GOLDEN, 'utf8')))
	})
})
