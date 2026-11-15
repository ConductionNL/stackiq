/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The guided entry wizard (capability-requirements-from-the-matrix task 1):
 * what each kind of record asks on its details step, which related records it
 * links on the next step, and the record it saves. One dialog
 * (`src/dialogs/EntryWizardDialog.vue`) reads these, so a kind is added by
 * configuration, not by another dialog.
 *
 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/guided-entry-wizard/spec.md#requirement-req-gew-001-applications-services-and-connections-are-added-through-a-step-by-step-wizard
 */

/**
 * The kinds the wizard adds. Field and relation keys are the schema's own
 * property names.
 */
export const ENTRY_WIZARD_KINDS = Object.freeze({
	connection: Object.freeze({
		schema: 'connection',
		detailRoute: 'KoppelingDetail',
		title: () => t('stackiq', 'New connection'),
		submitLabel: () => t('stackiq', 'Create connection'),
		successText: () => t('stackiq', 'Connection created.'),
		relatedLabel: () => t('stackiq', 'Applications'),
		fields: Object.freeze([
			Object.freeze({ key: 'name', label: () => t('stackiq', 'Name'), required: true }),
			Object.freeze({ key: 'shortDescription', label: () => t('stackiq', 'Short description') }),
			Object.freeze({ key: 'type', label: () => t('stackiq', 'Type'), options: ['n/a', 'file transfer', 'digikoppeling', 'message que', 'upload to portal', 'webservices', 'api'] }),
			Object.freeze({ key: 'status', label: () => t('stackiq', 'Status'), options: ['in development', 'in use', 'end of support', 'withdrawn'] }),
		]),
		relations: Object.freeze([
			Object.freeze({ key: 'moduleA', label: () => t('stackiq', 'From application'), schema: 'module', required: true }),
			Object.freeze({ key: 'moduleB', label: () => t('stackiq', 'To application'), schema: 'module', required: true }),
		]),
	}),
})

/**
 * The configuration of a kind.
 *
 * @param {string} kind The kind, for example `connection`.
 * @return {object} Its configuration.
 * @throws {Error} When the kind is unknown.
 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/guided-entry-wizard/spec.md#requirement-req-gew-001-applications-services-and-connections-are-added-through-a-step-by-step-wizard
 */
export function wizardKind(kind) {
	const config = ENTRY_WIZARD_KINDS[kind]
	if (!config) {
		throw new Error(`Unknown entry wizard kind: ${kind}`)
	}
	return config
}

/**
 * The empty step data of a kind: every field '', every relation null.
 *
 * @param {string} kind The kind.
 * @return {object} The defaults.
 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/guided-entry-wizard/spec.md#requirement-req-gew-001-applications-services-and-connections-are-added-through-a-step-by-step-wizard
 */
export function wizardDefaults(kind) {
	const config = wizardKind(kind)
	const data = {}
	for (const field of config.fields) {
		data[field.key] = ''
	}
	for (const relation of config.relations) {
		data[relation.key] = null
	}
	return data
}

/**
 * The label of the first required field left empty on a step, or null when
 * the step is complete.
 *
 * @param {Array<object>} entries The step's fields or relations.
 * @param {object} data The step data.
 * @return {string|null} The missing entry's label.
 */
function firstMissing(entries, data) {
	for (const entry of entries) {
		const value = data?.[entry.key]
		const empty = value === null || value === undefined || (typeof value === 'string' && value.trim() === '')
		if (entry.required && empty) {
			return entry.label()
		}
	}
	return null
}

/**
 * Whether a step may be left: true, or the message that names the missing field.
 *
 * @param {string} kind The kind.
 * @param {string} stepId `details`, `related` or `confirm`.
 * @param {object} data The step data.
 * @return {true|string} True, or why the step stays open.
 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/guided-entry-wizard/spec.md#requirement-req-gew-001-applications-services-and-connections-are-added-through-a-step-by-step-wizard
 */
export function validateWizardStep(kind, stepId, data) {
	const config = wizardKind(kind)
	let missing = null
	if (stepId === 'details') {
		missing = firstMissing(config.fields, data)
	} else if (stepId === 'related') {
		missing = firstMissing(config.relations, data)
	}
	if (missing === null) {
		return true
	}
	return t('stackiq', '{field} is required.', { field: missing })
}

/**
 * The record the wizard saves: trimmed text fields that are filled, and each
 * chosen related record by its id.
 *
 * @param {string} kind The kind.
 * @param {object} data The step data.
 * @return {object} The object to save.
 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/guided-entry-wizard/spec.md#requirement-req-gew-001-applications-services-and-connections-are-added-through-a-step-by-step-wizard
 */
export function buildWizardPayload(kind, data) {
	const config = wizardKind(kind)
	const payload = {}
	for (const field of config.fields) {
		const value = typeof data?.[field.key] === 'string' ? data[field.key].trim() : ''
		if (value !== '') {
			payload[field.key] = value
		}
	}
	for (const relation of config.relations) {
		const chosen = data?.[relation.key]
		if (chosen?.uuid) {
			payload[relation.key] = chosen.uuid
		}
	}
	return payload
}

/**
 * The rows of the confirmation summary: every field and relation with what was entered.
 *
 * @param {string} kind The kind.
 * @param {object} data The step data.
 * @return {Array<{key: string, label: string, value: string}>} The rows; an empty value for nothing entered.
 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/guided-entry-wizard/spec.md#requirement-req-gew-001-applications-services-and-connections-are-added-through-a-step-by-step-wizard
 */
export function wizardSummary(kind, data) {
	const config = wizardKind(kind)
	const fields = config.fields.map((field) => ({
		key: field.key,
		label: field.label(),
		value: typeof data?.[field.key] === 'string' ? data[field.key].trim() : '',
	}))
	const relations = config.relations.map((relation) => ({
		key: relation.key,
		label: relation.label(),
		value: data?.[relation.key]?.label ?? '',
	}))
	return [...fields, ...relations]
}
