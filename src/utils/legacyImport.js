// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

/**
 * Client side of bringing over an export from the previous Softwarecatalogus:
 * the routes, the request bodies, which rows the user left out, and the words
 * for every decision, skip reason and error code.
 *
 * The server stays the authority: it checks the right to import, reads the
 * file and decides per row. The texts are rendered as text, so translations
 * with placeholders use AS_TEXT.
 *
 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-4
 */

import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/** Placeholders are rendered as text by Vue, so translate() must not escape them. */
const AS_TEXT = { escape: false }

/** The label of the actions-menu entry and the title of the dialog. */
export const legacyImportTitle = () => t('stackiq', 'Bring over from the previous Softwarecatalogus')

/**
 * The URLs of the three endpoints.
 *
 * @return {{access: string, preview: string, apply: string}}
 */
export function legacyImportUrls() {
	return {
		access: generateUrl('/apps/stackiq/api/legacy-import/access'),
		preview: generateUrl('/apps/stackiq/api/legacy-import/preview'),
		apply: generateUrl('/apps/stackiq/api/legacy-import/apply'),
	}
}

/**
 * The multipart body: the export and, for apply, the ids left out.
 *
 * @param {File} file The export.
 * @param {Array<string>|null} leftOut The previous catalogue ids left out; null for the preview.
 * @return {FormData}
 */
export function legacyImportForm(file, leftOut = null) {
	const form = new FormData()
	form.append('file', file)
	if (Array.isArray(leftOut)) {
		form.append('leftOut', JSON.stringify(leftOut))
	}
	return form
}

/**
 * The rows the user may bring over: the ones the preview did not skip.
 *
 * @param {Array<object>} rows The preview rows.
 * @return {Array<string>} Their previous catalogue ids.
 */
export function selectableIds(rows) {
	return (rows ?? []).filter((row) => !row.skipped && row.legacyId !== '').map((row) => row.legacyId)
}

/**
 * The ids the user left out: selectable rows whose checkbox is off.
 *
 * @param {Array<object>} rows The preview rows.
 * @param {Array<string>} selected The ids still checked.
 * @return {Array<string>}
 */
export function leftOutIds(rows, selected) {
	const keep = new Set(selected)
	return selectableIds(rows).filter((id) => !keep.has(id))
}

/**
 * The word for a decision: matched or create; a row without that object shows a dash.
 *
 * @param {string} decision The decision.
 * @return {string}
 */
export function decisionLabel(decision) {
	const labels = {
		matched: () => t('stackiq', 'matched'),
		create: () => t('stackiq', 'create'),
	}
	return (labels[decision] ?? (() => '—'))()
}

/**
 * Why a row was skipped, in words.
 *
 * @param {string} reason The reason code of the apply result.
 * @return {string}
 */
export function skipReasonLabel(reason) {
	const labels = {
		mapping: () => t('stackiq', 'A required value is empty'),
		'no-id': () => t('stackiq', 'A required value is empty'),
		'left-out': () => t('stackiq', 'Left out in the preview'),
		'repeated-id': () => t('stackiq', 'The ID repeats an earlier row'),
	}
	return (labels[reason] ?? (() => t('stackiq', 'The record could not be saved.')))()
}

/**
 * The counts of an apply result, as the result step shows them.
 *
 * @param {{created: number, updated: number, skipped: number}} result The result.
 * @return {Array<{key: string, text: string}>}
 */
export function resultCounts(result) {
	return [
		{ key: 'created', text: t('stackiq', 'created {count}', { count: result.created ?? 0 }, undefined, AS_TEXT) },
		{ key: 'updated', text: t('stackiq', 'updated {count}', { count: result.updated ?? 0 }, undefined, AS_TEXT) },
		{ key: 'skipped', text: t('stackiq', 'skipped {count}', { count: result.skipped ?? 0 }, undefined, AS_TEXT) },
	]
}

/**
 * The message for a failed request, from the server's error code and details.
 *
 * @param {object} error The axios error.
 * @return {string}
 */
export function legacyImportError(error) {
	const data = error?.response?.data ?? {}
	const details = data.details ?? {}
	const notAnExport = (missing) => t(
		'stackiq',
		'This is not an export of the previous Softwarecatalogus: {missing} is missing.',
		{ missing },
		undefined,
		AS_TEXT,
	)
	const messages = {
		FORBIDDEN: () => t('stackiq', 'Only a member of an organisation who may register applications can bring over an export.'),
		NOT_XLSX: () => t('stackiq', 'The file is not an Excel workbook (.xlsx).'),
		FILE_TOO_LARGE: () => t('stackiq', 'The file is larger than the server accepts.'),
		WORKBOOK_TOO_LARGE: () => t('stackiq', 'The file is larger than the server accepts.'),
		NO_SOURCE_SHEET: () => notAnExport('Applicaties'),
		MISSING_COLUMN: () => notAnExport(details.column ?? ''),
		TOO_MANY_ROWS: () => t('stackiq', 'A sheet has more rows than the import can process.'),
		NOT_CONFIGURED: () => t('stackiq', 'Stackiq is not configured for the import.'),
	}
	return (messages[data.error] ?? (() => t('stackiq', 'The import failed unexpectedly.')))()
}
