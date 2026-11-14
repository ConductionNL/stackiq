/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Helpers of the ArchiMate settings section: the missing configuration items
 * an import error names, and the organisation options for the export.
 *
 * @spec openspec/specs/fe-settings-ui/spec.md
 */

/**
 * The missing configuration items an import error names.
 *
 * @param {string|null} errorMessage The full error message.
 * @return {Array<string>} The missing configuration items.
 */
export function missingConfigItems(errorMessage) {
	if (!errorMessage) return []

	// Handle new error format from updated validation
	if (errorMessage.includes('is not configured')) {
		return unconfiguredItems(errorMessage)
	}

	// Handle legacy error format
	return listedMissingItems(errorMessage)
}

/**
 * The item a "... is not configured" error names.
 *
 * @param {string} errorMessage The error message.
 * @return {Array<string>} The one missing item.
 */
function unconfiguredItems(errorMessage) {
	const schemaMatch = errorMessage.match(
		/Schema ID for (\w+) '([^']+)' is not configured/,
	)
	if (schemaMatch) {
		return [`${schemaMatch[2]} schema ID (for ${schemaMatch[1]} objects)`]
	}

	// Check for register ID configuration error
	if (errorMessage.includes('register ID is not configured')) {
		return ['AMEF Register ID']
	}

	return ['AMEF schema configuration']
}

/**
 * The `- item` lines after "Missing configuration:", up to the first other non-blank line.
 *
 * @param {string} errorMessage The error message.
 * @return {Array<string>} The items.
 */
function listedMissingItems(errorMessage) {
	const missingItems = []
	let inMissingSection = false

	for (const line of errorMessage.split('\n')) {
		const kind = missingLineKind(line, inMissingSection)
		if (kind === 'end') {
			// End of missing items list
			break
		}
		inMissingSection = inMissingSection || kind === 'start'
		if (kind === 'item') {
			// Clean up the item text
			missingItems.push(line.replace(/^-\s*/, '').trim())
		}
	}

	return missingItems
}

/**
 * What a line of a legacy error is: the section start, an item, the end of the list, or to skip.
 *
 * @param {string} line The line.
 * @param {boolean} inMissingSection Whether the missing-configuration section started.
 * @return {string} 'start', 'item', 'end' or 'skip'.
 */
function missingLineKind(line, inMissingSection) {
	if (line.includes('Missing configuration:')) return 'start'
	if (!inMissingSection) return 'skip'
	if (line.trim().startsWith('-')) return 'item'
	return line.trim() === '' ? 'skip' : 'end'
}

/**
 * GET a stackiq or OpenRegister endpoint as an OCS request and read its JSON.
 *
 * @param {string} url The URL.
 * @param {string} failureMessage The error thrown when the response is not ok.
 * @return {Promise<object|Array|null>} The parsed body.
 * @throws {Error} When the response is not ok.
 */
async function requestJson(url, failureMessage) {
	const response = await fetch(url, {
		headers: {
			'OCS-APIREQUEST': 'true',
			requesttoken: OC.requestToken,
		},
	})
	if (!response.ok) throw new Error(failureMessage)
	return await response.json()
}

/**
 * The voorzieningen register and organisatie schema the organisations live in,
 * from stackiq's voorzieningen config; null when either is not set.
 *
 * @return {Promise<{register: string, schema: string}|null>} The source.
 * @throws {Error} When the config cannot be loaded.
 */
export async function organisationSource() {
	const data = await requestJson(
		'/index.php/apps/stackiq/api/voorzieningen/config',
		'Failed to load config',
	)

	// Get voorzieningen register and organisatie schema from config
	const voorzRegister = data?.config?.register
	const orgSchema = data?.config?.organisatie_schema
	if (!voorzRegister || !orgSchema) {
		return null
	}

	return { register: voorzRegister, schema: orgSchema }
}

/**
 * The select options of the organisations in a register and schema.
 *
 * @param {{register: string, schema: string}} source Where the organisations live.
 * @return {Promise<Array<{label: string, value: string}>|null>} The options; null when there are none.
 * @throws {Error} When the organisations cannot be loaded.
 */
export async function fetchOrganizationOptions(source) {
	return organizationOptionsOf(
		await requestJson(
			`/index.php/apps/openregister/api/objects/${source.register}/${source.schema}?_limit=5000`,
			'Failed to load organizations',
		),
	)
}

/**
 * The select options of the organisations a listing returned, labelled by the
 * object's own name with the legacy identity fields as fallbacks, without
 * unnamed or id-less ones, sorted by label; null when it returned none.
 *
 * @param {object|Array|null} orgData The listing body: `{results}` or a list.
 * @return {Array<{label: string, value: string}>|null} The options.
 */
function organizationOptionsOf(orgData) {
	const orgs = orgData?.results || orgData || []
	if (!Array.isArray(orgs) || orgs.length === 0) {
		return null
	}

	return orgs
		.map((org) => ({
			label: organizationLabel(org),
			value: org.id || org['@self']?.id,
		}))
		.filter((option) => option.label !== '' && option.value)
		.sort((a, b) => a.label.localeCompare(b.label))
}

/**
 * The label of an organisation: its `@self` name, its name, or its contacts uid.
 *
 * @param {object} org The organisation object.
 * @return {string} The label, or '' without one.
 */
function organizationLabel(org) {
	return org['@self']?.name || org.name || org.contactsUid || ''
}
