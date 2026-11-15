// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * How the object view shows values: the display text per property (themes by
 * title, time without seconds, dates localised, structures as JSON), the
 * values handed to the date and time inputs, the JSON tab helpers, file sizes,
 * the attachment selection on the files tab, and loading the tag options.
 * The methods run against a stand-in component instance. Dates are compared
 * with the same locale functions the view uses, so the expectations hold in
 * every timezone and locale.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

import { afterEach, describe, expect, it, vi } from 'vitest'

const store = vi.hoisted(() => ({
	themes: [],
	files: { results: [] },
}))

vi.mock('../../src/store/store.js', () => ({
	catalogStore: {},
	navigationStore: {},
	objectStore: {
		getCollection: () => ({ results: store.themes }),
		getRelatedData: () => store.files,
		getPagination: () => ({ pages: 1, total: 0, limit: 20 }),
	},
}))

const { default: ViewObject } =
	await import('../../src/modals/object/ViewObject.vue')

const SCHEMA = {
	born: { type: 'string', format: 'date' },
	at: { type: 'string', format: 'time' },
	stamp: { type: 'string', format: 'date-time' },
	plain: { type: 'string' },
	nameless: { type: 'string', format: 'email' },
	count: { type: 'integer' },
}

/**
 * A stand-in component instance with every ViewObject method bound to it.
 *
 * @param {object} state Instance state to start from.
 * @return {object} The instance.
 */
function instance(state = {}) {
	const ctx = { formData: {}, selectedAttachments: [], labelOptionsEdit: { options: [] }, tagsLoading: false, ...state }
	for (const [name, method] of Object.entries(ViewObject.methods)) {
		ctx[name] = method.bind(ctx)
	}
	ctx.getSchemaProperties = () => SCHEMA
	Object.defineProperty(ctx, 'paginatedFiles', { get: () => ViewObject.computed.paginatedFiles.call(ctx) })
	return ctx
}

afterEach(() => {
	vi.restoreAllMocks()
	vi.unstubAllGlobals()
	store.themes = []
	store.files = { results: [] }
})

describe('ViewObject getDisplayValue', () => {
	it('shows themes by title, by #id when untitled, and by the raw id when unknown', () => {
		store.themes = [{ id: 1, title: 'Water' }, { id: 2 }]
		const vm = instance()

		expect(vm.getDisplayValue('themes', [1, 2, 3])).toBe('Water, #2, 3')
		expect(vm.getDisplayValue('themes', 1)).toBe('Water')
		expect(instance({ formData: { themes: [2] } }).getDisplayValue('themes', [1])).toBe('#2')
	})

	it('shows an empty string for a missing value', () => {
		const vm = instance()

		expect(vm.getDisplayValue('plain', null)).toBe('')
		expect(vm.getDisplayValue('plain', undefined)).toBe('')
	})

	it('drops the seconds of a time, edited or stored', () => {
		expect(instance().getDisplayValue('at', '09:30:15')).toBe('09:30')
		expect(instance({ formData: { at: '18:05:59' } }).getDisplayValue('at', '09:30')).toBe('18:05')
	})

	it('localises a stored date string and an edited timestamp', () => {
		const stored = '2026-03-04T05:06:07Z'
		expect(instance().getDisplayValue('plain', stored)).toBe(new Date(stored).toLocaleString())
		expect(instance().getDisplayValue('born', '2026-03-04')).toBe(new Date('2026-03-04').toLocaleString())
		expect(instance({ formData: { stamp: stored } }).getDisplayValue('stamp', null)).toBe(
			new Date(stored).toLocaleString(),
		)
	})

	it('returns an edited value that is not a timestamp as it is', () => {
		expect(instance({ formData: { plain: 'edited' } }).getDisplayValue('plain', 'stored')).toBe('edited')
		expect(instance({ formData: { born: '2026-03-04' } }).getDisplayValue('born', null)).toBe('2026-03-04')
	})

	it('shows structures as JSON and other values as they are', () => {
		const vm = instance()

		expect(vm.getDisplayValue('plain', ['a', 1])).toBe('["a",1]')
		expect(vm.getDisplayValue('plain', { a: 1 })).toBe('{"a":1}')
		expect(vm.getDisplayValue('count', 42)).toBe(42)
		expect(vm.getDisplayValue('plain', 'just text')).toBe('just text')
	})
})

