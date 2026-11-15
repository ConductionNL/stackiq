// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the ArchiMate import and the two exports of the settings
 * section: the request made, the upload fields, the file download (name,
 * object URL, click), the notifications and console lines, the progress
 * polling, and the component state afterwards, for success, cancellation and
 * every failure path. Recorded with RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/fe-settings-ui/spec.md
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import ArchiMateImportExport from '../../src/views/settings/sections/ArchiMateImportExport.vue'

const trace = []

vi.mock('../../src/store/store.js', () => ({ settingsStore: {} }))
vi.mock('../../src/utils/heartbeat.js', () => ({
	withHeartbeat: async (fn, interval) => {
		trace.push(['withHeartbeat', interval])
		return fn()
	},
}))
vi.mock('../../src/utils/archiMateImportProgress.js', () => ({
	cancelImport: async () => {},
	makeOperationId: () => 'op-1',
	progressView: () => null,
	startProgressPolling: ({ operationId, onProgress }) => {
		trace.push(['startProgressPolling', operationId])
		onProgress({ processed: 1 })
		return () => trace.push(['stopProgressPolling'])
	},
}))

const GOLDEN = path.resolve(import.meta.dirname, 'golden/archiMateImportExport.json')

const metrics = { objects_processed: 12, total_time_seconds: 75 }

const cases = {
	'import without a file': {
		method: 'importArchiMateFile',
		vm: { selectedFile: null },
		answer: { ok: true, json: { success: true } },
	},
	'import succeeds': {
		method: 'importArchiMateFile',
		vm: { selectedFile: 'file' },
		answer: { ok: true, json: { success: true, performance_metrics: metrics } },
	},
	'import is cancelled': {
		method: 'importArchiMateFile',
		vm: { selectedFile: 'file' },
		answer: { ok: true, json: { cancelled: true, saved: 3 } },
	},
	'import fails with an error': {
		method: 'importArchiMateFile',
		vm: { selectedFile: 'file' },
		answer: { ok: false, json: { success: false, error: 'bad', message: 'm' } },
	},
	'import fails with a message only': {
		method: 'importArchiMateFile',
		vm: { selectedFile: 'file' },
		answer: { ok: true, json: { success: false, message: 'only message' } },
	},
	'import fails without text': {
		method: 'importArchiMateFile',
		vm: { selectedFile: 'file' },
		answer: { ok: true, json: {} },
	},
	'import succeeds without metrics': {
		method: 'importArchiMateFile',
		vm: { selectedFile: 'file' },
		answer: { ok: true, json: { success: true } },
	},
	'import network failure': {
		method: 'importArchiMateFile',
		vm: { selectedFile: 'file' },
		answer: 'reject',
	},
	'export with a file name': {
		method: 'exportArchiMateFile',
		vm: { selectedOrganization: { value: 'o1' } },
		answer: { ok: true, disposition: 'attachment; filename="model.xml"' },
	},
	'export without a header, generic': {
		method: 'exportArchiMateFile',
		vm: { selectedOrganization: null },
		answer: { ok: true, disposition: null },
	},
	'export header without a file name': {
		method: 'exportArchiMateFile',
		vm: { selectedOrganization: { value: null } },
		answer: { ok: true, disposition: 'inline' },
	},
	'export fails with a message': {
		method: 'exportArchiMateFile',
		vm: { selectedOrganization: null },
		answer: { ok: false, json: { message: 'no access' } },
	},
	'export fails without a message': {
		method: 'exportArchiMateFile',
		vm: { selectedOrganization: null },
		answer: { ok: false, json: {} },
	},
	'export network failure': {
		method: 'exportArchiMateFile',
		vm: { selectedOrganization: null },
		answer: 'reject',
	},
	'organisation export without an organisation': {
		method: 'exportOrgArchiMateFile',
		vm: { selectedOrganization: null },
		answer: { ok: true, disposition: null },
	},
	'organisation export with an option': {
		method: 'exportOrgArchiMateFile',
		vm: {
			selectedOrganization: { value: 'org 1/a' },
			includeDeelnames: true,
			organizationOptions: [{ label: 'Org One', value: 'org 1/a' }],
		},
		answer: { ok: true, disposition: 'attachment; filename=org.xml' },
	},
	'organisation export with a bare id': {
		method: 'exportOrgArchiMateFile',
		vm: { selectedOrganization: 'plain-id', includeModules: false },
		answer: { ok: true, disposition: null },
	},
	'organisation export fails with a message': {
		method: 'exportOrgArchiMateFile',
		vm: { selectedOrganization: 'plain-id' },
		answer: { ok: false, json: { message: 'nope' } },
	},
	'organisation export fails without a message': {
		method: 'exportOrgArchiMateFile',
		vm: { selectedOrganization: 'plain-id' },
		answer: { ok: false, json: {} },
	},
}

