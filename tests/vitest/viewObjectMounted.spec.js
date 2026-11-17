// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The object view mounted against reactive store doubles: the catalog,
 * register and schema steps of a new publication, the property table with
 * its per-type inputs, validation icons, constant toggle and drop buttons,
 * the markdown editor's lifetime, the properties / metadata / files tabs,
 * the file row and mass actions with the requests they send, label editing,
 * pagination, and the footer buttons with the store calls they make.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const mocks = vi.hoisted(() => ({
	editors: [],
}))

vi.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'tok' }))
vi.mock('@nextcloud/vue', () => Object.fromEntries([
	'NcActionButton', 'NcActions', 'NcButton', 'NcCheckboxRadioSwitch',
	'NcCounterBubble', 'NcDateTimePicker', 'NcDialog', 'NcEmptyContent',
	'NcLoadingIcon', 'NcNoteCard', 'NcSelect', 'NcTextArea', 'NcTextField',
].map((name) => [name, { name }])))
vi.mock('@toast-ui/editor', () => ({
	Editor: vi.fn(function(options) {
		this.options = options
		this.destroy = vi.fn()
		this.getMarkdown = vi.fn(() => '# Edited')
		mocks.editors.push(this)
	}),
}))
vi.mock('../../src/store/store.js', async () => {
	const { reactive } = await import('vue')
	return {
		catalogStore: reactive({ isLoading: false }),
		navigationStore: reactive({ modal: 'viewObject' }),
		objectStore: reactive({
			state: {},
			availableRegisters: [],
			availableSchemas: [],
			getActiveObject() {
				return this.state.active
			},
			getCollection(name) {
				return { results: name === 'catalog' ? this.state.catalogs : this.state.themes }
			},
			getRelatedData() {
				return { results: this.state.files }
			},
			getPagination() {
				return this.state.pagination
			},
			isLoading() {
				return this.state.loading
			},
		}),
	}
})

const { catalogStore, navigationStore, objectStore } =
	await import('../../src/store/store.js')
const { Editor } = await import('@toast-ui/editor')
const { default: ViewObject } =
	await import('../../src/modals/object/ViewObject.vue')

const API = '/index.php/apps/openregister/api/objects'

/**
 * A stub rendering its slots, emitting a click with the native event.
 *
 * @param {string} name The component name.
 * @param {string} tag The element.
 * @return {object} The stub.
 */
function stub(name, tag = 'div') {
	return defineComponent({
		name,
		inheritAttrs: false,
		props: ['name', 'disabled', 'modelValue', 'options', 'type', 'variant', 'count', 'title', 'description', 'inputLabel', 'ariaLabel', 'totalItems', 'menuName'],
		emits: ['click', 'update:modelValue', 'update:open', 'pageChanged', 'pageSizeChanged'],
		setup(props, { slots, emit, attrs }) {
			return () => h(tag, {
				class: ['stub', name, attrs.class],
				'data-label': props.inputLabel,
				'data-aria': props.ariaLabel ?? attrs['aria-label'],
				disabled: props.disabled === true ? '' : undefined,
				onClick: (event) => emit('click', event),
			}, [
				...(slots.name ? slots.name() : []),
				...(slots.icon ? slots.icon() : []),
				...(slots.default ? slots.default() : []),
				...(slots.actions ? slots.actions() : []),
			])
		},
	})
}

const NAMES = [
	'NcDialog', 'NcButton', 'NcNoteCard', 'NcCounterBubble', 'NcTextField',
	'NcTextArea', 'NcCheckboxRadioSwitch', 'NcLoadingIcon', 'NcActions',
	'NcActionButton', 'NcDateTimePicker', 'NcEmptyContent', 'NcSelect',
	'PaginationComponent', 'PublishedIcon',
]
const stubs = Object.fromEntries(NAMES.map((name) => [
	name,
	stub(name, name === 'NcButton' || name === 'NcActionButton' ? 'button' : 'div'),
]))

const SCHEMA_PROPERTIES = {
	title: { type: 'string', title: 'Title' },
	kind: { type: 'string', const: 'fixed' },
	body: { type: 'string', format: 'markdown' },
	active: { type: 'boolean' },
	born: { type: 'string', format: 'date' },
	notes: { type: 'string', format: 'text' },
	tags: { type: 'array' },
	themes: { type: 'array' },
}

const EXISTING = {
	id: 'obj-1',
	title: 'Hello',
	kind: 'fixed',
	active: 'yes',
	born: '2026-03-04',
	themes: ['t1'],
	extra: { nested: 1 },
	'@self': { id: 'obj-1', register: 1, schema: 10, version: '1.0', published: null },
}

