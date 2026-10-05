// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

/**
 * Client side of the CMDB import: request building, the checks the page can
 * make before uploading, and the words for every error code and outcome.
 *
 * The routes, field names, report shape and error codes are fixed by
 * openspec/changes/cmdb-export-import/contract.md. The server stays the
 * authority: every check here is repeated there. The texts are rendered by
 * CmdbImport.vue as text, so translations with placeholders use AS_TEXT.
 *
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-the-admin-settings-shall-offer-a-cmdb-import-section-req-cmdb-014
 */

import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * The limits and sheet names the import profile ships with
 * (lib/Settings/cmdb-import/topdesk-profile.json: `maxFileBytes`,
 * `maxRowsPerSheet` and `sheets`).
 *
 * These are defaults, used only where the page has no answer from the server
 * yet (the help text). The server reads the profile itself, and when it
 * refuses a file its error `details` (`maxBytes`, `limit`, `expected`) carry
 * the values in force, which the error texts show instead. The page makes no
 * size check of its own, so a changed limit needs no change here.
 */
export const PROFILE_DEFAULTS = Object.freeze({
	maxFileBytes: 10 * 1024 * 1024,
	maxRowsPerSheet: 10000,
	sheets: Object.freeze(['Onbeh Applicaties CMDB', 'Beheerde Applicaties CMDB']),
})

/**
 * The l10n options for a translation with placeholders that is rendered as
 * text, through `{{ }}` or a text prop. By default translate() escapes the
 * placeholder values for HTML and sanitises the result, and Vue escapes the
 * text again, so a name like "Berkel & Rodenrijs" showed as
 * "Berkel &amp; Rodenrijs". Never use it for a string that goes into v-html.
 */
export const AS_TEXT = Object.freeze({ escape: false, sanitize: false })

/** Every row outcome the report can carry, in display order. */
export const OUTCOMES = ['created', 'updated', 'unchanged', 'skipped', 'failed']

/**
 * The words for one row outcome.
 *
 * @param {string} outcome The outcome key from the report
 * @return {string} The label
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-each-row-shall-be-processed-in-isolation-and-reported-with-its-outcome-req-cmdb-011
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
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-a-running-import-shall-report-its-progress-and-shall-stop-when-cancelled-req-cmdb-013
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
 * A size in bytes as megabytes, for the texts ("10 MB", "12.5 MB").
 *
 * @param {number} bytes The size
 * @return {string} The size with its unit
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-the-import-endpoint-shall-accept-only-a-bounded-xlsx-upload-from-a-nextcloud-admin-req-cmdb-001
 */
export function formatMegabytes(bytes) {
	const megabytes = Math.round((Number(bytes) / (1024 * 1024)) * 10) / 10
	return t('stackiq', '{size} MB', { size: String(megabytes) }, AS_TEXT)
}

/**
 * The check the page makes on a chosen file before it uploads it.
 *
 * Only the name is checked here. The size limit is the server's (it can be
 * changed in the import profile), and so is the content check (ZIP
 * signature, `xl/workbook.xml`).
 *
 * @param {File|null} file The chosen file
 * @return {{error: string, details: object}|null} An error in the server's shape, or null when the file may be sent
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-the-import-endpoint-shall-accept-only-a-bounded-xlsx-upload-from-a-nextcloud-admin-req-cmdb-001
 */
