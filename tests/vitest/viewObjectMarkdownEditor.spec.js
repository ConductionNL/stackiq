// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The object view's markdown editor plumbing and small helpers, called with a
 * stand-in component instance: the editor options and their load hooks, the
 * initial value handed to a new editor, when no editor is built, teardown
 * that survives a failing destroy, border stripping, reading the editor's
 * content back, clipboard copy, input focus, invalid JSON, and the error a
 * failed label save shows.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const mocks = vi.hoisted(() => ({
	Editor: vi.fn(function(options) {
		this.options = options
	}),
	fetchRelatedData: vi.fn(),
}))

vi.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'tok' }))
vi.mock('@toast-ui/editor', () => ({ Editor: mocks.Editor }))
vi.mock('../../src/store/store.js', () => ({
	catalogStore: {},
	navigationStore: {},
	objectStore: { fetchRelatedData: mocks.fetchRelatedData },
}))

const { default: ViewObject } =
	await import('../../src/modals/object/ViewObject.vue')

const methods = ViewObject.methods

/**
 * A stand-in component instance with every method bound to it.
 *
 * @param {object} state State to start from.
 * @return {object} The instance.
 */
function instance(state = {}) {
	const ctx = {
		formData: {},
		objectProperties: [],
		markdownEditors: {},
		selectedProperty: null,
		isCopied: false,
		error: null,
		success: null,
		jsonData: '',
		showProperties: false,
		currentObject: { id: 'obj-1', '@self': { register: 'r', schema: 's' } },
		$refs: {},
		$nextTick: (callback) => callback(),
		getSchemaProperties: () => ({}),
		...state,
	}
	for (const [name, method] of Object.entries(methods)) {
		if (!(name in ctx)) {
			ctx[name] = method.bind(ctx)
		}
	}
	return ctx
}

beforeEach(() => {
	mocks.Editor.mockClear()
	mocks.fetchRelatedData.mockReset()
	vi.spyOn(console, 'warn').mockImplementation(() => {})
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	vi.useRealTimers()
	vi.unstubAllGlobals()
	vi.restoreAllMocks()
})

describe('ViewObject markdown editor options', () => {
	it('strips the borders when the editor loads or changes mode, and refuses image uploads', () => {
		const ctx = instance({ removeBordersFromEditor: vi.fn() })
		const options = methods.getMarkdownEditorOptions.call(ctx, 'body')
		const editor = { name: 'ed' }

		options.events.load(editor)
		options.events.changeMode(editor)
		expect(ctx.removeBordersFromEditor.mock.calls).toEqual([[editor], [editor]])
		expect(options.hooks.addImageBlobHook()).toBe(false)
		expect(options.placeholder).toBe('body')
		expect(options.hideModeSwitch).toBe(true)
	})

	it('starts from the edited value, else the shown value, else empty', () => {
		const ctx = instance({
			formData: { edited: 0 },
			objectProperties: [['shown', 'From object'], ['empty', null]],
		})
		expect(methods.getMarkdownInitialValue.call(ctx, 'edited')).toBe('0')
		expect(methods.getMarkdownInitialValue.call(ctx, 'shown')).toBe('From object')
		expect(methods.getMarkdownInitialValue.call(ctx, 'empty')).toBe('')
		expect(methods.getMarkdownInitialValue.call(ctx, 'absent')).toBe('')
	})
})

describe('ViewObject markdown editor lifetime', () => {
	it('builds no editor without a container or when one exists for the key', () => {
		methods.createMarkdownEditor.call(instance(), 'body')
		const existing = { kept: true }
		const ctx = instance({
			$refs: { markdownEditorContainer: document.createElement('div') },
			markdownEditors: { body: existing },
		})
		methods.createMarkdownEditor.call(ctx, 'body')
		expect(mocks.Editor).not.toHaveBeenCalled()
		expect(ctx.markdownEditors.body).toBe(existing)
	})

	it('builds the editor on a single container ref', () => {
		const el = document.createElement('div')
		const ctx = instance({ $refs: { markdownEditorContainer: el }, formData: { body: 'Text' } })
		methods.createMarkdownEditor.call(ctx, 'body')
		expect(mocks.Editor).toHaveBeenCalledTimes(1)
		const options = mocks.Editor.mock.calls[0][0]
		expect(options.el).toBe(el)
		expect(options.initialValue).toBe('Text')
		expect(options.height).toBe('400px')
		expect(ctx.markdownEditors.body.options).toBe(options)
	})

	it('drops every editor even when destroying one throws', () => {
		const failing = { destroy: vi.fn(() => { throw new Error('gone') }) }
		const fine = { destroy: vi.fn() }
		const ctx = instance({ markdownEditors: { a: failing, b: fine, c: null } })
		methods.destroyMarkdownEditors.call(ctx)
		expect(fine.destroy).toHaveBeenCalledTimes(1)
		expect(ctx.markdownEditors).toEqual({})
		expect(console.warn).toHaveBeenCalledWith('Could not destroy markdown editor:', expect.any(Error))
	})
})

