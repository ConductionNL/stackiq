/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The file actions of the object view: the request each one sends, the
 * loading list it marks and the console line a failed bulk run logs.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

export const FILE_ACTIONS = Object.freeze({
	publish: Object.freeze({
		verb: 'publish',
		suffix: '/publish',
		method: 'POST',
		loading: 'publishLoading',
		bulkError: 'Error publishing files:',
	}),
	depublish: Object.freeze({
		verb: 'depublish',
		suffix: '/depublish',
		method: 'POST',
		loading: 'depublishLoading',
		bulkError: 'Error depublishing files:',
	}),
	delete: Object.freeze({
		verb: 'delete',
		suffix: '',
		method: 'DELETE',
		loading: 'fileIdsLoading',
		bulkError: 'Failed to delete selected files:',
	}),
})
