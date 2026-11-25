// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * MoveToOrganisationDialog, mounted: the source is the active organisation,
 * the target list holds the user's other organisations, Preview posts the
 * selection to the plan endpoint and lists what moves, what is skipped and
 * which connections stay with the source, Move posts to execute and reports
 * the count, and a refusal shows the administrator message.
 *
 * @spec openspec/changes/archive/2026-11-25-landscape-move-between-organisations/specs/move-between-organisations/spec.md#requirement-req-mbo-003-the-dry-run-shows-what-moves-and-what-stays-behind
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const navigation = vi.hoisted(() => ({ dialogProperties: null, setDialog: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('../../src/store/store.js', () => ({ navigationStore: navigation }))
vi.mock('@nextcloud/l10n', () => {
	const fill = (text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
	return {
		translate: (app, text, vars) => fill(text, vars),
		translatePlural: (app, one, many, count, vars) => fill(count === 1 ? one : many, vars),
	}
})
vi.mock('@nextcloud/vue', async () => {
	const { h } = await import('vue')
	return {
		NcDialog: {
			props: { name: { default: '' } },
			emits: ['closing'],
			render() {
				return h('section', { class: 'dialog', 'data-name': this.name }, [this.$slots.default?.(), this.$slots.actions?.()])
			},
		},
		NcButton: {
			props: { variant: { default: '' }, disabled: { default: false } },
			emits: ['click'],
			render() {
				return h('button', { class: this.variant || 'secondary', disabled: this.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.())
			},
		},
		NcSelect: {
			props: { modelValue: { default: null }, options: { default: () => [] }, inputLabel: { default: '' }, disabled: { default: false } },
			emits: ['update:modelValue'],
			render() {
				return h('select', {
					'aria-label': this.inputLabel,
					disabled: this.disabled,
					onChange: (e) => this.$emit('update:modelValue', e.target.value),
				}, [h('option', { value: '' }, ''), ...this.options.map((o) => h('option', { value: o.value }, o.label))])
			},
		},
		NcNoteCard: {
			props: { type: { default: '' } },
			render() {
				return h('div', { class: 'note-' + this.type }, this.$slots.default?.())
			},
		},
		NcLoadingIcon: { render: () => h('i', { class: 'loading' }) },
	}
})

const { default: MoveToOrganisationDialog } = await import('../../src/dialogs/MoveToOrganisationDialog.vue')

const ME = {
	organisations: {
		active: { uuid: 'org-a', name: 'Gemeente Tilburg' },
		all: [
			{ uuid: 'org-a', name: 'Gemeente Tilburg' },
			{ uuid: 'org-b', name: 'Gemeente Breda' },
		],
	},
}

const PLAN = {
	source: 'org-a',
	target: 'org-b',
	items: [
		{ schema: 'usage', id: 'u-1', name: 'Zaaksysteem', action: 'move', reason: null },
		{ schema: 'usage', id: 'u-2', name: 'DMS', action: 'skip', reason: 'Not owned by the source organisation' },
	],
	staysBehind: [{ schema: 'connection', id: 'c-1', name: 'StUF koppeling', organisation: 'org-a', linkedTo: 'u-1' }],
}

/**
 * Mount the dialog for the given selection and wait for /api/me.
 *
 * @param {Array<string>} ids The selected ids.
 * @return {Promise<object>} The wrapper.
 */
async function open(ids = ['u-1', 'u-2']) {
	navigation.dialogProperties = { schema: 'usage', ids }
	const wrapper = mount(MoveToOrganisationDialog)
	await flushPromises()
	return wrapper
}

const button = (wrapper, label) => wrapper.findAll('button').find((b) => b.text() === label)

/**
 * Pick a target organisation in the select.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} uuid The organisation.
 * @return {Promise<void>}
 */
async function pick(wrapper, uuid) {
	await wrapper.find('select').setValue(uuid)
}

describe('MoveToOrganisationDialog', () => {
	beforeEach(() => {
		http.get.mockReset().mockResolvedValue({ data: ME })
		http.post.mockReset()
		navigation.setDialog.mockReset()
	})

	it('moves from the active organisation and offers only the other organisations', async () => {
		const wrapper = await open()

		expect(http.get).toHaveBeenCalledWith('/index.php/apps/stackiq/api/me')
		expect(wrapper.find('.move-to-organisation__intro').text()).toBe('Move 2 entries from Gemeente Tilburg to another organisation.')
		expect(wrapper.findAll('option').map((o) => o.text())).toEqual(['', 'Gemeente Breda'])
		expect(button(wrapper, 'Preview').attributes('disabled')).toBeDefined()
		expect(button(wrapper, 'Move').attributes('disabled')).toBeDefined()
	})

	it('previews the plan: moving, skipped with the reason, and the connection staying with the source', async () => {
		http.post.mockResolvedValue({ data: PLAN })
		const wrapper = await open()
		await pick(wrapper, 'org-b')

		await button(wrapper, 'Preview').trigger('click')
		await flushPromises()

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/stackiq/api/ownership-transfers/plan', {
			objects: [{ schema: 'usage', id: 'u-1' }, { schema: 'usage', id: 'u-2' }],
			sourceOrganisation: 'org-a',
			targetOrganisation: 'org-b',
		})
		const groups = wrapper.findAll('.move-to-organisation__group')
		expect(groups.map((g) => g.find('h3').text())).toEqual(['Moving', 'Skipped', 'Staying with Gemeente Tilburg'])
		expect(groups[0].findAll('li').map((li) => li.text())).toEqual(['Zaaksysteem'])
		expect(groups[1].find('li').text()).toBe('DMS — Not owned by the source organisation')
		expect(groups[2].findAll('li').map((li) => li.text())).toEqual(['StUF koppeling'])
		expect(button(wrapper, 'Move').attributes('disabled')).toBeUndefined()
	})

	it('moves the previewed entries and reports the count', async () => {
		http.post.mockResolvedValueOnce({ data: PLAN }).mockResolvedValueOnce({ data: { ...PLAN, moved: 1 } })
		const wrapper = await open()
		await pick(wrapper, 'org-b')
		await button(wrapper, 'Preview').trigger('click')
		await flushPromises()

		await button(wrapper, 'Move').trigger('click')
		await flushPromises()

		expect(http.post).toHaveBeenLastCalledWith('/index.php/apps/stackiq/api/ownership-transfers/execute', {
			objects: [{ schema: 'usage', id: 'u-1' }, { schema: 'usage', id: 'u-2' }],
			sourceOrganisation: 'org-a',
			targetOrganisation: 'org-b',
		})
		expect(wrapper.find('.note-success').text()).toBe('1 entry moved.')
		expect(wrapper.emitted('moved')).toEqual([[1]])
		expect(button(wrapper, 'Move')).toBeUndefined()
		expect(button(wrapper, 'Close')).toBeDefined()
	})

	it('cannot move when nothing in the plan moves', async () => {
		http.post.mockResolvedValue({ data: { ...PLAN, items: [PLAN.items[1]], staysBehind: [] } })
		const wrapper = await open(['u-2'])
		await pick(wrapper, 'org-b')
		await button(wrapper, 'Preview').trigger('click')
		await flushPromises()

		expect(wrapper.find('.move-to-organisation__empty').text()).toBe('Nothing moves.')
		expect(button(wrapper, 'Move').attributes('disabled')).toBeDefined()
	})

	it('shows the administrator message on a refusal and keeps nothing planned', async () => {
		http.post.mockRejectedValue({ response: { status: 403 } })
		const wrapper = await open()
		await pick(wrapper, 'org-b')

		await button(wrapper, 'Preview').trigger('click')
		await flushPromises()

		expect(wrapper.find('.note-error').text()).toBe('Only an administrator of both organisations may move entries.')
		expect(wrapper.findAll('.move-to-organisation__group')).toHaveLength(0)
	})

	it('says so when there is no active organisation', async () => {
		http.get.mockResolvedValue({ data: { organisations: { all: [], active: null } } })
		const wrapper = await open()

		expect(wrapper.find('.note-error').text()).toBe('Choose an active organisation first.')
	})

	it('closes through the navigation store', async () => {
		const wrapper = await open()

		await button(wrapper, 'Cancel').trigger('click')

		expect(navigation.setDialog).toHaveBeenCalledWith(false)
		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})
