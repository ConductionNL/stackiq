/**
 * Follow and cancel a running ArchiMate import from the settings page.
 *
 * The import is one long request, so the page names the operation up front
 * and reads its progress from a second request while the first is running.
 *
 * @spec openspec/changes/architecture-import-progress-and-cancel/specs/archimate-import-progress/spec.md#requirement-req-aip-003-the-settings-page-shall-show-the-progress-and-offer-a-cancel
 */

import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/** Operation ids the server accepts for an ArchiMate import. */
export const OPERATION_ID_PATTERN = /^archimate_import_[A-Za-z0-9]{8,64}$/

const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'

/**
 * Make a fresh operation id for an import.
 *
 * @return {string} The id
 * @spec openspec/changes/architecture-import-progress-and-cancel/specs/archimate-import-progress/spec.md#requirement-req-aip-001-a-running-import-shall-record-its-phase-and-the-objects-saved-so-far
 */
export function makeOperationId() {
	const bytes = new Uint8Array(24)
	globalThis.crypto.getRandomValues(bytes)
	let suffix = ''
	for (const byte of bytes) {
		suffix += ALPHABET[byte % ALPHABET.length]
	}
	return 'archimate_import_' + suffix
}

/**
 * The words for each import phase the server records.
 *
 * @param {string} phase The phase key
 * @return {string} The label
 */
function phaseLabel(phase) {
	switch (phase) {
		case 'initializing':
		case 'validating':
			return t('stackiq', 'Checking the file')
		case 'parsing':
			return t('stackiq', 'Reading the model')
		case 'analyzing':
			return t('stackiq', 'Converting the model')
		case 'processing_elements':
			return t('stackiq', 'Saving objects')
		case 'finalizing':
			return t('stackiq', 'Finishing the import')
		default:
			return t('stackiq', 'Importing')
	}
}

/**
 * What the page shows for a progress snapshot.
 *
 * @param {object|null} progress The snapshot from the progress endpoint
 * @return {{label: string, percentage: number, detail: string}|null} The view, or null before any progress
 * @spec openspec/changes/architecture-import-progress-and-cancel/specs/archimate-import-progress/spec.md#requirement-req-aip-003-the-settings-page-shall-show-the-progress-and-offer-a-cancel
 */
export function progressView(progress) {
	if (!progress) {
		return null
	}
	const processed = Number(progress.processed_items) || 0
	const total = Number(progress.total_items) || 0
	return {
		label: phaseLabel(progress.phase),
		percentage: Number(progress.percentage) || 0,
		detail:
			total > 0
				? t('stackiq', '{processed} of {total} objects saved', {
						processed,
						total,
					})
				: '',
	}
}

/**
 * Read the progress of an operation every two seconds until stopped.
 *
 * An operation that is not readable yet (the import has not started it) is
 * skipped quietly; the next tick tries again.
 *
 * @param {object} options The options
 * @param {string} options.operationId The operation to follow
 * @param {object} options.http An axios-like client with get
 * @param {Function} options.onProgress Called with each snapshot
 * @param {Function} [options.setIntervalFn] Timer, for tests
 * @param {Function} [options.clearIntervalFn] Timer, for tests
 * @return {Function} Stops the polling
 * @spec openspec/changes/architecture-import-progress-and-cancel/specs/archimate-import-progress/spec.md#requirement-req-aip-003-the-settings-page-shall-show-the-progress-and-offer-a-cancel
 */
export function startProgressPolling({
	operationId,
	http,
	onProgress,
	setIntervalFn = setInterval,
	clearIntervalFn = clearInterval,
}) {
	const url = generateUrl('/apps/stackiq/api/progress/{operationId}', {
		operationId,
	})
	const handle = setIntervalFn(async () => {
		try {
			const response = await http.get(url)
			if (response?.data?.progress) {
				onProgress(response.data.progress)
			}
		} catch {
			// Not readable yet or briefly unavailable: try again on the next tick.
		}
	}, 2000)
	return () => clearIntervalFn(handle)
}

/**
 * Ask the server to cancel a running import.
 *
 * @param {object} options The options
 * @param {string} options.operationId The operation to cancel
 * @param {object} options.http An axios-like client with post
 * @return {Promise<object>} The server's answer
 * @spec openspec/changes/architecture-import-progress-and-cancel/specs/archimate-import-progress/spec.md#requirement-req-aip-002-an-admin-shall-be-able-to-cancel-a-running-import
 */
export async function cancelImport({ operationId, http }) {
	const response = await http.post(
		generateUrl('/apps/stackiq/api/archimate/import/cancel'),
		{ operationId },
	)
	return response.data
}
