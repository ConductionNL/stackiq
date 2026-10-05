// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

/**
 * Unit tests for the client side of the CMDB import: request building, the
 * error codes the server can send and the words for each, and following an
 * import whose request was cut off.
 *
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-the-admin-settings-shall-offer-a-cmdb-import-section-req-cmdb-014
 */

import {
	buildImportForm,
	cancelCmdbImport,
	cancelFailureText,
	checkFile,
	cmdbProgressView,
	errorText,
	followInterruptedImport,
	formatMegabytes,
	importUrl,
	interruptedImportError,
	isKnownError,
	makeCmdbOperationId,
	normaliseError,
	outcomeLabel,
	OUTCOMES,
	PROFILE_DEFAULTS,
	reportRows,
	sortReportRows,
} from './cmdbImport.js'

/** The operation ids CmdbImportController accepts. */
const SERVER_OPERATION_ID = /^cmdb-[A-Za-z0-9-]{8,64}$/

/**
 * Every code the import endpoint, the cancel endpoint and Nextcloud can
 * answer with, plus the ones the page derives itself.
 */
const SERVER_CODES = [
	'NO_FILE_UPLOADED',
	'NOT_XLSX',
	'FILE_TOO_LARGE',
	'MISSING_RECORDS_UNSUPPORTED',
	'FIELD_INVALID',
	'UPLOAD_FAILED',
	'MUNICIPALITY_REQUIRED',
	'MUNICIPALITY_INVALID',
	'NO_SOURCE_SHEET',
	'MISSING_COLUMN',
	'TOO_MANY_ROWS',
	'MAPPING_UNAVAILABLE',
	'READER_UNAVAILABLE',
	'NOT_CONFIGURED',
	'WORKBOOK_TOO_LARGE',
	'SCHEMA_OUTDATED',
	'IMPORT_IN_PROGRESS',
	'MUNICIPALITY_AMBIGUOUS',
	'OPERATION_NOT_FOUND',
	'IMPORT_INTERRUPTED',
	'NOT_SIGNED_IN',
	'NOT_ADMIN',
	'CSRF_FAILED',
	'NETWORK_ERROR',
]

const GENERIC_TITLE = errorText({ error: 'SOMETHING_ELSE' }).title

/**
 * An axios error with a response.
 *
 * @param {number} status The HTTP status
 * @param {*} data The response body
 * @return {object} The error
 */
function httpError(status, data = '') {
	return { response: { status, data } }
}

describe('makeCmdbOperationId', () => {
	it('makes an id the server accepts, different each time', () => {
		const a = makeCmdbOperationId()
		const b = makeCmdbOperationId()
		expect(a).toMatch(SERVER_OPERATION_ID)
		expect(b).toMatch(SERVER_OPERATION_ID)
		expect(a).not.toBe(b)
	})
})

describe('checkFile', () => {
	it('asks for a file when none is chosen', () => {
		expect(checkFile(null)).toEqual({ error: 'NO_FILE_UPLOADED', details: {} })
	})

	it('refuses a file that is not named .xlsx', () => {
		expect(checkFile({ name: 'export.csv', size: 10 }).error).toBe('NOT_XLSX')
		expect(checkFile({ name: 'export.xlsm', size: 10 }).error).toBe('NOT_XLSX')
	})

	it('leaves the size limit to the server, which may allow more than the default', () => {
		expect(
			checkFile({
				name: 'Export.XLSX',
				size: PROFILE_DEFAULTS.maxFileBytes * 3,
			}),
		).toBeNull()
	})
})

