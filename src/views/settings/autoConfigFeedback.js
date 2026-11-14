/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The toasts the settings page and its version section show for the result
 * of an auto-configuration action.
 *
 * @spec openspec/specs/fe-settings-ui/spec.md
 */

import { showError, showSuccess } from '@nextcloud/dialogs'

/**
 * The texts shown when an action's result carries no message.
 *
 * @typedef {object} ActionTexts
 * @property {string} success Shown for a successful result.
 * @property {string} failure Shown for an unsuccessful result.
 */

/**
 * Shows the result of a force update or reset: its message, or the default
 * text, as a success or an error; nothing for no result.
 *
 * @param {object|null} result The store's result.
 * @param {ActionTexts} texts The default texts.
 */
export function reportActionResult(result, texts) {
	if (result?.success) {
		showSuccess(result.message || texts.success)
		return
	}
	if (result) {
		showError(result.message || texts.failure)
	}
}

/**
 * Shows the result of an auto-configuration: a fixed success text, or the
 * failure message when there is one.
 *
 * @param {object|null} result The store's result.
 */
export function reportAutoConfigResult(result) {
	if (result?.success) {
		showSuccess('Auto configuration completed successfully')
		return
	}
	if (result?.message) {
		showError('Auto configuration failed: ' + result.message)
	}
}

export const FORCE_UPDATE_TEXTS = {
	success: 'Force update completed successfully',
	failure: 'Force update failed',
}

export const RESET_AUTO_CONFIG_TEXTS = {
	success: 'Auto-config reset successfully',
	failure: 'Failed to reset auto-config',
}