describe('ViewObject editor border stripping', () => {
	it('removes the borders from the editor and everything inside it', () => {
		const root = document.createElement('div')
		root.style.border = '1px solid red'
		const child = document.createElement('span')
		child.style.boxShadow = '1px 1px red'
		root.appendChild(child)

		methods.removeBordersFromEditor.call(instance(), { getEl: () => root })
		for (const el of [root, child]) {
			expect(el.style.borderStyle).toBe('none')
			expect(el.style.borderColor).toBe('transparent')
			expect(el.style.boxShadow).toBe('none')
			expect(el.style.outline).toBe('none')
		}
	})

	it('does nothing without an element and warns when the editor throws', () => {
		methods.removeBordersFromEditor.call(instance(), { getEl: () => null })
		expect(console.warn).not.toHaveBeenCalled()

		methods.removeBordersFromEditor.call(instance(), { getEl: () => { throw new Error('x') } })
		expect(console.warn).toHaveBeenCalledWith('Could not remove borders from editor:', expect.any(Error))
	})
})

describe('ViewObject reading the editor back', () => {
	it.each([
		['markdown', { getMarkdown: () => '# md', getHTML: () => '<p>no</p>' }, '# md'],
		['HTML when there is no markdown', { getHTML: () => '<p>html</p>' }, '<p>html</p>'],
		['a plain string', 'raw text', 'raw text'],
		['nothing usable', { other: true }, ''],
		['nothing at all', null, ''],
		['empty when reading throws', { getMarkdown: () => { throw new Error('x') } }, ''],
	])('stores %s', (label, editor, expected) => {
		const ctx = instance({ formData: { body: 'old' } })
		methods.updateMarkdownValue.call(ctx, 'body', editor)
		expect(ctx.formData.body).toBe(expected)
	})
})

describe('ViewObject small helpers', () => {
	it('copies text to the clipboard and resets the copied flag after two seconds', async () => {
		vi.useFakeTimers()
		const writeText = vi.fn(async () => {})
		vi.stubGlobal('navigator', { clipboard: { writeText } })
		const ctx = instance()
		await methods.copyToClipboard.call(ctx, 'payload')
		expect(writeText).toHaveBeenCalledWith('payload')
		expect(ctx.isCopied).toBe(true)
		vi.advanceTimersByTime(2000)
		expect(ctx.isCopied).toBe(false)
	})

	it('stays uncopied when the clipboard refuses', async () => {
		vi.stubGlobal('navigator', { clipboard: { writeText: vi.fn(async () => { throw new Error('denied') }) } })
		const ctx = instance()
		await methods.copyToClipboard.call(ctx, 'payload')
		expect(ctx.isCopied).toBe(false)
	})

	it('focuses and selects the input of the selected property', () => {
		const container = document.createElement('div')
		const input = document.createElement('input')
		input.value = 'abc'
		container.appendChild(input)
		document.body.appendChild(container)
		const ctx = instance({ $refs: { propertyValueInput: [{ $el: container }] } })

		methods.selectProperty.call(ctx, 'title')
		expect(ctx.selectedProperty).toBe('title')
		expect(document.activeElement).toBe(input)
		expect(input.selectionStart).toBe(0)
		expect(input.selectionEnd).toBe(3)
		container.remove()
	})

	it('reports invalid JSON instead of replacing the form', () => {
		const ctx = instance({ jsonData: '{ nope', formData: { kept: 1 } })
		methods.updateFormFromJson.call(ctx)
		expect(ctx.error).toBe('Invalid JSON format')
		expect(ctx.formData).toEqual({ kept: 1 })
	})

	it('shows the properties on continue', () => {
		const ctx = instance()
		methods.proceedToProperties.call(ctx)
		expect(ctx.showProperties).toBe(true)
	})
})

describe('ViewObject label save failure', () => {
	it('shows the server error and clears it after five seconds', async () => {
		vi.useFakeTimers()
		const fetchMock = vi.fn(async () => ({ ok: false, statusText: 'Forbidden' }))
		vi.stubGlobal('fetch', fetchMock)
		const ctx = instance({ editingTags: 7 })

		await methods.saveTags.call(ctx, { id: 7 }, ['a'])
		expect(fetchMock).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/r/s/obj-1/files/7',
			expect.objectContaining({ method: 'PUT', body: JSON.stringify({ tags: ['a'] }) }),
		)
		expect(ctx.error).toBe('Failed to save file labels: Failed to update file tags: Forbidden')
		expect(ctx.editingTags).toBe(7)
		expect(mocks.fetchRelatedData).not.toHaveBeenCalled()
		vi.advanceTimersByTime(5000)
		expect(ctx.error).toBeNull()
	})
})