describe('buildImportForm', () => {
	const file = new File(['x'], 'export.xlsx')

	it('sends an existing municipality by uuid', () => {
		const form = buildImportForm({
			file,
			municipality: { uuid: 'uuid-1', name: 'Tilburg' },
			updateExisting: true,
			operationId: 'cmdb-abcdefgh',
		})
		expect(form.get('cmdbFile')).toBeInstanceOf(File)
		expect(form.get('municipalityUuid')).toBe('uuid-1')
		expect(form.has('municipalityName')).toBe(false)
		expect(form.get('updateExisting')).toBe('true')
		expect(form.get('missingRecords')).toBe('keep')
		expect(form.get('operationId')).toBe('cmdb-abcdefgh')
	})

	it('sends a typed new municipality by name, and updateExisting as an explicit false', () => {
		const form = buildImportForm({
			file,
			municipality: { uuid: null, name: 'Berkel & Rodenrijs' },
			updateExisting: false,
			operationId: 'cmdb-abcdefgh',
		})
		expect(form.has('municipalityUuid')).toBe(false)
		expect(form.get('municipalityName')).toBe('Berkel & Rodenrijs')
		expect(form.get('updateExisting')).toBe('false')
	})

	it('publishes what the import creates unless told not to', () => {
		const options = {
			file,
			municipality: { uuid: 'uuid-1', name: 'Tilburg' },
			updateExisting: true,
			operationId: 'cmdb-abcdefgh',
		}
		expect(buildImportForm(options).get('publish')).toBe('true')
		expect(buildImportForm({ ...options, publish: true }).get('publish')).toBe(
			'true',
		)
		expect(buildImportForm({ ...options, publish: false }).get('publish')).toBe(
			'false',
		)
	})
})

describe('the endpoints', () => {
	it('posts the import to the CMDB import route', () => {
		expect(importUrl()).toContain('/apps/stackiq/api/cmdb-import')
	})

	it('posts a cancel to the route of the operation', async () => {
		const post = jest.fn().mockResolvedValue({ data: { cancelled: true } })
		await expect(
			cancelCmdbImport({ operationId: 'cmdb-abcdefgh', http: { post } }),
		).resolves.toEqual({ cancelled: true })
		expect(post.mock.calls[0][0]).toContain(
			'/apps/stackiq/api/cmdb-import/cmdb-abcdefgh/cancel',
		)
	})
})

describe('normaliseError', () => {
	it('keeps the code, message and details the server sent', () => {
		const error = normaliseError(
			httpError(422, {
				error: 'MISSING_COLUMN',
				message: 'Sheet "X" has no column "APPID".',
				details: { sheet: 'X', column: 'APPID' },
			}),
		)
		expect(error).toEqual({
			error: 'MISSING_COLUMN',
			message: 'Sheet "X" has no column "APPID".',
			details: { sheet: 'X', column: 'APPID' },
			status: 422,
			interrupted: false,
		})
	})

	it.each([
		[401, 'NOT_SIGNED_IN'],
		[403, 'NOT_ADMIN'],
		[412, 'CSRF_FAILED'],
		[413, 'FILE_TOO_LARGE'],
		[500, 'IMPORT_FAILED'],
	])('gives a %i without a CMDB code the code %s', (status, code) => {
		const error = normaliseError(httpError(status, '<html>Error</html>'))
		expect(error.error).toBe(code)
		expect(error.details).toEqual({})
		expect(error.interrupted).toBe(false)
	})

	it('marks a request that got no answer at all as interrupted', () => {
		const error = normaliseError({ message: 'Network Error' })
		expect(error.error).toBe('NETWORK_ERROR')
		expect(error.status).toBe(0)
		expect(error.interrupted).toBe(true)
	})

	it.each([502, 503, 504])(
		'marks a %i from a gateway, without a CMDB code, as interrupted',
		(status) => {
			expect(
				normaliseError(httpError(status, '<html>Gateway</html>'))
					.interrupted,
			).toBe(true)
		},
	)

	it("never counts the import's own 503 answer as interrupted", () => {
		const error = normaliseError(
			httpError(503, { error: 'MAPPING_UNAVAILABLE', message: '' }),
		)
		expect(error.error).toBe('MAPPING_UNAVAILABLE')
		expect(error.interrupted).toBe(false)
	})
})

