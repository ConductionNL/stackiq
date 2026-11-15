/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The download dialog's logic: it starts the download on mount only for an
 * object with an id, records success or the error, auto-closes two seconds
 * after a success, and closing resets it and cancels the pending auto-close.
 *
 * @spec openspec/specs/fe-object-modals/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const stores = vi.hoisted(() => ({
	navigationStore: { setModal: null },
	objectStore: { objectItem: null, downloadObject: null },
}))

vi.mock('../../src/store/store.js', () => stores)
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcDialog: {}, NcNoteCard: {} }))
vi.mock('vue-codemirror6', () => ({ default: {} }))

const { default: DownloadObject } =
	await import('../../src/modals/object/DownloadObject.vue')

/**
 * A stand-in for the dialog: its data, every method bound and a `t`.
 *
 * @return {object} The stand-in.
 */
function viewModel() {
	const vm = { ...DownloadObject.data(), t: (_app, text) => text }
	for (const [name, fn] of Object.entries(DownloadObject.methods)) {
		vm[name] = fn.bind(vm)
	}
	return vm
}

beforeEach(() => {
	stores.navigationStore.setModal = vi.fn()
	stores.objectStore.objectItem = { id: 'obj-1' }
	stores.objectStore.downloadObject = vi.fn(async () => ({ ok: true }))
})

afterEach(() => {
	vi.useRealTimers()
})

describe('DownloadObject', () => {
	it('starts the download on mount for an object with an id', () => {
		const vm = viewModel()
		vm.downloadObject = vi.fn()

		DownloadObject.mounted.call(vm)

		expect(vm.downloadObject).toHaveBeenCalledTimes(1)
	})

	it('does not start a download for an object without an id', () => {
		stores.objectStore.objectItem = { '@self': { name: 'x' } }
		const vm = viewModel()
		vm.downloadObject = vi.fn()

		DownloadObject.mounted.call(vm)

		expect(vm.downloadObject).not.toHaveBeenCalled()
	})

	it('downloads the current object and records success', async () => {
		const vm = viewModel()

		await vm.downloadObject()

		expect(stores.objectStore.downloadObject).toHaveBeenCalledWith(
			stores.objectStore.objectItem,
		)
		expect(vm.success).toBe(true)
		expect(vm.error).toBe(false)
		expect(vm.loading).toBe(false)
	})

	it('closes two seconds after a successful download', async () => {
		vi.useFakeTimers()
		const vm = viewModel()

		await vm.downloadObject()
		vi.advanceTimersByTime(1999)
		expect(stores.navigationStore.setModal).not.toHaveBeenCalled()
		vi.advanceTimersByTime(1)

		expect(stores.navigationStore.setModal).toHaveBeenCalledWith(false)
	})

	it('does not auto-close when the response is not ok', async () => {
		vi.useFakeTimers()
		stores.objectStore.downloadObject = vi.fn(async () => ({ ok: false }))
		const vm = viewModel()

		await vm.downloadObject()
		vi.advanceTimersByTime(5000)

		expect(stores.navigationStore.setModal).not.toHaveBeenCalled()
		expect(vm.success).toBe(false)
		expect(vm.error).toBe(false)
	})

	it('records the message of a failed download', async () => {
		stores.objectStore.downloadObject = vi.fn(async () => {
			throw new Error('Forbidden')
		})
		const vm = viewModel()

		await vm.downloadObject()

		expect(vm.success).toBe(false)
		expect(vm.error).toBe('Forbidden')
		expect(vm.loading).toBe(false)
	})

	it('falls back to a generic error without a message', async () => {
		stores.objectStore.downloadObject = vi.fn(async () => {
			throw new Error('')
		})
		const vm = viewModel()

		await vm.downloadObject()

		expect(vm.error).toBe('An error occurred while downloading the object')
	})

	it('closing resets the dialog and cancels the pending auto-close', async () => {
		vi.useFakeTimers()
		const vm = viewModel()
		await vm.downloadObject()

		vm.closeModal()
		expect(stores.navigationStore.setModal).toHaveBeenCalledTimes(1)
		expect(vm.success).toBeNull()
		expect(vm.error).toBe(false)
		expect(vm.loading).toBe(false)

		vi.advanceTimersByTime(5000)
		expect(stores.navigationStore.setModal).toHaveBeenCalledTimes(1)
	})
})
