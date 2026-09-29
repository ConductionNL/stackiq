/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * EU AI Act helpers for the AI systems pages: the missing-FRIA rule the list
 * warns on, and the evidence checklist the detail page shows.
 *
 * @spec openspec/specs/ai-system-inventory/spec.md#requirement-req-ais-003-a-high-risk-ai-system-without-a-fundamental-rights-impact-assessment-is-flagged
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * The evidence tags a deployer of a high-risk AI system keeps, in the order
 * the checklist shows them. The same list is the schema's `allowedTags`.
 */
export const EVIDENCE_TAGS = [
	'FRIA',
	'Technical documentation',
	'Human oversight',
	'Logging',
]

/**
 * Whether an AI system is high risk and has no FRIA reference.
 *
 * @param {object} system The aiSystem object.
 * @return {boolean} True when the FRIA is missing.
 * @spec openspec/specs/ai-system-inventory/spec.md#requirement-req-ais-003-a-high-risk-ai-system-without-a-fundamental-rights-impact-assessment-is-flagged
 */
export function friaMissing(system) {
	if (!system || system.aiActRiskCategory !== 'high risk') {
		return false
	}
	const ref = system.friaDocumentRef
	return typeof ref !== 'string' || ref.trim() === ''
}

/**
 * Cell formatter for the FRIA column of the AI systems list: reads
 * "FRIA missing" on a flagged system and nothing otherwise.
 *
 * @param {unknown} _value The friaDocumentRef value (the row is read instead).
 * @param {object} row The aiSystem row.
 * @return {string} The warning, or an empty string.
 * @spec openspec/specs/ai-system-inventory/spec.md#requirement-req-ais-003-a-high-risk-ai-system-without-a-fundamental-rights-impact-assessment-is-flagged
 */
export function friaStatus(_value, row) {
	return friaMissing(row) ? t('stackiq', 'FRIA missing') : ''
}

/**
 * The evidence checklist: one entry per tag, present when a file carries it.
 *
 * @param {Array<object>|undefined} files The object's files, each with `labels`.
 * @return {Array<{tag: string, present: boolean, files: Array<string>}>} The checklist.
 * @spec openspec/specs/ai-system-inventory/spec.md#requirement-req-ais-003-a-high-risk-ai-system-without-a-fundamental-rights-impact-assessment-is-flagged
 */
export function evidenceChecklist(files) {
	const list = Array.isArray(files) ? files : []
	return EVIDENCE_TAGS.map((tag) => {
		const names = list
			.filter((f) => Array.isArray(f?.labels) && f.labels.includes(tag))
			.map((f) => f.name)
		return { tag, present: names.length > 0, files: names }
	})
}
