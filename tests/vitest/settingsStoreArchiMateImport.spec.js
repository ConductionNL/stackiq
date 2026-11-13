/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the settings store's ArchiMate import start, its status
 * polling, the register-change handlers and the small setting updaters.
 *
 * @vitest-environment jsdom
 * @spec openspec/specs/fe-stores/spec.md
 */

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useSettingsStore } from '../../src/store/modules/settings.js'

const toasts = []

vi.mock('@nextcloud/dialogs', () => ({
	showError: (message) => toasts.push(['error', message]),
	showSuccess: (message) => toasts.push(['success', message]),
}))

/**
 * Let pending promise callbacks run.
 *
 * @return {Promise<void>}
 */
async function flush() {
	for (let i = 0; i < 5; i++) {
		await Promise.resolve()
	}
}

describe('settings store ArchiMate import', () => {
	let store
	let fetchMock

	beforeEach(() => {
		setActivePinia(createPinia())
		toasts.length = 0
		vi.useFakeTimers()
		globalThis.OC = { requestToken: 'tok' }
		fetchMock = vi.fn()
		vi.stubGlobal('fetch', fetchMock)
		store = useSettingsStore()
	})

	afterEach(() => {
		store.cleanup()
		vi.useRealTimers()
		vi.unstubAllGlobals()
		delete globalThis.OC
	})

	it('refuses to start without a selected file', () => {
		store.importArchiMateFile()

		expect(fetchMock).not.toHaveBeenCalled()
		expect(store.importing).toBe(false)
		expect(store.isImportRunning).toBe(false)
		expect(toasts).toEqual([['error', 'No file selected for import']])
	})

	it('posts the file with the import options and starts polling', async () => {
		fetchMock.mockResolvedValue({ ok: true, status: 202 })
		store.selectedFile = new File(['<model/>'], 'model.xml')
		store.importOptions = { updateExisting: true, deleteOrphaned: false }

		store.importArchiMateFile('memory')

		expect(store.isImportRunning).toBe(true)
		expect(store.importing).toBe(false)
		expect(store.archimateStatus.import).toEqual({
			status: 'running',
			current_step: 'Starting import...',
			progress: 0,
			statistics: null,
		})
		expect(store.statusPollingInterval).not.toBeNull()
		expect(toasts).toEqual([['success', 'ArchiMate import started - monitoring progress...']])

		expect(fetchMock).toHaveBeenCalledTimes(1)
		const [url, options] = fetchMock.mock.calls[0]
		expect(url).toBe('/index.php/apps/stackiq/api/archimate/import')
		expect(options.method).toBe('POST')
		expect(options.headers).toEqual({ requesttoken: 'tok', 'X-Requested-With': 'XMLHttpRequest' })
		expect(options.body.get('archiMateFile').name).toBe('model.xml')
		expect(options.body.get('updateExisting')).toBe('true')
		expect(options.body.get('deleteOrphaned')).toBe('false')
		expect(options.body.get('preserveIds')).toBe('true')
		expect(options.body.get('processingMode')).toBe('memory')

		await flush()
		expect(store.isImportRunning).toBe(true)
	})

	it('marks the import failed on a server error and stops polling', async () => {
		fetchMock.mockResolvedValue({ ok: false, status: 500 })
		store.selectedFile = new File(['x'], 'big.xml')

		store.importArchiMateFile()
		await flush()

		expect(store.isImportRunning).toBe(false)
		expect(store.statusPollingInterval).toBeNull()
		expect(store.archimateStatus.import).toEqual({
			status: 'failed',
			current_step: 'Import failed',
			progress: 0,
			statistics: null,
			error: 'Server error (500)',
		})
		expect(toasts.at(-1)).toEqual([
			'error',
			'Import failed: Server error. Please try with a smaller file or check server logs.',
		])
	})

	it('marks the import failed on a network error but not on an abort', async () => {
		store.selectedFile = new File(['x'], 'a.xml')
		fetchMock.mockRejectedValueOnce(Object.assign(new Error('offline'), { name: 'TypeError' }))

		store.importArchiMateFile()
		await flush()

		expect(store.isImportRunning).toBe(false)
		expect(store.archimateStatus.import.error).toBe('offline')
		expect(toasts.at(-1)).toEqual(['error', 'Import failed: offline'])

		toasts.length = 0
		fetchMock.mockRejectedValueOnce(Object.assign(new Error('aborted'), { name: 'AbortError' }))

		store.importArchiMateFile()
		await flush()

		expect(store.isImportRunning).toBe(true)
		expect(toasts).toEqual([['success', 'ArchiMate import started - monitoring progress...']])
	})

	it('polls the status after half a second and then every five seconds until idle', async () => {
		const running = { import: { status: 'running' }, export: {} }
		fetchMock.mockResolvedValue({
			ok: true,
			json: async () => ({ success: true, status: running }),
		})

		store.startStatusPolling()
		expect(fetchMock).not.toHaveBeenCalled()

		await vi.advanceTimersByTimeAsync(500)
		expect(fetchMock).toHaveBeenCalledTimes(1)
		expect(fetchMock.mock.calls[0][0]).toBe('/index.php/apps/stackiq/api/archimate/status')
		expect(store.isImportRunning).toBe(true)

		await vi.advanceTimersByTimeAsync(4500)
		expect(fetchMock).toHaveBeenCalledTimes(2)

		fetchMock.mockResolvedValue({
			ok: true,
			json: async () => ({ success: true, status: { import: { status: 'completed' }, export: {} } }),
		})
		await vi.advanceTimersByTimeAsync(5000)
		expect(fetchMock).toHaveBeenCalledTimes(3)
		expect(store.isImportRunning).toBe(false)
		expect(store.statusPollingInterval).toBeNull()

		await vi.advanceTimersByTimeAsync(20000)
		expect(fetchMock).toHaveBeenCalledTimes(3)
	})

	it('restarting the polling replaces the previous interval', async () => {
		fetchMock.mockResolvedValue({ ok: true, json: async () => ({ success: false }) })

		store.startStatusPolling()
		const first = store.statusPollingInterval
		store.startStatusPolling()

		expect(store.statusPollingInterval).not.toBe(first)
		await vi.advanceTimersByTimeAsync(5000)
		// Two half-second kicks plus one tick of the single remaining interval.
		expect(fetchMock).toHaveBeenCalledTimes(3)

		store.cleanup()
		expect(store.statusPollingInterval).toBeNull()
		expect(store.isStatusPolling).toBe(false)
	})
})

