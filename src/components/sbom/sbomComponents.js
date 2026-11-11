/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The imported SBOM components of a module version, sorted by name, and the
 * distinct licences across them.
 *
 * @spec openspec/specs/sbom-import/spec.md#requirement-the-module-version-detail-page-shows-imported-components-with-summary-counts
 */

import { resolveUuid } from '../../utils/lifecyclePhase.js'

/**
 * A component's data: its object envelope's, else its own.
 *
 * @param {object} component The component record.
 * @return {object} The data.
 */
function componentData(component) {
	return component.object || component
}

/**
 * A component's name for sorting, '' without one.
 *
 * @param {object} component The component record.
 * @return {string} The name.
 */
function componentName(component) {
	return String(componentData(component).name || '')
}

/**
 * The components of one module version, sorted by name.
 *
 * @param {Array<object>} components The component records.
 * @param {string} moduleVersionId The module version's uuid.
 * @return {Array<object>} The components.
 */
export function moduleVersionComponents(components, moduleVersionId) {
	return components
		.filter(
			(c) => resolveUuid(componentData(c).moduleVersion) === moduleVersionId,
		)
		.slice()
		.sort((a, b) => componentName(a).localeCompare(componentName(b)))
}

/**
 * The licences a component lists, none when it lists no array.
 *
 * @param {object} component The component record.
 * @return {Array} The licences.
 */
function componentLicenses(component) {
	const licenses = componentData(component).licenses
	return Array.isArray(licenses) ? licenses : []
}

/**
 * The number of distinct, non-empty licence strings across components.
 *
 * @param {Array<object>} components The component records.
 * @return {number} The count.
 */
export function distinctLicenseCount(components) {
	const set = new Set()
	for (const component of components) {
		for (const license of componentLicenses(component)) {
			if (typeof license === 'string' && license !== '') {
				set.add(license)
			}
		}
	}
	return set.size
}