describe('errorText', () => {
	it.each(SERVER_CODES)('has its own words for %s', (code) => {
		expect(isKnownError(code)).toBe(true)
		const words = errorText({ error: code, details: {} })
		expect(words.title).not.toBe('')
		expect(words.title).not.toBe(GENERIC_TITLE)
	})

	it('falls back to the generic text, and shows the server message, for an unknown code', () => {
		expect(isKnownError('IMPORT_FAILED')).toBe(false)
		expect(isKnownError('SOMETHING_NEW')).toBe(false)
		expect(errorText({ error: 'IMPORT_FAILED' }).title).toBe(GENERIC_TITLE)
	})

	it('names the field and the values it accepts', () => {
		const words = errorText({
			error: 'FIELD_INVALID',
			details: { field: 'updateExisting', accepted: ['true', 'false'] },
		})
		expect(words.title).toContain('"updateExisting"')
		expect(words.hint).toContain('Accepted values: true, false.')
		expect(
			errorText({
				error: 'FIELD_INVALID',
				details: { field: 'municipalityUuid' },
			}).hint,
		).toBe('Reload the page and try again.')
	})

	it('names the upload limit the server applied', () => {
		expect(
			errorText({
				error: 'FILE_TOO_LARGE',
				details: { maxBytes: 20 * 1024 * 1024 },
			}).title,
		).toContain('20 MB')
		expect(errorText({ error: 'FILE_TOO_LARGE', details: {} }).title).toBe(
			'The file is larger than the server accepts.',
		)
	})

	it('names the row limit the server applied', () => {
		const words = errorText({
			error: 'TOO_MANY_ROWS',
			details: { sheet: 'Beheerde Applicaties CMDB', limit: 2500 },
		})
		expect(words.title).toContain('Beheerde Applicaties CMDB')
		expect(words.hint).toMatch(/at most 2[,.]?500 rows/)
		expect(errorText({ error: 'TOO_MANY_ROWS', details: {} }).hint).not.toMatch(
			/\d/,
		)
	})

	it('names the sheets the server expected, and the defaults without them', () => {
		expect(
			errorText({
				error: 'NO_SOURCE_SHEET',
				details: { expected: ['Blad A', 'Blad B'] },
			}).hint,
		).toContain('"Blad A" or "Blad B"')
		expect(errorText({ error: 'NO_SOURCE_SHEET', details: {} }).hint).toContain(
			PROFILE_DEFAULTS.sheets[1],
		)
	})

	it('names the unpacked size limit the server applied', () => {
		expect(
			errorText({
				error: 'WORKBOOK_TOO_LARGE',
				details: { maxUncompressedBytes: 100 * 1024 * 1024 },
			}).title,
		).toContain('100 MB')
		expect(errorText({ error: 'WORKBOOK_TOO_LARGE', details: {} }).title).toBe(
			'The workbook is too large to read once unpacked.',
		)
	})

	it('names the part limit and the part, or the shared-strings limit, the server applied', () => {
		expect(
			errorText({
				error: 'WORKBOOK_TOO_LARGE',
				details: { maxPartBytes: 10 * 1024 * 1024, part: 'xl/sharedStrings.xml' },
			}).title,
		).toBe(
			'Unpacked, the part xl/sharedStrings.xml of the workbook is larger than 10 MB, the most the import reads of one part.',
		)
		expect(
			errorText({
				error: 'WORKBOOK_TOO_LARGE',
				details: { maxSharedStrings: 200000 },
			}).title,
		).toBe(
			'The workbook holds more than 200000 different texts, the most the import reads.',
		)
	})

	it('names the outdated schema and points to Force Update', () => {
		const words = errorText({
			error: 'SCHEMA_OUTDATED',
			details: { schema: 'module', missing: ['externalKey'] },
		})
		expect(words.title).toBe(
			'The "module" schema of the stackiq register is out of date.',
		)
		expect(words.hint).toContain('Force Update')
		expect(errorText({ error: 'SCHEMA_OUTDATED', details: {} }).title).toBe(
			'The stackiq register is out of date.',
		)
	})

	it('asks to wait for the import that is running', () => {
		expect(errorText({ error: 'IMPORT_IN_PROGRESS', details: {} }).hint).toContain(
			'Only one import runs at a time.',
		)
	})

	it('counts the municipalities with the typed name and asks to pick one', () => {
		const words = errorText({
			error: 'MUNICIPALITY_AMBIGUOUS',
			details: { matches: ['uuid-1', 'uuid-2', 'uuid-3'] },
		})
		expect(words.title).toBe('3 municipalities have this name.')
		expect(words.hint).toContain('from the list')
		expect(
			errorText({ error: 'MUNICIPALITY_AMBIGUOUS', details: {} }).title,
		).toBe('Several municipalities have this name.')
	})

	it('puts names from the details in as they are, for Vue to escape once', () => {
		const words = errorText({
			error: 'MISSING_COLUMN',
			details: { sheet: 'Apps & Co <b>', column: 'APPID' },
		})
		expect(words.title).toBe('The sheet "Apps & Co <b>" has no column "APPID".')
	})
})

