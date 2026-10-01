// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

/**
 * Client side of the CMDB import: request building, the checks the page can
 * make before uploading, and the words for every error code and outcome.
 *
 * The routes, field names, report shape and error codes are fixed by
 * openspec/changes/cmdb-export-import/contract.md. The server stays the
 * authority: every check here is repeated there.
 *
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-req-cmdb-014-the-admin-settings-shall-offer-a-cmdb-import-section
 */

import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/** Largest upload the import accepts (contract: profile `maxFileBytes`). */
export const MAX_FILE_BYTES = 10 * 1024 * 1024

/** The two sheets the import reads (contract: NO_SOURCE_SHEET details). */
export const SOURCE_SHEETS = ['Onbeh Applicaties CMDB', 'Beheerde Applicaties CMDB']

/** Every row outcome the report can carry, in display order. */
export const OUTCOMES = ['created', 'updated', 'unchanged', 'skipped', 'failed']

/**
 * The words for one row outcome.
 *
 * @param {string} outcome The outcome key from the report
 * @return {string} The label
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-req-cmdb-011-each-row-shall-be-processed-in-isolation-and-reported-with-its-outcome
 */
export function outcomeLabel(outcome) {
	switch (outcome) {
		case 'created':
			return t('stackiq', 'Created')
		case 'updated':
			return t('stackiq', 'Updated')
		case 'unchanged':
			return t('stackiq', 'Unchanged')
		case 'skipped':
			return t('stackiq', 'Skipped')
		case 'failed':
			return t('stackiq', 'Failed')
		default:
			return String(outcome ?? '')
	}
}

/**
 * Make a fresh operation id, in the form the contract's example uses
 * (`cmdb-` plus a random version 4 uuid).
 *
 * `crypto.randomUUID()` exists only in a secure context, and an instance
 * served over plain http is not one, so the uuid is built from
 * `getRandomValues()`, which is available everywhere.
 *
 * @return {string} The id
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-req-cmdb-013-a-running-import-shall-report-its-progress-and-shall-stop-when-cancelled
 */
export function makeCmdbOperationId() {
	const bytes = new Uint8Array(16)
	globalThis.crypto.getRandomValues(bytes)
	bytes[6] = (bytes[6] & 0x0f) | 0x40
	bytes[8] = (bytes[8] & 0x3f) | 0x80
	const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')
	return (
		'cmdb-'
		+ hex.slice(0, 8)
		+ '-'
		+ hex.slice(8, 12)
		+ '-'
		+ hex.slice(12, 16)
		+ '-'
		+ hex.slice(16, 20)
		+ '-'
		+ hex.slice(20)
	)
}

/**
 * The check the page makes on a chosen file before it uploads it.
 *
 * Only the name and size are checked here; the content check (ZIP signature,
 * `xl/workbook.xml`) is the server's.
 *
 * @param {File|null} file The chosen file
 * @return {{error: string, details: object}|null} An error in the server's shape, or null when the file may be sent
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-req-cmdb-001-the-import-endpoint-shall-accept-only-a-bounded-xlsx-upload-from-a-nextcloud-admin
 */
export function checkFile(file) {
	if (!file) {
		return { error: 'NO_FILE_UPLOADED', details: {} }
	}
	if (!/\.xlsx$/i.test(file.name || '')) {
		return { error: 'NOT_XLSX', details: {} }
	}
	if (file.size > MAX_FILE_BYTES) {
		return { error: 'FILE_TOO_LARGE', details: {} }
	}
	return null
}

/**
 * The multipart body for `POST /api/cmdb-import`.
 *
 * @param {object} options The options
 * @param {File} options.file The export
 * @param {{uuid: string|null, name: string}} options.municipality The chosen municipality: an existing one has a uuid, a new one only a name
 * @param {boolean} options.updateExisting Whether matched rows are updated
 * @param {string} options.operationId The progress operation id
 * @return {FormData} The body
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-req-cmdb-004-every-import-shall-have-exactly-one-consuming-municipality-chosen-by-the-admin
 */
