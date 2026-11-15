/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The value assessment of the applications an organisation uses: the risk
 * signals next to the risk score, and the value against fit plot and the
 * mismatch filter of the portfolio report.
 *
 * @spec openspec/specs/application-value-assessment/spec.md
 */
import { endOfSupportState } from './lifecyclePhase.js'
import { refId } from './maintenance.js'

const MIN_RADIUS = 6
const MAX_RADIUS = 22
const SPREAD = 0.18

/**
 * The risk signals of a usage: end of support of the version it runs and the
 * number of vulnerabilities linked to its application.
 *
 * @param {object|null} version The moduleVersion the usage runs.
 * @param {Array<object>|null} vulnerabilities Vulnerabilities to count.
 * @param {string} moduleId The application of the usage.
 * @param {Date} [now] The current moment.
 * @return {{endOfSupportPassed: boolean, endOfSupportDate: (string|null), withdrawn: boolean, vulnerabilityCount: number}} The signals.
 * @spec openspec/specs/application-value-assessment/spec.md#requirement-req-ava-002-the-usage-page-shows-the-risk-signals-next-to-the-risk-score
 */
export function riskSignals(version, vulnerabilities, moduleId, now = new Date()) {
	const eol = endOfSupportState(version || {}, now)
	const count = (Array.isArray(vulnerabilities) ? vulnerabilities : []).filter(
		(vulnerability) => {
			const data = vulnerability?.object || vulnerability || {}
			const modules = Array.isArray(data.modules) ? data.modules : []
			return modules.some((module) => refId(module) === moduleId)
		},
	).length
	return {
		endOfSupportPassed: eol.passed,
		endOfSupportDate: eol.endDate,
		withdrawn: eol.withdrawn,
		vulnerabilityCount: count,
	}
}

/**
 * Whether a report row has both scores the plot needs.
 *
 * @param {object} row A portfolio report row.
 * @return {boolean} True when value and fit are set.
 * @spec openspec/specs/application-value-assessment/spec.md#requirement-req-ava-003-the-portfolio-report-plots-value-against-fit-and-flags-classes-the-scores-contradict
 */
function isScored(row) {
	return (
		Number.isInteger(row?.businessValue) && Number.isInteger(row?.technicalFit)
	)
}

/**
 * The points of the value against fit plot: one per scored usage, its radius
 * by annualised cost (square root, so the area follows the cost), and a small
 * offset for points that share a cell.
 *
 * @param {Array<object>|null} rows Portfolio report rows.
 * @return {{points: Array<object>, notScored: number}} The points and the count of usages without both scores.
 * @spec openspec/specs/application-value-assessment/spec.md#requirement-req-ava-003-the-portfolio-report-plots-value-against-fit-and-flags-classes-the-scores-contradict
 */
export function valueFitPoints(rows) {
	const list = Array.isArray(rows) ? rows : []
	const scored = list.filter(isScored)
	const maxCost = Math.max(
		0,
		...scored.map((row) => Number(row.annualisedCost) || 0),
	)
	const seen = {}
	const points = scored.map((row) => {
		const cost = Math.max(0, Number(row.annualisedCost) || 0)
		const share = maxCost > 0 ? Math.sqrt(cost / maxCost) : 0
		const cell = `${row.technicalFit}:${row.businessValue}`
		const index = seen[cell] || 0
		seen[cell] = index + 1
		const angle = index * 2.4
		return {
			uuid: row.uuid,
			label: row.moduleName,
			fit: row.technicalFit,
			value: row.businessValue,
			cost,
			radius: MIN_RADIUS + share * (MAX_RADIUS - MIN_RADIUS),
			offset:
				index === 0
					? [0, 0]
					: [Math.cos(angle) * SPREAD, Math.sin(angle) * SPREAD],
			recorded: row.timeClassification || null,
			suggested: row.suggestedTimeClassification || null,
		}
	})
	return { points, notScored: list.length - scored.length }
}

/**
 * The rows whose recorded TIME class differs from the class the scores suggest.
 *
 * @param {Array<object>|null} rows Portfolio report rows.
 * @return {Array<object>} The mismatching rows.
 * @spec openspec/specs/application-value-assessment/spec.md#requirement-req-ava-003-the-portfolio-report-plots-value-against-fit-and-flags-classes-the-scores-contradict
 */
export function mismatchRows(rows) {
	return (Array.isArray(rows) ? rows : []).filter(
		(row) => row?.timeMismatch === true,
	)
}
