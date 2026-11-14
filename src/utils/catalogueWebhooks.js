/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The catalogue webhooks of the exchange settings (platform-properties,
 * REQ-PLAT-005): which OpenRegister webhooks belong to the catalogue, and the
 * webhook a form describes.
 *
 * OpenRegister delivers a webhook for every event class it lists, when every
 * filter matches the event payload; a list filter value means "one of these".
 * So a catalogue webhook is "these actions" (created, changed, deleted) on
 * "these catalogue entries" (applications, contracts, applications in use),
 * filtered to the catalogue register and the schemas of those entries.
 */

/**
 * What a catalogue webhook can follow, with the configuration key that holds its schema id.
 *
 * @type {Array<{id: string, configKey: string}>}
 */
export const WEBHOOK_SUBJECTS = [
	{ id: 'module', configKey: 'module_schema' },
	{ id: 'contract', configKey: 'contract_schema' },
	{ id: 'usage', configKey: 'gebruik_schema' },
]

/**
 * What happens to an entry, with the OpenRegister event class that reports it.
 *
 * @type {Array<{id: string, event: string}>}
 */
export const WEBHOOK_ACTIONS = [
	{ id: 'created', event: 'OCA\\OpenRegister\\Event\\ObjectCreatedEvent' },
	{ id: 'updated', event: 'OCA\\OpenRegister\\Event\\ObjectUpdatedEvent' },
	{ id: 'deleted', event: 'OCA\\OpenRegister\\Event\\ObjectDeletedEvent' },
]

/**
 * An id as a string, or '' for none.
 *
 * @param {string|number|null|undefined} value The id.
 * @return {string} The id.
 */
const idOf = (value) => (value === null || value === undefined || value === 0 || value === '0' ? '' : String(value))

/**
 * A filter value as a list of strings.
 *
 * @param {string|number|Array<string|number>|null|undefined} value A scalar, a list or nothing.
 * @return {Array<string>} The values.
 */
const listOf = (value) => (Array.isArray(value) ? value : [value]).map(idOf).filter(Boolean)

/**
 * The catalogue register and the schema of each subject, from the catalogue configuration.
 *
 * @param {object} config The `config` of `GET /api/voorzieningen/config`.
 * @return {{register: string, schemas: Object<string, string>}} The scope; '' where an id is not configured.
 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
 */
export function catalogueScope(config = {}) {
	const schemas = {}
	for (const subject of WEBHOOK_SUBJECTS) {
		schemas[subject.id] = idOf(config?.[subject.configKey])
	}
	return { register: idOf(config?.register), schemas }
}

/**
 * Whether a webhook is filtered to the catalogue register.
 *
 * @param {object} webhook An OpenRegister webhook.
 * @param {{register: string}} scope The catalogue scope.
 * @return {boolean}
 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
 */
export function isCatalogueWebhook(webhook, scope) {
	if (!scope?.register) {
		return false
	}
	return listOf(webhook?.filters?.register).includes(scope.register)
}

/**
 * The form of a webhook: its name, address, the actions and the subjects it follows.
 *
 * @param {object|null} webhook An OpenRegister webhook, or null for a new one.
 * @param {{schemas: Object<string, string>}} scope The catalogue scope.
 * @return {{name: string, url: string, enabled: boolean, actions: Array<string>, subjects: Array<string>}} The form.
 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
 */
export function webhookForm(webhook, scope) {
	if (!webhook) {
		return { name: '', url: '', enabled: true, actions: ['updated'], subjects: [] }
	}
	const events = Array.isArray(webhook.events) ? webhook.events : []
	const schemaIds = listOf(webhook.filters?.schema)
	return {
		name: String(webhook.name || ''),
		url: String(webhook.url || ''),
		enabled: webhook.enabled !== false,
		actions: WEBHOOK_ACTIONS.filter((action) => events.includes(action.event)).map((action) => action.id),
		subjects: WEBHOOK_SUBJECTS
			.filter((subject) => scope?.schemas?.[subject.id] && schemaIds.includes(scope.schemas[subject.id]))
			.map((subject) => subject.id),
	}
}

/**
 * What is wrong with a form, or '' when it can be saved.
 *
 * @param {{url: string, actions: Array<string>, subjects: Array<string>}} form The form.
 * @param {{register: string, schemas: Object<string, string>}} scope The catalogue scope.
 * @return {string} The problem: `url`, `actions`, `subjects`, `scope`, or ''.
 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
 */
export function formProblem(form, scope) {
	if (!/^https?:\/\/[^\s/]+/i.test(String(form?.url || '').trim())) {
		return 'url'
	}
	if (!form.actions?.length) {
		return 'actions'
	}
	if (!form.subjects?.length) {
		return 'subjects'
	}
	if (!scope?.register || form.subjects.some((subject) => !scope.schemas?.[subject])) {
		return 'scope'
	}
	return ''
}

/**
 * The OpenRegister webhook a form describes, filtered to the catalogue register and the subjects' schemas.
 *
 * Both filters are lists, so OpenRegister compares them as "one of these"
 * whatever type the payload carries the ids in.
 *
 * @param {{name: string, url: string, enabled: boolean, actions: Array<string>, subjects: Array<string>}} form The form.
 * @param {{register: string, schemas: Object<string, string>}} scope The catalogue scope.
 * @param {object} [existing] The webhook being edited; its other settings are kept.
 * @return {object} The webhook body for `POST` or `PUT /api/webhooks`.
 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
 */
export function webhookBody(form, scope, existing = {}) {
	const url = String(form.url).trim()
	const filters = { ...(existing?.filters || {}) }
	filters.register = [scope.register]
	filters.schema = WEBHOOK_SUBJECTS.filter((subject) => form.subjects.includes(subject.id)).map((subject) => scope.schemas[subject.id])
	return {
		name: String(form.name || '').trim() || url,
		url,
		method: existing?.method || 'POST',
		enabled: form.enabled !== false,
		events: WEBHOOK_ACTIONS.filter((action) => form.actions.includes(action.id)).map((action) => action.event),
		filters,
	}
}

/**
 * The last delivery of a webhook: when, and whether it arrived.
 *
 * @param {object} webhook An OpenRegister webhook.
 * @return {{at: string, ok: boolean}|null} The delivery, or null when none was made.
 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
 */
export function lastDelivery(webhook) {
	const at = webhook?.lastTriggeredAt
	if (!at) {
		return null
	}
	const success = Date.parse(webhook.lastSuccessAt || '') || 0
	const failure = Date.parse(webhook.lastFailureAt || '') || 0
	return { at, ok: success >= failure && success > 0 }
}