export function buildImportForm({
	file,
	municipality,
	updateExisting,
	operationId,
}) {
	const form = new FormData()
	form.append('cmdbFile', file)
	if (municipality?.uuid) {
		form.append('municipalityUuid', municipality.uuid)
	} else if (municipality?.name) {
		form.append('municipalityName', municipality.name)
	}
	form.append('updateExisting', updateExisting ? 'true' : 'false')
	form.append('missingRecords', 'keep')
	form.append('operationId', operationId)
	return form
}

/**
 * The URL of the import endpoint.
 *
 * @return {string} The URL
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-req-cmdb-001-the-import-endpoint-shall-accept-only-a-bounded-xlsx-upload-from-a-nextcloud-admin
 */
export function importUrl() {
	return generateUrl('/apps/stackiq/api/cmdb-import')
}

/**
 * Ask the server to stop a running import between two rows.
 *
 * @param {object} options The options
 * @param {string} options.operationId The operation to cancel
 * @param {object} options.http An axios-like client with post
 * @return {Promise<object>} The server's answer
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-req-cmdb-013-a-running-import-shall-report-its-progress-and-shall-stop-when-cancelled
 */
export async function cancelCmdbImport({ operationId, http }) {
	const response = await http.post(
		generateUrl('/apps/stackiq/api/cmdb-import/{operationId}/cancel', {
			operationId,
		}),
	)
	return response.data
}

/**
 * The link to a module's detail page in the app.
 *
 * The settings page is outside the app's router, so this is a plain URL.
 *
 * @param {string} uuid The module uuid
 * @return {string} The URL
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-req-cmdb-011-each-row-shall-be-processed-in-isolation-and-reported-with-its-outcome
 */
export function moduleUrl(uuid) {
	return generateUrl('/apps/stackiq/modules/{id}', { id: uuid })
}

/**
 * Turn a failed request into the server's error shape.
 *
 * Errors raised by Nextcloud itself (not signed in, not an admin, CSRF) come
 * without a CMDB error code, so they get one here from the HTTP status.
 *
 * @param {object} error The axios error
 * @return {{error: string, message: string, details: object, status: number}} The error
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-req-cmdb-001-the-import-endpoint-shall-accept-only-a-bounded-xlsx-upload-from-a-nextcloud-admin
 */
export function normaliseError(error) {
	const status = error?.response?.status ?? 0
	const body = error?.response?.data
	const fromBody =
		body && typeof body === 'object' && typeof body.error === 'string'
			? body.error
			: ''
	let code = fromBody
	if (code === '') {
		if (status === 401) {
			code = 'NOT_SIGNED_IN'
		} else if (status === 403) {
			code = 'NOT_ADMIN'
		} else if (status === 412) {
			code = 'CSRF_FAILED'
		} else if (status === 413) {
			code = 'FILE_TOO_LARGE'
		} else if (status === 0) {
			code = 'NETWORK_ERROR'
		} else {
			code = 'IMPORT_FAILED'
		}
	}
	return {
		error: code,
		message:
			body && typeof body === 'object' && typeof body.message === 'string'
				? body.message
				: '',
		details:
			body
			&& typeof body === 'object'
			&& body.details
			&& typeof body.details === 'object'
				? body.details
				: {},
		status,
	}
}

/** Every error code the page has its own words for. */
const KNOWN_ERRORS = new Set([
	'NO_FILE_UPLOADED',
	'NOT_XLSX',
	'FILE_TOO_LARGE',
	'MISSING_RECORDS_UNSUPPORTED',
	'MUNICIPALITY_REQUIRED',
	'MUNICIPALITY_INVALID',
	'NO_SOURCE_SHEET',
	'MISSING_COLUMN',
	'TOO_MANY_ROWS',
	'MAPPING_UNAVAILABLE',
	'READER_UNAVAILABLE',
	'NOT_CONFIGURED',
	'OPERATION_NOT_FOUND',
	'NOT_SIGNED_IN',
	'NOT_ADMIN',
	'CSRF_FAILED',
	'NETWORK_ERROR',
])

