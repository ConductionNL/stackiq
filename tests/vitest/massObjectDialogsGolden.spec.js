// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the mass object dialogs (publish, depublish, lock, unlock,
 * validate, delete), mounted: the rendered dialog before and after the run,
 * which store call is made with which objects, the publication refresh, the
 * auto-close timer, the error texts, closing the dialog and the delete
 * dialog's link to the deleted publications. Recorded with RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

import { mount } from '@vue/test-utils'
import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, nextTick } from 'vue'

const trace = []
const store = vi.hoisted(() => ({ objectStore: {} }))

vi.mock('../../src/store/store.js', () => ({
	catalogStore: {
		fetchPublications: () => trace.push(['catalogStore.fetchPublications']),
	},
	navigationStore: {
		setDialog: (value) => trace.push(['navigationStore.setDialog', value]),
		setSelected: (value) => trace.push(['navigationStore.setSelected', value]),
	},
	objectStore: store.objectStore,
}))

const { default: MassPublishObjects } =
	await import('../../src/modals/object/MassPublishObjects.vue')
const { default: MassDepublishObjects } =
	await import('../../src/modals/object/MassDepublishObjects.vue')
const { default: MassLockObjects } =
	await import('../../src/modals/object/MassLockObjects.vue')
const { default: MassUnlockObjects } =
	await import('../../src/modals/object/MassUnlockObjects.vue')
const { default: MassValidateObjects } =
	await import('../../src/modals/object/MassValidateObjects.vue')
const { default: MassDeleteObject } =
	await import('../../src/modals/object/MassDeleteObject.vue')

const GOLDEN = path.resolve(import.meta.dirname, 'golden/massObjectDialogsGolden.json')

const dialogs = {
	publish: [MassPublishObjects, 'massPublishObjects'],
	depublish: [MassDepublishObjects, 'massDepublishObjects'],
	lock: [MassLockObjects, 'massLockObjects'],
	unlock: [MassUnlockObjects, 'massUnlockObjects'],
	validate: [MassValidateObjects, 'massValidateObjects'],
	delete: [MassDeleteObject, 'massDeleteObjects'],
}

const two = [{ id: 'a' }, { id: 'b' }]

const answers = {
	'all succeed': { successful: two, failed: [] },
	'some fail': { successful: [two[0]], failed: [two[1]] },
	'all fail': { successful: [], failed: two },
	'store throws with a message': { throws: 'boom' },
	'store throws without a message': { throws: '' },
}

const selections = {
	'two selected': two,
	'one selected': [two[0]],
	'nothing selected': null,
}

/**
 * A stub rendering one element with the given attributes and slots.
 *
 * @param {string} name Component name.
 * @param {string} tag Root tag.
 * @param {Array<string>} props The props shown as data attributes.
 * @param {Array<string>} slotNames The slots rendered, in order.
 * @return {object} A component.
 */
function stub(name, tag, props, slotNames) {
	return defineComponent({
		name,
		props,
		emits: ['click', 'update:open', 'update:modelValue'],
		setup(componentProps, { slots, emit }) {
			return () => {
				const attrs = { class: name }
				for (const prop of props) {
					attrs['data-' + prop.toLowerCase()] = JSON.stringify(componentProps[prop] ?? null)
				}
				if (tag === 'button') {
					attrs.onClick = () => emit('click')
				}
				const children = slotNames.map((slot) => h('section', { class: 'slot-' + slot }, slots[slot] ? slots[slot]() : []))
				if (name === 'NcDialog') {
					children.push(h('button', { class: 'dialog-close', onClick: () => emit('update:open', false) }))
				}
				return h(tag, attrs, children)
			}
		},
	})
}

const stubs = {
	NcDialog: stub('NcDialog', 'div', ['name', 'canClose', 'size'], ['default', 'actions']),
	NcButton: stub('NcButton', 'button', ['disabled', 'variant'], ['icon', 'default']),
	NcNoteCard: stub('NcNoteCard', 'div', ['type'], ['default']),
	NcLoadingIcon: stub('NcLoadingIcon', 'span', ['size'], []),
	NcTextField: stub('NcTextField', 'span', ['label', 'disabled', 'type', 'modelValue'], []),
	SelectedObjectsList: stub('SelectedObjectsList', 'div', ['title', 'showRemove'], []),
}

/**
 * The rendered markup without Vue's scoped-style attributes and v-if placeholders.
 *
 * @param {object} wrapper The mounted dialog.
 * @return {string} The markup.
 */
