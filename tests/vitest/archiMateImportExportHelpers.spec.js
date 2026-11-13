// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The ArchiMate import section's helpers: file selection, size and memory
 * formatting, cancelling a running import, clearing and resetting the import
 * state, the error-details toggle, error-type labels and the recognition and
 * description of configuration errors, plus stopping progress polling on
 * unmount.
 *
 * @spec openspec/specs/archimate-import-progress/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ post: vi.fn(), get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ __esModule: true, default: http }))
vi.mock('../../src/store/store.js', () => ({ settingsStore: {} }))

const { default: ArchiMateImportExport } =
	await import('../../src/views/settings/sections/ArchiMateImportExport.vue')

/**
 * A stand-in for the component: its initial data, the given state and every
 * method bound to it.
 *
 * @param {object} state State overrides.
 * @return {object} The stand-in.
 */
function viewModel(state = {}) {
	const vm = { ...ArchiMateImportExport.data.call({}), $refs: {}, ...state }
	for (const [name, fn] of Object.entries(ArchiMateImportExport.methods)) {
		vm[name] = fn.bind(vm)
	}
	return vm
}

beforeEach(() => {
	http.post.mockReset()
})

describe('ArchiMateImportExport helpers', () => {
	it('takes the first selected file and clears previous results', () => {
		const vm = viewModel({ importResult: { ok: true }, importError: 'old' })
		const file = { name: 'model.xml' }

		vm.handleFileSelect({ target: { files: [file] } })
		expect(vm.selectedFile).toBe(file)
		expect(vm.importResult).toBeNull()
		expect(vm.importError).toBeNull()

		vm.importError = 'kept'
		vm.handleFileSelect({ target: { files: [] } })
		expect(vm.selectedFile).toBe(file)
		expect(vm.importError).toBe('kept')
	})

	it('formats file sizes and memory', () => {
		const vm = viewModel()
		expect(vm.formatFileSize(0)).toBe('0 Bytes')
		expect(vm.formatFileSize(500)).toBe('500 Bytes')
		expect(vm.formatFileSize(1536)).toBe('1.5 KB')
		expect(vm.formatFileSize(5 * 1024 * 1024)).toBe('5 MB')
		expect(vm.formatMemory(512.4)).toBe('512 MB')
		expect(vm.formatMemory(1536)).toBe('1.5 GB')
	})

	it('cancels a running import through the cancel endpoint', async () => {
		const vm = viewModel({ operationId: 'op-7' })
		http.post.mockResolvedValue({ data: { cancelled: true } })

		await vm.cancelRunningImport()
		expect(http.post).toHaveBeenCalledWith(expect.stringContaining('/apps/stackiq/api/archimate/import/cancel'), { operationId: 'op-7' })
		expect(vm.cancelling).toBe(true)
	})

	it('does nothing without a running import and re-enables cancel when the request fails', async () => {
		const idle = viewModel()
		await idle.cancelRunningImport()
		expect(http.post).not.toHaveBeenCalled()
		expect(idle.cancelling).toBe(false)

		const vm = viewModel({ operationId: 'op-8' })
		http.post.mockRejectedValue(new Error('offline'))
		await vm.cancelRunningImport()
		expect(vm.cancelling).toBe(false)
	})

	it('clears results and resets the whole import, including the file input', () => {
		const vm = viewModel({ importResult: {}, importError: 'e', selectedFile: {}, importing: true, showErrors: true })
		vm.clearResults()
		expect([vm.importResult, vm.importError]).toEqual([null, null])
		expect(vm.selectedFile).not.toBeNull()

		const input = { value: 'C:\\fakepath\\model.xml' }
		vm.$refs.fileInput = input
		vm.resetImport()
		expect(vm.selectedFile).toBeNull()
		expect(vm.importing).toBe(false)
		expect(vm.showErrors).toBe(false)
		expect(input.value).toBe('')

		vm.$refs.fileInput = undefined
		expect(() => vm.resetImport()).not.toThrow()
	})

	it('shows error details only when the import reported errors', () => {
		const vm = viewModel({ importResult: { statistics: { omschrijving: { total_errors: 0 } } } })
		vm.showErrorDetails()
		expect(vm.showErrors).toBe(false)

		vm.importResult.statistics.omschrijving.total_errors = 2
		vm.showErrorDetails()
		expect(vm.showErrors).toBe(true)
		vm.hideErrorDetails()
		expect(vm.showErrors).toBe(false)

		vm.importResult = null
		vm.showErrorDetails()
		expect(vm.showErrors).toBe(false)
	})

	it('labels error types, capitalising unknown ones', () => {
		const vm = viewModel()
		expect(vm.formatErrorType('data_type')).toBe('Data Type')
		expect(vm.formatErrorType('relationship')).toBe('Relationship')
		expect(vm.formatErrorType('unexpected')).toBe('Unexpected')
	})

	it('recognises configuration errors', () => {
		const vm = viewModel()
		expect(vm.isConfigurationError('Schema ID for element \'Element\' is not configured')).toBe(true)
		expect(vm.isConfigurationError('ArchiMate import has missing configuration')).toBe(true)
		expect(vm.isConfigurationError('Please configure all AMEF schema IDs first')).toBe(true)
		expect(vm.isConfigurationError('Timeout')).toBe(false)
		expect(vm.isConfigurationError('')).toBeFalsy()
		expect(vm.isConfigurationError(null)).toBeFalsy()
	})

	it('describes configuration errors in both message formats', () => {
		const vm = viewModel()
		expect(vm.getConfigurationErrorDescription('')).toBe('')
		expect(vm.getConfigurationErrorDescription('Schema ID for view \'View schema\' is not configured')).toBe('Missing configuration for view schema (View schema)')
		expect(vm.getConfigurationErrorDescription('The register is not configured')).toBe('Required AMEF schema configuration is missing.')
		expect(vm.getConfigurationErrorDescription('Error\nArchiMate import cannot proceed without schemas \n- element')).toBe('Import cannot proceed without schemas')
		expect(vm.getConfigurationErrorDescription('missing configuration')).toBe('Required configuration is missing for ArchiMate import.')
	})

	it('stops progress polling when unmounted', () => {
		const stop = vi.fn()
		const vm = viewModel({ stopProgressPolling: stop })
		ArchiMateImportExport.beforeUnmount.call(vm)
		expect(stop).toHaveBeenCalledTimes(1)
		expect(vm.stopProgressPolling).toBeNull()

		expect(() => ArchiMateImportExport.beforeUnmount.call(viewModel())).not.toThrow()
	})
})
