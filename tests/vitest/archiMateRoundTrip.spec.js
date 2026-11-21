/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * The client side of the ArchiMate round-trip check: the rows per category,
 * the summary, and the upload request.
 *
 * @spec openspec/changes/architecture-round-trip-check/specs/archimate-round-trip-check/spec.md#requirement-req-art-004-the-admin-settings-page-shall-show-the-round-trip-report
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const axios = vi.hoisted(() => ({ post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: axios }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))

const {
	CATEGORIES,
	checkRoundTrip,
	roundTripRows,
	roundTripSummary,
} = await import('../../src/utils/archiMateRoundTrip.js')

const clean = { source: 3, result: 3, missing: 0, extra: 0, changed: 0, examples: [] }

/**
 * A report with every category clean, and the given overrides.
 *
 * @param {object} overrides Categories to replace.
 * @return {object}
 */
function report(overrides = {}) {
	const categories = {}
	for (const key of CATEGORIES) {
		categories[key] = { ...clean, ...(overrides[key] ?? {}) }
	}
	return { mode: 'before-import', imported: true, lossless: true, categories }
}

describe('roundTripRows', () => {
	it('gives one row per category in report order with the counts', () => {
		const rows = roundTripRows(report({ relationships: { missing: 1, examples: [{ kind: 'missing', identifier: 'r1' }] } }))

		expect(rows.map((row) => row.key)).toEqual(CATEGORIES)
		expect(rows[1]).toMatchObject({ key: 'relationships', label: 'Relationships', source: 3, result: 3, missing: 1, lossy: true })
		expect(rows[1].examples).toHaveLength(1)
		expect(rows[0].lossy).toBe(false)
	})

	it('gives no rows for a report without categories', () => {
		expect(roundTripRows({ imported: false })).toEqual([])
		expect(roundTripRows(null)).toEqual([])
	})
})

describe('roundTripSummary', () => {
	it('reads No losses found when every category is clean', () => {
		expect(roundTripSummary(report())).toBe('No losses found')
	})

	it('names the categories with losses', () => {
		const summary = roundTripSummary(report({ elements: { changed: 2 }, views: { extra: 1 } }))

		expect(summary).toBe('Losses in: Elements, Views')
	})

	it('says when the model is not imported', () => {
		expect(roundTripSummary({ imported: false })).toBe('This model is not imported.')
	})
})

describe('checkRoundTrip', () => {
	beforeEach(() => {
		axios.post.mockReset()
	})

	it('uploads the file and the mode to the check route', async () => {
		axios.post.mockResolvedValue({ data: report() })
		const file = new File(['<model/>'], 'model.xml', { type: 'application/xml' })

		const answer = await checkRoundTrip(file, 'against-imported')

		expect(answer.imported).toBe(true)
		const [url, body] = axios.post.mock.calls[0]
		expect(url).toBe('/index.php/apps/stackiq/api/archimate/round-trip-check')
		expect(body.get('mode')).toBe('against-imported')
		expect(body.get('archiMateFile').name).toBe('model.xml')
	})

	it('throws the server message when the check fails', async () => {
		axios.post.mockRejectedValue({ response: { data: { message: 'Check failed: not XML' } } })

		await expect(checkRoundTrip(new File([''], 'x.xml'), 'before-import')).rejects.toThrow('Check failed: not XML')
	})
})
