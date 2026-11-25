// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * The Export menu on an architecture view: the three actions in order, the
 * SVG download link, the two document actions disabled with the reason when
 * filinq is not there, and a document request that reports where it was filed.
 *
 * @spec openspec/specs/architecture-view-office-export/spec.md#requirement-req-avo-003-the-document-actions-shall-say-why-they-are-unavailable-when-filinq-cannot-take-the-request
 * @spec openspec/specs/architecture-view-office-export/spec.md#requirement-req-avo-002-stackiq-shall-ask-filinq-for-a-word-document-or-a-powerpoint-slide-that-holds-the-view
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const state = vi.hoisted(() => ({ documentExport: false }))
const axios = vi.hoisted(() => ({ post: vi.fn() }))
const dialogs = vi.hoisted(() => ({ showSuccess: vi.fn(), showError: vi.fn() }))

vi.mock('@nextcloud/initial-state', () => ({
	loadState: (app, key, fallback) => (key === 'view_document_export' ? state.documentExport : fallback),
}))
vi.mock('@nextcloud/axios', () => ({ default: axios }))
vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) => '/index.php' + path.replace(/\{(\w+)\}/g, (match, key) => params[key]),
}))

const { default: ViewExportMenu } = await import('../../src/components/architecture/ViewExportMenu.vue')

const stubs = {
	NcActions: { props: ['menuName'], template: '<div class="actions" :data-name="menuName"><slot /></div>' },
	NcActionLink: { props: ['href', 'download'], template: '<a class="action" :href="href" :download="download"><slot /></a>' },
	NcActionButton: {
		props: ['disabled', 'title'],
		emits: ['click'],
		template: '<button class="action" :disabled="disabled" :title="title" @click="$emit(\'click\')"><slot /></button>',
	},
	NcActionText: { template: '<p class="reason"><slot /></p>' },
}

/**
 * Mount the menu for view v1.
 *
 * @return {object} The wrapper.
 */
function menu() {
	return mount(ViewExportMenu, { props: { viewId: 'v1', viewName: 'Zaak / afhandeling' }, global: { stubs } })
}

describe('ViewExportMenu', () => {
	beforeEach(() => {
		state.documentExport = false
		axios.post.mockReset()
		dialogs.showSuccess.mockReset()
		dialogs.showError.mockReset()
	})

	it('lists Download SVG, Create Word document and Create PowerPoint slide in that order', () => {
		const wrapper = menu()

		expect(wrapper.findAll('.action').map((action) => action.text())).toEqual([
			'Download SVG',
			'Create Word document',
			'Create PowerPoint slide',
		])
		expect(wrapper.get('.actions').attributes('data-name')).toBe('Export')
	})

	it('links Download SVG to the view image with a safe file name', () => {
		const link = menu().get('a.action')

		expect(link.attributes('href')).toBe('/index.php/apps/stackiq/api/views/v1/image.svg')
		expect(link.attributes('download')).toBe('Zaak  afhandeling.svg')
	})

	it('disables the document actions with the reason when filinq is not there', () => {
		const wrapper = menu()

		const buttons = wrapper.findAll('button.action')
		expect(buttons.every((button) => button.attributes('disabled') !== undefined)).toBe(true)
		expect(buttons[0].attributes('title')).toBe('Word and PowerPoint need the document app filinq')
		expect(wrapper.get('.reason').text()).toBe('Word and PowerPoint need the document app filinq')
	})

	it('asks filinq for a Word document and links the filed file', async () => {
		state.documentExport = true
		axios.post.mockResolvedValue({ data: { name: 'View.docx', path: '/Stackiq/View.docx', fileId: 42 } })
		const wrapper = menu()

		expect(wrapper.find('.reason').exists()).toBe(false)
		await wrapper.findAll('button.action')[0].trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/stackiq/api/views/v1/document', { format: 'docx' })
		expect(dialogs.showSuccess).toHaveBeenCalledWith('View.docx was saved in Files')
		expect(wrapper.get('.view-export__saved').attributes('href')).toBe('/index.php/f/42')
		expect(wrapper.get('.view-export__saved').text()).toBe('Open in Files')
	})

	it('shows the reason when the document could not be made', async () => {
		state.documentExport = true
		axios.post.mockRejectedValue({ response: { status: 502, data: { error: 'filinq refused' } } })
		const wrapper = menu()

		await wrapper.findAll('button.action')[1].trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/stackiq/api/views/v1/document', { format: 'pptx' })
		expect(dialogs.showError).toHaveBeenCalledWith('filinq refused')
		expect(wrapper.find('.view-export__saved').exists()).toBe(false)
	})
})
