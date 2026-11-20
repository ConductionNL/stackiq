/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The organisation detail page's Export as ArchiMate action
 * (insight-exports-and-custom-reports): which organisation the page shows,
 * where the export and its access probe live, and whether the probe's answer
 * lets the action show. Pure, so the rule is tested without a browser; the
 * server answers both from the same organisation rule.
 *
 * @spec openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-003-a-member-of-an-organisation-must-be-able-to-export-that-organisation-as-archimate-from-its-detail-page-and-only-that-organisation
 */

const EXPORT_PATH = '/apps/stackiq/api/archimate/export/organization/{organizationUuid}'

/**
 * The uuid of the organisation the page shows: the route's id, else the object's own.
 *
 * @param {object|null} object The page's object.
 * @param {string} objectId The route's object id.
 * @return {string} The uuid, or '' when the page names none.
 */
export function organisationUuidOf(object, objectId) {
	const id = objectId || object?.['@self']?.id || object?.id || object?.uuid || ''
	return typeof id === 'string' ? id : String(id)
}

/**
 * The download URL of one organisation's ArchiMate export.
 *
 * @param {(path: string, params: object) => string} generateUrl Nextcloud's URL builder.
 * @param {string} organizationUuid The organisation.
 * @return {string} The URL.
 */
export function organisationExportUrl(generateUrl, organizationUuid) {
	return generateUrl(EXPORT_PATH, { organizationUuid })
}

/**
 * The URL that answers whether the caller may export one organisation.
 *
 * @param {(path: string, params: object) => string} generateUrl Nextcloud's URL builder.
 * @param {string} organizationUuid The organisation.
 * @return {string} The URL.
 */
export function organisationExportAccessUrl(generateUrl, organizationUuid) {
	return generateUrl(EXPORT_PATH + '/access', { organizationUuid })
}

/**
 * Whether the probe's answer lets the action show; anything but a plain yes hides it.
 *
 * @param {unknown} body The probe's answer.
 * @return {boolean} True only for `{mayExport: true}`.
 */
export function mayExportFrom(body) {
	return body?.mayExport === true
}
