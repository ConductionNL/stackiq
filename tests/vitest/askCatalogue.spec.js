/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Ask page (insight-assistant-answers-and-action-trail task 2): what it
 * posts, how it reads the answer, what it says when the assistant cannot
 * answer, and that the page and its menu entry are declared, the entry shown
 * only when hermiq is installed.
 *
 * @spec openspec/changes/archive/2026-11-24-insight-assistant-answers-and-action-trail/specs/assistant-insight/spec.md#requirement-req-ask-002-the-ask-panel-is-only-offered-when-hermiq-is-installed
 */
import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'
import manifest from '../../src/manifest.json'
import menuLayout from '../../src/menu-layout.json'
import { askBody, askFailure, MAX_QUESTION, readAnswer } from '../../src/utils/askCatalogue.js'

const echo = (text) => text

describe('askBody', () => {
	it('trims the question and carries the conversation', () => {
		expect(askBody('  Which modules support Zaakgericht werken?  ', 'sess-1'))
			.toEqual({ question: 'Which modules support Zaakgericht werken?', sessionId: 'sess-1' })
		expect(askBody('Who runs the BRP connection?')).toEqual({ question: 'Who runs the BRP connection?', sessionId: null })
	})

	it('asks nothing for an empty or too long question', () => {
		expect(askBody('   ')).toBeNull()
		expect(askBody(undefined)).toBeNull()
		expect(askBody('x'.repeat(MAX_QUESTION + 1))).toBeNull()
		expect(askBody('x'.repeat(MAX_QUESTION))).not.toBeNull()
	})
})

describe('readAnswer', () => {
	it('lists the cited and the searched entries with their links', () => {
		const answer = readAnswer({
			sessionId: 'sess-2',
			reply: 'Two modules support it [E1] [E2].',
			sources: [{ ref: 'E1', schema: 'module', id: 'm-1', title: 'Zaaksysteem', url: '/index.php/apps/stackiq/modules/m-1' }],
			searched: [
				{ ref: 'E1', schema: 'module', id: 'm-1', title: 'Zaaksysteem', url: '/index.php/apps/stackiq/modules/m-1', summary: 'Vendor: Acme' },
				{ ref: 'E2', schema: 'module', id: 'm-2', title: '', url: '' },
			],
		})

		expect(answer.empty).toBe(false)
		expect(answer.sessionId).toBe('sess-2')
		expect(answer.reply).toBe('Two modules support it [E1] [E2].')
		expect(answer.sources).toEqual([{ ref: 'E1', title: 'Zaaksysteem', url: '/index.php/apps/stackiq/modules/m-1', schema: 'module', summary: '' }])
		// An entry without a title is listed by its id.
		expect(answer.searched.map((e) => e.title)).toEqual(['Zaaksysteem', 'm-2'])
		expect(answer.searched[0].summary).toBe('Vendor: Acme')
	})

	it('marks an answer without searched entries as empty', () => {
		const answer = readAnswer({ sessionId: null, reply: '', sources: [], searched: [] })

		expect(answer.empty).toBe(true)
		expect(answer.sources).toEqual([])
		expect(readAnswer(null).empty).toBe(true)
	})
})

describe('askFailure', () => {
	it('says the assistant is not available on 503', () => {
		expect(askFailure({ response: { status: 503, data: { error: 'The assistant is not available.' } } }, echo)).toBe('The assistant is not available.')
	})

	it('asks to wait on 429', () => {
		expect(askFailure({ response: { status: 429 } }, echo)).toBe('You asked many questions in a short time. Try again in a minute.')
	})

	it('passes the server message on, else a general one', () => {
		expect(askFailure({ response: { status: 400, data: { error: 'Ask a question of at most 2000 characters.' } } }, echo)).toBe('Ask a question of at most 2000 characters.')
		expect(askFailure(new Error('Network Error'), echo)).toBe('The assistant could not answer this question.')
	})
})

describe('the Ask page in the manifest', () => {
	it('is a custom page at /ask drawn by a registered AskView', () => {
		const page = manifest.pages.find((p) => p.id === 'Ask')
		expect(page).toMatchObject({ route: '/ask', type: 'custom', component: 'AskView' })
		const registered = fs.readFileSync(path.resolve(__dirname, '../../src/customComponents.js'), 'utf8')
		expect(registered).toMatch(/import AskView from '\.\/views\/AskView\.vue'/)
		expect(registered).toMatch(/^\tAskView,$/m)
	})

	it('has a menu entry under Reports & Compliance shown only with hermiq installed', () => {
		const entry = manifest.menu.find((m) => m.id === 'Ask')
		expect(entry).toMatchObject({ route: 'Ask', visibleIf: { appInstalled: 'hermiq' } })
		expect(menuLayout.relocations.Ask).toBe('ReportsCompliance')
	})

	it('posts to the ask route the server declares', () => {
		const view = fs.readFileSync(path.resolve(__dirname, '../../src/views/AskView.vue'), 'utf8')
		const routes = fs.readFileSync(path.resolve(__dirname, '../../appinfo/routes.php'), 'utf8')
		expect(view).toMatch(/generateUrl\('\/apps\/stackiq\/api\/ask'\)/)
		expect(routes).toMatch(/'url' => '\/api\/ask', 'verb' => 'POST'/)
	})
})
