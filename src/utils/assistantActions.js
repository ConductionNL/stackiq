/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Assistant actions: the assistant tool calls on catalogue records, read from
 * OpenRegister's audit trail. OpenRegister filters server side on the action
 * prefix `mcp.` and the tool prefix `stackiq.`, and pages by cursor; this
 * module builds that request and shapes each audit row for the page. Stackiq
 * keeps no copy of the trail.
 *
 * @spec openspec/changes/archive/2026-11-24-insight-assistant-answers-and-action-trail/specs/assistant-insight/spec.md#requirement-req-ask-003-assistant-actions-on-catalogue-records-are-listed
 */

/** The OpenRegister audit list, relative to the instance root. */
export const AUDIT_TRAIL_PATH = '/apps/openregister/api/audit-trails'

/** Every assistant tool call is audited as `mcp.<verb>`. */
export const ACTION_PREFIX = 'mcp.'

/** Stackiq's own tools. */
export const TOOL_PREFIX = 'stackiq.'

/** Rows per page. */
export const PAGE_SIZE = 50

/**
 * The query parameters of one page of assistant actions.
 *
 * `cursor` is always sent, empty for the first page, because OpenRegister
 * answers the keyset-paged list only when it is present.
 *
 * @param {{tool?: string, user?: string, from?: string, to?: string}} filters The page's filters.
 * @param {number|null} cursor The cursor of the page, null for the first.
 * @return {Record<string, string|number>}
 */
export function actionsQuery(filters = {}, cursor = null) {
	const params = {
		cursor: cursor === null || cursor === undefined ? '' : cursor,
		limit: PAGE_SIZE,
		actionPrefix: ACTION_PREFIX,
		toolPrefix: TOOL_PREFIX,
	}
	const tool = String(filters.tool ?? '').trim()
	if (tool !== '') {
		// A bare tool name is read as one of stackiq's tools.
		params.tool = tool.startsWith(TOOL_PREFIX) ? tool : TOOL_PREFIX + tool
	}
	const user = String(filters.user ?? '').trim()
	if (user !== '') {
		params.actor = user
	}
	for (const key of ['from', 'to']) {
		const value = String(filters[key] ?? '').trim()
		if (value !== '') {
			params[key] = value
		}
	}
	return params
}

/**
 * The detail route of each schema that has one, from the manifest's detail pages.
 *
 * @param {object} manifest The app manifest.
 * @return {Record<string, string>} Schema slug to route name.
 */
export function detailRoutes(manifest) {
	const routes = {}
	for (const page of manifest?.pages ?? []) {
		const schema = page?.config?.schema
		if (page?.type === 'detail' && typeof schema === 'string' && schema !== '' && !(schema in routes)) {
			routes[schema] = page.id
		}
	}
	return routes
}

/**
 * Whether the call succeeded, from the audit row's result summary.
 *
 * @param {object|null} summary The row's `resultSummary`.
 * @return {{ok: boolean, error: string}}
 */
export function outcomeOf(summary) {
	const failed = summary !== null && typeof summary === 'object' && summary.isError === true
	return {
		ok: !failed,
		error: failed && typeof summary.errorClass === 'string' ? summary.errorClass : '',
	}
}

/**
 * One audit row as the page lists it.
 *
 * @param {object} entry A row of the audit list.
 * @return {{id: string, when: string, user: string, tool: string, schemaId: string, recordId: string, outcome: {ok: boolean, error: string}}}
 */
export function readAction(entry) {
	return {
		id: String(entry?.id ?? entry?.uuid ?? ''),
		when: String(entry?.created ?? ''),
		user: String(entry?.userName || entry?.user || ''),
		tool: String(entry?.toolId ?? ''),
		schemaId: entry?.schema === null || entry?.schema === undefined ? '' : String(entry.schema),
		recordId: String(entry?.objectUuid ?? ''),
		outcome: outcomeOf(entry?.resultSummary ?? null),
	}
}

/**
 * One page of the audit list as the page shows it.
 *
 * @param {object} data The JSON the audit list answered with.
 * @return {{rows: Array, nextCursor: number|null}}
 */
export function readActionsPage(data) {
	const rows = Array.isArray(data?.results) ? data.results.map(readAction) : []
	const next = data?.nextCursor
	return { rows, nextCursor: next === null || next === undefined || next === '' ? null : Number(next) }
}

/**
 * Where the record of a row opens, or null when it has no record or the
 * record's schema has no page in stackiq.
 *
 * @param {{schemaId: string, recordId: string}} row A row from readAction().
 * @param {Record<string, string>} schemaSlugs Schema id to schema slug.
 * @param {Record<string, string>} routes Schema slug to route name, from detailRoutes().
 * @return {{name: string, params: {id: string}}|null}
 */
export function recordLocation(row, schemaSlugs, routes) {
	if (!row?.recordId || !row?.schemaId) {
		return null
	}
	const route = routes[schemaSlugs[row.schemaId]]
	return route ? { name: route, params: { id: row.recordId } } : null
}

/**
 * What to tell the user when the list could not be read. OpenRegister lets
 * only administrators read the audit list; anyone else gets a 403.
 *
 * @param {object} error The axios error.
 * @param {(text: string) => string} t The translate function, bound to the app.
 * @return {string}
 */
export function actionsFailure(error, t) {
	const status = error?.response?.status
	const message = error?.response?.data?.error
	if (status === 401 || status === 403) {
		return t('Only administrators can review assistant actions.')
	}
	if (status === 400 && typeof message === 'string' && message !== '') {
		return message
	}
	return t('The assistant actions could not be loaded.')
}
