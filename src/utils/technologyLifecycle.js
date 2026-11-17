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
	return stateBetween(end, now)
}

/**
 * The support state for an end of support and a day, both as UTC midnights.
 *
 * @param {number|null} end The end of support, null when unknown.
 * @param {number|null} now The day to judge on, null when unreadable.
 * @return {'supported'|'ending'|'ended'|'unknown'}
 */
function stateBetween(end, now) {
	if (end === null || now === null) {
		return 'unknown'
	}
	if (end < now) {
		return 'ended'
	}
	return end - now <= ENDING_WITHIN_DAYS * DAY_MS ? 'ending' : 'supported'
}

/**
 * The worse of two states.
 *
 * @param {string} left A state.
 * @param {string} right Another.
 * @return {string}
 *
 * @spec openspec/changes/operations-technology-components/specs/technology-components/spec.md#requirement-req-tco-003-an-application-in-use-shall-record-the-components-it-runs-on-and-show-their-support-state
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
	if (typeof reference === 'string') {
		return reference !== '' ? reference : null
	}
	return isObject(reference) ? idInObject(reference) : null
}

/**
 * The id an object reference carries: its `id`, `uuid` or `@self.id`.
 *
 * @param {object} reference The reference.
 * @return {string|null}
 */
function idInObject(reference) {
	return reference.id || reference.uuid || reference['@self']?.id || null
}

/**
 * Whether a value is a non-null object.
 *
 * @param {unknown} value The value.
 * @return {boolean}
 */
function isObject(value) {
	return Boolean(value) && typeof value === 'object'
}

/**
 * A value as a list: itself when it is an array, else empty.
 *
 * @param {unknown} value The value.
 * @return {Array}
 */
function listOf(value) {
	return Array.isArray(value) ? value : []
}

/**
 * A reference resolved against the known components: the known component,
 * else the reference itself when it is an object, else null.
 *
 * @param {Object<string, object>} componentsById Every known component by id.
 * @param {unknown} reference The reference.
 * @return {{id: string|null, component: object|null}}
 */
function resolveIn(componentsById, reference) {
	const id = idOf(reference)
	const known = id !== null ? componentsById[id] : undefined
	if (known) {
		return { id, component: known }
	}
	return { id, component: isObject(reference) ? reference : null }
}

/**
 * One exposure row of a resolved component.
 *
 * @param {{id: string|null, component: object|null}} resolved The resolved component.
 * @param {{today: string|Date, through: string|null}} context The day to judge on and the component it is reached through.
 * @return {{id: string|null, name: string, state: string, through: string|null}}
 */
function rowOf(resolved, context) {
	const { id, component } = resolved
	return { id, name: component?.name ?? id ?? '', state: supportState(component, context.today), through: context.through }
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
	return exposureOf(runsOn, { componentsById, today })
}

/**
 * The exposure of `runsOn` against the known components on a day (see exposure()).
 *
 * @param {Array<object|string>} runsOn The usage's `runsOn`: components or their ids.
 * @param {{componentsById: Object<string, object>, today: string|Date}} context The known components and the day.
 * @return {{state: string, components: Array<{id: string|null, name: string, state: string, through: string|null}>}}
 */
function exposureOf(runsOn, context) {
	const rows = []
	for (const reference of listOf(runsOn)) {
		const upper = resolveIn(context.componentsById, reference)
		rows.push(rowOf(upper, { today: context.today, through: null }))
		for (const lower of listOf(upper.component?.runsOn)) {
			rows.push(rowOf(resolveIn(context.componentsById, lower), { today: context.today, through: upper.id }))
		}
	}

	if (rows.length === 0) {
		return { state: 'unknown', components: [] }
	}

	return { state: rows.map((row) => row.state).reduce(worse, 'supported'), components: rows }
}

/**
 * The data of an object the store returned: its `object` when wrapped.
 *
 * @param {object|null|undefined} value The object.
 * @return {object|null}
 */
function dataOf(value) {
	if (!value || typeof value !== 'object') {
		return null
	}
	return value.object && typeof value.object === 'object' ? value.object : value
}

/**
 * Load the exposure of one application in use: the usage, the components it
 * runs on and, one level down, the components those run on. A component that
 * cannot be loaded keeps its reference and reads `unknown`.
 *
 * @param {string} usageId The usage id.
 * @param {function(string, string): Promise<object|null>} fetchObject Loads one object by schema slug and id.
 * @param {string|Date} [today] The day to judge on (defaults to now).
 * @return {Promise<{state: string, components: Array<{id: string|null, name: string, state: string, through: string|null, endOfSupport: string|null}>}>}
 * @spec openspec/changes/operations-technology-components/specs/technology-components/spec.md#requirement-req-tco-003-an-application-in-use-shall-record-the-components-it-runs-on-and-show-their-support-state
 */
export async function loadUsageExposure(usageId, fetchObject, today = new Date()) {
	return loadExposure(usageId, { fetchObject, today })
}

/**
 * Load the exposure of one application in use (see loadUsageExposure()).
 *
 * @param {string} usageId The usage id.
 * @param {{fetchObject: function(string, string): Promise<object|null>, today: string|Date}} context The loader and the day.
 * @return {Promise<{state: string, components: Array<{id: string|null, name: string, state: string, through: string|null, endOfSupport: string|null}>}>}
 */
async function loadExposure(usageId, context) {
	const usage = dataOf(await context.fetchObject('usage', usageId))
	const runsOn = listOf(usage?.runsOn)
	const loader = { fetchObject: context.fetchObject, componentsById: {} }

	for (const reference of runsOn) {
		const component = await loadComponent(reference, loader)
		for (const lower of listOf(component?.runsOn)) {
			await loadComponent(lower, loader)
		}
	}

	const result = exposureOf(runsOn, { componentsById: loader.componentsById, today: context.today })
	return {
		state: result.state,
		components: result.components.map((row) => withEndOfSupport(row, loader.componentsById)),
	}
}

/**
 * A row with the end of support of its loaded component, or null.
 *
 * @param {{id: string|null}} row The exposure row.
 * @param {Object<string, object>} componentsById The loaded components by id.
 * @return {object}
 */
function withEndOfSupport(row, componentsById) {
	return { ...row, endOfSupport: (row.id !== null ? componentsById[row.id]?.endOfSupport : null) || null }
}

/**
 * Load one referenced component once into `loader.componentsById`.
 *
 * @param {unknown} reference The reference.
 * @param {{fetchObject: function(string, string): Promise<object|null>, componentsById: Object<string, object>}} loader The loader and what it loaded.
 * @return {Promise<object|null>} The component, or null when it cannot be loaded.
 */
async function loadComponent(reference, loader) {
	const id = idOf(reference)
	if (id !== null && !loader.componentsById[id]) {
		await fetchInto(id, loader)
	}
	return loader.componentsById[id] ?? null
}

/**
 * Fetch one component into `loader.componentsById`. An unreadable component
 * stays a reference and reads unknown.
 *
 * @param {string} id The component id.
 * @param {{fetchObject: function(string, string): Promise<object|null>, componentsById: Object<string, object>}} loader The loader and what it loaded.
 * @return {Promise<void>}
 */
async function fetchInto(id, loader) {
	try {
		const component = dataOf(await loader.fetchObject('technologyComponent', id))
		if (component) {
			loader.componentsById[id] = component
		}
	} catch {
		// An unreadable component stays a reference and reads unknown.
	}
}