/**
 * Whether the page has its own words for an error code. For any other code
 * (including `IMPORT_FAILED`) the page also shows the server's message.
 *
 * @param {string} code The error code
 * @return {boolean} True for a code with its own text
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-req-cmdb-001-the-import-endpoint-shall-accept-only-a-bounded-xlsx-upload-from-a-nextcloud-admin
 */
export function isKnownError(code) {
	return KNOWN_ERRORS.has(code)
}

/**
 * What the page says for an error: a title and, where the code has one, a
 * hint on what to do. The text is the page's own, so it is translated even
 * when the server's message is not.
 *
 * @param {{error: string, message?: string, details?: object}} error The error in the server's shape
 * @return {{title: string, hint: string}} The words
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-req-cmdb-003-columns-shall-be-resolved-by-header-name-and-a-missing-required-column-shall-stop-the-import-with-422
 */
export function errorText(error) {
	const details = error?.details || {}
	switch (error?.error) {
		case 'NO_FILE_UPLOADED':
			return {
				title: t('stackiq', 'No file was uploaded.'),
				hint: t('stackiq', 'Choose the TOPdesk export and try again.'),
			}
		case 'NOT_XLSX':
			return {
				title: t('stackiq', 'This file is not an Excel workbook (.xlsx).'),
				hint: t(
					'stackiq',
					'Save the TOPdesk export as an Excel workbook (.xlsx). CSV, .xls and macro-enabled .xlsm files are not accepted.',
				),
			}
		case 'FILE_TOO_LARGE':
			return {
				title: t('stackiq', 'The file is larger than 10 MB.'),
				hint: t(
					'stackiq',
					'Remove sheets the import does not read, or split the export, and try again.',
				),
			}
		case 'MISSING_RECORDS_UNSUPPORTED':
			return {
				title: t(
					'stackiq',
					'Records missing from the export can only be kept.',
				),
				hint: '',
			}
		case 'MUNICIPALITY_REQUIRED':
			return {
				title: t('stackiq', 'Choose a municipality first.'),
				hint: t(
					'stackiq',
					'Pick an existing municipality or type the name of a new one.',
				),
			}
		case 'MUNICIPALITY_INVALID':
			return {
				title: t(
					'stackiq',
					'The chosen organisation is not a municipality.',
				),
				hint: t(
					'stackiq',
					'Pick an organisation of type Municipality, or type the name of a new one.',
				),
			}
		case 'NO_SOURCE_SHEET': {
			const expected =
				Array.isArray(details.expected) && details.expected.length > 0
					? details.expected
					: SOURCE_SHEETS
			return {
				title: t(
					'stackiq',
					'The workbook has none of the sheets the import reads.',
				),
				hint: t(
					'stackiq',
					'Expected a sheet named "{first}" or "{second}". Sheet names must match exactly.',
					{
						first: String(expected[0] ?? SOURCE_SHEETS[0]),
						second: String(expected[1] ?? SOURCE_SHEETS[1]),
					},
				),
			}
		}
		case 'MISSING_COLUMN':
			return {
				title: t(
					'stackiq',
					'The sheet "{sheet}" has no column "{column}".',
					{
						sheet: String(details.sheet ?? ''),
						column: String(details.column ?? ''),
					},
				),
				hint: t(
					'stackiq',
					'The columns "APPID" and "Applicatie Naam" are required on every source sheet. Add the column to the export and try again. Nothing was imported.',
				),
			}
		case 'TOO_MANY_ROWS':
			return {
				title: details.sheet
					? t(
							'stackiq',
							'The sheet "{sheet}" has more rows than the import can process.',
							{ sheet: String(details.sheet) },
						)
					: t(
							'stackiq',
							'A sheet has more rows than the import can process.',
						),
				hint: t(
					'stackiq',
					'A source sheet may hold at most 10,000 rows. Split the export and import the parts one after the other.',
				),
			}
		case 'MAPPING_UNAVAILABLE':
			return {
				title: t('stackiq', 'The import mapping cannot run.'),
				hint: t(
					'stackiq',
					"OpenRegister's mapping engine is missing or a mapping file is invalid. Update OpenRegister and check the Nextcloud log.",
				),
			}
		case 'READER_UNAVAILABLE':
			return {
				title: t('stackiq', 'The Excel reader is not available.'),
				hint: t(
					'stackiq',
					'The import reads workbooks with the spreadsheet library that ships with OpenRegister. Make sure OpenRegister is installed and enabled.',
				),
			}
		case 'NOT_CONFIGURED':
			return {
				title: t('stackiq', 'Stackiq is not configured for the import.'),
				hint: t(
					'stackiq',
					'The stackiq register or its schemas cannot be found. Run Auto Configure at the top of this page, then try again.',
				),
			}
		case 'OPERATION_NOT_FOUND':
			return {
				title: t('stackiq', 'This import is no longer running.'),
				hint: '',
			}
		case 'NOT_SIGNED_IN':
			return {
				title: t('stackiq', 'You are not signed in.'),
				hint: t('stackiq', 'Sign in again and retry the import.'),
			}
		case 'NOT_ADMIN':
			return {
				title: t(
					'stackiq',
					'Only Nextcloud administrators can import a CMDB export.',
				),
				hint: '',
			}
		case 'CSRF_FAILED':
			return {
				title: t('stackiq', 'Your session has expired.'),
				hint: t('stackiq', 'Reload the page and try again.'),
			}
		case 'NETWORK_ERROR':
			return {
				title: t('stackiq', 'The server could not be reached.'),
				hint: t('stackiq', 'Check the connection and try again.'),
			}
		case 'IMPORT_FAILED':
		default:
			return {
				title: t('stackiq', 'The import failed unexpectedly.'),
				hint: t(
					'stackiq',
					'Nothing more is known on this page; the Nextcloud log has the details.',
				),
			}
	}
}