describe('formatMegabytes', () => {
	it('shows whole and fractional megabytes', () => {
		expect(formatMegabytes(10 * 1024 * 1024)).toBe('10 MB')
		expect(formatMegabytes(12.5 * 1024 * 1024)).toBe('12.5 MB')
	})
})

describe('cancelFailureText', () => {
	it('explains a cancel the server cannot match to a running import', () => {
		const error = normaliseError(
			httpError(404, { error: 'OPERATION_NOT_FOUND', message: '' }),
		)
		expect(cancelFailureText(error)).toContain(
			'The import cannot be cancelled now',
		)
	})

	it('names the reason for any other refusal', () => {
		expect(cancelFailureText(normaliseError(httpError(403, '')))).toBe(
			'The import could not be cancelled: Only Nextcloud administrators can import a CMDB export.',
		)
	})
})

describe('followInterruptedImport', () => {
	const report = { summary: { rowsRead: 2 }, rows: [], cancelled: false }

	it('returns the report a completed operation kept', async () => {
		const get = jest.fn().mockResolvedValue({
			data: { progress: { status: 'completed', statistics: { report } } },
		})
		const outcome = await followInterruptedImport({
			operationId: 'cmdb-abcdefgh',
			http: { get },
		})
		expect(outcome).toEqual({ state: 'finished', report })
		expect(get.mock.calls[0][0]).toContain(
			'/apps/stackiq/api/progress/cmdb-abcdefgh',
		)
	})

	it('waits while the operation runs, then returns its report', async () => {
		const get = jest
			.fn()
			.mockResolvedValueOnce({
				data: { progress: { status: 'running', processed_items: 1 } },
			})
			.mockResolvedValueOnce({
				data: { progress: { status: 'cancelled', statistics: { report } } },
			})
		const wait = jest.fn().mockResolvedValue()
		const seen = []
		const outcome = await followInterruptedImport({
			operationId: 'cmdb-abcdefgh',
			http: { get },
			wait,
			onProgress: (progress) => seen.push(progress.status),
		})
		expect(outcome.state).toBe('finished')
		expect(seen).toEqual(['running', 'cancelled'])
		expect(wait).toHaveBeenCalledWith(2000)
	})

	it('reports a failed operation as failed', async () => {
		const get = jest
			.fn()
			.mockResolvedValue({ data: { progress: { status: 'failed' } } })
		await expect(
			followInterruptedImport({ operationId: 'cmdb-abcdefgh', http: { get } }),
		).resolves.toEqual({ state: 'failed' })
	})

	it('does not know the outcome when the operation cannot be found', async () => {
		const get = jest
			.fn()
			.mockRejectedValue(httpError(404, { error: 'OPERATION_NOT_FOUND' }))
		await expect(
			followInterruptedImport({ operationId: 'cmdb-abcdefgh', http: { get } }),
		).resolves.toEqual({ state: 'unknown' })
	})

	it('says when the progress cannot be read either', async () => {
		const get = jest.fn().mockRejectedValue({ message: 'Network Error' })
		await expect(
			followInterruptedImport({ operationId: 'cmdb-abcdefgh', http: { get } }),
		).resolves.toEqual({ state: 'unreachable' })
	})

	it('stops waiting for a running import after the timeout', async () => {
		const get = jest
			.fn()
			.mockResolvedValue({ data: { progress: { status: 'running' } } })
		const outcome = await followInterruptedImport({
			operationId: 'cmdb-abcdefgh',
			http: { get },
			wait: () => Promise.resolve(),
			intervalMs: 10,
			timeoutMs: 30,
		})
		expect(outcome).toEqual({ state: 'unknown' })
		expect(get).toHaveBeenCalledTimes(4)
	})

	it('stops waiting when the page is left', async () => {
		const get = jest
			.fn()
			.mockResolvedValue({ data: { progress: { status: 'running' } } })
		const outcome = await followInterruptedImport({
			operationId: 'cmdb-abcdefgh',
			http: { get },
			shouldStop: () => true,
		})
		expect(outcome).toEqual({ state: 'unknown' })
		expect(get).toHaveBeenCalledTimes(1)
	})
})

