/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Confirmation rounds (landscape-owner-attestation): the Start round body, the
 * row handlers of My confirmation requests, and the pages as the app builds
 * them (manifest.d merged the way src/main.js merges it).
 *
 * @spec openspec/changes/archive/2026-11-25-landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-004-the-round-shows-who-answered-and-who-is-overdue
 */

import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it, vi } from 'vitest'
import { buildManifest } from '../../node_modules/@conduction/nextcloud-vue/src/utils/buildManifest.js'
import base from '../../src/manifest.json'
import menuLayout from '../../src/menu-layout.json'
import {
	START_ROUND_DIALOG,
	createAttestationHandlers,
	organisationOptions,
	roundBody,
} from '../../src/services/attestation.js'

const dir = path.resolve(__dirname, '../../src/manifest.d')
const merged = buildManifest(
	base,
	fs
		.readdirSync(dir)
		.filter((f) => f.endsWith('.json'))
		.sort()
		.map((f) => JSON.parse(fs.readFileSync(path.join(dir, f), 'utf8'))),
	menuLayout,
)

/**
 * The handlers over doubles.
 *
 * @param {object} overrides Collaborators to replace.
 * @return {object} The handlers and their doubles.
 */
function handlers(overrides = {}) {
	const deps = {
		setDialog: vi.fn(),
		post: vi.fn().mockResolvedValue({ data: {} }),
		generateUrl: (url) => `/index.php${url}`,
		assign: vi.fn(),
		refresh: vi.fn(),
		notifyError: vi.fn(),
		...overrides,
	}
	return { deps, ...createAttestationHandlers(deps) }
}

describe('roundBody', () => {
	it('builds the round from the dialog fields, trimming the name', () => {
		expect(
			roundBody({
				organisation: 'org-1',
				scopeSchema: 'usage',
				deadline: '2026-12-01',
				name: ' Q4 ',
			}),
		).toEqual({
			organisation: 'org-1',
			scopeSchema: 'usage',
			deadline: '2026-12-01',
			name: 'Q4',
		})
	})

	it('is null while the organisation, a known scope or a date is missing', () => {
		expect(
			roundBody({ scopeSchema: 'usage', deadline: '2026-12-01' }),
		).toBeNull()
		expect(
			roundBody({
				organisation: 'org-1',
				scopeSchema: 'contract',
				deadline: '2026-12-01',
			}),
		).toBeNull()
		expect(
			roundBody({
				organisation: 'org-1',
				scopeSchema: 'module',
				deadline: '1-12-2026',
			}),
		).toBeNull()
		expect(roundBody(null)).toBeNull()
	})
})

describe('organisationOptions', () => {
	it('sorts the organisations by name and drops those without a uuid', () => {
		expect(
			organisationOptions([
				{ uuid: 'b', name: 'Zuiddrecht' },
				{ name: 'No id' },
				{ uuid: 'a', name: 'Aadorp' },
				{ uuid: 'c' },
			]),
		).toEqual([
			{ value: 'a', label: 'Aadorp' },
			{ value: 'c', label: 'c' },
			{ value: 'b', label: 'Zuiddrecht' },
		])
	})
})

