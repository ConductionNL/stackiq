/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The URL query parameters that make a compliance matrix selection
 * shareable: a list of selected options as comma-separated uuids, an
 * optional single value, and the options a list of uuids names.
 *
 * @spec openspec/specs/module-compliance-assessment/spec.md
 * @spec openspec/specs/bio-compliance-assessment/spec.md
 */

/**
 * A query parameter and its value; an empty value removes the parameter.
 *
 * @typedef {object} SelectionParam
 * @property {string} name The parameter name.
 * @property {*} value The value: selected options for a list, else a string.
 */

/**
 * Sets a parameter to the uuids of the selected options, comma-separated,
 * or removes it when none of them has a uuid.
 *
 * @param {URLSearchParams} searchParams The query parameters.
 * @param {SelectionParam} param The parameter and the selected options.
 */
export function setUuidListParam(searchParams, param) {
	const uuids = param.value.map((option) => option.uuid).filter(Boolean)
	if (uuids.length > 0) {
		searchParams.set(param.name, uuids.join(','))
	} else {
		searchParams.delete(param.name)
	}
}

/**
 * Sets a parameter to its value, or removes it when the value is empty.
 *
 * @param {URLSearchParams} searchParams The query parameters.
 * @param {SelectionParam} param The parameter and its value.
 */
export function setOptionalParam(searchParams, param) {
	if (param.value) {
		searchParams.set(param.name, param.value)
	} else {
		searchParams.delete(param.name)
	}
}

/**
 * The options whose uuid a comma-separated list names, in option order;
 * blanks around and between the uuids are ignored.
 *
 * @param {Array<{uuid: string}>} options The options.
 * @param {string} rawUuids The comma-separated uuids.
 * @return {Array} The options named.
 */
export function optionsByUuids(options, rawUuids) {
	const wanted = new Set(
		rawUuids
			.split(',')
			.map((s) => s.trim())
			.filter(Boolean),
	)
	return options.filter((option) => wanted.has(option.uuid))
}
