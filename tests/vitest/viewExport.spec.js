/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * The Export menu of an architecture view: its actions, the filinq notice, and the document request.
 *
 * @spec openspec/changes/architecture-views-to-office-documents/specs/architecture-view-office-export/spec.md#requirement-req-avo-003-the-document-actions-shall-say-why-they-are-unavailable-when-filinq-cannot-take-the-request
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/{(\w+)}/g, (m, k) => (k in vars ? String(vars[k]) : m)),
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params = {}) => '/nc' + url.replace(/{(\w+)}/g, (m, k) => encodeURIComponent(params[k])),
}))
vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn() } }))
vi.mock('@nextcloud/initial-state', () => ({ loadState: vi.fn() }))

const {
	createViewDocument,
	documentExportAvailable,
	documentSavedMessage,
	filesLink,
	openInFilesLabel,
	viewExportActions,
	viewImageUrl,
} = await import('../../src/services/viewExport.js')

describe('viewExportActions', () => {
	it('lists Download SVG, Create Word document and Create PowerPoint slide, all enabled with filinq', () => {
		const actions = viewExportActions(true)

		expect(actions.map((a) => a.label)).toEqual(['Download SVG', 'Create Word document', 'Create PowerPoint slide'])
		expect(actions.map((a) => a.disabled)).toEqual([false, false, false])
		expect(actions.every((a) => a.reason === '')).toBe(true)
	})

	it('disables the two document actions with the filinq notice and keeps Download SVG', () => {
		const [svg, word, powerpoint] = viewExportActions(false)

		expect(svg).toEqual({ id: 'svg', label: 'Download SVG', disabled: false, reason: '' })
		expect(word.disabled).toBe(true)
		expect(powerpoint.disabled).toBe(true)
		expect(word.reason).toBe('Word and PowerPoint need the document app filinq')
		expect(powerpoint.reason).toBe(word.reason)
	})

	it('treats anything but true as unavailable', () => {
		expect(viewExportActions(undefined)[1].disabled).toBe(true)
		expect(viewExportActions('true')[2].disabled).toBe(true)
	})
})

describe('documentExportAvailable', () => {
	it('reads view_document_export from the initial state', () => {
		const load = vi.fn().mockReturnValue(true)

		expect(documentExportAvailable(load)).toBe(true)
		expect(load).toHaveBeenCalledWith('stackiq', 'view_document_export', false)
	})

	it('answers false when the state is false, not a boolean, or unreadable', () => {
		expect(documentExportAvailable(() => false)).toBe(false)
		expect(documentExportAvailable(() => 1)).toBe(false)
		expect(documentExportAvailable(() => { throw new Error('no state') })).toBe(false)
	})
})

describe('createViewDocument', () => {
	it('posts the format to the document route and answers the filed document', async () => {
		const http = { post: vi.fn().mockResolvedValue({ data: { success: true, name: 'Landschap.docx', path: '/Stackiq/Landschap.docx', fileId: 42 } }) }

		const answer = await createViewDocument('v 1', 'word', http)

		expect(http.post).toHaveBeenCalledWith('/nc/apps/stackiq/api/views/v%201/document', { format: 'docx' })
		expect(answer).toEqual({ ok: true, name: 'Landschap.docx', path: '/Stackiq/Landschap.docx', fileId: 42 })
	})

	it('asks for pptx for the PowerPoint action', async () => {
		const http = { post: vi.fn().mockResolvedValue({ data: { name: 'a.pptx', path: '/a.pptx', fileId: 1 } }) }

		await createViewDocument('v1', 'powerpoint', http)

		expect(http.post.mock.calls[0][1]).toEqual({ format: 'pptx' })
	})

	it('answers the route reason and marks a 503 as unavailable', async () => {
		const http = { post: vi.fn().mockRejectedValue({ response: { status: 503, data: { error: 'Word and PowerPoint need the document app filinq' } } }) }

		expect(await createViewDocument('v1', 'word', http)).toEqual({
			ok: false,
			error: 'Word and PowerPoint need the document app filinq',
			unavailable: true,
		})
	})

	it('falls back to a generic reason when the failure carries none', async () => {
		const http = { post: vi.fn().mockRejectedValue(new Error('network')) }

		expect(await createViewDocument('v1', 'powerpoint', http)).toEqual({
			ok: false,
			error: 'The document could not be made.',
			unavailable: false,
		})
	})

	it('refuses an unknown action without a request', async () => {
		const http = { post: vi.fn() }

		const answer = await createViewDocument('v1', 'svg', http)

		expect(answer.ok).toBe(false)
		expect(http.post).not.toHaveBeenCalled()
	})
})

describe('links and texts', () => {
	it('builds the image route and the Files link', () => {
		expect(viewImageUrl('v1')).toBe('/nc/apps/stackiq/api/views/v1/image.svg')
		expect(filesLink(42)).toBe('/nc/f/42')
	})

	it('names the filed document and offers Open in Files', () => {
		expect(documentSavedMessage('Landschap.docx')).toBe('Landschap.docx was saved in Files')
		expect(openInFilesLabel()).toBe('Open in Files')
	})
})
