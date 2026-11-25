/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Confirmation rounds (landscape-owner-attestation): the manifest handlers of
 * the round and request pages, and the pure helpers of the Start round dialog.
 *
 * @spec openspec/changes/archive/2026-11-25-landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-003-an-owner-confirms-or-corrects-each-entry
 */

/**
 * The navigation-store dialog the Start round action opens.
 *
 * @type {string}
 */
export const START_ROUND_DIALOG = 'startAttestationRound'

/**
 * The detail page path of each entry type a round asks about
 * (the GebruikDetail and ModuleDetail routes).
 *
 * @type {Object<string, string>}
 */
export const ENTRY_PATHS = {
	usage: '/gebruik/',
	module: '/modules/',
}

/**
 * The entry types a round can cover.
 *
 * @type {Array<string>}
 */
export const SCOPES = ['usage', 'module']

/**
 * The request body of a new round, or null when a required field is missing.
 *
 * @param {object} form The dialog's fields: organisation, scopeSchema, deadline, name.
 * @spec openspec/changes/archive/2026-11-25-landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-001-an-information-manager-starts-a-confirmation-round-for-a-scope
 * @return {object|null} The body.
 */
export function roundBody(form) {
	const organisation = String(form?.organisation || '')
	const scopeSchema = String(form?.scopeSchema || '')
	const deadline = String(form?.deadline || '')
	if (
		organisation === ''
		|| !SCOPES.includes(scopeSchema)
		|| !/^\d{4}-\d{2}-\d{2}$/.test(deadline)
	) {
		return null
	}
	return {
		organisation,
		scopeSchema,
		deadline,
		name: String(form?.name || '').trim(),
	}
}

/**
 * Organisations as select options, sorted by name.
 *
 * @param {Array<object>} organisations OpenRegister's organisations ({ uuid, name }).
 * @spec openspec/changes/archive/2026-11-25-landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-001-an-information-manager-starts-a-confirmation-round-for-a-scope
 * @return {Array<{value: string, label: string}>} The options.
 */
export function organisationOptions(organisations) {
	return (organisations || [])
		.filter((org) => org && org.uuid)
		.map((org) => ({ value: org.uuid, label: org.name || org.uuid }))
		.sort((a, b) => a.label.localeCompare(b.label))
}

/**
 * The id of a row the index page passes to a handler.
 *
 * @param {object} item The row.
 * @return {string} The id, or an empty string.
 */
function rowId(item) {
	return String(item?.id ?? item?.['@self']?.id ?? '')
}

/**
 * The handlers of the confirmation pages, keyed by their manifest names.
 *
 * @param {object} deps Collaborators.
 * @param {function(string, object=): void} deps.setDialog Opens a navigation-store dialog.
 * @param {function(string, object=): Promise} deps.post POSTs to an app URL.
 * @param {function(string): string} deps.generateUrl Builds an app URL.
 * @param {function(string): void} deps.assign Opens a URL.
 * @param {function(): void} deps.refresh Asks the page to refetch.
 * @param {function(string): void} deps.notifyError Shows an error.
 * @spec openspec/changes/archive/2026-11-25-landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-003-an-owner-confirms-or-corrects-each-entry
 * @return {{startAttestationRound: function(): void, confirmAttestationRequest: function(object): Promise<void>, openAttestationEntry: function(object): void}} The handlers.
 */
export function createAttestationHandlers({
	setDialog,
	post,
	generateUrl,
	assign,
	refresh,
	notifyError,
}) {
	return {
		/**
		 * Opens the dialog that starts a confirmation round.
		 *
		 * @spec openspec/changes/archive/2026-11-25-landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-001-an-information-manager-starts-a-confirmation-round-for-a-scope
		 * @return {void}
		 */
		startAttestationRound() {
			setDialog(START_ROUND_DIALOG)
		},

		/**
		 * Confirms an attestation request unchanged and refreshes the page.
		 *
		 * @param {object} context The row context.
		 * @param {object} context.item The attestation request row.
		 * @spec openspec/changes/archive/2026-11-25-landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-003-an-owner-confirms-or-corrects-each-entry
		 * @return {Promise<void>}
		 */
		async confirmAttestationRequest({ item } = {}) {
			const id = rowId(item)
			if (id === '') {
				return
			}
			try {
				await post(
					generateUrl(
						`/apps/stackiq/api/attestation-requests/${encodeURIComponent(id)}/confirm`,
					),
				)
				refresh()
			} catch (error) {
				notifyError(error?.response?.data?.message || error?.message || '')
			}
		},

		/**
		 * Opens the landscape entry an attestation request is about, so the owner can correct it.
		 *
		 * @param {object} context The row context.
		 * @param {object} context.item The attestation request row.
		 * @spec openspec/changes/archive/2026-11-25-landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-003-an-owner-confirms-or-corrects-each-entry
		 * @return {void}
		 */
		openAttestationEntry({ item } = {}) {
			const path = ENTRY_PATHS[item?.entrySchema]
			const id = String(item?.entryId || '')
			if (path && id !== '') {
				assign(generateUrl(`/apps/stackiq${path}${encodeURIComponent(id)}`))
			}
		},
	}
}
