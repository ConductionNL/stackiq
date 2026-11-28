// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Small helpers for the mail settings: where a template lives, the message
 * of a refused request, and whether a transport is set at all.
 *
 * @spec openspec/changes/mail-template-editor-saves/specs/email-delivery/spec.md
 */

import { generateUrl } from '@nextcloud/router'

/**
 * The backend route of one mail template.
 *
 * @param {string} templateName The template key, e.g. organization_activation.
 * @return {string} The URL.
 * @spec openspec/changes/mail-template-editor-saves/specs/email-delivery/spec.md#requirement-an-administrator-edits-and-saves-a-mail-template-req-010
 */
export function templateUrl(templateName) {
	return generateUrl('/apps/stackiq/api/email/templates/' + encodeURIComponent(templateName))
}

/**
 * The backend's own message for a failed request, else the fallback.
 *
 * @param {object} error The caught error.
 * @param {string} fallback The text when the backend said nothing.
 * @return {string} The message.
 * @spec openspec/changes/mail-template-editor-saves/specs/email-delivery/spec.md#requirement-an-administrator-edits-and-saves-a-mail-template-req-010
 */
export function messageOf(error, fallback) {
	return error?.response?.data?.message || error?.message || fallback
}

/**
 * Whether no mail transport is configured, so no mail goes out.
 *
 * @param {object|null} emailSettings The stored mail settings.
 * @return {boolean} True for a `null`, `'null'` or empty transport.
 * @spec openspec/changes/mail-template-editor-saves/specs/email-delivery/spec.md#requirement-the-settings-say-when-mail-is-not-being-sent-req-011
 */
export function isTransportUnset(emailSettings) {
	const transport = emailSettings?.transportType
	return transport === null || transport === undefined || transport === '' || transport === 'null'
}