describe('interruptedImportError', () => {
	const gateway = { error: 'IMPORT_FAILED', status: 504 }
	const offline = { error: 'NETWORK_ERROR', status: 0 }

	it('shows the report, not an error, when it was found', () => {
		expect(interruptedImportError({ state: 'finished' }, gateway)).toBeNull()
	})

	it('says the import may still be running when its outcome is unknown', () => {
		expect(interruptedImportError({ state: 'unknown' }, gateway).error).toBe(
			'IMPORT_INTERRUPTED',
		)
		expect(interruptedImportError({ state: 'unknown' }, offline).error).toBe(
			'IMPORT_INTERRUPTED',
		)
		expect(interruptedImportError({ state: 'unreachable' }, gateway).error).toBe(
			'IMPORT_INTERRUPTED',
		)
	})

	it('keeps "server not reached" when nothing reached the server', () => {
		expect(interruptedImportError({ state: 'unreachable' }, offline).error).toBe(
			'NETWORK_ERROR',
		)
	})

	it('reports a failed operation as a failed import', () => {
		expect(interruptedImportError({ state: 'failed' }, gateway).error).toBe(
			'IMPORT_FAILED',
		)
	})
})

describe('cmdbProgressView', () => {
	it('computes the percentage from the row counts', () => {
		expect(
			cmdbProgressView({ processed_items: 1, total_items: 4, percentage: 90 }),
		).toEqual({ percentage: 25, detail: '1 of 4 rows processed' })
	})

	it('shows nothing before any progress', () => {
		expect(cmdbProgressView(null)).toBeNull()
	})
})

describe('the report table', () => {
	it('has words for every outcome', () => {
		for (const outcome of OUTCOMES) {
			expect(outcomeLabel(outcome)).not.toBe(outcome)
		}
	})

	it('turns report rows into table rows with their reasons and warnings', () => {
		expect(
			reportRows([
				{
					sheet: 'Beheerde Applicaties CMDB',
					row: 2,
					appId: 2,
					name: 'Mailen',
					outcome: 'skipped',
					reasons: ['exists'],
					warnings: ['Column "Vendor": empty'],
					moduleUuid: 'm-1',
				},
			]),
		).toEqual([
			{
				key: 'Beheerde Applicaties CMDB:2:0',
				sheet: 'Beheerde Applicaties CMDB',
				row: 2,
				appId: '2',
				name: 'Mailen',
				outcome: 'skipped',
				notes: 'exists; Column "Vendor": empty',
				moduleUuid: 'm-1',
			},
		])
		expect(reportRows(null)).toEqual([])
	})

	it('sorts numbers by value and keeps the report order for ties', () => {
		const rows = [
			{ key: 'a', row: 10, sheet: 'B' },
			{ key: 'b', row: 9, sheet: 'A' },
			{ key: 'c', row: 100, sheet: 'B' },
		]
		expect(sortReportRows(rows, 'row', 'asc').map((r) => r.key)).toEqual([
			'b',
			'a',
			'c',
		])
		expect(sortReportRows(rows, 'row', 'desc').map((r) => r.key)).toEqual([
			'c',
			'a',
			'b',
		])
		expect(sortReportRows(rows, 'sheet', 'asc').map((r) => r.key)).toEqual([
			'b',
			'a',
			'c',
		])
		expect(sortReportRows(rows, null, null)).toBe(rows)
	})
})