/**
 * A fetch double answering every call the same way and recording it.
 *
 * @param {object|string} answer The scripted answer, or 'reject'.
 * @return {Function} The fetch double.
 */
function fakeFetch(answer) {
	return async (url, options) => {
		const body =
			options?.body instanceof FormData
				? [...options.body.entries()].map(([key, value]) => [
						key,
						value instanceof Blob ? `(file ${value.name})` : value,
					])
				: options?.body
		trace.push(['fetch', url, { ...options, body }])
		if (answer === 'reject') {
			throw new TypeError('Failed to fetch')
		}
		return {
			ok: answer.ok,
			headers: {
				get: (name) => {
					trace.push(['headers.get', name])
					return answer.disposition ?? null
				},
			},
			blob: async () => {
				trace.push(['blob'])
				return 'blob-data'
			},
			json: async () => {
				trace.push(['json'])
				return structuredClone(answer.json)
			},
		}
	}
}

/**
 * The component's data and methods bound to a plain object.
 *
 * @param {object} overrides Data values for the case.
 * @return {object} The fake instance.
 */
function instance(overrides) {
	const vm = { ...ArchiMateImportExport.data(), store: {} }
	Object.assign(vm, structuredClone(overrides))
	if (vm.selectedFile === 'file') {
		vm.selectedFile = new File(['<model/>'], 'model.xml')
	}
	for (const [name, fn] of Object.entries(ArchiMateImportExport.methods)) {
		vm[name] = fn.bind(vm)
	}
	return vm
}

/**
 * Run one case.
 *
 * @param {object} input The case.
 * @return {Promise<object>} What happened.
 */
async function run(input) {
	trace.length = 0
	globalThis.fetch = fakeFetch(input.answer)
	const vm = instance(input.vm)
	let outcome = 'resolved'
	try {
		await vm[input.method]()
	} catch (error) {
		outcome = `rejected: ${error.message}`
	}
	const state = Object.fromEntries(
		Object.entries(vm).filter(
			([key, value]) => typeof value !== 'function' && key !== 'store',
		),
	)
	state.selectedFile = state.selectedFile ? '(file)' : state.selectedFile
	state.bodyChildren = document.body.children.length
	return JSON.parse(
		JSON.stringify({ outcome, trace: [...trace], state }, (key, value) =>
			value === undefined ? '(undefined)' : value,
		),
	)
}

describe('ArchiMate import and export', () => {
	const recorded = {}

	beforeEach(() => {
		globalThis.OC = {
			requestToken: 'token-1',
			Notification: {
				showTemporary: (...args) => trace.push(['notify', ...args]),
			},
		}
		window.URL.createObjectURL = (blob) => {
			trace.push(['createObjectURL', blob])
			return 'blob:1'
		}
		window.URL.revokeObjectURL = (url) => trace.push(['revokeObjectURL', url])
		vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(
			function () {
				trace.push([
					'click',
					this.href,
					this.download,
					document.body.contains(this),
				])
			},
		)
		for (const level of ['error', 'warn', 'info', 'debug']) {
			vi.spyOn(console, level).mockImplementation((...args) =>
				trace.push([`console.${level}`, ...args.map(String)]),
			)
		}
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	for (const [name, input] of Object.entries(cases)) {
		it(name, async () => {
			recorded[name] = await run(input)
			if (process.env.RECORD_GOLDEN) {
				writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
				return
			}
			const expected = JSON.parse(readFileSync(GOLDEN, 'utf8'))
			expect(recorded[name]).toEqual(expected[name])
		})
	}
})
