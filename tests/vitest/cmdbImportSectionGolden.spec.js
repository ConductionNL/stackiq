// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for three steps of the CMDB import section: the sentence that
 * says an import finished, loading the municipalities to choose from, and
 * starting an import (the guards, the request, the progress polling, the
 * report or the error, and the state afterwards). Recorded with RECORD_GOLDEN=1.
 *
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-the-admin-settings-shall-offer-a-cmdb-import-section-req-cmdb-014
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'

const trace = []
const answers = { get: [], post: null }

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: async (url, config) => {
			trace.push(['axios.get', url, config ?? null])
			const answer = answers.get.shift()
			if (answer instanceof Error) throw answer
			return answer
		},
		post: async (url, form) => {
			trace.push(['axios.post', url, form])
			if (answers.post instanceof Error) throw answers.post
			return answers.post
		},
	},
}))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDataTable: {}, CnStatusBadge: {} }))
vi.mock('../../src/views/settings/sections/CmdbImportMapping.vue', () => ({ default: {} }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params) =>
		params ? `${url} ${JSON.stringify(params)}` : url,
}))
vi.mock('../../src/utils/archiMateImportProgress.js', () => ({
	startProgressPolling: ({ operationId }) => {
		trace.push(['startProgressPolling', operationId])
		return () => trace.push(['stop polling fn'])
	},
}))
vi.mock('../../src/utils/cmdbImport.js', async (importOriginal) => ({
	...(await importOriginal()),
	makeCmdbOperationId: () => 'cmdb-op-1',
	buildImportForm: (fields) => ({ form: { ...fields, file: fields.file?.name } }),
}))

const { default: CmdbImport } =
	await import('../../src/views/settings/sections/CmdbImport.vue')

const GOLDEN = path.resolve(import.meta.dirname, 'golden/cmdbImportSection.json')

/**
 * The section's methods and computed values bound to a plain object.
 *
 * @param {object} state The state the step reads.
 * @return {object} The fake instance.
 */
function instance(state) {
	const vm = { ...state }
	for (const [name, getter] of Object.entries(CmdbImport.computed)) {
		if (!(name in vm)) {
			Object.defineProperty(vm, name, {
				get: getter.bind(vm),
				configurable: true,
			})
		}
	}
	for (const [name, fn] of Object.entries(CmdbImport.methods)) {
		vm[name] = fn.bind(vm)
	}
	vm.showReport = (report) => trace.push(['showReport', report])
	vm.recoverInterruptedImport = async (normalised) =>
		trace.push(['recoverInterruptedImport', normalised])
	vm.stopPolling = () => trace.push(['stopPolling'])
	return vm
}

const STATE_KEYS = [
	'importing',
	'cancelling',
	'cancelStatus',
	'error',
	'report',
	'progress',
	'operationId',
	'municipalityOptions',
	'municipalityLoadError',
	'loadingMunicipalities',
]

/**
 * The state the steps change.
 *
 * @param {object} vm The fake instance.
 * @return {object} The state.
 */
function snapshot(vm) {
	return Object.fromEntries(
		STATE_KEYS.map((key) => [key, vm[key] === undefined ? '(unset)' : vm[key]]),
	)
}

const xlsx = {
	name: 'export.xlsx',
	size: 2048,
	type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
}

const REPORTS = {
	none: null,
	cancelled: {
		cancelled: true,
		municipality: { name: 'Delft' },
		summary: { rowsRead: 4 },
	},
	'municipality created': {
		municipality: { name: 'Delft', created: true },
		summary: { rowsRead: '9', created: 2, updated: 3, unchanged: 4 },
	},
	plain: {
		municipality: { name: 'Delft' },
		summary: { rowsRead: 9, created: 'x', updated: 3, unchanged: null },
	},
	'without municipality': { summary: {} },
}

const START = {
	'cannot import': [{ canImport: false }, null],
	'not a spreadsheet': [
		{
			canImport: true,
			selectedFile: { name: 'a.csv', size: 10, type: 'text/csv' },
			report: { old: true },
		},
		null,
	],
	'new municipality, succeeds': [
		{
			canImport: true,
			selectedFile: xlsx,
			municipality: { isNew: true, id: 'tmp', name: '', label: 'Nieuwdorp' },
			updateExisting: false,
			publish: true,
			missingRecords: 'keep',
		},
		{ data: { summary: { rowsRead: 1 } } },
	],
	'existing municipality, fails': [
		{
			canImport: true,
			selectedFile: xlsx,
			municipality: { isNew: false, id: 'm-1', name: 'Delft' },
			updateExisting: true,
			publish: false,
			missingRecords: 'archive',
		},
		Object.assign(new Error('boom'), {
			response: {
				status: 422,
				data: { error: 'MUNICIPALITY_REQUIRED', message: 'm' },
			},
		}),
	],
	'interrupted request': [
		{
			canImport: true,
			selectedFile: xlsx,
			municipality: { isNew: false, id: 'm-1', name: 'Delft' },
			updateExisting: true,
			publish: true,
			missingRecords: 'archive',
		},
		new Error('Network Error'),
	],
}

const MUNICIPALITIES = {
	'not configured': [{ data: { config: { register: 'r' } } }],
	listed: [
		{ data: { config: { register: 'r', organisatie_schema: 's' } } },
		{
			data: {
				results: [{ id: 'm-1', '@self': { name: 'Delft' }, name: 'Delft' }],
			},
		},
	],
	'no results key': [
		{ data: { config: { register: 'r', organisatie_schema: 's' } } },
		{ data: {} },
	],
	'config fails': [new Error('down')],
	'list fails': [
		{ data: { config: { register: 'r', organisatie_schema: 's' } } },
		new Error('down'),
	],
}

describe('CMDB import section golden', () => {
	it('says, loads and starts the same', async () => {
		const out = { finished: {}, municipalities: {}, start: {} }
		for (const [name, report] of Object.entries(REPORTS)) {
			for (const applications of ['', '2 applications archived.']) {
				const vm = instance({ report, applicationChangesText: applications })
				out.finished[`${name} | applications ${applications !== ''}`] =
					CmdbImport.computed.finishedText.call(vm)
			}
		}
		for (const [name, gets] of Object.entries(MUNICIPALITIES)) {
			trace.length = 0
			answers.get = [...gets]
			const vm = instance({
				municipalityOptions: ['before'],
				municipalityLoadError: 'before',
				loadingMunicipalities: false,
			})
			await vm.loadMunicipalities()
			out.municipalities[name] = {
				trace: structuredClone(trace),
				state: structuredClone(snapshot(vm)),
			}
		}
		for (const [name, [state, post]] of Object.entries(START)) {
			trace.length = 0
			answers.post = post
			const vm = instance({
				importing: 'before',
				error: 'before',
				report: 'before',
				progress: 'before',
				...state,
			})
			await vm.startImport()
			out.start[name] = {
				trace: JSON.parse(JSON.stringify(trace)),
				state: JSON.parse(JSON.stringify(snapshot(vm))),
			}
		}
		if (process.env.RECORD_GOLDEN === '1') {
			writeFileSync(GOLDEN, JSON.stringify(out, null, '\t') + '\n')
		}
		expect(out).toEqual(JSON.parse(readFileSync(GOLDEN, 'utf8')))
	})
})
