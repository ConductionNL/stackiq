/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * Pure helpers for OrganisationMergePanel.vue: the organisations a source
 * organisation can be merged into, as select options.
 *
 * @module components/organisations/organisationMergeTargets
 * @copyright 2026 Conduction B.V.
 * @license EUPL-1.2
 *
 * @spec openspec/specs/organisation-merge/spec.md#requirement-merge-requests-must-be-validated-and-rejected-with-blockers-before-any-write
 */

/**
 * The id of an organisation: its own, else its @self id.
 *
 * @param {object} org The organisation.
 * @return {string|number|undefined} The id.
 */
function organisationId(org) {
	return org.id || org['@self']?.id
}

/**
 * Whether an organisation can take a merge: it is not the source itself and
 * not already merged.
 *
 * @param {object} org The organisation.
 * @param {string|number} sourceId The source organisation's id.
 * @return {boolean} Whether it can.
 */
function isMergeTarget(org, sourceId) {
	return (
		String(organisationId(org)) !== String(sourceId) && org.status !== 'merged'
	)
}

/**
 * The select option of a target organisation, labelled by its name, else
 * its @self name, else its id.
 *
 * @param {object} org The organisation.
 * @return {{value: (string|number), label: string}} The option.
 */
function targetOption(org) {
	return {
		value: organisationId(org),
		label: org.name || org['@self']?.name || String(org.id),
	}
}

/**
 * The select options of the organisations a source organisation can be
 * merged into.
 *
 * @param {Array<object>} organisations The organisations.
 * @param {string|number} sourceId The source organisation's id.
 * @return {Array<{value: (string|number), label: string}>} The options.
 */
export function mergeTargetOptions(organisations, sourceId) {
	return organisations
		.filter((org) => isMergeTarget(org, sourceId))
		.map((org) => targetOption(org))
}
