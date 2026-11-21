/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The comparison of today's landscape with the plan or a scenario on a date
 * (architecture-future-state-scenarios, design D3 and D4), as rows for the
 * comparison page. Every state reads as a word, so nothing depends on colour.
 */

import { translate as t } from '@nextcloud/l10n'
import { resolveUuid } from './lifecyclePhase.js'

/** The URL of the comparison endpoint, relative to the app. */
export const COMPARISON_PATH = '/apps/stackiq/api/landscape-comparison'

/**
 * The query of a comparison: the organisation and the date for the plan, plus
 * the scenario when one is compared (its target date then wins on the server).
 *
 * @param {object}      options              The comparison.
 * @param {string}      options.organisation The organisation uuid.
 * @param {string}      [options.date]       The Y-m-d date.
 * @param {string|null} [options.scenario]   The scenario uuid.
 * @return {object} The query parameters.
 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-002-an-information-manager-shall-compare-todays-landscape-with-the-plan-on-a-date
 */
export function comparisonParams({ organisation, date = '', scenario = null }) {
	const params = { organisation }
	if (date) {
		params.date = date
	}
	if (scenario) {
		params.scenario = scenario
	}
	return params
}

/**
 * The word for an application difference.
 *
 * @param {object} row A row of `applications`.
 * @return {string} Added, Removed, Replaced by … or Unchanged.
 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-002-an-information-manager-shall-compare-todays-landscape-with-the-plan-on-a-date
 */
export function changeLabel(row) {
	switch (row?.change) {
	case 'added':
		return t('stackiq', 'Added')
	case 'removed':
		return t('stackiq', 'Removed')
	case 'replaced':
		return t('stackiq', 'Replaced by {name}', { name: row.replacedBy || '' })
	default:
		return t('stackiq', 'Unchanged')
	}
}

/**
 * The words for the sources of a difference: plan, scenario, or both.
 *
 * @param {object} row A row of `applications`.
 * @return {string} The sources.
 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-003-an-information-manager-shall-write-a-scenario-of-additions-phase-outs-and-replacements
 */
export function sourceLabel(row) {
	const sources = Array.isArray(row?.sources) && row.sources.length > 0
		? row.sources
		: (row?.source ? [row.source] : [])
	const words = []
	if (sources.includes('scenario')) {
		words.push(t('stackiq', 'Scenario'))
	}
	if (sources.includes('plan')) {
		words.push(t('stackiq', 'Plan'))
	}
	return words.join(', ')
}

/**
 * The application differences as table rows; unchanged applications are left
 * out, because both columns hold them.
 *
 * @param {object|null} report The comparison answer.
 * @return {Array<object>} Rows with `id`, `application`, `change`, `source` and `date`.
 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-002-an-information-manager-shall-compare-todays-landscape-with-the-plan-on-a-date
 */
export function applicationRows(report) {
	return (report?.applications || [])
		.filter((row) => row.change !== 'unchanged')
		.map((row, index) => ({
			id: `${row.usage || row.module || 'row'}:${index}`,
			application: row.moduleName || row.module || '',
			change: changeLabel(row),
			source: sourceLabel(row),
			date: row.date || '',
		}))
}

/**
 * The word for a coverage state.
 *
 * @param {string} state covered, gap or overlap.
 * @return {string} The word.
 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-002-an-information-manager-shall-compare-todays-landscape-with-the-plan-on-a-date
 */
export function coverageStateLabel(state) {
	if (state === 'covered') {
		return t('stackiq', 'Covered')
	}
	if (state === 'overlap') {
		return t('stackiq', 'Overlap')
	}
	return t('stackiq', 'Gap')
}

/**
 * The word for a coverage change.
 *
 * @param {string} change gap closed, gap opened, overlap resolved or overlap created.
 * @return {string} The word.
 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-002-an-information-manager-shall-compare-todays-landscape-with-the-plan-on-a-date
 */
export function coverageChangeLabel(change) {
	switch (change) {
	case 'gap closed':
		return t('stackiq', 'Gap closed')
	case 'gap opened':
		return t('stackiq', 'Gap opened')
	case 'overlap resolved':
		return t('stackiq', 'Overlap resolved')
	default:
		return t('stackiq', 'Overlap created')
	}
}

/**
 * The reference components whose coverage differs, as table rows.
 *
 * @param {object|null} report The comparison answer.
 * @return {Array<object>} Rows with `id`, `component`, `today`, `future` and `change`.
 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-002-an-information-manager-shall-compare-todays-landscape-with-the-plan-on-a-date
 */
export function coverageRows(report) {
	return (report?.coverage || []).map((row, index) => ({
		id: row.componentId || `${row.component}:${index}`,
		component: row.component || '',
		today: coverageStateLabel(row.today),
		future: coverageStateLabel(row.future),
		change: coverageChangeLabel(row.change),
	}))
}

/**
 * The applications of one landscape column, by name.
 *
 * @param {object|null} report The comparison answer.
 * @param {string}      side   today or future.
 * @return {Array<string>} The application names.
 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-002-an-information-manager-shall-compare-todays-landscape-with-the-plan-on-a-date
 */
export function landscapeNames(report, side) {
	return (report?.landscapes?.[side] || []).map((entry) => entry.moduleName || entry.module || '')
}

/**
 * The notice for a failed apply, naming the change it stopped at.
 *
 * @param {{change: (object|null), message: string}} failed The failure.
 * @return {string} The notice.
 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-004-a-scenario-shall-move-through-a-declared-lifecycle-and-an-adopted-scenario-shall-be-applied-to-the-landscape
 */
export function applyFailureNotice(failed) {
	if (!failed?.change) {
		return t('stackiq', 'The scenario was not marked as applied: {message}', { message: failed?.message || '' })
	}
	const change = failed.change
	const subject = change.module?.name || change.usage?.name || change.note || change.action
	return t('stackiq', 'Applying stopped at the change "{change}": {message}. The changes before it are written; apply again to continue.', {
		change: subject,
		message: failed.message,
	})
}

/**
 * Organisation records as picker options, labelled by name.
 *
 * @param {Array<object>|null|undefined} records The organisation records.
 * @return {Array<{uuid: string, label: string}>} The options.
 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-002-an-information-manager-shall-compare-todays-landscape-with-the-plan-on-a-date
 */
export function organisationPickerOptions(records) {
	return (records || []).map((record) => {
		const data = record.object || record
		const uuid = resolveUuid(record.uuid ?? record.id ?? record['@self']?.id ?? record)
		return { uuid, label: data.name || data.title || uuid }
	})
}
