// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

/**
 * Pure display-formatting helpers for the portfolio rationalization report
 * (PortfolioReport.vue). Mirrors `lifecyclePhase.js` / `contractCost.js` /
 * `licensePosture.js`: no I/O, no `@nextcloud/*` imports, no translation
 * calls (labels that need `t()` stay in the Vue component, matching
 * `LifecycleRoadmapView.phaseLabel()`), so these functions are directly
 * unit-testable with plain vitest.
 *
 * @spec openspec/changes/portfolio-rationalization-time/specs/portfolio-rationalization-time/spec.md
 */

/**
 * The five report groups, in canonical TIME order — Unclassified last, so it
 * stays visible rather than being dropped (spec Scenario "Unclassified
 * gebruiken are visible, not hidden").
 *
 * @type {string[]}
 */
export const QUADRANT_ORDER = ['Tolerate', 'Invest', 'Migrate', 'Eliminate', 'Unclassified']

/**
 * NL Design System token for each quadrant's accent colour — one of the four
 * semantic status colours plus a neutral for Unclassified, never a
 * hardcoded hex (ADR-003).
 *
 * @type {Record<string, string>}
 */
export const QUADRANT_COLORS = {
	Tolerate: 'var(--color-text-maxcontrast, #767676)',
	Invest: 'var(--color-success, #46ba61)',
	Migrate: 'var(--color-warning, #e9a300)',
	Eliminate: 'var(--color-error, #e04224)',
	Unclassified: 'var(--color-border-dark, #949494)',
}

/**
 * The accent colour for a quadrant key, falling back to the primary element
 * colour for an unknown key (defensive — never throws on a bad key).
 *
 * @param {string} key A QUADRANT_ORDER key.
 * @return {string} A CSS custom-property reference.
 */
export function quadrantColor(key) {
	return QUADRANT_COLORS[key] || 'var(--color-primary-element)'
}

/**
 * Render a quadrant's cloud-transition (hosting model) breakdown as a short
 * label, e.g. "SaaS: 3, IaaS: 1".
 *
 * @param {Record<string, number>} cloudTransition Hosting model → count.
 * @return {string} Display label, or an em dash when empty.
 */
export function cloudTransitionLabel(cloudTransition) {
	const entries = Object.entries(cloudTransition || {})
	if (entries.length === 0) {
		return '—'
	}
	return entries.map(([model, count]) => `${model}: ${count}`).join(', ')
}

/**
 * Format a cost figure as a euro label, falling back to a plain rounded
 * figure if `Intl.NumberFormat` is unavailable. Zero/falsy amounts render
 * as an em dash (matches `LicensePostureView`'s "—" convention for absent
 * figures).
 *
 * @param {number} amount The amount.
 * @return {string} Currency label, or an em dash when zero/falsy.
 */
export function formatCurrency(amount) {
	if (!amount) {
		return '—'
	}
	try {
		return new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }).format(amount)
	} catch (error) {
		return `€ ${Math.round(amount)}`
	}
}

/**
 * Group report rows by their `quadrant` field, in canonical TIME order —
 * every quadrant key is present even when it has zero rows, so Unclassified
 * (or any empty quadrant) is never silently omitted from the grouped view.
 *
 * @param {Array<{quadrant: string}>} rows Report rows (each carries a `quadrant` key).
 * @return {Array<{key: string, rows: Array}>} Grouped rows, one entry per QUADRANT_ORDER key.
 */
export function groupRowsByQuadrant(rows) {
	const list = Array.isArray(rows) ? rows : []
	return QUADRANT_ORDER.map((key) => ({
		key,
		rows: list.filter((row) => row && row.quadrant === key),
	}))
}

/**
 * Build the CSV export URL for the portfolio report endpoint, given the
 * endpoint's base URL and the organisation uuid — a pure string-building
 * helper so `PortfolioReport.vue`'s `exportCsv()` stays a thin caller.
 *
 * @param {string} baseUrl The report endpoint's base URL (no query string).
 * @param {string} organisationUuid The selected organisation's uuid.
 * @return {string} The CSV export URL, or '' when `organisationUuid` is empty.
 */
export function buildCsvExportUrl(baseUrl, organisationUuid) {
	if (!organisationUuid) {
		return ''
	}
	return `${baseUrl}?organisation=${encodeURIComponent(organisationUuid)}&format=csv`
}
