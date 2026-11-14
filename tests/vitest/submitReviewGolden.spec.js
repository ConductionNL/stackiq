// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for submitting a review: the guard, the request body, the
 * messages and events, and the state after a success or a failure.
 * Recorded with RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/catalog-ratings/spec.md
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'

const trace = vi.hoisted(() => [])
const api = vi.hoisted(() => ({ fail: false }))

vi.mock('@nextcloud/dialogs', () => ({
	showError: (message) => trace.push(['showError', message]),
	showSuccess: (message) => trace.push(['showSuccess', message]),
}))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => '[' + text + ']' }))
vi.mock('@nextcloud/vue', async () => {
	const names = [
		'NcButton',
		'NcDialog',
		'NcLoadingIcon',
		'NcNoteCard',
		'NcTextArea',
		'NcTextField',
		'NcModal',
		'NcSelect',
	]
	return Object.fromEntries(names.map((name) => [name, {}]))
})
vi.mock('../../src/utils/adminApi.js', () => ({
	apiRequest: async (route, options) => {
		trace.push(['apiRequest', route, options])
		if (api.fail) throw new Error('rate limited')
		return { ok: true }
	},
}))

const { default: SubmitReviewModal } =
	await import('../../src/modals/SubmitReviewModal.vue')

const GOLDEN = path.resolve(import.meta.dirname, 'golden/submitReview.json')

const CASES = {
	'invalid form': { valid: false },
	submitted: { valid: true },
	'submitted without text': {
		valid: true,
		form: { name: 'Short', longDescription: '' },
	},
	'request fails': { valid: true, fail: true },
}

describe('SubmitReviewModal submitReview golden', () => {
	it('matches the recorded outcomes', async () => {
		const actual = {}
		for (const [name, setup] of Object.entries(CASES)) {
			trace.length = 0
			api.fail = Boolean(setup.fail)
			const vm = {
				...SubmitReviewModal.data.call({}),
				isFormValid: setup.valid,
				subjectType: 'module',
				subjectId: 'm-1',
				selectedRating: 4,
				loading: 'unchanged',
				error: 'unchanged',
				$emit: (event) => trace.push(['emit', event]),
			}
			vm.formData = setup.form || {
				name: 'Great app',
				longDescription: 'Works well',
			}
			for (const [method, fn] of Object.entries(SubmitReviewModal.methods)) {
				vm[method] = fn.bind(vm)
			}
			vm.resetForm = () => trace.push(['resetForm'])
			await vm.submitReview()
			actual[name] = {
				trace: [...trace],
				loading: vm.loading,
				error: vm.error,
			}
		}
		if (process.env.RECORD_GOLDEN === '1') {
			writeFileSync(GOLDEN, JSON.stringify(actual, null, '\t') + '\n')
		}
		expect(actual).toEqual(JSON.parse(readFileSync(GOLDEN, 'utf8')))
	})
})
