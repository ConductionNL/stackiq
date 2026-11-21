/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * Client side of the ArchiMate round-trip check: the request to
 * `POST /api/archimate/round-trip-check` and the rows and summary the admin
 * settings page shows from its report.
 *
 * @spec openspec/changes/architecture-round-trip-check/specs/archimate-round-trip-check/spec.md#requirement-req-art-004-the-admin-settings-page-shall-show-the-round-trip-report
 */

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/** Convert the file and generate it back; nothing is written. */
export const MODE_BEFORE_IMPORT = 'before-import'

/** Generate the file back from the stored objects of its model. */
export const MODE_AGAINST_IMPORTED = 'against-imported'

/**
 * The categories of the report, in the order the server reports them.
 *
 * @type {string[]}
 */
export const CATEGORIES = ['elements', 'relationships', 'views', 'viewNodes', 'propertyDefinitions', 'organizations']

/**
 * The translated name of a report category.
 *
 * @param {string} category The category key.
 * @return {string}
 *
 * @spec openspec/changes/architecture-round-trip-check/specs/archimate-round-trip-check/spec.md#requirement-req-art-004-the-admin-settings-page-shall-show-the-round-trip-report
 */
export function categoryLabel(category) {
	const labels = {
		elements: t('stackiq', 'Elements'),
		relationships: t('stackiq', 'Relationships'),
		views: t('stackiq', 'Views'),
		viewNodes: t('stackiq', 'View nodes'),
		propertyDefinitions: t('stackiq', 'Property definitions'),
		organizations: t('stackiq', 'Organization folders'),
	}
	return labels[category] ?? category
}

/**
 * One row per category: the counts and the examples.
 *
 * @param {object} report The report the server answered.
 * @return {Array<{key: string, label: string, source: number, result: number, missing: number, extra: number, changed: number, examples: Array<object>, lossy: boolean}>}
 *
 * @spec openspec/changes/architecture-round-trip-check/specs/archimate-round-trip-check/spec.md#requirement-req-art-004-the-admin-settings-page-shall-show-the-round-trip-report
 */
export function roundTripRows(report) {
	const categories = report?.categories ?? {}
	return CATEGORIES.filter((key) => categories[key] !== undefined).map((key) => {
		const category = categories[key]
		const missing = Number(category.missing ?? 0)
		const extra = Number(category.extra ?? 0)
		const changed = Number(category.changed ?? 0)
		return {
			key,
			label: categoryLabel(key),
			source: Number(category.source ?? 0),
			result: Number(category.result ?? 0),
			missing,
			extra,
			changed,
			examples: Array.isArray(category.examples) ? category.examples : [],
			lossy: missing + extra + changed > 0,
		}
	})
}

/**
 * The summary line: "No losses found", the categories with losses, or that the model is not imported.
 *
 * @param {object} report The report the server answered.
 * @return {string}
 *
 * @spec openspec/changes/architecture-round-trip-check/specs/archimate-round-trip-check/spec.md#requirement-req-art-004-the-admin-settings-page-shall-show-the-round-trip-report
 */
export function roundTripSummary(report) {
	if (report?.imported === false) {
		return t('stackiq', 'This model is not imported.')
	}
	const lossy = roundTripRows(report).filter((row) => row.lossy).map((row) => row.label)
	if (lossy.length === 0) {
		return t('stackiq', 'No losses found')
	}
	return t('stackiq', 'Losses in: {categories}', { categories: lossy.join(', ') })
}

/**
 * Upload a file to the round-trip check and answer its report.
 *
 * @param {File} file The exchange file.
 * @param {string} mode MODE_BEFORE_IMPORT or MODE_AGAINST_IMPORTED.
 * @return {Promise<object>} The report.
 * @throws {Error} With the server's message when the check fails.
 *
 * @spec openspec/changes/architecture-round-trip-check/specs/archimate-round-trip-check/spec.md#requirement-req-art-004-the-admin-settings-page-shall-show-the-round-trip-report
 */
export async function checkRoundTrip(file, mode) {
	const formData = new FormData()
	formData.append('archiMateFile', file)
	formData.append('mode', mode)
	try {
		const response = await axios.post(generateUrl('/apps/stackiq/api/archimate/round-trip-check'), formData)
		return response.data
	} catch (error) {
		throw new Error(error?.response?.data?.message || t('stackiq', 'The check failed'), { cause: error })
	}
}
