/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The props AlwaysVisibleSection and CollapsibleSection share: the section
 * text, its loading state and its save, refresh and info buttons.
 *
 * @spec openspec/specs/fe-shell-navigation/spec.md
 */

export const settingsSectionProps = {
	/**
	 * Section name
	 */
	name: {
		type: String,
		required: true,
	},

	/**
	 * Section description
	 */
	description: {
		type: String,
		default: '',
	},

	/**
	 * Documentation URL
	 */
	docUrl: {
		type: String,
		default: '',
	},

	/**
	 * Loading state
	 */
	loading: {
		type: Boolean,
		default: false,
	},

	/**
	 * Show save button
	 */
	showSaveButton: {
		type: Boolean,
		default: false,
	},

	/**
	 * Show refresh button
	 */
	showRefreshButton: {
		type: Boolean,
		default: false,
	},

	/**
	 * Save button text
	 */
	saveButtonText: {
		type: String,
		default: 'Save',
	},

	/**
	 * Refresh button text
	 */
	refreshButtonText: {
		type: String,
		default: 'Refresh',
	},

	/**
	 * Can save state
	 */
	canSave: {
		type: Boolean,
		default: true,
	},

	/**
	 * Saving state
	 */
	saving: {
		type: Boolean,
		default: false,
	},

	/**
	 * Refreshing state
	 */
	refreshing: {
		type: Boolean,
		default: false,
	},

	/**
	 * Has info content
	 */
	hasInfoContent: {
		type: Boolean,
		default: false,
	},
}