export function checkFile(file) {
	if (!file) {
		return { error: 'NO_FILE_UPLOADED', details: {} }
	}
	if (!/\.xlsx$/i.test(file.name || '')) {
		return { error: 'NOT_XLSX', details: {} }
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
 * @param {boolean} [options.publish] Whether the modules the import creates are published; true when left out
 * @param {string} options.operationId The progress operation id
 * @return {FormData} The body
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-every-import-shall-have-exactly-one-consuming-municipality-chosen-by-the-admin-req-cmdb-004
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-a-newly-created-module-shall-get-a-publicationdate-when-the-admin-publishes-and-an-existing-one-shall-keep-its-own-req-cmdb-007
 */
export function buildImportForm({
	file,
	municipality,
	updateExisting,
	publish = true,
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
	form.append('publish', publish ? 'true' : 'false')
	form.append('missingRecords', 'keep')
	form.append('operationId', operationId)
	return form
}

/**
 * The statuses of a municipality the import never matches a typed name to, as on the server.
 */
export const UNMATCHED_MUNICIPALITY_STATUSES = ['merged', 'Inactive']

/**
 * The chooser's options from the organisations OpenRegister returned.
 *
 * Only live organisations of type Municipality are offered: an organisation
 * without a type, a merged one or an inactive one is left out, because the
 * server refuses it or never matches a name to it. Municipalities that share
 * a name get the start of their uuid in the label, so the admin can tell them
 * apart; `name` keeps the plain name.
 *
 * @param {Array<object>} objects The organisations
 * @return {Array<{id: string, label: string, name: string, isNew: boolean}>} The options, sorted by label
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-every-import-shall-have-exactly-one-consuming-municipality-chosen-by-the-admin-req-cmdb-004
 */
export function municipalityOptions(objects) {
	const options = (Array.isArray(objects) ? objects : [])
		.filter(
			(org) =>
				org?.type === 'Municipality'
				&& !UNMATCHED_MUNICIPALITY_STATUSES.includes(org?.status),
		)
		.map((org) => {
			const name = String(org.name || org['@self']?.name || '')
			return {
				id: String(org.id || org['@self']?.id || ''),
				label: name,
				name,
				isNew: false,
			}
		})
		.filter((option) => option.id !== '' && option.name !== '')
	const counts = {}
	for (const option of options) {
		const key = normaliseMunicipalityName(option.name)
		counts[key] = (counts[key] || 0) + 1
	}
	return options
		.map((option) =>
			counts[normaliseMunicipalityName(option.name)] > 1
				? { ...option, label: `${option.name} (${option.id.slice(0, 8)})` }
				: option,
		)
		.sort((a, b) => a.label.localeCompare(b.label))
}

/**
 * The chooser's option for a name the admin typed.
 *
 * A typed name is always sent as `municipalityName`, also when it equals the
 * name of a listed municipality: the server then reuses the one live
 * municipality with that name, creates one when there is none, and refuses
 * the name with MUNICIPALITY_AMBIGUOUS when several share it. Only an option
 * picked from the list sends its uuid.
 *
 * @param {string|object} typed What the admin typed
 * @return {{id: null, label: string, name: string, isNew: boolean}} The option
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-every-import-shall-have-exactly-one-consuming-municipality-chosen-by-the-admin-req-cmdb-004
 */
export function typedMunicipalityOption(typed) {
	const name = String(
		typeof typed === 'object' && typed !== null ? typed.label : typed,
	)
		.trim()
		.replace(/\s+/g, ' ')
	return { id: null, label: name, name, isNew: true }
}

/**
 * A municipality name as the server compares it: trimmed, single spaces, lower case.
 *
 * @param {string} name The name
 * @return {string} The normalised name
 */
function normaliseMunicipalityName(name) {
	return String(name).trim().replace(/\s+/g, ' ').toLowerCase()
}

/**
 * The URL of the import endpoint.
 *
 * @return {string} The URL
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-the-import-endpoint-shall-accept-only-a-bounded-xlsx-upload-from-a-nextcloud-admin-req-cmdb-001
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
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-a-running-import-shall-report-its-progress-and-shall-stop-when-cancelled-req-cmdb-013
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
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-each-row-shall-be-processed-in-isolation-and-reported-with-its-outcome-req-cmdb-011
 */
export function moduleUrl(uuid) {
	return generateUrl('/apps/stackiq/modules/{id}', { id: uuid })
}

/**
 * HTTP statuses that mean the request was cut off before the import
 * answered: no answer at all, or a proxy or gateway that gave up waiting.
 * The import itself may still be running on the server.
 */
const INTERRUPTED_STATUSES = new Set([0, 502, 503, 504])

/**
 * Turn a failed request into the server's error shape.
 *
 * Errors raised by Nextcloud itself (not signed in, not an admin, CSRF) come
 * without a CMDB error code, so they get one here from the HTTP status.
 * `interrupted` is true when the request was cut off without an answer from
 * the import (see INTERRUPTED_STATUSES); an answer with a CMDB error code,
 * such as a 503 MAPPING_UNAVAILABLE, is the import's own and never counts.
 *
 * @param {object} error The axios error
 * @return {{error: string, message: string, details: object, status: number, interrupted: boolean}} The error
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-the-import-endpoint-shall-accept-only-a-bounded-xlsx-upload-from-a-nextcloud-admin-req-cmdb-001
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
		interrupted: fromBody === '' && INTERRUPTED_STATUSES.has(status),
	}
}

/**
 * Find out what became of an import whose request was cut off.
 *
 * The server keeps the operation's progress, and the finished report in its
 * `statistics.report`, for an hour. This reads the operation until it is no
 * longer running, waiting `intervalMs` between reads.
 *
 * @param {object} options The options
 * @param {string} options.operationId The operation of the import
 * @param {object} options.http An axios-like client with get
 * @param {(progress: object) => void} [options.onProgress] Called with each snapshot
 * @param {() => boolean} [options.shouldStop] Returns true when the page no longer waits
 * @param {(ms: number) => Promise<void>} [options.wait] Waits the given milliseconds, for tests
 * @param {number} [options.intervalMs] Time between two reads
 * @param {number} [options.timeoutMs] How long to wait for a running import
 * @return {Promise<{state: string, report?: object, status?: number}>} `finished` with the report, `failed`, `unknown` (not found, or still running when the page stopped waiting) or `unreachable` (the progress could not be read either)
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-a-running-import-shall-report-its-progress-and-shall-stop-when-cancelled-req-cmdb-013
 */
export async function followInterruptedImport({
	operationId,
	http,
	onProgress = () => {},
	shouldStop = () => false,
	wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms)),
	intervalMs = 2000,
	timeoutMs = 30 * 60 * 1000,
}) {
	const url = generateUrl('/apps/stackiq/api/progress/{operationId}', {
		operationId,
	})
	let waited = 0
	for (;;) {
		let progress = null
		try {
			const response = await http.get(url)
			progress = response?.data?.progress ?? null
		} catch (error) {
			if (!error?.response) {
				return { state: 'unreachable' }
			}
		}
		if (!progress || typeof progress !== 'object') {
			return { state: 'unknown' }
		}
		onProgress(progress)
		const report = progress.statistics?.report
		if (
			(progress.status === 'completed' || progress.status === 'cancelled')
			&& report
			&& typeof report === 'object'
		) {
			return { state: 'finished', report }
		}
		if (progress.status === 'failed') {
			return { state: 'failed' }
		}
		if (progress.status !== 'running' || waited >= timeoutMs || shouldStop()) {
			return { state: 'unknown' }
		}
		await wait(intervalMs)
		waited += intervalMs
	}
}

/**
 * The error the page shows for an interrupted import, once
 * followInterruptedImport() has an outcome; null when the report was found.
 *
 * @param {{state: string}} outcome What followInterruptedImport() found
 * @param {{error: string, status: number}} error The normalised error of the cut-off request
 * @return {object|null} The error in the server's shape, or null
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-a-running-import-shall-report-its-progress-and-shall-stop-when-cancelled-req-cmdb-013
 */
export function interruptedImportError(outcome, error) {
	const base = {
		message: '',
		details: {},
		status: error?.status ?? 0,
		interrupted: true,
	}
	switch (outcome?.state) {
		case 'finished':
			return null
		case 'failed':
			return { ...base, error: 'IMPORT_FAILED' }
		case 'unreachable':
			if ((error?.status ?? 0) === 0) {
				return { ...base, error: 'NETWORK_ERROR' }
			}
			return { ...base, error: 'IMPORT_INTERRUPTED' }
		default:
			return { ...base, error: 'IMPORT_INTERRUPTED' }
	}
}

/** Every error code the page has its own words for. */
const KNOWN_ERRORS = new Set([
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
])

/**
 * Whether the page has its own words for an error code. For any other code
 * (including `IMPORT_FAILED`) the page also shows the server's message.
 *
 * @param {string} code The error code
 * @return {boolean} True for a code with its own text
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-the-import-endpoint-shall-accept-only-a-bounded-xlsx-upload-from-a-nextcloud-admin-req-cmdb-001
 */
export function isKnownError(code) {
	return KNOWN_ERRORS.has(code)
}

/**
 * The title of WORKBOOK_TOO_LARGE, naming the limit the server applied.
 *
 * @param {object} details The error details: `maxPartBytes` and `part`, `maxSharedStrings`, or `maxUncompressedBytes`
 * @return {string} The title
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-the-workbook-shall-be-read-as-stored-data-without-evaluating-formulas-or-following-links-req-cmdb-002
 */
function workbookTooLargeTitle(details) {
	if (Number(details.maxPartBytes) > 0) {
		return t(
			'stackiq',
			'Unpacked, the part {part} of the workbook is larger than {size}, the most the import reads of one part.',
			{ part: String(details.part ?? ''), size: formatMegabytes(details.maxPartBytes) },
			AS_TEXT,
		)
	}
	if (Number(details.maxSharedStrings) > 0) {
		return t(
			'stackiq',
			'The workbook holds more than {count} different texts, the most the import reads.',
			{ count: String(details.maxSharedStrings) },
			AS_TEXT,
		)
	}
	if (Number(details.maxUncompressedBytes) > 0) {
		return t(
			'stackiq',
			'Unpacked, the workbook is larger than {size}, the most the import reads.',
			{ size: formatMegabytes(details.maxUncompressedBytes) },
			AS_TEXT,
		)
	}
	return t('stackiq', 'The workbook is too large to read once unpacked.')
}

/**
 * What the page says for an error: a title and, where the code has one, a
 * hint on what to do. The text is the page's own, so it is translated even
 * when the server's message is not.
 *
 * @param {{error: string, message?: string, details?: object}} error The error in the server's shape
 * @return {{title: string, hint: string}} The words
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-columns-shall-be-resolved-by-header-name-and-a-missing-required-column-shall-stop-the-import-with-422-req-cmdb-003
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
				title:
					Number(details.maxBytes) > 0
						? t(
								'stackiq',
								'The file is larger than {size}, the most the import accepts.',
								{ size: formatMegabytes(details.maxBytes) },
								AS_TEXT,
							)
						: t(
								'stackiq',
								'The file is larger than the server accepts.',
							),
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
		case 'FIELD_INVALID': {
			const accepted = Array.isArray(details.accepted)
				? details.accepted.map((value) => String(value))
				: []
			return {
				title: t(
					'stackiq',
					'The request field "{field}" has a value the import does not accept.',
					{ field: String(details.field ?? '') },
					AS_TEXT,
				),
				hint:
					accepted.length > 0
						? t(
								'stackiq',
								'Accepted values: {accepted}. Reload the page and try again.',
								{ accepted: accepted.join(', ') },
								AS_TEXT,
							)
						: t('stackiq', 'Reload the page and try again.'),
			}
		}
		case 'UPLOAD_FAILED':
			return {
				title: t('stackiq', 'The server could not store the uploaded file.'),
				hint: t(
					'stackiq',
					'Try again. If it keeps failing, the Nextcloud log has the details; check the free space and the upload settings of the server.',
				),
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
					: PROFILE_DEFAULTS.sheets
			return {
				title: t(
					'stackiq',
					'The workbook has none of the sheets the import reads.',
				),
				hint: t(
					'stackiq',
					'Expected a sheet named "{first}" or "{second}". Sheet names must match exactly.',
					{
						first: String(expected[0] ?? PROFILE_DEFAULTS.sheets[0]),
						second: String(expected[1] ?? PROFILE_DEFAULTS.sheets[1]),
					},
					AS_TEXT,
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
					AS_TEXT,
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
							AS_TEXT,
						)
					: t(
							'stackiq',
							'A sheet has more rows than the import can process.',
						),
				hint:
					Number(details.limit) > 0
						? t(
								'stackiq',
								'A source sheet may hold at most {limit} rows. Split the export and import the parts one after the other.',
								{ limit: Number(details.limit).toLocaleString() },
								AS_TEXT,
							)
						: t(
								'stackiq',
								'Split the export and import the parts one after the other.',
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
		case 'WORKBOOK_TOO_LARGE':
			return {
				title: workbookTooLargeTitle(details),
				hint: t(
					'stackiq',
					'Remove sheets the import does not read, such as the archive sheet, or split the export, and try again. Nothing was imported.',
				),
			}
		case 'SCHEMA_OUTDATED':
			return {
				title: details.schema
					? t(
							'stackiq',
							'The "{schema}" schema of the stackiq register is out of date.',
							{ schema: String(details.schema) },
							AS_TEXT,
						)
					: t('stackiq', 'The stackiq register is out of date.'),
				hint: t(
					'stackiq',
					'It lacks properties the import matches on. Press Force Update at the top of this page to import the register configuration again, then try again. Nothing was imported.',
				),
			}
		case 'IMPORT_IN_PROGRESS':
			return {
				title: t('stackiq', 'Another CMDB import is running.'),
				hint: t(
					'stackiq',
					'Only one import runs at a time. Wait until it has finished and try again. Nothing was imported.',
				),
			}
		case 'MUNICIPALITY_AMBIGUOUS':
			return {
				title:
					Array.isArray(details.matches) && details.matches.length > 1
						? t(
								'stackiq',
								'{count} municipalities have this name.',
								{ count: String(details.matches.length) },
								AS_TEXT,
							)
						: t('stackiq', 'Several municipalities have this name.'),
				hint: t(
					'stackiq',
					'Choose the municipality from the list instead of typing its name. Nothing was imported.',
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
		case 'IMPORT_INTERRUPTED':
			return {
				title: t('stackiq', 'The page got no answer from the import.'),
				hint: t(
					'stackiq',
					'The connection was cut off before the import answered, so it may still be running or may have finished. Wait a few minutes and check the applications of the municipality before you import again. Importing the same file again creates no duplicates.',
				),
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
 * What the page says when its request to cancel the import was refused.
 *
 * @param {{error: string, details?: object}} error The normalised error of the cancel request
 * @return {string} The sentence
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-a-running-import-shall-report-its-progress-and-shall-stop-when-cancelled-req-cmdb-013
 */
export function cancelFailureText(error) {
	if (error?.error === 'OPERATION_NOT_FOUND') {
		return t(
			'stackiq',
			'The import cannot be cancelled now: the server has not started its rows yet, or has already finished them. If the import keeps running, press Cancel import again in a moment.',
		)
	}
	return t(
		'stackiq',
		'The import could not be cancelled: {reason}',
		{
			reason: errorText(error).title,
		},
		AS_TEXT,
	)
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
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-a-running-import-shall-report-its-progress-and-shall-stop-when-cancelled-req-cmdb-013
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
				? t(
						'stackiq',
						'{processed} of {total} rows processed',
						{
							processed,
							total,
						},
						AS_TEXT,
					)
				: '',
	}
}

