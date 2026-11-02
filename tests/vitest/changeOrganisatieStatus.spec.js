// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for ChangeOrganisatieStatusDialog's changeStatus: which
 * organisation is patched with which status, the transfer data written on
 * activation, the log lines, the auto-close timer, the error texts and the
 * dialog state afterwards. Recorded with RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/fe-organizations/spec.md
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const trace = []
const store = vi.hoisted(() => ({
	navigationStore: { dialogProperties: null },
	objectStore: {},
}))

vi.mock('../../src/store/store.js', () => store)

const { default: ChangeOrganisatieStatusDialog } =
	await import('../../src/modals/object/ChangeOrganisatieStatusDialog.vue')

const GOLDEN = path.resolve(
	import.meta.dirname,
	'golden/changeOrganisatieStatus.json',
)

const organisation = {
	id: 'org-1',
	name: 'Gemeente Zuiddrecht',
	status: 'Draft',
	'@self': { name: 'self name' },
}

const cases = {
	activate: { active: organisation, properties: { newStatus: 'Active' } },
	deactivate: { active: organisation, properties: { newStatus: 'Inactive' } },
	'activate, name only on @self': {
		active: { id: 'org-2', '@self': { name: 'Self only' } },
		properties: { newStatus: 'Active' },
	},
	'activate, no name at all': {
		active: { id: 'org-3' },
		properties: { newStatus: 'Active' },
	},
	'activate, lowercase status is not activation': {
		active: organisation,
		properties: { newStatus: 'active' },
	},
	'no active organisation': { active: null, properties: { newStatus: 'Active' } },
	'organisation without id': {
		active: { name: 'No id' },
		properties: { newStatus: 'Active' },
	},
	'organisation with empty id': {
		active: { id: '', name: 'Empty id' },
		properties: { newStatus: 'Active' },
	},
	'no new status': { active: organisation, properties: {} },
	'empty new status': { active: organisation, properties: { newStatus: '' } },
	'no dialog properties': { active: organisation, properties: null },
	'patch fails with a message': {
		active: organisation,
		properties: { newStatus: 'Active' },
		patch: 'Forbidden',
	},
	'patch fails without a message': {
		active: organisation,
		properties: { newStatus: 'Inactive' },
		patch: '',
	},
	'earlier error is cleared': {
		active: organisation,
		properties: { newStatus: 'Inactive' },
		earlierError: 'earlier',
	},
}

/**
 * Run changeStatus for one case against a fake component.
 *
 * @param {object} input The case.
 * @return {Promise<object>} The trace and the dialog state afterwards.
 */
async function run(input) {
	trace.length = 0
	store.navigationStore.dialogProperties = structuredClone(input.properties)
	store.navigationStore.setTransferData = (data) =>
		trace.push(['navigationStore.setTransferData', data])
	store.objectStore.getActiveObject = (type) => {
		trace.push(['objectStore.getActiveObject', type])
		return structuredClone(input.active)
	}
	store.objectStore.patchObject = async (...args) => {
		trace.push(['objectStore.patchObject', ...args])
		if (input.patch !== undefined) {
			throw new Error(input.patch)
		}
		return { ok: true }
	}
	const self = {
		loading: false,
		success: null,
		error: input.earlierError ?? null,
		closeDialog: () => trace.push(['closeDialog']),
		t: (app, text, vars) => `${app}:${text}:${JSON.stringify(vars ?? null)}`,
	}
	for (const [name, method] of Object.entries(
		ChangeOrganisatieStatusDialog.methods,
	)) {
		self[name] ??= method.bind(self)
	}
	let outcome = 'resolved'
	const pending = ChangeOrganisatieStatusDialog.methods.changeStatus.call(self)
	trace.push(['while running', { loading: self.loading, error: self.error }])
	try {
		await pending
	} catch (error) {
		outcome = `rejected: ${error.constructor.name}`
	}
	const state = { success: self.success, loading: self.loading, error: self.error }
	trace.push(['timers pending', vi.getTimerCount()])
	vi.advanceTimersByTime(1999)
	trace.push(['after 1999 ms'])
	vi.advanceTimersByTime(1)
	return JSON.parse(
		JSON.stringify({ outcome, state, trace: [...trace] }, (key, value) =>
			value === undefined ? '(undefined)' : value,
		),
	)
}

describe('ChangeOrganisatieStatusDialog changeStatus', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		vi.spyOn(console, 'info').mockImplementation((...args) =>
			trace.push(['console.info', ...args]),
		)
		vi.spyOn(console, 'error').mockImplementation((...args) =>
			trace.push(['console.error', ...args.map(String)]),
		)
	})

	afterEach(() => {
		vi.clearAllTimers()
		vi.useRealTimers()
		vi.restoreAllMocks()
	})

	for (const [name, input] of Object.entries(cases)) {
		it(name, async () => {
			const actual = await run(input)
			if (process.env.RECORD_GOLDEN) {
				const all = { ...readGolden(), [name]: actual }
				writeFileSync(GOLDEN, JSON.stringify(all, null, '\t') + '\n')
			}
			expect(actual).toEqual(readGolden()[name])
		})
	}

	it('covers every case', () => {
		expect(Object.keys(readGolden()).sort()).toEqual(Object.keys(cases).sort())
	})
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
