/**
 * lifecyclePhase — derive an application-in-use (gebruik) lifecycle phase.
 *
 * Lifecycle tracking is the core job of application-portfolio management. A
 * gebruik carries five phase-start dates; the derived phase is the most
 * advanced phase whose start date is in the past. Nothing is stored — the
 * phase is a pure function of the dates (single source of truth), so editing
 * a date immediately changes the derived phase with no object write.
 *
 * Phases (Dutch domain terms, preserved for GEMMA/export consistency):
 *   Verwerving → Gepland → In productie → Uit te faseren → Uitgefaseerd.
 * A gebruik with no phase dates derives as `Onbekend` (unknown) and MUST stay
 * visible in portfolio views — an undated portfolio entry is itself a
 * rationalisation finding.
 *
 * Also derives end-of-support state from the linked moduleVersie
 * (`datumEindeOndersteuning` / `datumTeruggetrokken`).
 *
 * @module utils/lifecyclePhase
 * @author Ruben Linde
 * @copyright 2026 Conduction B.V.
 * @license EUPL-1.2
 *
 * @spec openspec/specs/application-lifecycle-tracking/spec.md
 */

/**
 * Phase constants. Exported so callers never compare bare strings.
 *
 * @type {{ACQUISITION: string, PLANNED: string, PRODUCTION: string, PHASING_OUT: string, PHASED_OUT: string, UNKNOWN: string}}
 */
export const PHASE = Object.freeze({
	ACQUISITION: 'Acquisition',
	PLANNED: 'Planned',
	PRODUCTION: 'In production',
	PHASING_OUT: 'To be phased out',
	PHASED_OUT: 'Phased out',
	UNKNOWN: 'Onbekend',
})

/**
 * Ordered phase steps, least → most advanced, with the gebruik date field that
 * starts each phase. Derivation walks this from the end and returns the first
 * phase whose start date is in the past.
 *
 * @type {Array<{phase: string, field: string}>}
 */
const PHASE_STEPS = [
	{ phase: PHASE.ACQUISITION, field: 'startDateAcquisition' },
	{ phase: PHASE.PLANNED, field: 'startDatePlanned' },
	{ phase: PHASE.PRODUCTION, field: 'startDateInProduction' },
	{ phase: PHASE.PHASING_OUT, field: 'startDateOutPhasing' },
	{ phase: PHASE.PHASED_OUT, field: 'startDateOutPhased' },
]

/**
 * Read the data bag of a gebruik that may be an OR object envelope or plain data.
 *
 * @param {object} gebruik A gebruik record.
 * @return {object} The property bag.
 */
function dataOf(gebruik) {
	if (!gebruik || typeof gebruik !== 'object') {
		return {}
	}
	if (gebruik.object && typeof gebruik.object === 'object') {
		return gebruik.object
	}
	return gebruik
}

/**
 * Parse a date value to a Date, or null when blank/unparseable.
 *
 * @param {*} value A date string.
 * @return {Date|null} The parsed date, or null.
 *
 * @spec openspec/specs/application-lifecycle-tracking/spec.md
 */
export function parseDate(value) {
	if (typeof value !== 'string' || value.trim() === '') {
		return null
	}
	const d = new Date(value)
	return Number.isNaN(d.getTime()) ? null : d
}

/**
 * Derive the lifecycle phase of a gebruik at a given moment.
 *
 * Walks the phase steps from most → least advanced and returns the first phase
 * whose start date is set and not in the future. Out-of-order dates are
 * tolerated (the most advanced past date wins). No past phase date → `Onbekend`.
 *
 * @param {object} gebruik A gebruik record (OR object or data bag).
 * @param {Date}   [now]   Reference moment (defaults to now).
 * @return {string} The derived phase (one of PHASE.*).
 *
 * @spec openspec/specs/application-lifecycle-tracking/spec.md
 */
export function derivePhase(gebruik, now = new Date()) {
	const data = dataOf(gebruik)
	for (let i = PHASE_STEPS.length - 1; i >= 0; i--) {
		const date = parseDate(data[PHASE_STEPS[i].field])
		if (date !== null && date <= now) {
			return PHASE_STEPS[i].phase
		}
	}
	return PHASE.UNKNOWN
}

/**
 * Resolve the UUID of a relation value (string, object, or null).
 *
 * @param {*} value A relation value.
 * @return {string} The resolved UUID, or ''.
 *
 * @spec openspec/specs/application-lifecycle-tracking/spec.md
 */
export function resolveUuid(value) {
	if (typeof value === 'string') {
		return value.trim()
	}
	if (value && typeof value === 'object') {
		const uuid = value.uuid ?? value.id ?? value['@self']?.id ?? ''
		return typeof uuid === 'string' ? uuid.trim() : String(uuid || '')
	}
	return ''
}

/**
 * Derive end-of-support state from a moduleVersie record.
 *
 * Returns `{ passed, withdrawn, endDate, withdrawnDate }`:
 *   - `passed`       — `datumEindeOndersteuning` is in the past;
 *   - `withdrawn`    — `datumTeruggetrokken` is set (any date);
 *   - `endDate`/`withdrawnDate` — the raw date strings for display.
 *
 * @param {object} moduleVersie A moduleVersie record (OR object or data bag).
 * @param {Date}   [now]        Reference moment.
 * @return {{passed: boolean, withdrawn: boolean, endDate: (string|null), withdrawnDate: (string|null)}} EOL state.
 *
 * @spec openspec/specs/application-lifecycle-tracking/spec.md
 */
export function endOfSupportState(moduleVersie, now = new Date()) {
	const data = dataOf(moduleVersie)
	const endRaw =
		typeof data.dateEndSupport === 'string' ? data.dateEndSupport : null
	const withdrawnRaw =
		typeof data.dateWithdrawn === 'string' && data.dateWithdrawn.trim() !== ''
			? data.dateWithdrawn
			: null
	const end = parseDate(endRaw)
	return {
		passed: end !== null && end <= now,
		withdrawn: withdrawnRaw !== null,
		endDate: endRaw,
		withdrawnDate: withdrawnRaw,
	}
}

/**
 * Whether a moduleVersie's end-of-support falls within the approaching window.
 *
 * "Approaching" = `datumEindeOndersteuning` is in the future but within
 * `windowDays` days from `now`. A passed end-of-support is NOT "approaching"
 * (it is already past — surfaced by endOfSupportState().passed).
 *
 * @param {object} moduleVersie A moduleVersie record.
 * @param {number} windowDays   The look-ahead window in days.
 * @param {Date}   [now]        Reference moment.
 * @return {boolean} True when end-of-support is within the window.
 *
 * @spec openspec/specs/application-lifecycle-tracking/spec.md
 */
export function isEolApproaching(moduleVersie, windowDays, now = new Date()) {
	const end = parseDate(dataOf(moduleVersie).dateEndSupport)
	if (end === null) {
		return false
	}
	const horizon = new Date(now.getTime() + windowDays * 24 * 60 * 60 * 1000)
	return end > now && end <= horizon
}

/**
 * Phase ordering index for grouping/sorting (UNKNOWN sorts first by convention).
 *
 * @param {string} phase A PHASE.* value.
 * @return {number} A sortable index (0 = unknown, then phase order).
 *
 * @spec openspec/specs/application-lifecycle-tracking/spec.md
 */
export function phaseOrder(phase) {
	if (phase === PHASE.UNKNOWN) {
		return 0
	}
	const idx = PHASE_STEPS.findIndex((s) => s.phase === phase)
	return idx === -1 ? 0 : idx + 1
}