/**
 * How many report rows the table shows at first, and how many more each
 * "Show more" adds. A report can hold up to two full sheets of rows.
 */
export const REPORT_PAGE_SIZE = 100

/**
 * The report rows in the order of one column: numbers by value, text in the
 * locale's order. Rows that tie keep their order in the report.
 *
 * The table emits the sort the admin asked for and leaves the sorting to the
 * page, so the whole row set is sorted before it is cut into pages.
 *
 * @param {Array<object>} rows The table rows
 * @param {string|null} key The column key
 * @param {string|null} order 'asc', 'desc', or null for the report's order
 * @return {Array<object>} The rows, sorted (a new array when sorted)
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-the-admin-settings-shall-offer-a-cmdb-import-section-req-cmdb-014
 */
export function sortReportRows(rows, key, order) {
	if (!key || (order !== 'asc' && order !== 'desc')) {
		return rows
	}
	const direction = order === 'desc' ? -1 : 1
	const collator = new Intl.Collator(undefined, {
		numeric: true,
		sensitivity: 'base',
	})
	return rows
		.map((row, index) => ({ row, index }))
		.sort((a, b) => {
			const compared = collator.compare(
				String(a.row[key] ?? ''),
				String(b.row[key] ?? ''),
			)
			return compared !== 0 ? compared * direction : a.index - b.index
		})
		.map((entry) => entry.row)
}

/**
 * The rows of the report as the table shows them.
 *
 * @param {Array<object>} rows The report's `rows`
 * @return {Array<object>} The table rows
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-each-row-shall-be-processed-in-isolation-and-reported-with-its-outcome-req-cmdb-011
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
