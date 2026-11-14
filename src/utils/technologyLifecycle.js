/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The support state of a technology component, and the worst state an
 * application in use is exposed to through the components it runs on
 * (operations-technology-components, REQ-TCO-003).
 *
 * A component is `ended` once its end of support has passed, `ending` within
 * 180 days of it, `supported` before that, and `unknown` without a date. The
 * exposure of an application in use is the worst state over the components it
 * runs on and the components those run on, one level down: a database on a
 * virtual machine on a host past its support is exposed through the host.
 *
 * @spec openspec/changes/operations-technology-components/specs/technology-components/spec.md#requirement-req-tco-003-an-application-in-use-shall-record-the-components-it-runs-on-and-show-their-support-state
 */

/**
 * Days before the end of support from which a component is `ending`.
 *
 * @type {number}
 */
export const ENDING_WITHIN_DAYS = 180

/**
 * The states from best to worst. An unknown date is worse than a known
 * supported one: nobody can say it is safe.
 *
 * @type {string[]}
 */
export const STATES = ['supported', 'unknown', 'ending', 'ended']

const DAY_MS = 24 * 60 * 60 * 1000

/**
 * A `YYYY-MM-DD` (or ISO date-time) string as the UTC midnight it names, or null.
 *
 * @param {unknown} value The date.
 * @return {number|null} Milliseconds since the epoch.
 */
function dayOf(value) {
	if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}/.test(value)) {
		return null
	}
	const time = Date.UTC(Number(value.slice(0, 4)), Number(value.slice(5, 7)) - 1, Number(value.slice(8, 10)))
	return Number.isNaN(time) ? null : time
}

/**
 * Today as a `YYYY-MM-DD` string in UTC.
 *
 * @param {Date} date The moment.
 * @return {string}
 */
function isoDay(date) {
	return date.toISOString().slice(0, 10)
}

/**
 * The support state of one component on a day.
 *
 * @param {{endOfSupport?: string}|null|undefined} component The component.
 * @param {string|Date} [today] The day to judge on (defaults to now).
 * @return {'supported'|'ending'|'ended'|'unknown'}
 * @spec openspec/changes/operations-technology-components/specs/technology-components/spec.md#requirement-req-tco-003-an-application-in-use-shall-record-the-components-it-runs-on-and-show-their-support-state
 */
export function supportState(component, today = new Date()) {
	const end = dayOf(component?.endOfSupport)
	const now = dayOf(today instanceof Date ? isoDay(today) : today)
	if (end === null || now === null) {
		return 'unknown'
	}
	if (end < now) {
		return 'ended'
	}
	if (end - now <= ENDING_WITHIN_DAYS * DAY_MS) {
		return 'ending'
	}
	return 'supported'
}

/**
 * The worse of two states.
 *
 * @param {string} left A state.
 * @param {string} right Another.
 * @return {string}
 */
export function worse(left, right) {
	return STATES.indexOf(right) > STATES.indexOf(left) ? right : left
}

/**
 * A reference to a component as its id: a string, or an object with `id` or `uuid`.
 *
 * @param {unknown} reference The reference.
 * @return {string|null}
 */
function idOf(reference) {
	if (typeof reference === 'string' && reference !== '') {
		return reference
	}
	if (reference && typeof reference === 'object') {
		return reference.id || reference.uuid || reference['@self']?.id || null
	}
	return null
}

/**
 * The worst state over the components an application in use runs on and,
 * one level down, the components those run on. Without components the state
 * is `unknown`.
 *
 * @param {Array<object|string>} runsOn The usage's `runsOn`: components or their ids.
 * @param {Object<string, object>} componentsById Every known component by id, to resolve references.
 * @param {string|Date} [today] The day to judge on (defaults to now).
 * @return {{state: string, components: Array<{id: string|null, name: string, state: string, through: string|null}>}}
 * @spec openspec/changes/operations-technology-components/specs/technology-components/spec.md#requirement-req-tco-003-an-application-in-use-shall-record-the-components-it-runs-on-and-show-their-support-state
 */
export function exposure(runsOn, componentsById = {}, today = new Date()) {
	const rows = []
	const resolve = (reference) => {
		const id = idOf(reference)
		const known = id !== null ? componentsById[id] : undefined
		if (known) {
			return { id, component: known }
		}
		return { id, component: reference && typeof reference === 'object' ? reference : null }
	}

	for (const reference of Array.isArray(runsOn) ? runsOn : []) {
		const { id, component } = resolve(reference)
		rows.push({ id, name: component?.name ?? id ?? '', state: supportState(component, today), through: null })
		for (const lower of Array.isArray(component?.runsOn) ? component.runsOn : []) {
			const below = resolve(lower)
			rows.push({ id: below.id, name: below.component?.name ?? below.id ?? '', state: supportState(below.component, today), through: id })
		}
	}

	if (rows.length === 0) {
		return { state: 'unknown', components: [] }
	}

	return { state: rows.map((row) => row.state).reduce(worse, 'supported'), components: rows }
}