const FILES = [
	{ id: 5, name: 'a.pdf', size: 2048, type: 'application/pdf', labels: ['x'], accessUrl: 'https://u' },
	{ id: 6, title: 'b', size: 0, labels: [] },
]

/**
 * A fetch response.
 *
 * @param {boolean} ok Whether it is ok.
 * @param {*} json The body.
 * @return {object} The response.
 */
function response(ok, json = {}) {
	return {
		ok,
		status: ok ? 200 : 500,
		statusText: ok ? 'OK' : 'Server Error',
		json: async () => json,
		text: async () => '',
	}
}

/**
 * Reset the store doubles to a given state.
 *
 * @param {object} state The object store state.
 */
function setStores(state = {}) {
	objectStore.state = {
		active: null,
		catalogs: [],
		themes: [{ id: 't1', title: 'Theme One' }, { id: 't2' }],
		files: [],
		pagination: { page: 1, pages: 1, total: 0, limit: 500 },
		loading: false,
		...state,
	}
	objectStore.availableRegisters = [
		{ id: 1, title: 'Reg One', schemas: [{ id: 10 }, { id: 11 }] },
	]
	objectStore.availableSchemas = [
		{ id: 10, title: 'Article', properties: SCHEMA_PROPERTIES },
		{ id: 11, title: 'Other', properties: {} },
	]
	objectStore.fetchCollection = vi.fn()
	objectStore.fetchRelatedData = vi.fn(async () => {})
	objectStore.setActiveObject = vi.fn()
	objectStore.setSelectedObjects = vi.fn()
	navigationStore.modal = 'viewObject'
	navigationStore.setModal = vi.fn()
	navigationStore.setDialog = vi.fn()
	catalogStore.fetchPublications = vi.fn()
}

/**
 * Mount the view and let mount-time requests settle.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountView() {
	const wrapper = mount(ViewObject, {
		attachTo: document.body,
		global: {
			stubs,
			directives: { tooltip: {} },
			mocks: { n: (app, one, many, count, vars = {}) => String(count === 1 ? one : many).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m)) },
		},
	})
	await flushPromises()
	return wrapper
}

/**
 * The options-API instance proxy.
 *
 * @param {object} wrapper The wrapper.
 * @return {object} The proxy.
 */
function vm(wrapper) {
	return wrapper.vm.$.proxy
}

/**
 * A stub component with the given name whose text or label matches.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} name The component name.
 * @param {string} text Text the element contains exactly.
 * @return {object|undefined} The component wrapper.
 */
function byText(wrapper, name, text) {
	return wrapper.findAllComponents({ name }).find((c) => c.text().trim() === text)
}

/**
 * The property names shown in the visible property table.
 *
 * @param {object} wrapper The wrapper.
 * @return {Array<string>} The names.
 */
function propertyNames(wrapper) {
	return wrapper.findAll('.prop-cell-content').map(rowName)
}

/**
 * The name shown in a property cell (the last span; icons render spans too).
 *
 * @param {object} cell The `.prop-cell-content` wrapper.
 * @return {string} The name.
 */
function rowName(cell) {
	return cell.findAll(':scope > span').at(-1).text()
}

/**
 * The property row showing the given name.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} name The shown property name.
 * @return {object} The row.
 */
function rowOf(wrapper, name) {
	return wrapper.findAll('tr.viewTableRow')
		.find((r) => r.find('.prop-cell-content').exists() && rowName(r.find('.prop-cell-content')) === name)
}

/**
 * Click the value cell of a property row.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} name The shown property name.
 * @return {Promise<void>}
 */
async function clickRow(wrapper, name) {
	await rowOf(wrapper, name).find('td').trigger('click')
	await flushPromises()
}

let fetchMock

beforeEach(() => {
	mocks.editors.length = 0
	Editor.mockClear()
	setStores()
	fetchMock = vi.fn(async (url) => (url.endsWith('/tags') ? response(true, ['red', 'blue']) : response(true, {})))
	vi.stubGlobal('fetch', fetchMock)
	vi.spyOn(console, 'error').mockImplementation(() => {})
	vi.spyOn(console, 'warn').mockImplementation(() => {})
})

afterEach(() => {
	document.body.innerHTML = ''
	vi.useRealTimers()
	vi.unstubAllGlobals()
	vi.restoreAllMocks()
})

