// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * Unit tests for following and cancelling a running ArchiMate import.
 *
 * @spec openspec/specs/archimate-import-progress/spec.md#requirement-req-aip-003-the-settings-page-shall-show-the-progress-and-offer-a-cancel
 */

import { describe, expect, it, vi } from 'vitest'
import {
	cancelImport,
	makeOperationId,
	OPERATION_ID_PATTERN,
	progressView,
	startProgressPolling,
} from '../../src/utils/archiMateImportProgress.js'

vi.mock('@nextcloud/l10n', () => {
	const fill = (app, text, vars = {}) => String(text).replace(/{(\w+)}/g, (match, key) => (key in vars ? String(vars[key]) : match))
	return { t: fill, translate: fill, n: (app, one, many, count, vars) => fill(app, count === 1 ? one : many, { count, ...vars }), getLanguage: () => 'en' }
})

vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params = {}) => '/index.php' + url.replace(/{(\w+)}/g, (match, key) => (key in params ? encodeURIComponent(String(params[key])) : match)),
	generateOcsUrl: (url) => '/ocs/v2.php' + url,
}))

describe('makeOperationId', () => {
	it('makes an id the server accepts, different each time', () => {
		const a = makeOperationId()
		const b = makeOperationId()
		expect(a).toMatch(OPERATION_ID_PATTERN)
		expect(b).toMatch(OPERATION_ID_PATTERN)
		expect(a).not.toBe(b)
	})
})

describe('progressView', () => {
	it('shows the phase and the percentage the server reports', () => {
		const view = progressView({
			phase: 'processing_elements',
			percentage: 40,
			processed_items: 120,
			total_items: 300,
			status: 'running',
		})
		expect(view.label).toBe('Saving objects')
		expect(view.percentage).toBe(40)
		expect(view.detail).toBe('120 of 300 objects saved')
	})

	it('has no detail before any object is counted', () => {
		expect(
			progressView({
				phase: 'parsing',
				percentage: 10,
				processed_items: 0,
				total_items: 0,
			}).detail,
		).toBe('')
	})

	it('shows nothing when there is no progress yet', () => {
		expect(progressView(null)).toBeNull()
	})
})

describe('startProgressPolling', () => {
	it('reads the progress endpoint every interval and hands each answer on until stopped', async () => {
		let tick = null
		const setIntervalFn = vi.fn((fn) => {
			tick = fn
			return 7
		})
		const clearIntervalFn = vi.fn()
		const get = vi.fn().mockResolvedValue({
			data: { progress: { phase: 'processing_elements', percentage: 40 } },
		})
		const seen = []

		const stop = startProgressPolling({
			operationId: 'archimate_import_abc12345',
			http: { get },
			onProgress: (p) => seen.push(p.percentage),
			setIntervalFn,
			clearIntervalFn,
		})
		expect(setIntervalFn.mock.calls[0][1]).toBe(2000)

		await tick()
		expect(get.mock.calls[0][0]).toContain(
			'/apps/stackiq/api/progress/archimate_import_abc12345',
		)
		expect(seen).toEqual([40])

		stop()
		expect(clearIntervalFn).toHaveBeenCalledWith(7)
	})

	it('keeps polling quietly when the operation is not readable yet', async () => {
		let tick = null
		const get = vi.fn().mockRejectedValue({ response: { status: 404 } })
		const seen = []
		startProgressPolling({
			operationId: 'archimate_import_abc12345',
			http: { get },
			onProgress: (p) => seen.push(p),
			setIntervalFn: (fn) => {
				tick = fn
				return 1
			},
			clearIntervalFn: () => {},
		})
		await tick()
		expect(seen).toEqual([])
	})

	it('sends no new request while the previous one is still waiting', async () => {
		let tick = null
		let answer = null
		const get = vi.fn(
			() =>
				new Promise((resolve) => {
					answer = resolve
				}),
		)
		startProgressPolling({
			operationId: 'archimate_import_abc12345',
			http: { get },
			onProgress: () => {},
			setIntervalFn: (fn) => {
				tick = fn
				return 1
			},
			clearIntervalFn: () => {},
		})

		const first = tick()
		await tick()
		expect(get).toHaveBeenCalledTimes(1)

		answer({ data: { progress: { percentage: 10 } } })
		await first
		tick()
		expect(get).toHaveBeenCalledTimes(2)
	})
})

describe('cancelImport', () => {
	it('posts the operation id to the cancel endpoint', async () => {
		const post = vi.fn().mockResolvedValue({ data: { success: true } })
		await cancelImport({
			operationId: 'archimate_import_abc12345',
			http: { post },
		})
		expect(post.mock.calls[0][0]).toContain(
			'/apps/stackiq/api/archimate/import/cancel',
		)
		expect(post.mock.calls[0][1]).toEqual({
			operationId: 'archimate_import_abc12345',
		})
	})
})
