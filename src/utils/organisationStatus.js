/**
 * Organisation status values the concept-organisations dashboard widget reads
 * and writes. They must be members of the `status` enum of the organization
 * schema in lib/Settings/softwarecatalogus_register.json, or OpenRegister
 * refuses the accept and the widget never lists anything.
 *
 * @spec openspec/specs/fe-organizations/spec.md
 */

/** Status of an organisation still waiting for review. */
export const CONCEPT_STATUS = 'Draft'

/** Status an organisation gets once it is accepted. */
export const ACCEPTED_STATUS = 'Active'

/**
 * Whether an organisation is still a concept, compared case-insensitively.
 *
 * @param {object} organisation The organisation object from the store
 * @return {boolean} True when its status is the concept status
 * @spec openspec/specs/fe-organizations/spec.md
 */
export function isConceptOrganisation(organisation) {
	return (
		String(organisation?.status ?? '').toLowerCase()
		=== CONCEPT_STATUS.toLowerCase()
	)
}

/**
 * The patch payload that accepts an organisation.
 *
 * @return {{status: string}} The payload for objectStore.patchObject()
 * @spec openspec/specs/fe-organizations/spec.md
 */
export function acceptPayload() {
	return { status: ACCEPTED_STATUS }
}
