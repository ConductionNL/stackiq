// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The CMDB file import form, mounted for an admin: choosing a file enables
 * the button, submitting the form posts the file with the request token, and
 * the reply, a refusal, an unreadable reply or a lost connection each show
 * their own note.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

vi.mock('../../src/store/store.js', () => ({ objectStore: {} }))
vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: () => ({ isAdmin: true }),
	getRequestToken: () => 'token-1',
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => String(url) }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		String(text).replace(/\{(\w+)\}/g, (_, key) => String(vars?.[key] ?? `{${key}}`)),
}))

/**
 * A stub that renders its slots and keeps its props readable.
 *
 * @param {string} name The component name.
 * @return {object} The stub.
 */
function stub(name) {
	return defineComponent({
		name,
		props: ['type', 'size', 'variant', 'disabled', 'to', 'href'],
		emits: ['click'],
		render() {
			return h('div', { class: name }, [this.$slots.default?.() ?? [], this.$slots.icon?.() ?? []])
		},
	})
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton'),
	NcLoadingIcon: stub('NcLoadingIcon'),
	NcNoteCard: stub('NcNoteCard'),
}))

const { default: CmdbOverview } = await import('../../src/views/cmdb/CmdbOverview.vue')

let fetchMock

/**
 * Mount the overview once its status request has answered.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountOverview() {
	const wrapper = mount(CmdbOverview, { global: { stubs: { 'router-link': stub('RouterLink') } } })
	await flushPromises()
	fetchMock.mockClear()
	return wrapper
}

/**
 * Choose a file in the import input.
 *
 * @param {object} wrapper The wrapper.
 * @param {File[]} files The files.
 * @return {Promise<void>}
 */
async function choose(wrapper, files) {
	const input = wrapper.find('#cmdb-file')
	Object.defineProperty(input.element, 'files', { value: files, configurable: true })
	await input.trigger('change')
}

/**
 * The import section's submit button.
 *
 * @param {object} wrapper The wrapper.
 * @return {object} The button.
 */
function importButton(wrapper) {
	return wrapper.find('[data-testid="cmdb-file-import"]').findComponent({ name: 'NcButton' })
}

/**
 * The import section's note.
 *
 * @param {object} wrapper The wrapper.
 * @return {object} The note card.
 */
function importNote(wrapper) {
	return wrapper.find('[data-testid="cmdb-file-import"]').findComponent({ name: 'NcNoteCard' })
}

const file = new File(['recordId,name\n1,Zaaksysteem'], 'cmdb.csv', { type: 'text/csv' })

beforeEach(() => {
	fetchMock = vi.fn(async () => ({ ok: true, json: async () => ({ enabled: false }) }))
	vi.stubGlobal('fetch', fetchMock)
})

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('CmdbOverview file import form', () => {
	it('enables the import once a file is chosen, and disables it again when the choice is cleared', async () => {
		const wrapper = await mountOverview()
		expect(importButton(wrapper).props('disabled')).toBe(true)

		await choose(wrapper, [file])
		expect(importButton(wrapper).props('disabled')).toBe(false)

		await choose(wrapper, [])
		expect(importButton(wrapper).props('disabled')).toBe(true)
	})

	it('posts the chosen file with the request token and says the import started', async () => {
		const wrapper = await mountOverview()
		await choose(wrapper, [file])
		fetchMock.mockResolvedValueOnce({ ok: true, json: async () => ({ started: true, rows: 12 }) })

		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(fetchMock).toHaveBeenCalledTimes(1)
		const [url, init] = fetchMock.mock.calls[0]
		expect(url).toBe('/apps/stackiq/api/itsm/import')
		expect(init.method).toBe('POST')
		expect(init.headers).toEqual({ requesttoken: 'token-1' })
		expect(init.body.get('file')).toBe(file)
		expect(importNote(wrapper).props('type')).toBe('success')
		expect(importNote(wrapper).text()).toBe('The import of 12 rows has started. The applications appear as the run goes.')
		expect(importButton(wrapper).props('disabled')).toBe(false)
	})

	it('shows the server refusal as an error', async () => {
		const wrapper = await mountOverview()
		await choose(wrapper, [file])
		fetchMock.mockResolvedValueOnce({ ok: false, json: async () => ({ message: 'The file has no recordId column.' }) })

		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(importNote(wrapper).props('type')).toBe('error')
		expect(importNote(wrapper).text()).toBe('The file has no recordId column.')
	})

	it('says the import did not start when the reply is not JSON or not started', async () => {
		const wrapper = await mountOverview()
		await choose(wrapper, [file])
		fetchMock.mockResolvedValueOnce({ ok: true, json: async () => { throw new SyntaxError('not json') } })

		await wrapper.find('form').trigger('submit')
		await flushPromises()
		expect(importNote(wrapper).text()).toBe('The import did not start.')

		fetchMock.mockResolvedValueOnce({ ok: true, json: async () => ({ started: false }) })
		await wrapper.find('form').trigger('submit')
		await flushPromises()
		expect(importNote(wrapper).props('type')).toBe('error')
		expect(importNote(wrapper).text()).toBe('The import did not start.')
	})

	it('says the file could not be sent when the request fails', async () => {
		const wrapper = await mountOverview()
		await choose(wrapper, [file])
		fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'))

		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(importNote(wrapper).props('type')).toBe('error')
		expect(importNote(wrapper).text()).toBe('The file could not be sent. Check your connection and try again.')
	})
})
