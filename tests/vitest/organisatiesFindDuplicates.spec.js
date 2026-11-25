// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Find duplicates on the Organisations page: a header action of the index
 * page that links to OpenRegister's Duplicate candidates page, drawn only
 * when `/api/me` says the reader may find duplicates (a Nextcloud admin or a
 * functional administrator). The candidates page checks access itself.
 *
 * @spec openspec/specs/record-reconciliation/spec.md#requirement-req-rrc-002-the-catalogue-pages-shall-lead-an-administrator-to-openregisters-duplicate-candidates
 */

import { evaluateVisibleWhen } from '@conduction/nextcloud-vue/src/utils/visibleWhen.js'
import { afterEach, describe, expect, it, vi } from 'vitest'
import icons from '../../src/icons.js'
import manifest from '../../src/manifest.json'
import { duplicateCandidatesUrl } from '../../src/utils/mergedRecord.js'

const page = manifest.pages.find((entry) => entry.id === 'Organisaties')
const action = (page.config.headerActions || []).find((entry) => entry.id === 'find-duplicates')

/**
 * Evaluate the action's visibility with `/api/me` answering `me` (or failing).
 *
 * @param {?object|number} me The `/api/me` body, or an HTTP status to fail with.
 * @return {Promise<boolean>} Whether the action is drawn.
 */
async function visibleFor(me) {
	const fetchMock = vi.fn(async () => (typeof me === 'number'
		? { ok: false, status: me, json: async () => ({}) }
		: { ok: true, status: 200, json: async () => me }))
	vi.stubGlobal('fetch', fetchMock)
	const verdict = await evaluateVisibleWhen(action.visibleWhen, {}).catch(() => false)
	expect(String(fetchMock.mock.calls[0][0])).toMatch(/\/apps\/stackiq\/api\/me$/)
	return verdict === true
}

describe('Find duplicates on /organisaties', () => {
	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('is a header action linking to the candidates page the other lists use', () => {
		expect(action).toBeDefined()
		expect(action.label).toBe('Find duplicates')
		expect(action.href).toBe('/apps/openregister/duplicates')
		expect(duplicateCandidatesUrl((path) => path)).toBe(action.href)
	})

	it('names an icon the app registers', () => {
		expect(icons[action.icon]).toBeDefined()
	})

	it('shows for a reader /api/me lets find duplicates', async () => {
		expect(await visibleFor({ mayFindDuplicates: true })).toBe(true)
	})

	it('stays hidden for any other reader', async () => {
		expect(await visibleFor({ mayFindDuplicates: false })).toBe(false)
		expect(await visibleFor({})).toBe(false)
	})

	it('stays hidden when /api/me fails', async () => {
		expect(await visibleFor(500)).toBe(false)
	})
})