describe('ViewObject mounted: a new publication', () => {
	beforeEach(() => {
		setStores({
			catalogs: [
				{ id: 'c1', title: 'Cat One', registers: [1], schemas: [10, 11] },
				{ id: 'c2', title: 'Cat Two', registers: [], schemas: [] },
			],
		})
	})

	it('loads themes and tags on mount and titles the dialog', async () => {
		const wrapper = await mountView()
		expect(objectStore.fetchCollection).toHaveBeenCalledWith('theme')
		expect(fetchMock).toHaveBeenCalledWith('/index.php/apps/openregister/api/tags', { method: 'get' })
		expect(vm(wrapper).labelOptionsEdit.options).toEqual(['red', 'blue'])
		expect(wrapper.find('.dialog__name').text()).toBe('Create Publication')
		expect(wrapper.findComponent({ name: 'PublishedIcon' }).exists()).toBe(false)
	})

	it('steps through catalog, register and schema to the property table', async () => {
		const wrapper = await mountView()
		expect(wrapper.findAll('h3').map((x) => x.text())).toEqual(['Select Catalog'])

		await wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', { id: 'c1', label: 'Cat One' })
		await flushPromises()
		// The catalog holds one register, so it is picked; the register has two schemas
		expect(vm(wrapper).selectedRegister).toEqual({ id: 1, label: 'Reg One' })
		expect(wrapper.findAll('h3').map((x) => x.text())).toEqual(['Select Catalog', 'Select Schema'])

		const schemaSelect = wrapper.findAllComponents({ name: 'NcSelect' })
			.find((s) => s.attributes('data-label') === 'Schema')
		expect(schemaSelect.props('options')).toEqual([
			{ id: 10, label: 'Article' },
			{ id: 11, label: 'Other' },
		])
		await schemaSelect.vm.$emit('update:modelValue', { id: 10, label: 'Article' })
		await flushPromises()

		expect(vm(wrapper).showProperties).toBe(true)
		expect(propertyNames(wrapper)).toEqual(['active', 'body', 'born', 'notes', 'tags', 'themes', 'Title'])
		expect(byText(wrapper, 'NcButton', 'Create')).toBeTruthy()
		expect(byText(wrapper, 'NcButton', 'Add File')).toBeUndefined()
	})

	it('asks for the register when the catalog holds more than one', async () => {
		objectStore.availableRegisters.push({ id: 2, title: 'Reg Two', schemas: [{ id: 10 }] })
		objectStore.state.catalogs[0].registers = [1, 2]
		const wrapper = await mountView()
		await wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', { id: 'c1', label: 'Cat One' })
		expect(wrapper.findAll('h3').map((x) => x.text())).toEqual(['Select Catalog', 'Select Register'])

		const register = wrapper.findAllComponents({ name: 'NcSelect' })
			.find((s) => s.attributes('data-label') === 'Register')
		expect(register.props('options')).toEqual([
			{ id: 1, label: 'Reg One' },
			{ id: 2, label: 'Reg Two' },
		])
		await register.vm.$emit('update:modelValue', { id: 2, label: 'Reg Two' })
		await flushPromises()
		// Reg Two holds one schema the catalog also has, so it is picked
		expect(vm(wrapper).selectedSchema).toEqual({ id: 10, label: 'Article' })
		expect(vm(wrapper).showProperties).toBe(true)
	})

	it('clears the later steps when the catalog or register is cleared', async () => {
		const wrapper = await mountView()
		vm(wrapper).selectedCatalog = { id: 'c1' }
		await flushPromises()
		vm(wrapper).selectedSchema = { id: 10 }
		await flushPromises()
		expect(vm(wrapper).showProperties).toBe(true)

		vm(wrapper).selectedRegister = null
		await flushPromises()
		expect(vm(wrapper).selectedSchema).toBeNull()
		expect(vm(wrapper).showProperties).toBe(false)

		vm(wrapper).selectedRegister = { id: 1 }
		vm(wrapper).selectedSchema = { id: 10 }
		await flushPromises()
		vm(wrapper).selectedCatalog = null
		await flushPromises()
		expect(vm(wrapper).selectedRegister).toBeNull()
		expect(vm(wrapper).selectedSchema).toBeNull()
		expect(vm(wrapper).showProperties).toBe(false)
	})

	it('picks the only catalog, register and schema by itself', async () => {
		setStores({ catalogs: [{ id: 'c1', title: 'Cat One', registers: [1], schemas: [10] }] })
		const wrapper = await mountView()
		expect(vm(wrapper).selectedCatalog).toEqual({ id: 'c1', label: 'Cat One' })
		expect(vm(wrapper).selectedRegister).toEqual({ id: 1, label: 'Reg One' })
		expect(vm(wrapper).selectedSchema).toEqual({ id: 10, label: 'Article' })
		expect(propertyNames(wrapper)).toContain('Title')
	})

	it('renders the input for each property type and writes edits to the form', async () => {
		setStores({ catalogs: [{ id: 'c1', title: 'Cat One', registers: [1], schemas: [10] }] })
		const wrapper = await mountView()

		await clickRow(wrapper, 'Title')
		expect(vm(wrapper).selectedProperty).toBe('title')
		await wrapper.findComponent({ name: 'NcTextField' }).vm.$emit('update:modelValue', 'Hello')
		expect(vm(wrapper).formData.title).toBe('Hello')

		await clickRow(wrapper, 'active')
		await wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' }).vm.$emit('update:modelValue', true)
		expect(vm(wrapper).formData.active).toBe(true)

		await clickRow(wrapper, 'born')
		const picker = wrapper.findComponent({ name: 'NcDateTimePicker' })
		await picker.vm.$emit('update:modelValue', new Date(2026, 0, 2, 12))
		expect(vm(wrapper).formData.born).toBe('2026-01-02')

		await clickRow(wrapper, 'notes')
		await wrapper.findComponent({ name: 'NcTextArea' }).vm.$emit('update:modelValue', 'Long text')
		expect(vm(wrapper).formData.notes).toBe('Long text')

		await clickRow(wrapper, 'tags')
		await wrapper.findComponent({ name: 'NcTextField' }).vm.$emit('update:modelValue', 'a , b,,c')
		expect(vm(wrapper).formData.tags).toEqual(['a', 'b', 'c'])

		await clickRow(wrapper, 'themes')
		const themes = wrapper.findAllComponents({ name: 'NcSelect' })
			.find((s) => s.attributes('data-label') === 'Themes')
		expect(themes.props('options')).toEqual([
			{ id: 't1', label: 'Theme One' },
			{ id: 't2', label: '#t2' },
		])
		await themes.vm.$emit('update:modelValue', [{ id: 't2', label: '#t2' }, 't9'])
		expect(vm(wrapper).formData.themes).toEqual(['t2', 't9'])
		expect(vm(wrapper).themeFormData).toEqual([
			{ id: 't2', label: '#t2' },
			{ id: 't9', label: 't9' },
		])
	})

	it('builds a markdown editor for the edited row and tears it down', async () => {
		setStores({ catalogs: [{ id: 'c1', title: 'Cat One', registers: [1], schemas: [10] }] })
		const wrapper = await mountView()
		vm(wrapper).formData.body = 'Initial'

		await clickRow(wrapper, 'body')
		expect(Editor).toHaveBeenCalledTimes(1)
		const editor = mocks.editors[0]
		expect(editor.options.el).toBe(wrapper.find('.markdown-editor-container').element)
		expect(editor.options.initialValue).toBe('Initial')
		expect(editor.options.initialEditType).toBe('wysiwyg')
		expect(editor.options.placeholder).toBe('body')

		editor.options.events.blur()
		expect(vm(wrapper).formData.body).toBe('# Edited')

		await clickRow(wrapper, 'Title')
		expect(editor.destroy).toHaveBeenCalledTimes(1)
		expect(vm(wrapper).markdownEditors).toEqual({})

		await clickRow(wrapper, 'body')
		const second = mocks.editors[1]
		wrapper.unmount()
		expect(second.destroy).toHaveBeenCalledTimes(1)
	})

	it('toggles the constant properties and drops an edited one', async () => {
		setStores({ catalogs: [{ id: 'c1', title: 'Cat One', registers: [1], schemas: [10] }] })
		const wrapper = await mountView()
		expect(propertyNames(wrapper)).not.toContain('kind')

		const eye = wrapper.find('.eye-toggle-btn')
		expect(eye.attributes('data-aria')).toBe('Show constant & immutable properties')
		await eye.trigger('click')
		expect(propertyNames(wrapper)).toContain('kind')
		expect(wrapper.find('.eye-toggle-btn').attributes('data-aria')).toBe('Hide constant & immutable properties')

		vm(wrapper).formData.title = 'Edited'
		await flushPromises()
		const row = wrapper.findAll('tr.viewTableRow').find((r) => r.text().includes('Title'))
		expect(row.classes()).toContain('edited-row')
		await row.find('.drop-property-btn').trigger('click')
		expect(vm(wrapper).formData.title).toBe('')
	})

	it('posts the new publication and shows it as the active object', async () => {
		setStores({ catalogs: [{ id: 'c1', title: 'Cat One', registers: [1], schemas: [10] }] })
		fetchMock.mockImplementation(async (url) => (url.endsWith('/tags')
			? response(true, [])
			: response(true, { id: 'new-1', '@self': { id: 'new-1', schema: '10' } })))
		const wrapper = await mountView()
		vm(wrapper).formData.title = 'Brand new'

		await byText(wrapper, 'NcButton', 'Create').trigger('click')
		await flushPromises()

		const [url, init] = fetchMock.mock.calls.find(([u]) => u.startsWith(API))
		expect(url).toBe(`${API}/1/10`)
		expect(init.method).toBe('POST')
		expect(JSON.parse(init.body).title).toBe('Brand new')
		expect(objectStore.setActiveObject).toHaveBeenCalledWith('publication', {
			id: 'new-1',
			'@self': { id: 'new-1', schema: objectStore.availableSchemas[0] },
		})
		expect(catalogStore.fetchPublications).toHaveBeenCalledTimes(1)
		expect(vm(wrapper).success).toBe('Publication created successfully')
	})
})

