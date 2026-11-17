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
 * property names. A field or relation with `multiple` takes a list; a
 * relation with `step: 'details'` is asked on the details step instead of the
 * related step; `query` narrows the records a relation offers.
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
	module: Object.freeze({
		schema: 'module',
		detailRoute: 'ModuleDetail',
		title: () => t('stackiq', 'New application'),
		submitLabel: () => t('stackiq', 'Create application'),
		successText: () => t('stackiq', 'Application created.'),
		relatedLabel: () => t('stackiq', 'Reference components'),
		fields: Object.freeze([
			Object.freeze({ key: 'name', label: () => t('stackiq', 'Name'), required: true }),
			Object.freeze({ key: 'shortDescription', label: () => t('stackiq', 'Short description') }),
			Object.freeze({ key: 'type', label: () => t('stackiq', 'Type'), options: ['Application', 'System software'] }),
			Object.freeze({ key: 'licentietype', label: () => t('stackiq', 'License type'), options: ['Closed source', 'Open source'] }),
		]),
		relations: Object.freeze([
			Object.freeze({ key: 'referenceComponents', label: () => t('stackiq', 'Reference components'), schema: 'element', multiple: true, query: Object.freeze({ gemmaType: 'referentiecomponent' }) }),
		]),
	}),
	catalogService: Object.freeze({
		schema: 'catalogService',
		detailRoute: '',
		title: () => t('stackiq', 'New service'),
		submitLabel: () => t('stackiq', 'Create service'),
		successText: () => t('stackiq', 'Service created.'),
		relatedLabel: () => t('stackiq', 'Applications'),
		fields: Object.freeze([
			Object.freeze({ key: 'name', label: () => t('stackiq', 'Name'), required: true }),
			Object.freeze({ key: 'shortDescription', label: () => t('stackiq', 'Short description') }),
			Object.freeze({ key: 'type', label: () => t('stackiq', 'Service type'), required: true, multiple: true, options: ['Functional management', 'Application management', 'Technical management', 'Implementation support', 'Training', 'Licence reseller'] }),
		]),
		relations: Object.freeze([
			Object.freeze({ key: 'provider', label: () => t('stackiq', 'Provider'), schema: 'organization', required: true, step: 'details' }),
			Object.freeze({ key: 'modules', label: () => t('stackiq', 'Applications the service runs on'), schema: 'module', multiple: true }),
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
		data[field.key] = field.multiple ? [] : ''
	}
	for (const relation of config.relations) {
		data[relation.key] = relation.multiple ? [] : null
	}
	return data
}

/**
 * The relations a step asks for: those with `step: 'details'` on the details
 * step, every other one on the related step.
 *
 * @param {string} kind The kind.
 * @param {string} stepId `details` or `related`.
 * @return {Array<object>} The relations of that step.
 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/guided-entry-wizard/spec.md#requirement-req-gew-001-applications-services-and-connections-are-added-through-a-step-by-step-wizard
 */
export function wizardRelations(kind, stepId) {
	return wizardKind(kind).relations.filter((relation) => (relation.step || 'related') === stepId)
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
		const empty = value === null || value === undefined
			|| (typeof value === 'string' && value.trim() === '')
			|| (Array.isArray(value) && value.length === 0)
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
		missing = firstMissing(config.fields, data) ?? firstMissing(wizardRelations(kind, 'details'), data)
	} else if (stepId === 'related') {
		missing = firstMissing(wizardRelations(kind, 'related'), data)
	}
	if (missing === null) {
		return true
	}
	return t('stackiq', '{field} is required.', { field: missing })
}

/**
 * The trimmed text of a field, or the chosen values of a multiple field.
 *
 * @param {object} field The field.
 * @param {string|Array<string>|undefined} value What was entered.
 * @return {string|Array<string>} The value; '' or [] for nothing entered.
 */
function fieldValue(field, value) {
	if (field.multiple) {
		return Array.isArray(value) ? value.filter((item) => typeof item === 'string' && item !== '') : []
	}
	return typeof value === 'string' ? value.trim() : ''
}

/**
 * The ids of the chosen records of a relation: one id, or a list for a multiple relation.
 *
 * @param {object} relation The relation.
 * @param {object|Array<object>|null|undefined} chosen The chosen option or options.
 * @return {string|Array<string>|null} The id(s); null or [] for nothing chosen.
 */
function relationIds(relation, chosen) {
	if (relation.multiple) {
		return Array.isArray(chosen) ? chosen.map((option) => option?.uuid).filter(Boolean) : []
	}
	return chosen?.uuid || null
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
		const value = fieldValue(field, data?.[field.key])
		if (value.length > 0) {
			payload[field.key] = value
		}
	}
	for (const relation of config.relations) {
		const ids = relationIds(relation, data?.[relation.key])
		if (ids !== null && ids.length > 0) {
			payload[relation.key] = ids
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
	const fields = config.fields.map((field) => {
		const value = fieldValue(field, data?.[field.key])
		return { key: field.key, label: field.label(), value: Array.isArray(value) ? value.join(', ') : value }
	})
	const relations = config.relations.map((relation) => {
		const chosen = data?.[relation.key]
		const value = relation.multiple
			? (Array.isArray(chosen) ? chosen.map((option) => option?.label).filter(Boolean).join(', ') : '')
			: (chosen?.label ?? '')
		return { key: relation.key, label: relation.label(), value }
	})
	return [...fields, ...relations]
}