/**
 * What the page shows for a progress snapshot of the running import.
 *
 * The percentage comes from the processed and total row counts when the
 * server has set them, because the tracker's own percentage is weighted by
 * the phases of the ArchiMate import.
 *
 * @param {object|null} progress The snapshot from `GET /api/progress/{operationId}`
 * @return {{percentage: number, detail: string}|null} The view, or null before any progress
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-req-cmdb-013-a-running-import-shall-report-its-progress-and-shall-stop-when-cancelled
 */
export function cmdbProgressView(progress) {
	if (!progress) {
		return null
	}
	const processed = Number(progress.processed_items) || 0
	const total = Number(progress.total_items) || 0
	const percentage =
		total > 0
			? Math.min(100, Math.round((processed / total) * 100))
			: Number(progress.percentage) || 0
	return {
		percentage,
		detail:
			total > 0
				? t('stackiq', '{processed} of {total} rows processed', {
						processed,
						total,
					})
				: '',
	}
}

/**
 * The rows of the report as the table shows them.
 *
 * @param {Array<object>} rows The report's `rows`
 * @return {Array<object>} The table rows
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-req-cmdb-011-each-row-shall-be-processed-in-isolation-and-reported-with-its-outcome
 */
export function reportRows(rows) {
	if (!Array.isArray(rows)) {
		return []
	}
	return rows.map((row, index) => ({
		key: `${row.sheet ?? ''}:${row.row ?? index}:${index}`,
		sheet: String(row.sheet ?? ''),
		row: row.row ?? '',
		appId: String(row.appId ?? ''),
		name: String(row.name ?? ''),
		outcome: String(row.outcome ?? ''),
		notes: [
			...(Array.isArray(row.reasons) ? row.reasons : []),
			...(Array.isArray(row.warnings) ? row.warnings : []),
		]
			.map((note) => String(note))
			.join('; '),
		moduleUuid: row.moduleUuid ? String(row.moduleUuid) : '',
	}))
}