describe('settings store register changes and setting updaters', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useSettingsStore()
		store.settings.availableRegisters = [
			{ id: 3, schemas: [{ id: 30 }] },
			{ id: 4, schemas: [{ id: 40 }, { id: 41 }] },
			{ id: 5 },
		]
	})

	it('loads the schemas of the selected voorzieningen register', () => {
		store.handleVoorzieningenRegisterChange({ value: '4' })
		expect(store.voorzieningenSchemas).toEqual([{ id: 40 }, { id: 41 }])

		store.handleVoorzieningenRegisterChange({ value: '5' })
		expect(store.voorzieningenSchemas).toEqual([])

		store.handleVoorzieningenRegisterChange({ value: '3' })
		store.handleVoorzieningenRegisterChange(null)
		expect(store.voorzieningenSchemas).toEqual([])
	})

	it('loads the schemas of the selected AMEF register', () => {
		store.handleAmefRegisterChange({ value: '3' })
		expect(store.amefSchemas).toEqual([{ id: 30 }])

		store.handleAmefRegisterChange({ value: '99' })
		expect(store.amefSchemas).toEqual([])

		store.handleAmefRegisterChange({ value: '4' })
		store.handleAmefRegisterChange(undefined)
		expect(store.amefSchemas).toEqual([])
	})

	it('updates the catalog location, sync window and loading flag', async () => {
		await store.updateCatalogLocation('https://catalog.example')
		await store.updateSyncTimeWindow(15)
		store.setLoading(true)

		expect(store.settings.catalogLocation).toBe('https://catalog.example')
		expect(store.settings.syncTimeWindow).toBe(15)
		expect(store.loading).toBe(true)
	})

	it('saves email settings through the configuration save', async () => {
		const save = vi.spyOn(store, 'saveConfiguration').mockResolvedValue(undefined)

		await store.saveEmailSettings()
		expect(save).toHaveBeenCalledTimes(1)

		const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {})
		save.mockRejectedValueOnce(new Error('denied'))
		await expect(store.saveEmailSettings()).rejects.toThrow('denied')
		expect(consoleError).toHaveBeenCalledWith('Failed to save email settings:', expect.any(Error))
		consoleError.mockRestore()
	})
})
