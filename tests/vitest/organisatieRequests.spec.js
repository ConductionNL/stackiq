/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The request and answer helpers of the organisation store: the fetch options
 * they build and how each one reads a response back or refuses it.
 *
 * @spec openspec/specs/fe-stores/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
	assertOk,
	httpError,
	lenientJson,
	messageJson,
	okJson,
	requireSuccess,
	tokenRequest,
} from '../../src/store/modules/organisatieRequests.js'

/**
 * A fetch response stand-in.
 *
 * @param {boolean} ok Whether the status is a success
 * @param {number} status The HTTP status
 * @param {Function} json What json() does
 * @return {object} The response
 */
function response(ok, status, json) {
	return { ok, status, json: vi.fn(json) }
}

describe('tokenRequest', () => {
	beforeEach(() => {
		globalThis.OC = { requestToken: 'tok' }
	})

	afterEach(() => {
		delete globalThis.OC
	})

	it('builds a JSON request with the token and body', () => {
		expect(tokenRequest('PUT', { name: 'x' })).toEqual({
			method: 'PUT',
			headers: { 'Content-Type': 'application/json', requesttoken: 'tok' },
			body: '{"name":"x"}',
		})
	})

	it('leaves the body out when there is none', () => {
		expect(tokenRequest('DELETE')).not.toHaveProperty('body')
	})

	it('serializes a null body', () => {
		expect(tokenRequest('POST', null).body).toBe('null')
	})
})

describe('httpError', () => {
	it('uses the message when given', () => {
		expect(httpError({ status: 500 }, 'Boom').message).toBe('Boom')
	})

	it('falls back to the status', () => {
		expect(httpError({ status: 404 }).message).toBe('HTTP error! status: 404')
		expect(httpError({ status: 404 }, '').message).toBe('HTTP error! status: 404')
	})
})

describe('okJson', () => {
	it('returns the body of a successful answer', async () => {
		await expect(okJson(response(true, 200, async () => ({ a: 1 })))).resolves.toEqual({ a: 1 })
	})

	it('refuses a failed status without reading the body', async () => {
		const failed = response(false, 403, async () => ({}))

		await expect(okJson(failed)).rejects.toThrow('HTTP error! status: 403')
		expect(failed.json).not.toHaveBeenCalled()
	})
})

describe('requireSuccess', () => {
	it('returns successful data', () => {
		const data = { success: true, id: 3 }

		expect(requireSuccess(data, 'Failed')).toBe(data)
	})

	it('throws the answer message, else the fallback', () => {
		expect(() => requireSuccess({ success: false, message: 'Name taken' }, 'Failed')).toThrow('Name taken')
		expect(() => requireSuccess({}, 'Failed to save')).toThrow('Failed to save')
	})
})

describe('messageJson', () => {
	it('returns the body of a successful answer', async () => {
		await expect(messageJson(response(true, 200, async () => ({ ok: 1 })))).resolves.toEqual({ ok: 1 })
	})

	it('throws the message a failed answer carries', async () => {
		await expect(messageJson(response(false, 422, async () => ({ message: 'Invalid KVK' }))))
			.rejects.toThrow('Invalid KVK')
	})

	it('falls back to the status without a message', async () => {
		await expect(messageJson(response(false, 500, async () => ({})))).rejects.toThrow('HTTP error! status: 500')
	})
})

describe('lenientJson', () => {
	it('returns the body of a successful answer', async () => {
		await expect(lenientJson(response(true, 200, async () => ({ a: 1 })))).resolves.toEqual({ a: 1 })
	})

	it('reads an unparseable successful answer as empty', async () => {
		await expect(lenientJson(response(true, 204, async () => { throw new Error('no json') }))).resolves.toEqual({})
	})

	it('throws the error field of a failed answer', async () => {
		await expect(lenientJson(response(false, 400, async () => ({ error: 'Bad input', message: 'ignored' }))))
			.rejects.toThrow('Bad input')
	})

	it('falls back to the status when a failed answer is not JSON', async () => {
		await expect(lenientJson(response(false, 502, async () => { throw new Error('html') })))
			.rejects.toThrow('HTTP error! status: 502')
	})
})

describe('assertOk', () => {
	it('accepts a successful answer without reading it', async () => {
		const ok = response(true, 200, async () => ({}))

		await expect(assertOk(ok, 'Failed')).resolves.toBeUndefined()
		expect(ok.json).not.toHaveBeenCalled()
	})

	it('throws the message of a failed answer, else the fallback', async () => {
		await expect(assertOk(response(false, 409, async () => ({ message: 'Exists' })), 'Failed')).rejects.toThrow('Exists')
		await expect(assertOk(response(false, 409, async () => ({})), 'Failed to delete')).rejects.toThrow('Failed to delete')
	})
})
