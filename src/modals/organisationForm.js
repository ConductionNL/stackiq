/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The form of the organisation modal: the empty form, and the form loaded
 * from an organisation.
 *
 * @spec openspec/specs/fe-organizations/spec.md
 */

/**
 * A new, empty organisation form.
 *
 * @return {object} The form fields with their defaults.
 */
export function emptyOrganisationForm() {
	return {
		name: '',
		website: '',
		type: '',
		shortDescription: '',
		'e-mailadres': '',
		telefoonnummer: '',
		oin: '',
		cbs: '',
		status: 'Draft',
		participants: [],
		contactpersonen: [],
	}
}

/**
 * The form loaded from an organisation: each field's value, or its default
 * when the organisation has a falsy one. A copy starts without contact persons.
 *
 * @param {object} organisation The organisation.
 * @param {boolean} isCopyMode Whether the organisation is being copied.
 * @return {object} The form fields.
 */
export function organisationFormData(organisation, isCopyMode) {
	const formData = emptyOrganisationForm()
	for (const field of Object.keys(formData)) {
		formData[field] = organisation[field] || formData[field]
	}
	if (isCopyMode) {
		formData.contactpersonen = []
	}
	return formData
}

/**
 * The organisation types the modal offers.
 *
 * @return {Array<{value: string, label: string}>} A new list of the options.
 */
export function organisationTypeOptions() {
	return [
		{ value: 'Municipality', label: 'Municipality' },
		{ value: 'Supplier', label: 'Supplier' },
		{ value: 'Collaboration', label: 'Collaboration' },
		{ value: 'Community', label: 'Community' },
	]
}
