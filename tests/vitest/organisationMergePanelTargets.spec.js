// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation test for the merge panel's target list and preview: the
 * request and the options built (this organisation and merged ones left
 * out, labels with their fallbacks), when loading is skipped, a failed
 * load, and the dry-run preview with and without blockers or a failure.
 *
 * @spec openspec/specs/organisation-merge/spec.md
 */

import { describe, expect, it, vi } from 'vitest'

const http = { reply: null, fail: false, calls: [] }
const store = vi.hoisted(() => ({ dryRunMerge: null }))

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: async (url) => {
			http.calls.push(['get', url])
			if (http.fail) throw new Error('down')
			return { data: structuredClone(http.reply) }
		},
	},
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params) => url + ' ' + JSON.stringify(params),
}))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => '[' + text + ']' }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: () => {} }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {},
	NcLoadingIcon: {},
	NcNoteCard: {},
	NcSelect: {},
}))
vi.mock('@conduction/nextcloud-vue', () => ({ CnIcon: {}, CnWidgetWrapper: {} }))
vi.mock('vue-material-design-icons/ArrowRight.vue', () => ({ default: {} }))
vi.mock('vue-material-design-icons/Eye.vue', () => ({ default: {} }))
vi.mock('../../src/modals/object/MergeOrganisationConfirmDialog.vue', () => ({
	default: {},
}))
vi.mock('../../src/store/store.js', () => ({
	organisatieStore: store,
	settingsStore: {},
}))

const { default: OrganisationMergePanel } =
	await import('../../src/components/organisations/OrganisationMergePanel.vue')

/**
 * A stand-in for the panel with its data, the given state and every method
 * bound to it.
 *
 * @param {object} state The panel state.
 * @return {object} The stand-in.
 */
function viewModel(state = {}) {
	const vm = {
		...OrganisationMergePanel.data.call({}),
		register: 'reg',
		schema: 'org',
		objectId: 'self-1',
		...state,
	}
	for (const [name, fn] of Object.entries(OrganisationMergePanel.methods)) {
		vm[name] = fn.bind(vm)
	}
	return vm
}

describe('OrganisationMergePanel loadTargetOptions', () => {
	it('lists every other organisation that is not merged', async () => {
		http.reply = {
			results: [
				{ id: 'self-1', name: 'Self' },
				{ '@self': { id: 'a', name: 'Self name A' } },
				{ id: 'b', name: 'B' },
				{ id: 'c', name: 'C', status: 'merged' },
				{ id: 7 },
				{ '@self': { id: 'self-1' } },
			],
		}
		http.fail = false
		http.calls = []
		const vm = viewModel()
		await vm.loadTargetOptions()
		expect(http.calls).toEqual([
			[
				'get',
				'/apps/openregister/api/objects/{register}/{schema} {"register":"reg","schema":"org"}',
			],
		])
		expect(vm.targetOptions).toEqual([
			{ value: 'a', label: 'Self name A' },
			{ value: 'b', label: 'B' },
			{ value: 7, label: '7' },
		])
		expect(vm.loadingTargets).toBe(false)
		expect(vm.error).toBe('')
	})

	it('reads data, then nothing, when there are no results', async () => {
		http.fail = false
		http.reply = { data: [{ id: 'x', name: 'X' }] }
		const fromData = viewModel()
		await fromData.loadTargetOptions()
		expect(fromData.targetOptions).toEqual([{ value: 'x', label: 'X' }])
		http.reply = null
		const empty = viewModel()
		await empty.loadTargetOptions()
		expect(empty.targetOptions).toEqual([])
	})

	it('skips loading when options exist or are loading', async () => {
		http.calls = []
		await viewModel({ targetOptions: [{ value: 'k' }] }).loadTargetOptions()
		await viewModel({ loadingTargets: true }).loadTargetOptions()
		expect(http.calls).toEqual([])
	})

	it('reports a failed load and a malformed result', async () => {
		http.fail = true
		const failed = viewModel()
		await failed.loadTargetOptions()
		expect(failed.error).toBe('[Could not load target organisations.]')
		expect(failed.targetOptions).toEqual([])
		expect(failed.loadingTargets).toBe(false)
		http.fail = false
		http.reply = { results: [null] }
		const malformed = viewModel()
		await malformed.loadTargetOptions()
		expect(malformed.error).toBe('[Could not load target organisations.]')
		expect(malformed.targetOptions).toEqual([])
	})
})

describe('OrganisationMergePanel preview', () => {
	const run = async (answer, state = {}) => {
		store.dryRunMerge = vi.fn(answer)
		const vm = viewModel({
			selectedTarget: { value: 9 },
			error: 'old',
			blockers: ['old'],
			confirmError: 'old',
			...state,
		})
		await vm.preview()
		return {
			calls: store.dryRunMerge.mock.calls,
			previewing: vm.previewing,
			error: vm.error,
			blockers: vm.blockers,
			dryRunCounts: vm.dryRunCounts,
			confirmError: vm.confirmError,
			showConfirm: vm.showConfirm,
		}
	}

	it('does nothing without a target', async () => {
		const out = await run(async () => ({}), { selectedTarget: null })
		expect(out.calls).toEqual([])
		expect(out.error).toBe('old')
	})

	it('opens the confirm dialog for a legal merge', async () => {
		expect(await run(async () => ({ counts: { a: 1 }, blockers: [] }))).toEqual({
			calls: [['self-1', '9']],
			previewing: false,
			error: '',
			blockers: [],
			dryRunCounts: { a: 1 },
			confirmError: '',
			showConfirm: true,
		})
		expect((await run(async () => ({}))).dryRunCounts).toEqual({})
	})

	it('shows the blockers of an illegal merge', async () => {
		const out = await run(async () => ({ counts: { a: 2 }, blockers: ['x'] }))
		expect(out.blockers).toEqual(['x'])
		expect(out.showConfirm).toBe(false)
		expect(out.confirmError).toBe('old')
		expect(out.dryRunCounts).toEqual({ a: 2 })
	})

	it('reports a failed preview', async () => {
		expect(
			(
				await run(async () => {
					throw new Error('nope')
				})
			).error,
		).toBe('nope')
		const out = await run(async () => {
			throw new Error('')
		})
		expect(out.error).toBe('[Could not preview the merge.]')
		expect(out.previewing).toBe(false)
		expect(out.blockers).toEqual([])
	})
})
