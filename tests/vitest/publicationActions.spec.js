/**
 * publicationActions.js — publish, withdraw and their per-entry outcome
 * (publish-and-lock-from-the-list, REQ-ODP-010 / REQ-ODP-011).
 *
 * @spec openspec/changes/publish-and-lock-from-the-list/tasks.md#task-1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => `/index.php${p}` }))
vi.mock('@nextcloud/axios', () => ({ default: { put: vi.fn(), delete: vi.fn() } }))

const {
	entryId,
	isPublishableType,
	publicationState,
	publicationTarget,
	publishEntry,
	refusalMessage,
	runPublication,
	selectedEntries,
	setPublicationTarget,
	withdrawEntry,
} = await import('../../src/services/publicationActions.js')

function refusal (status, message) {
  return Object.assign(new Error(`Request failed with status code ${status}`), {
		response: { status, data: { message } },
	})
}

describe('publishEntry / withdrawEntry', () => {
	it('PUTs the publish endpoint of the entry, with the moment when given', async () => {
		const client = { put: vi.fn().mockResolvedValue({ data: { ok: true, publicationDate: '2026-12-01T09:00:00+00:00' } }) }

		const result = await publishEntry('module', 'u-1', { when: '2026-12-01T09:00:00.000Z', client })

		expect(client.put).toHaveBeenCalledWith(
			'/index.php/apps/stackiq/api/publication/module/u-1/publish',
			{ when: '2026-12-01T09:00:00.000Z' },
		)
		expect(result.publicationDate).toBe('2026-12-01T09:00:00+00:00')
	})

	it('PUTs an empty body to publish now', async () => {
		const client = { put: vi.fn().mockResolvedValue({ data: {} }) }

		await publishEntry('catalogService', 'u-2', { client })

		expect(client.put).toHaveBeenCalledWith('/index.php/apps/stackiq/api/publication/catalogService/u-2/publish', {})
	})

	it('DELETEs the depublish endpoint to withdraw', async () => {
		const client = { delete: vi.fn().mockResolvedValue({ data: { ok: true } }) }

		await withdrawEntry('module', 'u-3', { client })

		expect(client.delete).toHaveBeenCalledWith('/index.php/apps/stackiq/api/publication/module/u-3/depublish')
	})
})

describe('runPublication', () => {
	it('calls the endpoint once per entry and splits succeeded from refused with the reason', async () => {
		const client = {
			put: vi.fn((url) => (url.includes('/theirs/')
				? Promise.reject(refusal(403, 'You may not publish this entry'))
				: Promise.resolve({ data: { ok: true } }))),
		}
		const mine1 = { id: 'mine1', name: 'A' }
		const mine2 = { '@self': { id: 'mine2' }, name: 'B' }
		const theirs = { id: 'theirs', name: 'C' }

		const out = await runPublication('publish', 'module', [mine1, theirs, mine2], { client })

		expect(client.put).toHaveBeenCalledTimes(3)
		expect(out.successful).toEqual([mine1, mine2])
		expect(out.failed).toEqual([{ entry: theirs, message: 'You may not publish this entry' }])
	})

	it('withdraws through the depublish endpoint', async () => {
		const client = { delete: vi.fn().mockResolvedValue({ data: {} }), put: vi.fn() }

		const out = await runPublication('withdraw', 'module', [{ id: 'x' }], { client })

		expect(client.delete).toHaveBeenCalledTimes(1)
		expect(client.put).not.toHaveBeenCalled()
		expect(out.successful).toHaveLength(1)
	})

	it('refuses every entry without a call when the type is not publishable', async () => {
		const client = { put: vi.fn() }

		const out = await runPublication('publish', 'usage', [{ id: 'a' }, { name: 'no id' }], { client })

		expect(client.put).not.toHaveBeenCalled()
		expect(out.successful).toEqual([])
		expect(out.failed.map((f) => f.message)).toEqual([
			'This entry cannot be published.',
			'This entry cannot be published.',
		])
	})
})

describe('helpers', () => {
	it('takes the endpoint message of a refusal, else the error message', () => {
		expect(refusalMessage(refusal(400, 'entry not resolvable'))).toBe('entry not resolvable')
		expect(refusalMessage(new Error('Network Error'))).toBe('Network Error')
		expect(refusalMessage(null)).toBe('Unknown error')
	})

	it('knows the publishable types', () => {
		expect(isPublishableType('module')).toBe(true)
		expect(isPublishableType('catalogService')).toBe(true)
		expect(isPublishableType('usage')).toBe(false)
	})

	it('reads the entry id from the top level or @self', () => {
		expect(entryId({ id: 'a', '@self': { id: 'b' } })).toBe('a')
		expect(entryId({ '@self': { id: 'b' } })).toBe('b')
		expect(entryId(null)).toBe('')
	})

	it('derives the publication state from the dates', () => {
		const now = new Date('2026-11-14T12:00:00Z')

		expect(publicationState({ publicationDate: '2026-11-01T00:00:00Z' }, now)).toBe('published')
		expect(publicationState({ publicationDate: '2026-12-01T00:00:00Z' }, now)).toBe('scheduled')
		expect(publicationState({ publicationDate: '2026-11-01T00:00:00Z', depublicationDate: '2026-11-10T00:00:00Z' }, now)).toBe('unpublished')
		expect(publicationState({ publicationDate: '2026-11-01T00:00:00Z', depublicationDate: '2026-12-10T00:00:00Z' }, now)).toBe('published')
		expect(publicationState({}, now)).toBe('unpublished')
	})

	it('keeps the selection order and stands in a bare id for an unloaded row', () => {
		const a = { id: 'a', name: 'A' }
		const b = { '@self': { id: 'b' }, name: 'B' }

		expect(selectedEntries(['b', 'z', 'a'], [a, b])).toEqual([b, { id: 'z' }, a])
	})

	it('remembers the object type the next mass dialog acts on', () => {
		setPublicationTarget({ objectType: 'catalogService' })
		expect(publicationTarget()).toEqual({ objectType: 'catalogService' })
		setPublicationTarget(null)
		expect(publicationTarget()).toEqual({ objectType: '' })
	})
})
