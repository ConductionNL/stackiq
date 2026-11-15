/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * Move to organisation: the bulk-action handlers that open the dialog, the
 * request body of the plan and execute endpoints, and the dry run split into
 * what moves, what is skipped and what stays behind.
 *
 * @module services/moveToOrganisation
 * @copyright 2026 Conduction B.V.
 * @license EUPL-1.2
 *
 * @spec openspec/changes/landscape-move-between-organisations
 */

/** The dialog name the handlers open in the navigation store. */
export const MOVE_DIALOG = 'moveToOrganisation'

/**
 * The bulk-action handler names, per schema whose entries can move. A list
 * page names one of them in its `bulkActions[].handler`.
 */
export const MOVE_HANDLERS = {
	moveModulesToOrganisation: 'module',
	moveServicesToOrganisation: 'catalogService',
	moveUsagesToOrganisation: 'usage',
	moveConnectionsToOrganisation: 'connection',
	moveContractsToOrganisation: 'catalogContract',
}

/**
 * The bulk-action handlers: each opens the dialog with its schema and the
 * selected ids. An empty selection opens nothing.
 *
 * @param {Function} setDialog The navigation store's `setDialog(name, properties)`.
 * @return {Object<string, Function>} Handler name to `(scope) => void`.
 * @spec openspec/changes/landscape-move-between-organisations
 */
export function createMoveHandlers(setDialog) {
	const handlers = {}
	for (const [name, schema] of Object.entries(MOVE_HANDLERS)) {
		handlers[name] = (scope) => {
			const ids = Array.isArray(scope?.selectedIds) ? scope.selectedIds.map(String) : []
			if (ids.length === 0) {
				return
			}
			setDialog(MOVE_DIALOG, { schema, ids })
		}
	}
	return handlers
}

/**
 * The registry key of the launcher a detail page's header action opens.
 * The launcher reads the record from the route and opens the dialog.
 */
export const MOVE_LAUNCHER = 'MoveToOrganisationLauncher'

/**
 * Open the dialog for the one record a detail page shows.
 *
 * A detail page's header action cannot reach a named app handler, and an
 * `open-modal` action's props are passed as written, so the record id comes
 * from the route instead of an `@objectId` token. Without a schema or an id
 * nothing opens.
 *
 * @param {(name: string, properties: object) => void} setDialog The navigation store's `setDialog(name, properties)`.
 * @param {string} schema The schema of the record.
 * @param {string|number|undefined} id The record's id, from the route.
 * @return {boolean} Whether the dialog was opened.
 * @spec openspec/changes/landscape-move-between-organisations
 */
export function openMoveForRecord(setDialog, schema, id) {
	if (!schema || id === undefined || id === null || String(id) === '') {
		return false
	}
	setDialog(MOVE_DIALOG, { schema, ids: [String(id)] })
	return true
}

/**
 * The body of a plan or execute request.
 *
 * @param {string} schema The schema of the chosen entries.
 * @param {Array<string>} ids Their ids.
 * @param {string} source The organisation they move from.
 * @param {string} target The organisation they move to.
 * @return {{objects: Array<{schema: string, id: string}>, sourceOrganisation: string, targetOrganisation: string}} The body.
 * @spec openspec/changes/landscape-move-between-organisations
 */
export function transferBody(schema, ids, source, target) {
	return {
		objects: ids.map((id) => ({ schema, id: String(id) })),
		sourceOrganisation: source,
		targetOrganisation: target,
	}
}

/**
 * The organisations a user can move entries to: their own, without the
 * source, as select options labelled by name.
 *
 * @param {Array<object>} organisations The user's organisations (`/api/me`).
 * @param {?string} source The source organisation's uuid.
 * @return {Array<{value: string, label: string}>} The options, by label.
 * @spec openspec/changes/landscape-move-between-organisations
 */
export function targetOptions(organisations, source) {
	return (organisations || [])
		.filter((org) => org && org.uuid && org.uuid !== source)
		.map((org) => ({ value: org.uuid, label: org.name || org.uuid }))
		.sort((a, b) => a.label.localeCompare(b.label))
}

/**
 * The dry run, split for the dialog.
 *
 * @param {object} plan The plan endpoint's answer: `items` and `staysBehind`.
 * @return {{moving: Array<object>, skipped: Array<object>, staysBehind: Array<object>}} The split.
 * @spec openspec/changes/landscape-move-between-organisations/specs/move-between-organisations/spec.md#requirement-req-mbo-003-the-dry-run-shows-what-moves-and-what-stays-behind
 */
export function splitPlan(plan) {
	const items = Array.isArray(plan?.items) ? plan.items : []
	return {
		moving: items.filter((item) => item.action === 'move'),
		skipped: items.filter((item) => item.action !== 'move'),
		staysBehind: Array.isArray(plan?.staysBehind) ? plan.staysBehind : [],
	}
}

/**
 * The label of a planned entry: its name, else its id.
 *
 * @param {object} item A planned or staying entry.
 * @return {string} The label.
 * @spec openspec/changes/landscape-move-between-organisations
 */
export function entryLabel(item) {
	return item?.name ? String(item.name) : String(item?.id ?? '')
}
