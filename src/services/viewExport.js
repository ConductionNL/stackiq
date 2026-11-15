/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * viewExport.js — the Export menu of an architecture view.
 *
 * Three actions: Download SVG always works (the image route), Create Word
 * document and Create PowerPoint slide ask filinq through the document route.
 * The two document actions read the initial state `view_document_export`
 * (provided by Application::provideViewDocumentExport()); when it is false
 * they are disabled with the reason, so the absence is visible.
 *
 * @module Services/viewExport
 * @spec openspec/changes/architecture-views-to-office-documents/specs/architecture-view-office-export/spec.md#requirement-req-avo-003-the-document-actions-shall-say-why-they-are-unavailable-when-filinq-cannot-take-the-request
 */

import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/** The formats filinq makes, by action id. */
export const DOCUMENT_FORMATS = { word: 'docx', powerpoint: 'pptx' }

/**
 * Whether filinq can take a document request, as the server provided it.
 *
 * @param {function(string, string, boolean): boolean} [load] Reads an initial state; `loadState` by default.
 * @return {boolean} True when the document actions may be offered.
 * @spec openspec/changes/architecture-views-to-office-documents/specs/architecture-view-office-export/spec.md#requirement-req-avo-003-the-document-actions-shall-say-why-they-are-unavailable-when-filinq-cannot-take-the-request
 */
export function documentExportAvailable(load = loadState) {
	try {
		return load('stackiq', 'view_document_export', false) === true
	} catch {
		return false
	}
}

/**
 * The Export menu's actions, the two document actions disabled with the reason when filinq cannot take them.
 *
 * @param {boolean} documentExport Whether filinq's document contract resolves.
 * @return {Array<{id: string, label: string, disabled: boolean, reason: string}>} The actions, in menu order.
 * @spec openspec/changes/architecture-views-to-office-documents/specs/architecture-view-office-export/spec.md#requirement-req-avo-003-the-document-actions-shall-say-why-they-are-unavailable-when-filinq-cannot-take-the-request
 */
export function viewExportActions(documentExport) {
	const reason = documentExport === true ? '' : t('stackiq', 'Word and PowerPoint need the document app filinq')
	const unavailable = documentExport !== true

	return [
		{ id: 'svg', label: t('stackiq', 'Download SVG'), disabled: false, reason: '' },
		{ id: 'word', label: t('stackiq', 'Create Word document'), disabled: unavailable, reason },
		{ id: 'powerpoint', label: t('stackiq', 'Create PowerPoint slide'), disabled: unavailable, reason },
	]
}

/**
 * The URL of a view's SVG image.
 *
 * @param {string} viewId The view.
 * @return {string} The image route.
 * @spec openspec/changes/architecture-views-to-office-documents/specs/architecture-view-office-export/spec.md#requirement-req-avo-003-the-document-actions-shall-say-why-they-are-unavailable-when-filinq-cannot-take-the-request
 */
export function viewImageUrl(viewId) {
	return generateUrl('/apps/stackiq/api/views/{viewId}/image.svg', { viewId })
}

/**
 * Ask filinq, through stackiq's document route, for a Word document or a PowerPoint slide of a view.
 *
 * Never throws: the answer is either the filed document or the reason it was not made.
 *
 * @param {string} viewId The view.
 * @param {string} action `word` or `powerpoint`.
 * @param {object} [http] The HTTP client; `@nextcloud/axios` by default.
 * @return {Promise<{ok: true, name: string, path: string, fileId: (number|string)}|{ok: false, error: string, unavailable: boolean}>} The outcome.
 * @spec openspec/changes/architecture-views-to-office-documents/specs/architecture-view-office-export/spec.md#requirement-req-avo-002-stackiq-shall-ask-filinq-for-a-word-document-or-a-powerpoint-slide-that-holds-the-view
 */
export async function createViewDocument(viewId, action, http = axios) {
	const format = DOCUMENT_FORMATS[action]
	if (format === undefined) {
		return { ok: false, error: t('stackiq', 'The document could not be made.'), unavailable: false }
	}

	try {
		const { data } = await http.post(
			generateUrl('/apps/stackiq/api/views/{viewId}/document', { viewId }),
			{ format },
		)
		return { ok: true, name: data.name, path: data.path, fileId: data.fileId }
	} catch (e) {
		const status = e?.response?.status
		const error = e?.response?.data?.error
		return {
			ok: false,
			error: typeof error === 'string' && error !== '' ? error : t('stackiq', 'The document could not be made.'),
			unavailable: status === 503,
		}
	}
}

/**
 * The toast text after a document was filed.
 *
 * @param {string} name The file name.
 * @return {string} The text naming the file.
 * @spec openspec/changes/architecture-views-to-office-documents/specs/architecture-view-office-export/spec.md#requirement-req-avo-002-stackiq-shall-ask-filinq-for-a-word-document-or-a-powerpoint-slide-that-holds-the-view
 */
export function documentSavedMessage(name) {
	return t('stackiq', '{name} was saved in Files', { name })
}

/**
 * The Files link that opens a filed document.
 *
 * @param {number|string} fileId The file id.
 * @return {string} The Files URL.
 * @spec openspec/changes/architecture-views-to-office-documents/specs/architecture-view-office-export/spec.md#requirement-req-avo-002-stackiq-shall-ask-filinq-for-a-word-document-or-a-powerpoint-slide-that-holds-the-view
 */
export function filesLink(fileId) {
	return generateUrl('/f/{fileId}', { fileId: String(fileId) })
}

/**
 * The label of the toast's action that opens the filed document.
 *
 * @return {string} The label.
 * @spec openspec/changes/architecture-views-to-office-documents/specs/architecture-view-office-export/spec.md#requirement-req-avo-002-stackiq-shall-ask-filinq-for-a-word-document-or-a-powerpoint-slide-that-holds-the-view
 */
export function openInFilesLabel() {
	return t('stackiq', 'Open in Files')
}
