/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * suiteWizard — pure helpers for the suite-creation wizard
 * (`src/dialogs/SuiteWizardDialog.vue`).
 *
 * Kept framework-free and side-effect-free so step validity and payload
 * shape are unit-testable without mounting any Vue component, mirroring the
 * existing `src/utils/translationBadge.js` pattern used throughout this app.
 *
 * @module utils/suiteWizard
 * @spec openspec/specs/suite-wizard/spec.md#requirement-the-wizard-must-require-at-least-one-attached-application-before-advancing-past-the-applications-step
 */

/**
 * Whether the `details` step's required fields (`naam`, `beschrijvingKort` —
 * the `suite` schema's own `required` array) are filled in.
 *
 * @param {object} stepData The wizard's accumulated step data.
 * @param {string} [stepData.name] The suite name.
 * @param {string} [stepData.beschrijvingKort] The short description.
 * @return {boolean} True when both required fields are non-blank.
 * @spec openspec/specs/suite-wizard/spec.md#requirement-the-wizard-shall-guide-suite-creation-through-details-application-attachment-and-confirmation-steps
 */
export function isDetailsStepValid(stepData) {
	const naam = ((stepData && stepData.name) || '').trim()
	const beschrijvingKort = ((stepData && stepData.beschrijvingKort) || '').trim()
	return naam.length > 0 && beschrijvingKort.length > 0
}

/**
 * Whether the `applications` step has at least one attached module. Returns
 * `true` to advance, or a translated error message string to block
 * navigation (the shape `CnWizardDialog`'s `validate` prop expects).
 *
 * @param {Array<object>} applications The modules attached in this step.
 * @param {Function} translate The `t('softwarecatalog', ...)` function.
 * @return {(true|string)} `true` when valid, else a validation message.
 * @spec openspec/specs/suite-wizard/spec.md#requirement-the-wizard-must-require-at-least-one-attached-application-before-advancing-past-the-applications-step
 */
export function isApplicationsStepValid(applications, translate) {
	if (Array.isArray(applications) && applications.length > 0) {
		return true
	}
	return translate(
		'softwarecatalog',
		'Attach at least one existing application before continuing.',
	)
}

/**
 * Build the `suite` object payload from the wizard's accumulated step data.
 * `applicaties` MUST be a plain array of module id strings — the same
 * related-object convention `CnFormDialog` uses for both single and array
 * reference fields (the referenced object's id, not a nested object).
 *
 * @param {object} stepData The wizard's accumulated step data.
 * @param {string} [stepData.name] The suite name.
 * @param {string} [stepData.beschrijvingKort] The short description.
 * @param {string} [stepData.beschrijvingLang] The long (markdown) description.
 * @param {string} [stepData.website] The suite website URL.
 * @param {Array<object>} [stepData.applications] The attached module objects.
 * @return {{name: string, beschrijvingKort: string, beschrijvingLang: string, website: string, applications: Array<string>}} The suite payload.
 * @spec openspec/specs/suite-wizard/spec.md#requirement-submitting-the-wizard-shall-create-a-suite-object-with-the-attached-applications
 */
export function buildSuitePayload(stepData) {
	const data = stepData || {}
	const applications = Array.isArray(data.applications) ? data.applications : []
	return {
		name: (data.name || '').trim(),
		beschrijvingKort: (data.beschrijvingKort || '').trim(),
		beschrijvingLang: (data.beschrijvingLang || '').trim(),
		website: (data.website || '').trim(),
		applications: applications
			.map((app) => (app && typeof app === 'object' ? app.id : app))
			.filter(Boolean),
	}
}

/**
 * Format the list of attached application names for the `confirm` step's
 * review summary.
 *
 * @param {Array<object>} applications The attached module objects.
 * @return {Array<string>} The applications' `naam` values (falls back to the
 *   id when a name is missing).
 * @spec openspec/specs/suite-wizard/spec.md#requirement-the-wizard-shall-guide-suite-creation-through-details-application-attachment-and-confirmation-steps
 */
export function summarizeApplications(applications) {
	if (!Array.isArray(applications)) return []
	return applications.map((app) => (app && (app.name || app.id)) || '')
}

/**
 * Map an object-store collection of modules to `NcSelect` option shape.
 *
 * Accepts the paginated ENVELOPE that `objectStore.getCollection()` actually
 * returns (`{ results: [...] }`) as well as a bare array, and tolerates
 * null/undefined while the collection is still loading. Reading the envelope
 * as an array threw `(intermediate value).map is not a function` at runtime and
 * left the wizard's Applications step blank, so no application could ever be
 * attached to a suite — the component's own computed had no unit test.
 *
 * @param {object|Array|null} collection Collection envelope, bare array, or null.
 *
 * @return {Array<{uuid: string, label: string, raw: object}>} Option list.
 *
 * @spec openspec/specs/suite-wizard/spec.md#requirement-the-wizard-shall-guide-suite-creation-through-details-application-attachment-and-confirmation-steps
 */
export function mapApplicationOptions(collection) {
	const modules = Array.isArray(collection)
		? collection
		: collection?.results || []

	return modules.map((mod) => {
		const uuid = mod.uuid || mod.id || mod['@self']?.id

		return {
			uuid,
			label: mod.name || mod.title || uuid,
			raw: mod,
		}
	})
}