describe('ViewObject date and time input values', () => {
	it('formats the value for the HTML input of its type', () => {
		const stamp = '2026-03-04T05:06:07Z'
		const vm = instance()

		expect(vm.getDateTimeValue('born', stamp)).toBe('2026-03-04')
		expect(vm.getDateTimeValue('stamp', stamp)).toBe('2026-03-04T05:06')
		expect(vm.getDateTimeValue('at', stamp)).toBe(new Date(stamp).toTimeString().split(' ')[0].substring(0, 5))
		expect(vm.getDateTimeValue('plain', stamp)).toBe(stamp)
	})

	it('prefers the edited value and answers empty for none or an invalid date', () => {
		expect(instance({ formData: { born: '2027-01-02T00:00:00Z' } }).getDateTimeValue('born', '2026-03-04')).toBe('2027-01-02')
		expect(instance().getDateTimeValue('born', '')).toBe('')
		expect(instance().getDateTimeValue('born', 'not a date')).toBe('')
	})

	it('hands the picker a date at noon for a date, today at the time for a time, and the stamp otherwise', () => {
		const vm = instance()

		const date = vm.getDateTimePickerValue('born', '2026-03-04')
		expect([date.getFullYear(), date.getMonth(), date.getDate(), date.getHours()]).toEqual([2026, 2, 4, 12])

		const time = vm.getDateTimePickerValue('at', '09:30')
		expect([time.getHours(), time.getMinutes()]).toEqual([9, 30])

		const stamp = '2026-03-04T05:06:07Z'
		expect(vm.getDateTimePickerValue('stamp', stamp).getTime()).toBe(new Date(stamp).getTime())
		expect(vm.getDateTimePickerValue('born', stamp).getTime()).toBe(new Date(stamp).getTime())
		expect(vm.getDateTimePickerValue('at', stamp).getTime()).toBe(new Date(stamp).getTime())
	})

	it('hands the picker null for no value or an invalid one', () => {
		const vm = instance()

		expect(vm.getDateTimePickerValue('born', '')).toBeNull()
		expect(vm.getDateTimePickerValue('stamp', 'garbage')).toBeNull()
		expect(vm.getDateTimePickerValue('at', '25:99')).toBeNull()
	})

	it('chooses the picker type from the format', () => {
		const vm = instance()

		expect(['born', 'at', 'stamp', 'nameless', 'plain', 'unknown'].map((key) => vm.getDateTimePickerType(key)))
			.toEqual(['date', 'time', 'datetime', 'datetime', 'datetime', 'datetime'])
	})

	it('knows which properties are date or time properties, and their format', () => {
		const vm = instance()

		expect(['born', 'at', 'stamp', 'plain', 'count'].map((key) => Boolean(vm.isDateTimeProperty(key))))
			.toEqual([true, true, true, false, false])
		expect(vm.getDateTimePropertyFormat('at')).toBe('time')
		expect(vm.getDateTimePropertyFormat('plain')).toBe('unknown')
	})

	it('formats a stored date or time value for reading', () => {
		const vm = instance()
		const stamp = '2026-03-04T05:06:07Z'

		expect(vm.formatDateTimeValue('born', '2026-03-04')).toBe(new Date('2026-03-04T12:00:00').toLocaleDateString())
		expect(vm.formatDateTimeValue('born', stamp)).toBe(new Date(stamp).toLocaleDateString())
		expect(vm.formatDateTimeValue('at', '09:30:15')).toBe('09:30:15')
		expect(vm.formatDateTimeValue('at', stamp)).toBe(new Date(stamp).toLocaleTimeString())
		expect(vm.formatDateTimeValue('stamp', stamp)).toBe(new Date(stamp).toLocaleString())
		expect(vm.formatDateTimeValue('plain', 'as is')).toBe('as is')
		expect(vm.formatDateTimeValue('born', '')).toBe('')
	})
})

