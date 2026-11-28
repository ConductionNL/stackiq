/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Assistant actions page (insight-assistant-answers-and-action-trail
 * task 3): the audit-list request it sends (filtered server side on `mcp.` and
 * `stackiq.`), how it reads each row and its outcome, where a record links to,
 * what it says when the trail cannot be read, and that the page and its menu
 * entry are declared.
 *
 * @spec openspec/changes/archive/2026-11-24-insight-assistant-answers-and-action-trail/specs/assistant-insight/spec.md#requirement-req-ask-003-assistant-actions-on-catalogue-records-are-listed
 */
import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'
import manifest from '../../src/manifest.json'
import menuLayout from '../../src/menu-layout.json'
import {
	actionsFailure,
	actionsQuery,
	AUDIT_TRAIL_PATH,
	detailRoutes,
	outcomeOf,
	PAGE_SIZE,
	readAction,
	readActionsPage,
	recordLocation,
} from '../../src/utils/assistantActions.js'

const echo = (text) => text

describe('actionsQuery', () => {
	it('asks the first page by an empty cursor, filtered on mcp. actions of stackiq. tools', () => {
		expect(actionsQuery()).toEqual({ cursor: '', limit: PAGE_SIZE, actionPrefix: 'mcp.', toolPrefix: 'stackiq.' })
	})

	it('carries the cursor of a later page and the tool, user and period filters', () => {
		expect(actionsQuery({ tool: ' stackiq.submitContractApproval ', user: 'anna', from: '2026-10-06', to: '2026-10-06' }, 812)).toEqual({
			cursor: 812,
			limit: PAGE_SIZE,
			actionPrefix: 'mcp.',
			toolPrefix: 'stackiq.',
			tool: 'stackiq.submitContractApproval',
			actor: 'anna',
			from: '2026-10-06',
			to: '2026-10-06',
		})
	})

	it('reads a bare tool name as one of stackiq\'s tools and drops blank filters', () => {
		const query = actionsQuery({ tool: 'submitContractApproval', user: '  ', from: '', to: undefined })
		expect(query.tool).toBe('stackiq.submitContractApproval')
		expect(query).not.toHaveProperty('actor')
		expect(query).not.toHaveProperty('from')
		expect(query).not.toHaveProperty('to')
	})
})

describe('reading the audit list', () => {
	const entry = {
		id: 4711,
		created: '2026-10-06T10:15:00+00:00',
		user: 'anna',
		userName: 'Anna de Vries',
		action: 'mcp.submitContractApproval',
		toolId: 'stackiq.submitContractApproval',
		schema: 12,
		objectUuid: 'c-2025-0042',
		resultSummary: { count: 1, isError: false },
	}

	it('shows when, user, tool, record and outcome of a row', () => {
		expect(readAction(entry)).toEqual({
			id: '4711',
			when: '2026-10-06T10:15:00+00:00',
			user: 'Anna de Vries',
			tool: 'stackiq.submitContractApproval',
			schemaId: '12',
			recordId: 'c-2025-0042',
			outcome: { ok: true, error: '' },
		})
	})

	it('falls back to the user id and leaves a row without a record unlinked', () => {
		const row = readAction({ id: 5, user: 'bob', toolId: 'stackiq.search', schema: null, objectUuid: null })
		expect(row.user).toBe('bob')
		expect(row.schemaId).toBe('')
		expect(recordLocation(row, {}, {})).toBeNull()
	})

	it('reads a failed call with its error class', () => {
		expect(outcomeOf({ isError: true, errorClass: 'AccessDenied' })).toEqual({ ok: false, error: 'AccessDenied' })
		expect(outcomeOf({ isError: true })).toEqual({ ok: false, error: '' })
		expect(outcomeOf(null)).toEqual({ ok: true, error: '' })
	})

	it('reads a page and its next cursor, null at the end', () => {
		expect(readActionsPage({ results: [entry], nextCursor: 4711 })).toMatchObject({ nextCursor: 4711, rows: [{ id: '4711' }] })
		expect(readActionsPage({ results: [], nextCursor: null })).toEqual({ rows: [], nextCursor: null })
		expect(readActionsPage(null)).toEqual({ rows: [], nextCursor: null })
	})

	it('links a contract to its contract page', () => {
		const routes = detailRoutes(manifest)
		expect(routes.catalogContract).toBe('ContractDetail')
		expect(routes.module).toBe('ModuleDetail')
		expect(recordLocation(readAction(entry), { 12: 'catalogContract' }, routes))
			.toEqual({ name: 'ContractDetail', params: { id: 'c-2025-0042' } })
		expect(recordLocation(readAction(entry), { 12: 'unknownSchema' }, routes)).toBeNull()
	})
})

describe('actionsFailure', () => {
	it('tells a non-administrator the trail is for administrators', () => {
		expect(actionsFailure({ response: { status: 403 } }, echo)).toBe('Only administrators can review assistant actions.')
		expect(actionsFailure({ response: { status: 401 } }, echo)).toBe('Only administrators can review assistant actions.')
	})

	it('passes a bad filter\'s message and reads anything else as a load failure', () => {
		expect(actionsFailure({ response: { status: 400, data: { error: 'Invalid date' } } }, echo)).toBe('Invalid date')
		expect(actionsFailure({ response: { status: 500, data: { error: 'boom' } } }, echo)).toBe('The assistant actions could not be loaded.')
		expect(actionsFailure(new Error('network'), echo)).toBe('The assistant actions could not be loaded.')
	})
})

describe('the Assistant actions page in the manifest', () => {
	it('is a custom page at /assistant-actions drawn by a registered AssistantActionsView', () => {
		const page = manifest.pages.find((p) => p.id === 'AssistantActions')
		expect(page).toMatchObject({ route: '/assistant-actions', type: 'custom', component: 'AssistantActionsView' })
		const registered = fs.readFileSync(path.resolve(__dirname, '../../src/customComponents.js'), 'utf8')
		expect(registered).toMatch(/import AssistantActionsView from '\.\/views\/AssistantActionsView\.vue'/)
		expect(registered).toMatch(/^\tAssistantActionsView,$/m)
	})

	it('has a menu entry under Reports & Compliance', () => {
		expect(manifest.menu.find((m) => m.id === 'AssistantActions')).toMatchObject({ route: 'AssistantActions' })
		expect(menuLayout.relocations.AssistantActions).toBe('ReportsCompliance')
	})

	it('reads OpenRegister\'s audit list and keeps no copy', () => {
		const view = fs.readFileSync(path.resolve(__dirname, '../../src/views/AssistantActionsView.vue'), 'utf8')
		expect(AUDIT_TRAIL_PATH).toBe('/apps/openregister/api/audit-trails')
		expect(view).toMatch(/generateUrl\(AUDIT_TRAIL_PATH\)/)
		expect(view).not.toMatch(/axios\.(post|put|patch|delete)/)
	})
})