describe('ViewObject mounted: an existing object', () => {
	beforeEach(() => {
		setStores({
			active: structuredClone(EXISTING),
			files: structuredClone(FILES),
			pagination: { page: 1, pages: 2, total: 20, limit: 10 },
		})
	})

	it('titles the dialog and marks each property row by its validity', async () => {
		const wrapper = await mountView()
		expect(wrapper.find('.dialog__name').text()).toBe('Hello (Publication)')
		expect(wrapper.findComponent({ name: 'PublishedIcon' }).exists()).toBe(true)

		expect(rowOf(wrapper, 'active').find('.error-icon').exists()).toBe(true)
		expect(rowOf(wrapper, 'extra').find('.warning-icon').exists()).toBe(true)
		expect(rowOf(wrapper, 'notes').find('.new-icon').exists()).toBe(true)
		expect(rowOf(wrapper, 'Title').find('.validation-icon').exists()).toBe(false)
		expect(rowOf(wrapper, 'extra').find('pre.json-value').text()).toBe(JSON.stringify({ nested: 1 }, null, 2))

		await wrapper.find('.eye-toggle-btn').trigger('click')
		expect(rowOf(wrapper, 'kind').classes()).toContain('non-editable-row')
	})

	it('edits each property type in the properties tab and drops a row', async () => {
		const wrapper = await mountView()
		const field = (name) => wrapper.find('.tab-content').findComponent({ name })

		await clickRow(wrapper, 'active')
		await field('NcCheckboxRadioSwitch').vm.$emit('update:modelValue', false)
		expect(vm(wrapper).formData.active).toBe(false)

		await clickRow(wrapper, 'born')
		expect(field('NcDateTimePicker').props('type')).toBe('date')
		await field('NcDateTimePicker').vm.$emit('update:modelValue', new Date(2026, 4, 6, 12))
		expect(vm(wrapper).formData.born).toBe('2026-05-06')

		await clickRow(wrapper, 'notes')
		await field('NcTextArea').vm.$emit('update:modelValue', 'Some notes')
		expect(vm(wrapper).formData.notes).toBe('Some notes')

		await clickRow(wrapper, 'themes')
		const themes = field('NcSelect')
		expect(themes.props('modelValue')).toEqual([{ id: 't1', label: 'Theme One' }])
		await themes.vm.$emit('update:modelValue', [{ id: 't2', label: '#t2' }])
		expect(vm(wrapper).formData.themes).toEqual(['t2'])

		await clickRow(wrapper, 'tags')
		await field('NcTextField').vm.$emit('update:modelValue', 'x,y')
		expect(vm(wrapper).formData.tags).toEqual(['x', 'y'])

		await clickRow(wrapper, 'Title')
		expect(field('NcTextField').props('modelValue')).toBe('Hello')
		await field('NcTextField').vm.$emit('update:modelValue', 'Renamed')
		expect(vm(wrapper).formData.title).toBe('Renamed')

		// A click inside the open input does not reselect; the drop button
		// takes the extra property off the form
		await rowOf(wrapper, 'Title').find('.value-input-container').trigger('click')
		expect(vm(wrapper).selectedProperty).toBe('title')
		await rowOf(wrapper, 'extra').find('.drop-property-btn').trigger('click')
		expect(Object.hasOwn(vm(wrapper).formData, 'extra')).toBe(false)
		expect(propertyNames(wrapper)).not.toContain('extra')
	})

	it('switches between the properties, metadata and files tabs', async () => {
		const wrapper = await mountView()
		const tab = (label) => wrapper.findAll('button.tab-button').find((b) => b.text().startsWith(label))

		await tab('Metadata').trigger('click')
		expect(vm(wrapper).activeTab).toBe('metadata')
		expect(tab('Metadata').classes()).toContain('active')
		const metadata = wrapper.findAll('.tab-content')[1].findAll('tbody tr').map((r) => r.findAll('td').map((d) => d.text()))
		expect(metadata.slice(0, 3)).toEqual([['ID', 'obj-1'], ['Version', '1.0'], ['Register', 'Reg One']])

		await tab('Files').trigger('click')
		expect(vm(wrapper).activeTab).toBe('files')
		expect(wrapper.findComponent({ name: 'NcCounterBubble' }).props('count')).toBe(20)

		await tab('Properties').trigger('click')
		expect(vm(wrapper).activeTab).toBe('properties')
	})

	it('lists the files with their size, type, sharing state and labels', async () => {
		const wrapper = await mountView()
		const rows = wrapper.findAll('.tab-content')[2].findAll('tbody tr')
		expect(rows.map((r) => r.find('.file-name').text())).toEqual(['a.pdf', 'b'])
		expect(rows.map((r) => r.findAll('td')[2].text())).toEqual(['2.0 KB', 'n/a'])
		expect(rows.map((r) => r.findAll('td')[3].text())).toEqual(['application/pdf', 'No type'])
		expect(rows[0].find('.files-list__system-tag').text()).toBe('x')
		expect(rows[1].text()).toContain('No labels')
		expect(rows[0].find('.warningIcon').exists()).toBe(false)
		expect(rows[1].find('.warningIcon').exists()).toBe(true)

		await rows[0].trigger('click')
		expect(vm(wrapper).activeAttachment).toBe(5)
		await rows[0].trigger('click')
		expect(vm(wrapper).activeAttachment).toBeNull()
	})

	it('sends the publish, depublish and delete request of a file row', async () => {
		const wrapper = await mountView()
		const rows = () => wrapper.findAll('.tab-content')[2].findAll('tbody tr')
		const action = (row, label) => row.findAllComponents({ name: 'NcActionButton' }).find((b) => b.text() === label)
		const fileCalls = () => fetchMock.mock.calls.filter(([u]) => u.startsWith(API))

		await action(rows()[1], 'Publish').trigger('click')
		await flushPromises()
		await action(rows()[0], 'Depublish').trigger('click')
		await flushPromises()
		await action(rows()[0], 'Delete').trigger('click')
		await flushPromises()

		expect(fileCalls().map(([u, init]) => [u, init.method, init.headers.requesttoken])).toEqual([
			[`${API}/1/10/obj-1/files/6/publish`, 'POST', 'tok'],
			[`${API}/1/10/obj-1/files/5/depublish`, 'POST', 'tok'],
			[`${API}/1/10/obj-1/files/5`, 'DELETE', 'tok'],
		])
		expect(objectStore.fetchRelatedData).toHaveBeenCalledWith(
			'publication', 'obj-1', 'files', {}, { source: 'openregister', schema: 10, register: 1 },
		)
		expect(vm(wrapper).publishLoading).toEqual([])
	})

	it('selects files and runs a mass action over the selection', async () => {
		const wrapper = await mountView()
		const selectAll = wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
			.find((c) => c.attributes('aria-label') === 'Select all attachments' || c.attributes('data-aria') === 'Select all attachments')
		await selectAll.vm.$emit('update:modelValue', true)
		expect(vm(wrapper).selectedAttachments).toEqual([5, 6])
		expect(wrapper.findComponent({ name: 'NcActions' }).props('menuName')).toBe('Mass Actions (2)')

		const rowBoxes = wrapper.findAll('.tab-content')[2].findAll('tbody tr')
			.map((r) => r.findComponent({ name: 'NcCheckboxRadioSwitch' }))
		await rowBoxes[0].vm.$emit('update:modelValue', false)
		expect(vm(wrapper).selectedAttachments).toEqual([6])
		await rowBoxes[0].vm.$emit('update:modelValue', true)
		expect(vm(wrapper).selectedAttachments).toEqual([6, 5])

		const mass = wrapper.find('.multi-actions-container')
		const button = (verb) => mass.findAllComponents({ name: 'NcActionButton' }).find((b) => b.text().startsWith(verb))
		expect(button('Publish').text().replace(/\s+/g, ' ')).toBe('Publish 2 files')
		await button('Publish').trigger('click')
		await flushPromises()
		expect(fetchMock.mock.calls.filter(([u]) => u.startsWith(API)).map(([u]) => u)).toEqual([
			`${API}/1/10/obj-1/files/5/publish`,
			`${API}/1/10/obj-1/files/6/publish`,
		])
		expect(vm(wrapper).selectedAttachments).toEqual([])

		await selectAll.vm.$emit('update:modelValue', true)
		await button('Depublish').trigger('click')
		await flushPromises()
		await selectAll.vm.$emit('update:modelValue', true)
		await button('Delete').trigger('click')
		await flushPromises()
		expect(fetchMock.mock.calls.filter(([u]) => u.startsWith(API)).slice(2).map(([u, i]) => `${i.method} ${u}`)).toEqual([
			`POST ${API}/1/10/obj-1/files/5/depublish`,
			`POST ${API}/1/10/obj-1/files/6/depublish`,
			`DELETE ${API}/1/10/obj-1/files/5`,
			`DELETE ${API}/1/10/obj-1/files/6`,
		])

		await selectAll.vm.$emit('update:modelValue', true)
		await selectAll.vm.$emit('update:modelValue', false)
		expect(vm(wrapper).selectedAttachments).toEqual([])
	})

	it('edits, saves and cancels a file\'s labels', async () => {
		vi.useFakeTimers()
		const wrapper = await mountView()
		const row = () => wrapper.findAll('.tab-content')[2].findAll('tbody tr')[0]
		await row().findAllComponents({ name: 'NcActionButton' }).find((b) => b.text() === 'Edit Labels').trigger('click')
		expect(vm(wrapper).editingTags).toBe(5)
		expect(vm(wrapper).editedTags).toEqual(['x'])
		const labels = row().findComponent({ name: 'NcSelect' })
		expect(labels.props('options')).toEqual(['red', 'blue'])
		await labels.vm.$emit('update:modelValue', ['x', 'red'])

		await row().find('.editTagsButton').trigger('click')
		await flushPromises()
		const [url, init] = fetchMock.mock.calls.find(([u]) => u.startsWith(API))
		expect(url).toBe(`${API}/1/10/obj-1/files/5`)
		expect(init.method).toBe('PUT')
		expect(JSON.parse(init.body)).toEqual({ tags: ['x', 'red'] })
		expect(vm(wrapper).success).toBe('File labels updated successfully')
		expect(vm(wrapper).editingTags).toBeNull()
		vi.advanceTimersByTime(3000)
		expect(vm(wrapper).success).toBeNull()

		await row().findAllComponents({ name: 'NcActionButton' }).find((b) => b.text() === 'Edit Labels').trigger('click')
		const cancel = row().findAllComponents({ name: 'NcButton' }).find((b) => b.attributes('data-aria') === 'cancel editing labels for a.pdf')
		await cancel.trigger('click')
		expect(vm(wrapper).editingTags).toBeNull()
		expect(vm(wrapper).editedTags).toEqual([])
	})

	it('opens a file in the Files app', async () => {
		const open = vi.fn()
		vi.stubGlobal('open', open)
		const wrapper = await mountView()
		wrapper.vm.$.proxy.paginatedFiles[0].path = '/admin/files/Docs/a.pdf'
		const row = wrapper.findAll('.tab-content')[2].findAll('tbody tr')[0]
		await row.findAllComponents({ name: 'NcActionButton' }).find((b) => b.text() === 'View').trigger('click')
		expect(open).toHaveBeenCalledWith('/index.php/apps/files/files/5?dir=%2FDocs&openfile=true', '_blank')
	})

	it('reloads the files for a new page or page size', async () => {
		const wrapper = await mountView()
		const pagination = wrapper.findComponent({ name: 'PaginationComponent' })
		const ids = { source: 'openregister', schema: 10, register: 1 }

		await pagination.vm.$emit('pageChanged', 2)
		await flushPromises()
		expect(objectStore.fetchRelatedData).toHaveBeenLastCalledWith('publication', 'obj-1', 'files', { _page: 2, _limit: 10 }, ids)

		await pagination.vm.$emit('pageSizeChanged', 50)
		await flushPromises()
		expect(objectStore.fetchRelatedData).toHaveBeenLastCalledWith('publication', 'obj-1', 'files', { _page: 1, _limit: 50 }, ids)
	})

	it('shows the loading state while the files load, and an empty state without files', async () => {
		objectStore.state.loading = true
		const wrapper = await mountView()
		expect(wrapper.findComponent({ name: 'NcCounterBubble' }).exists()).toBe(false)
		const loading = wrapper.findAllComponents({ name: 'NcEmptyContent' })
		expect(loading.map((c) => c.props('title'))).toEqual(['Loading files…'])

		objectStore.state.loading = false
		objectStore.state.files = []
		await flushPromises()
		expect(wrapper.findAllComponents({ name: 'NcEmptyContent' }).map((c) => c.props('name'))).toEqual(['No files attached'])
	})

	it('hands the object to the publish and delete dialogs from the footer', async () => {
		const wrapper = await mountView()
		const expected = [{ ...EXISTING, id: 'obj-1' }]

		expect(byText(wrapper, 'NcButton', 'Depublish')).toBeUndefined()
		await byText(wrapper, 'NcButton', 'Publish').trigger('click')
		expect(objectStore.setSelectedObjects).toHaveBeenLastCalledWith(expected)
		expect(navigationStore.setDialog).toHaveBeenLastCalledWith('massPublishObjects')

		await byText(wrapper, 'NcButton', 'Delete').trigger('click')
		expect(navigationStore.setDialog).toHaveBeenLastCalledWith('massDeleteObject')

		await byText(wrapper, 'NcButton', 'Add File').trigger('click')
		expect(navigationStore.setDialog).toHaveBeenLastCalledWith('uploadFiles')

		objectStore.state.active = { ...structuredClone(EXISTING), '@self': { ...EXISTING['@self'], published: '2026-01-01' } }
		await flushPromises()
		expect(byText(wrapper, 'NcButton', 'Publish')).toBeUndefined()
		await byText(wrapper, 'NcButton', 'Depublish').trigger('click')
		expect(navigationStore.setDialog).toHaveBeenLastCalledWith('massDepublishObjects')
	})

	it('puts the edited object and closes the dialog shortly after', async () => {
		vi.useFakeTimers()
		fetchMock.mockImplementation(async (url) => (url.endsWith('/tags')
			? response(true, [])
			: response(true, { id: 'obj-1', title: 'Changed', '@self': { id: 'obj-1', schema: '10' } })))
		const wrapper = await mountView()
		vm(wrapper).formData.title = 'Changed'

		await byText(wrapper, 'NcButton', 'Save').trigger('click')
		await flushPromises()
		const [url, init] = fetchMock.mock.calls.find(([u]) => u.startsWith(API))
		expect(url).toBe(`${API}/1/10/obj-1`)
		expect(init.method).toBe('PUT')
		expect(JSON.parse(init.body).title).toBe('Changed')
		expect(vm(wrapper).success).toBe('Publication updated successfully')

		vi.advanceTimersByTime(1000)
		expect(navigationStore.setModal).toHaveBeenCalledWith(null)
		vi.advanceTimersByTime(2000)
		expect(vm(wrapper).success).toBeNull()
	})

	it('shows the server error when saving fails', async () => {
		fetchMock.mockImplementation(async (url) => (url.endsWith('/tags') ? response(true, []) : response(false, {})))
		const wrapper = await mountView()
		await byText(wrapper, 'NcButton', 'Save').trigger('click')
		await flushPromises()
		expect(vm(wrapper).error).toBeTruthy()
		expect(vm(wrapper).success).toBe(false)
		expect(vm(wrapper).isSaving).toBe(false)
	})

	it('closes and resets when the dialog is closed or Close is clicked', async () => {
		const wrapper = await mountView()
		vm(wrapper).activeTab = 'files'
		vm(wrapper).selectedAttachments = [5]
		await wrapper.findComponent({ name: 'NcDialog' }).vm.$emit('update:open', true)
		expect(navigationStore.setModal).not.toHaveBeenCalled()

		await wrapper.findComponent({ name: 'NcDialog' }).vm.$emit('update:open', false)
		expect(navigationStore.setModal).toHaveBeenCalledWith(null)
		expect(vm(wrapper).activeTab).toBe('properties')
		expect(vm(wrapper).selectedAttachments).toEqual([])

		await byText(wrapper, 'NcButton', 'Close').trigger('click')
		expect(navigationStore.setModal).toHaveBeenCalledTimes(2)
	})

	it('reinitialises the form when the active object changes', async () => {
		const wrapper = await mountView()
		expect(vm(wrapper).formData.title).toBe('Hello')
		objectStore.state.active = { ...structuredClone(EXISTING), title: 'Swapped', themes: [{ id: 't2' }] }
		await flushPromises()
		expect(vm(wrapper).formData.title).toBe('Swapped')
		expect(vm(wrapper).formData.themes).toEqual(['t2'])
		expect(JSON.parse(vm(wrapper).jsonData).title).toBe('Swapped')
	})

	it('takes valid JSON typed into the editor into the form', async () => {
		const wrapper = await mountView()
		vm(wrapper).jsonData = '{"title":"From JSON"}'
		await flushPromises()
		expect(vm(wrapper).formData).toEqual({ title: 'From JSON' })

		vm(wrapper).jsonData = '{ broken'
		await flushPromises()
		expect(vm(wrapper).formData).toEqual({ title: 'From JSON' })
	})
})