describe('ViewObject JSON helpers', () => {
	it('accepts only non-blank parseable JSON', () => {
		const vm = instance()

		expect(vm.isValidJson('{"a":1}')).toBe(true)
		expect(vm.isValidJson('[]')).toBe(true)
		expect(vm.isValidJson('{a:1}')).toBe(false)
		expect(vm.isValidJson('   ')).toBe(false)
		expect(vm.isValidJson('')).toBe(false)
	})

	it('pretty-prints valid JSON and leaves invalid or empty JSON alone', () => {
		const vm = instance({ jsonData: '{"a":1,"b":[2]}' })
		vm.formatJSON()
		expect(vm.jsonData).toBe('{\n  "a": 1,\n  "b": [\n    2\n  ]\n}')

		const broken = instance({ jsonData: '{"a":' })
		broken.formatJSON()
		expect(broken.jsonData).toBe('{"a":')

		const empty = instance({ jsonData: '' })
		empty.formatJSON()
		expect(empty.jsonData).toBe('')
		expect(vm.formatValue({ a: 1 })).toBe('{\n  "a": 1\n}')
	})
})

describe('ViewObject files tab', () => {
	it('formats file sizes', () => {
		const vm = instance()

		expect(vm.formatFileSize(0)).toBe('n/a')
		expect(vm.formatFileSize(500)).toBe('< 1 KB')
		expect(vm.formatFileSize(1536)).toBe('1.5 KB')
		expect(vm.formatFileSize(5 * 1024 * 1024)).toBe('5.0 MB')
	})

	it('selects and deselects one file without duplicates', () => {
		const vm = instance({ selectedAttachments: ['a'] })

		vm.toggleFileSelection('b', true)
		vm.toggleFileSelection('b', true)
		vm.toggleFileSelection('a', false)

		expect(vm.selectedAttachments).toEqual(['b'])
	})

	it('selects and deselects the whole page, keeping files from other pages', () => {
		store.files = { results: [{ id: 'p1' }, { id: 'p2' }] }
		const vm = instance({ selectedAttachments: ['other', 'p1'] })

		expect(ViewObject.computed.allFilesSelected.call(vm)).toBe(false)
		vm.toggleSelectAllFiles(true)
		expect(vm.selectedAttachments).toEqual(['other', 'p1', 'p2'])
		expect(ViewObject.computed.allFilesSelected.call(vm)).toBe(true)

		vm.toggleSelectAllFiles(false)
		expect(vm.selectedAttachments).toEqual(['other'])
	})

	it('treats files that are not a list as an empty page', () => {
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		store.files = { results: 'oops' }
		const vm = instance()

		expect(vm.paginatedFiles).toEqual([])
		expect(ViewObject.computed.allFilesSelected.call(vm)).toBe(false)
		expect(warn).toHaveBeenCalled()
	})
})

describe('ViewObject tag options', () => {
	it('loads the tags into the label options and stops loading', async () => {
		const fetch = vi.fn().mockResolvedValue({ json: async () => ['urgent', 'archive'] })
		vi.stubGlobal('fetch', fetch)
		const vm = instance()

		await vm.getAllTags()

		expect(fetch).toHaveBeenCalledWith('/index.php/apps/openregister/api/tags', { method: 'get' })
		expect(vm.labelOptionsEdit.options).toEqual(['urgent', 'archive'])
		expect(vm.tagsLoading).toBe(false)
	})

	it('keeps the options and stops loading when the request fails', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')))
		const vm = instance({ labelOptionsEdit: { options: ['kept'] } })

		await vm.getAllTags()

		expect(vm.labelOptionsEdit.options).toEqual(['kept'])
		expect(vm.tagsLoading).toBe(false)
	})
})