function markup(wrapper) {
	return wrapper
		.html()
		.replace(/ data-v-[0-9a-f]+(="")?/g, '')
		.replace(/<!--v-if-->/g, '')
		.replace(/\n\s*\n/g, '\n')
}

/**
 * Mount one dialog, run its action for one store answer and record everything.
 *
 * @param {Array} dialog The component and its store method.
 * @param {object} input The store answer, the selection and the lock fields.
 * @return {Promise<object>} The recording.
 */
async function run(dialog, input) {
	const [component, storeMethod] = dialog
	trace.length = 0
	const selected = input.selected === null ? null : structuredClone(input.selected)
	store.objectStore.selectedObjects = selected
	store.objectStore[storeMethod] = async (...args) => {
		trace.push(['objectStore.' + storeMethod, ...args])
		if (input.answer.throws !== undefined) {
			throw new Error(input.answer.throws)
		}
		return structuredClone(input.answer)
	}
	const wrapper = mount(component, {
		global: {
			stubs,
			mixins: [{ methods: { t: (app, text, vars) => `${app}:${text}:${JSON.stringify(vars ?? null)}` } }],
		},
	})
	if (input.lockFields) {
		Object.assign(wrapper.vm, input.lockFields)
		await nextTick()
	}
	const before = markup(wrapper)
	const buttons = wrapper.findAll('button.NcButton')
	if (input.before) {
		await input.before(wrapper)
	}
	await buttons[buttons.length - 1].trigger('click')
	await new Promise((resolve) => setImmediate(resolve))
	await nextTick()
	const after = markup(wrapper)
	trace.push(['timers pending', vi.getTimerCount()])
	vi.advanceTimersByTime(1999)
	trace.push(['after 1999 ms'])
	vi.advanceTimersByTime(1)
	await wrapper.find('button.dialog-close').trigger('click')
	wrapper.unmount()
	return JSON.parse(
		JSON.stringify({ before, after, trace: [...trace] }, (key, value) =>
			value === undefined ? '(undefined)' : value,
		),
	)
}

const cases = {}
for (const [dialogName, dialog] of Object.entries(dialogs)) {
	for (const [answerName, answer] of Object.entries(answers)) {
		for (const [selectionName, selected] of Object.entries(selections)) {
			cases[`${dialogName}, ${answerName}, ${selectionName}`] = {
				dialog,
				input: { answer, selected, lockFields: dialogName === 'lock' ? { process: 'review', duration: 60 } : null },
			}
		}
	}
}
cases['delete, the link to the deleted publications'] = {
	dialog: dialogs.delete,
	input: {
		answer: answers['all succeed'],
		selected: two,
		lockFields: null,
		before: async (wrapper) => {
			await wrapper.find('a.deleted-link').trigger('click')
			trace.push(['after the link', markup(wrapper)])
		},
	},
}
for (const dialogName of Object.keys(dialogs)) {
	cases[`${dialogName}, cancel before running`] = {
		dialog: dialogs[dialogName],
		input: {
			answer: answers['all succeed'],
			selected: two,
			lockFields: null,
			before: async (wrapper) => {
				await wrapper.findAll('button.NcButton')[0].trigger('click')
				trace.push(['after cancel'])
			},
		},
	}
}
cases['lock, defaults'] = { dialog: dialogs.lock, input: { answer: answers['all succeed'], selected: two, lockFields: null } }
cases['lock, empty process and duration'] = { dialog: dialogs.lock, input: { answer: answers['all succeed'], selected: two, lockFields: { process: '', duration: 0 } } }

describe('mass object dialogs, mounted', () => {
	beforeEach(() => {
		vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
	})

	afterEach(() => {
		vi.clearAllTimers()
		vi.useRealTimers()
	})

	for (const [name, { dialog, input }] of Object.entries(cases)) {
		it(name, async () => {
			const actual = await run(dialog, input)
			if (process.env.RECORD_GOLDEN) {
				const all = { ...readGolden(), [name]: actual }
				writeFileSync(GOLDEN, JSON.stringify(all, null, '\t') + '\n')
			}
			expect(actual).toEqual(readGolden()[name])
		})
	}
})

/**
 * The recorded golden, or an empty object while recording the first case.
 *
 * @return {object} Results by case name.
 */
function readGolden() {
	try {
		return JSON.parse(readFileSync(GOLDEN, 'utf8'))
	} catch (error) {
		if (process.env.RECORD_GOLDEN) {
			return {}
		}
		throw error
	}
}