describe('the handlers', () => {
	it('Start round opens the dialog', () => {
		const h = handlers()
		h.startAttestationRound()
		expect(h.deps.setDialog).toHaveBeenCalledWith(START_ROUND_DIALOG)
	})

	it('Confirm posts the row request and refetches the list', async () => {
		const h = handlers()
		await h.confirmAttestationRequest({
			actionId: 'confirm-entry',
			item: { id: 'req 1' },
		})
		expect(h.deps.post).toHaveBeenCalledWith(
			'/index.php/apps/stackiq/api/attestation-requests/req%201/confirm',
		)
		expect(h.deps.refresh).toHaveBeenCalledTimes(1)
		expect(h.deps.notifyError).not.toHaveBeenCalled()
	})

	it('Confirm shows the refusal and does not refetch', async () => {
		const h = handlers({
			post: vi
				.fn()
				.mockRejectedValue({
					response: {
						data: { message: 'This request was already answered' },
					},
				}),
		})
		await h.confirmAttestationRequest({ item: { '@self': { id: 'req-2' } } })
		expect(h.deps.post).toHaveBeenCalledWith(
			'/index.php/apps/stackiq/api/attestation-requests/req-2/confirm',
		)
		expect(h.deps.notifyError).toHaveBeenCalledWith(
			'This request was already answered',
		)
		expect(h.deps.refresh).not.toHaveBeenCalled()
	})

	it('Confirm without a row id does nothing', async () => {
		const h = handlers()
		await h.confirmAttestationRequest({ item: {} })
		expect(h.deps.post).not.toHaveBeenCalled()
	})

	it('Open entry opens the usage or module page of the request', () => {
		const h = handlers()
		h.openAttestationEntry({ item: { entrySchema: 'usage', entryId: 'u1' } })
		h.openAttestationEntry({ item: { entrySchema: 'module', entryId: 'm1' } })
		h.openAttestationEntry({ item: { entrySchema: 'contract', entryId: 'c1' } })
		expect(h.deps.assign.mock.calls).toEqual([
			['/index.php/apps/stackiq/gebruik/u1'],
			['/index.php/apps/stackiq/modules/m1'],
		])
	})
})

describe('the confirmation pages', () => {
	const page = (id) => merged.pages.find((p) => p.id === id)

	it("My confirmation requests lists the signed-in owner's pending requests with Confirm and Open entry", () => {
		expect(page('MyAttestations')).toMatchObject({
			route: '/my-confirmations',
			type: 'index',
		})
		expect(page('MyAttestations').config.schema).toBe('attestationRequest')
		expect(page('MyAttestations').config.filter).toEqual({
			assigneeUserId: '@me',
			status: 'pending',
		})
		expect(page('MyAttestations').config.actions.map((a) => a.handler)).toEqual([
			'confirmAttestationRequest',
			'openAttestationEntry',
		])
	})

	it('the round page counts pending, confirmed, corrected and overdue requests of the round', () => {
		const detail = page('AttestationRoundDetail')
		expect(detail).toMatchObject({
			route: '/confirmation-rounds/:id',
			type: 'detail',
		})
		const stats = detail.config.widgets.filter((w) => w.type === 'stat')
		expect(stats.map((w) => w.content.source.filter)).toEqual([
			{ round: '@objectId', status: 'pending' },
			{ round: '@objectId', status: 'confirmed' },
			{ round: '@objectId', status: 'corrected' },
			{ round: '@objectId', status: 'overdue' },
		])
		const lists = detail.config.widgets.filter((w) => w.type === 'object-list')
		expect(
			lists.every((w) =>
				w.content.columns.some((c) => c.key === 'assigneeUserId'),
			),
		).toBe(true)
		const placed = detail.config.layout.map((l) => l.widgetId).sort()
		expect(placed).toEqual(detail.config.widgets.map((w) => w.id).sort())
	})

	it('the rounds list starts a round and opens the round page', () => {
		const rounds = page('AttestationRounds')
		expect(rounds.config.headerActions.map((a) => a.handler)).toEqual([
			'startAttestationRound',
		])
		expect(rounds.config.rowRoute).toBe('AttestationRoundDetail')
	})

	it('both pages are under Organisations, not at the top level', () => {
		const group = merged.menu.find((m) => m.id === 'Organisaties')
		expect((group.children || []).map((c) => c.id)).toEqual(
			expect.arrayContaining(['MyAttestations', 'AttestationRounds']),
		)
		expect(merged.menu.map((m) => m.id)).not.toContain('MyAttestations')
	})

	it('registers the three handlers and the dialog', () => {
		const registry = fs.readFileSync(
			path.resolve(__dirname, '../../src/customComponents.js'),
			'utf8',
		)
		expect(registry).toMatch(/\.\.\.createAttestationHandlers\(/)
		const dialogs = fs.readFileSync(
			path.resolve(__dirname, '../../src/dialogs/Dialogs.vue'),
			'utf8',
		)
		expect(dialogs).toContain(
			`navigationStore.dialog === '${START_ROUND_DIALOG}'`,
		)
	})
})
