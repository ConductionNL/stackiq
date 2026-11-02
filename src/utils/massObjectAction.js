/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The run shared by the mass object dialogs (publish, depublish, lock,
 * unlock, validate, delete): call the store, refresh the publications,
 * close the dialog after two seconds when nothing failed, and show the
 * failure or error text otherwise.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

import { catalogStore } from '../store/store.js'

/**
 * Run one mass action for a mass object dialog and set its state.
 *
 * @param {object} dialog The dialog component: loading, success, error, closeModalTimeout and closeDialog()
 * @param {object} action run() returns the store's { successful, failed }, failedText(count) and errorText() return the translated texts
 * @return {Promise<void>}
 * @spec openspec/specs/fe-object-modals/spec.md
 */
export async function runMassObjectAction(dialog, action) {
	dialog.loading = true

	try {
		const { successful, failed } = await action.run()
		applyMassResult(dialog, {
			successful,
			failed,
			failedText: action.failedText,
		})
	} catch (error) {
		dialog.success = false
		dialog.error = error.message || action.errorText()
	} finally {
		dialog.loading = false
	}
}

/**
 * Set the dialog state for the store's answer.
 *
 * @param {object} dialog The dialog component
 * @param {object} outcome The successful and failed objects, and failedText(count)
 * @return {void}
 * @spec openspec/specs/fe-object-modals/spec.md
 */
function applyMassResult(dialog, outcome) {
	const { successful, failed, failedText } = outcome
	if (successful.length > 0) {
		dialog.success = true
		catalogStore.fetchPublications()

		if (failed.length === 0) {
			dialog.closeModalTimeout = setTimeout(() => {
				dialog.closeDialog()
			}, 2000)
		}
	}

	if (failed.length > 0) {
		dialog.error = failedText(failed.length)
	}
}
