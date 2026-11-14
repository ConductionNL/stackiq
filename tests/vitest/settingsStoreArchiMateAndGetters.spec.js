// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The settings store's getters, simple setters, register-change handlers,
 * the ArchiMate import kick-off with its failure paths and the status polling
 * it starts and stops.
 *
 * @spec openspec/specs/fe-stores/spec.md
 */

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useSettingsStore } from '../../src/store/modules/settings.js'

const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/dialogs', () => dialogs)

/**
 * A fetch response.
 *
 * @param {object} body The JSON body.
 * @param {number} status The HTTP status.
 * @return {object} The response.
 */
function response(body, status = 200) {
	return { ok: status < 400, status, statusText: 'x', json: async () => body }
}

let store

beforeEach(() => {
	setActivePinia(createPinia())
	store = useSettingsStore()
	globalThis.OC = { requestToken: 'tok' }
	globalThis.fetch = vi.fn()
	dialogs.showError.mockClear()
	dialogs.showSuccess.mockClear()
	vi.spyOn(console, 'error').mockImplementation(() => {})
	vi.spyOn(console, 'info').mockImplementation(() => {})
})

afterEach(() => {
	store.cleanup()
	vi.useRealTimers()
	vi.restoreAllMocks()
	delete globalThis.OC
})

describe('settings store getters and setters', () => {
	it('exposes flags, loading states and register options', () => {
		expect(store.registerOptions).toEqual([])

		store.$patch({
			openRegisters: true,
			isAdmin: true,
			loadingRegisters: true,
			loadingVoorzieningenSchemas: true,
			loadingAmefSchemas: false,
			isExportRunning: true,
		})
		store.settings.availableRegisters = [{ id: 1, title: 'Voorzieningen' }, { id: 2, name: 'amef' }, { id: 3 }]

		expect(store.hasOpenRegisters).toBe(true)
		expect(store.getIsAdmin).toBe(true)
		expect(store.isAnyOperationRunning).toBe(true)
		expect(store.isLoadingRegisters).toBe(true)
		expect(store.isLoadingVoorzieningenSchemas).toBe(true)
		expect(store.isLoadingAmefSchemas).toBe(false)
		expect(store.registerOptions).toEqual([
			{ label: 'Voorzieningen', value: '1' },
			{ label: 'amef', value: '2' },
			{ label: 'Register 3', value: '3' },
		])
	})

	it('sets loading, catalog location and sync window', async () => {
		store.setLoading(true)
		await store.updateCatalogLocation('https://catalogus.example')
		await store.updateSyncTimeWindow(30)

		expect(store.loading).toBe(true)
		expect(store.settings.catalogLocation).toBe('https://catalogus.example')
		expect(store.settings.syncTimeWindow).toBe(30)
		expect(store.validateConfiguration()).toBeUndefined()
	})

	it('loads the schemas of the chosen register, or none', () => {
		store.settings.availableRegisters = [{ id: 1, schemas: [{ id: 10 }] }, { id: 2 }]

		store.handleVoorzieningenRegisterChange({ value: '1' })
		store.handleAmefRegisterChange({ value: '2' })
		expect(store.voorzieningenSchemas).toEqual([{ id: 10 }])
		expect(store.amefSchemas).toEqual([])

		store.handleAmefRegisterChange({ value: '1' })
		expect(store.amefSchemas).toEqual([{ id: 10 }])
		store.handleVoorzieningenRegisterChange(null)
		store.handleAmefRegisterChange(null)
		expect(store.voorzieningenSchemas).toEqual([])
		expect(store.amefSchemas).toEqual([])
	})

	it('saves email settings through the configuration save', async () => {
		globalThis.fetch.mockResolvedValue(response({ success: true }))

		await expect(store.saveEmailSettings()).resolves.toBeUndefined()
		expect(globalThis.fetch).toHaveBeenCalled()
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Configuration saved successfully')
		expect(dialogs.showError).not.toHaveBeenCalled()
	})
})

describe('settings store ArchiMate import', () => {
	it('refuses to import without a file', () => {
		store.importArchiMateFile()
		expect(dialogs.showError).toHaveBeenCalledWith('No file selected for import')
		expect(globalThis.fetch).not.toHaveBeenCalled()
	})

	it('posts the file with its options and marks the import running', () => {
		vi.useFakeTimers()
		globalThis.fetch.mockReturnValue(new Promise(() => {}))
		store.selectedFile = new Blob(['<model/>'])
		store.importOptions = { updateExisting: true, deleteOrphaned: false }

		store.importArchiMateFile('memory')

		const [url, options] = globalThis.fetch.mock.calls[0]
		expect(url).toBe('/index.php/apps/stackiq/api/archimate/import')
		expect(options.method).toBe('POST')
		expect(options.headers).toEqual({ requesttoken: 'tok', 'X-Requested-With': 'XMLHttpRequest' })
		expect(options.body.get('updateExisting')).toBe('true')
		expect(options.body.get('deleteOrphaned')).toBe('false')
		expect(options.body.get('preserveIds')).toBe('true')
		expect(options.body.get('processingMode')).toBe('memory')
		expect(store.isImportRunning).toBe(true)
		expect(store.importing).toBe(false)
		expect(store.archimateStatus.import.status).toBe('running')
		expect(dialogs.showSuccess).toHaveBeenCalledWith('ArchiMate import started - monitoring progress...')
	})

	it('marks the import failed on a server error', async () => {
		globalThis.fetch.mockImplementation(async (url) => (url.endsWith('/import') ? response({}, 500) : response({})))
		store.selectedFile = new Blob(['x'])

		store.importArchiMateFile()
		await vi.waitFor(() => expect(store.archimateStatus.import.status).toBe('failed'))

		expect(store.archimateStatus.import.error).toBe('Server error (500)')
		expect(store.isImportRunning).toBe(false)
		expect(store.statusPollingInterval).toBeNull()
		expect(dialogs.showError).toHaveBeenCalledWith('Import failed: Server error. Please try with a smaller file or check server logs.')
	})

	it('marks the import failed on a network error but ignores an abort', async () => {
		store.selectedFile = new Blob(['x'])
		globalThis.fetch.mockRejectedValueOnce(Object.assign(new Error('aborted'), { name: 'AbortError' }))
		store.importArchiMateFile()
		await Promise.resolve()
		await Promise.resolve()
		expect(store.archimateStatus.import.status).toBe('running')

		globalThis.fetch.mockRejectedValueOnce(new Error('offline'))
		store.importArchiMateFile()
		await vi.waitFor(() => expect(store.archimateStatus.import.status).toBe('failed'))
		expect(store.archimateStatus.import.error).toBe('offline')
		expect(dialogs.showError).toHaveBeenCalledWith('Import failed: offline')
	})

	it('polls the status after half a second and every five seconds, and stops when nothing runs', async () => {
		vi.useFakeTimers()
		globalThis.fetch.mockResolvedValue(response({ success: true, status: { import: { status: 'running' } } }))

		store.startStatusPolling()
		const first = store.statusPollingInterval
		store.startStatusPolling()
		expect(store.statusPollingInterval).not.toBe(first)

		await vi.advanceTimersByTimeAsync(500)
		expect(globalThis.fetch).toHaveBeenCalledWith('/index.php/apps/stackiq/api/archimate/status', undefined)
		expect(store.isImportRunning).toBe(true)

		globalThis.fetch.mockResolvedValue(response({ success: true, status: { import: { status: 'done' } } }))
		await vi.advanceTimersByTimeAsync(5000)
		expect(store.isImportRunning).toBe(false)
		expect(store.statusPollingInterval).toBeNull()
	})
})
