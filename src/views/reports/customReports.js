/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Custom reports (insight-exports-and-custom-reports): a report is an
 * OpenRegister export profile over one catalogue schema. These functions
 * decide which profiles the page lists, build the form a new report is
 * defined in, turn the form's answer into a profile, and name the URLs the
 * page calls. Pure, so the rules are tested without a browser.
 *
 * @spec openspec/changes/archive/2026-11-25-insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-004-a-user-shall-define-run-and-delete-their-own-reports-over-one-catalogue-schema
 */

/** The OpenRegister export profile API. */
const PROFILES_PATH = '/apps/openregister/api/export-profiles'

/** How a report reads values: as stored, or as the list renders them. */
export const VALUE_MODES = ['stored', 'rendered']

/** The formats a report is written in. */
export const FORMATS = ['csv', 'json']

/**
 * The profiles the page lists: the caller's own, over the catalogue register.
 * OpenRegister answers a Nextcloud admin with every user's profiles, so the
 * owner is checked here too.
 *
 * @param {object[]} profiles The profiles OpenRegister returned.
 * @param {{userId: string, registerId: string|number}} scope The caller and the catalogue register.
 * @return {object[]} The caller's own catalogue reports, in the order given.
 */
export function ownReports(profiles, { userId, registerId }) {
	if (!Array.isArray(profiles) || !userId) {
		return []
	}
	return profiles.filter((profile) =>
		profile?.owner === userId && String(profile?.registerId ?? '') === String(registerId ?? ''),
	)
}

/**
 * The properties of a catalogue schema a report can hold, in the schema's order.
 *
 * @param {object|null} schema The OpenRegister schema.
 * @return {Array<{key: string, label: string}>} Each property's key and title.
 */
export function reportableFields(schema) {
	const properties = schema?.properties
	if (!properties || typeof properties !== 'object') {
		return []
	}
	return Object.entries(properties)
		.filter(([, definition]) => definition && definition.type !== 'file' && definition.visible !== false)
		.map(([key, definition]) => ({ key, label: definition.title || key }))
}

/**
 * The form a report over one catalogue schema is defined in, as the JSON
 * schema `CnFormDialog` renders: a name, the fields in the order chosen, the
 * value mode, the format and optional filter rows of a field and a value.
 *
 * @param {object} schema The OpenRegister schema the report reads.
 * @param {(text: string) => string} translate Translates a label.
 * @return {object} The form's JSON schema.
 */
export function reportFormSchema(schema, translate) {
	const fields = reportableFields(schema)
	const keys = fields.map((field) => field.key)
	const labels = Object.fromEntries(fields.map((field) => [field.key, field.label]))
	return {
		title: translate('Report'),
		properties: {
			name: { type: 'string', title: translate('Name') },
			fields: {
				type: 'array',
				title: translate('Fields, in the order of the columns'),
				items: { type: 'string', enum: keys, 'x-enum-labels': labels },
			},
			valueMode: {
				type: 'string',
				title: translate('Values'),
				enum: VALUE_MODES,
				'x-enum-labels': { stored: translate('As stored'), rendered: translate('As shown in the lists') },
				default: 'stored',
			},
			format: {
				type: 'string',
				title: translate('Format'),
				enum: FORMATS,
				'x-enum-labels': { csv: 'CSV', json: 'JSON' },
				default: 'csv',
			},
			filters: {
				type: 'array',
				title: translate('Only rows where'),
				'x-widget': 'sub-objects',
				items: {
					type: 'object',
					properties: {
						field: { type: 'string', title: translate('Field'), enum: keys, 'x-enum-labels': labels },
						value: { type: 'string', title: translate('Equals') },
					},
				},
			},
		},
		required: ['name', 'fields', 'valueMode', 'format'],
	}
}

/**
 * The filter rows as the field-to-value object a profile keeps; null without a complete row.
 *
 * @param {unknown} rows The form's filter rows.
 * @return {object|null} The filters.
 */
export function filtersFromRows(rows) {
	if (!Array.isArray(rows)) {
		return null
	}
	const filters = {}
	for (const row of rows) {
		const field = typeof row?.field === 'string' ? row.field.trim() : ''
		const value = row?.value === undefined || row?.value === null ? '' : String(row.value).trim()
		if (field !== '' && value !== '') {
			filters[field] = value
		}
	}
	return Object.keys(filters).length > 0 ? filters : null
}

/**
 * The export profile a new report is stored as.
 *
 * @param {object} form The form's answer.
 * @param {{registerId: string|number, schemaId: string|number}} target The register and schema it reads.
 * @return {object} The profile to create.
 */
export function profileFromForm(form, { registerId, schemaId }) {
	return {
		name: String(form?.name ?? '').trim(),
		registerId: Number(registerId),
		schemaId: Number(schemaId),
		fields: Array.isArray(form?.fields) ? [...form.fields] : [],
		valueMode: VALUE_MODES.includes(form?.valueMode) ? form.valueMode : 'stored',
		format: FORMATS.includes(form?.format) ? form.format : 'csv',
		filters: filtersFromRows(form?.filters),
	}
}

/**
 * The URL of the export profile list.
 *
 * @param {(path: string, params?: object) => string} generateUrl Nextcloud's URL builder.
 * @return {string} The URL.
 */
export function profilesUrl(generateUrl) {
	return generateUrl(PROFILES_PATH)
}

/**
 * The URL of one export profile.
 *
 * @param {(path: string, params?: object) => string} generateUrl Nextcloud's URL builder.
 * @param {string|number} id The profile id.
 * @return {string} The URL.
 */
export function profileUrl(generateUrl, id) {
	return generateUrl(PROFILES_PATH + '/{id}', { id })
}

/**
 * The URL that runs one report and downloads its file.
 *
 * @param {(path: string, params?: object) => string} generateUrl Nextcloud's URL builder.
 * @param {string|number} id The profile id.
 * @return {string} The URL.
 */
export function runUrl(generateUrl, id) {
	return generateUrl(PROFILES_PATH + '/{id}/run', { id })
}

/**
 * The rows of an OpenRegister list answer, paginated or plain.
 *
 * @param {unknown} body The answer.
 * @return {object[]} The rows.
 */
export function rowsOf(body) {
	if (Array.isArray(body)) {
		return body
	}
	return Array.isArray(body?.results) ? body.results : []
}
