// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The organisation synchronisation settings (the saved time window, the
 * status, a manual and an organisation sync) and the cronjob settings
 * (loading jobs with their organisation, saving and running a job), each run
 * against a plain component context with fetch, axios and the settings store
 * doubled.
 *
 * @spec openspec/specs/fe-shell-navigation/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const toasts = vi.hoisted(() => [])
const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => ({
	showError: (m) => toasts.push(['error', m]),
	showSuccess: (m) => toasts.push(['success', m]),
}))
vi.mock('../../src/components/AlwaysVisibleSection.vue', () => ({ default: {} }))
vi.mock('../../src/utils/heartbeat.js', () => ({ withHeartbeat: (fn) => fn() }))
const store = vi.hoisted(() => ({ settingsStore: {} }))
vi.mock('../../src/store/store.js', () => store)

const { default: OrganizationSynchronization } = await import('../../src/views/settings/sections/OrganizationSynchronization.vue')
const { default: CronjobConfiguration } = await import('../../src/views/settings/sections/CronjobConfiguration.vue')

/**
 * A component context: props and extras, methods bound, data, and computed
 * values as getters.
 *
 * @param {object} comp The component definition.
 * @param {object} [extra] Anything else on `this`.
 * @return {object} The context.
 */
function context(comp, extra = {}) {
	const ctx = { $emit: vi.fn(), ...extra }
	for (const [name, fn] of Object.entries(comp.methods || {})) {
		ctx[name] = fn.bind(ctx)
	}
	Object.assign(ctx, comp.data ? comp.data.call(ctx) : {})
	for (const [name, def] of Object.entries(comp.computed || {})) {
		Object.defineProperty(ctx, name, { get: () => def.call(ctx), configurable: true })
	}
	return ctx
}

const json = (body, ok = true) => ({ ok, status: ok ? 200 : 500, statusText: ok ? 'OK' : 'Server Error', json: async () => body })

beforeEach(() => {
	toasts.length = 0
	http.get.mockReset()
	http.post.mockReset()
	globalThis.OC = { requestToken: 'tok' }
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	vi.restoreAllMocks()
	vi.unstubAllGlobals()
})

describe('OrganizationSynchronization', () => {
	const { settingsStore } = store
	const section = () => context(OrganizationSynchronization, { store: settingsStore })

	beforeEach(() => {
		Object.assign(settingsStore, { loadingSyncSettings: false, settings: { syncTimeWindow: 60 }, loadSyncConfig: vi.fn(), updateSyncTimeWindow: vi.fn(), saveConfiguration: vi.fn() })
	})

	it('starts from the saved time window and loads the status for it', async () => {
		const fetchSpy = vi.fn(async () => json({ pending: 3 }))
		vi.stubGlobal('fetch', fetchSpy)
		const s = section()
		await OrganizationSynchronization.created.call(s)
		expect([s.selectedTimeWindow, s.originalTimeWindow.value, s.hasTimeWindowChanges, s.loading, s.settings]).toEqual([{ value: 60, label: '1 hour' }, 60, false, false, { syncTimeWindow: 60 }])
		expect([fetchSpy.mock.calls[0][0], s.syncStatus, s.loadingSyncStatus]).toEqual(['/index.php/apps/stackiq/api/settings/sync-status?timeWindow=60', { pending: 3 }, false])
		s.selectedTimeWindow = { value: 5, label: '5 minutes' }
		expect(s.hasTimeWindowChanges).toBe(true)
		await s.handleTimeWindowChange()
		expect(fetchSpy.mock.calls[1][0]).toMatch(/timeWindow=5$/)
	})

	it('asks the store for the window when none is loaded, and ignores an unknown one', async () => {
		settingsStore.settings = {}
		settingsStore.loadSyncConfig = vi.fn(async () => { settingsStore.settings = { syncTimeWindow: 0 } })
		const s = section()
		await s.loadSavedConfiguration()
		expect([settingsStore.loadSyncConfig.mock.calls.length, s.selectedTimeWindow.value, s.getTimeWindowDescription()]).toEqual([1, 0, 'Full synchronization will process all organizations regardless of when they were last updated.'])
		settingsStore.settings = { syncTimeWindow: 7 }
		await s.loadSavedConfiguration()
		expect(s.selectedTimeWindow.value).toBe(0)
		settingsStore.settings = null
		await s.loadSavedConfiguration()
		expect(console.error).toHaveBeenCalled()
	})

	it('reloads the window when the store finishes loading or the setting changes', () => {
		const s = section()
		const load = vi.fn()
		s.loadSavedConfiguration = load
		const watch = OrganizationSynchronization.watch
		watch['store.loadingSyncSettings'].handler.call(s, false, true)
		watch['store.loadingSyncSettings'].handler.call(s, true, false)
		watch['settings.syncTimeWindow'].handler.call(s, 30)
		watch['settings.syncTimeWindow'].handler.call(s, undefined)
		expect(load).toHaveBeenCalledTimes(2)
	})

	it('says when the status cannot be loaded', async () => {
		vi.stubGlobal('fetch', vi.fn(async () => json({}, false)))
		const s = section()
		await s.loadSyncStatus()
		expect([toasts, s.loadingSyncStatus]).toEqual([[['error', 'Failed to load synchronization status: HTTP 500: Server Error']], false])
	})

	it('runs a manual sync for the window and refreshes the status', async () => {
		const fetchSpy = vi.fn(async (url) => (url.endsWith('/sync') ? json({ success: true, processed: 4 }) : json({ pending: 0 })))
		vi.stubGlobal('fetch', fetchSpy)
		const s = section()
		s.selectedTimeWindow = { value: 30, label: '30 minutes' }
		await s.performManualSync()
		const [url, init] = fetchSpy.mock.calls[0]
		expect([url, init.method, JSON.parse(init.body), init.headers.requesttoken]).toEqual(['/index.php/apps/stackiq/api/settings/sync', 'POST', { timeWindow: 30 }, 'tok'])
		expect([s.syncResult, toasts[0], fetchSpy.mock.calls.length, s.performingSync]).toEqual([{ success: true, processed: 4 }, ['success', 'Manual synchronization completed successfully'], 2, false])
	})

	it('reports a refused or failed manual sync', async () => {
		vi.stubGlobal('fetch', vi.fn(async () => json({ success: false, message: 'Locked' })))
		const s = section()
		await s.performManualSync()
		expect(s.syncResult).toEqual({ success: false, message: 'Failed to perform manual synchronization: Locked' })
		vi.stubGlobal('fetch', vi.fn(async () => json({ success: false })))
		await s.performManualSync()
		vi.stubGlobal('fetch', vi.fn(async () => json({}, false)))
		await s.performManualSync()
		expect(toasts.map((e) => e[1])).toEqual(['Failed to perform manual synchronization: Locked', 'Failed to perform manual synchronization: Synchronization failed', 'Failed to perform manual synchronization: HTTP 500: Server Error'])
	})

	it('saves the time window and refreshes the configuration', async () => {
		const s = section()
		s.selectedTimeWindow = { value: 120, label: '2 hours' }
		await s.saveConfiguration()
		expect([settingsStore.updateSyncTimeWindow.mock.calls[0], settingsStore.saveConfiguration.mock.calls.length, s.originalTimeWindow.value, toasts[0]]).toEqual([[120], 1, 120, ['success', 'Time window configuration saved successfully']])
		settingsStore.saveConfiguration.mockRejectedValueOnce(new Error('denied'))
		await s.saveConfiguration()
		expect([toasts[1], s.savingConfig]).toEqual([['error', 'Failed to save time window configuration: denied'], false])
		await s.refreshConfiguration()
		settingsStore.loadSyncConfig.mockRejectedValueOnce(new Error('x'))
		await s.refreshConfiguration()
		expect(settingsStore.loadSyncConfig).toHaveBeenCalledTimes(2)
	})

	it('runs an organisation sync as a preview or for real', async () => {
		const fetchSpy = vi.fn(async () => json({ success: true }))
		vi.stubGlobal('fetch', fetchSpy)
		const s = section()
		await s.performOrganisationSync()
		s.orgSyncOptions = { dryRun: false, batchSize: 50 }
		await s.performOrganisationSync()
		expect(fetchSpy.mock.calls.map((c) => [c[0], JSON.parse(c[1].body)])).toEqual([['/index.php/apps/stackiq/api/settings/sync/organisations', { dry_run: true, batch_size: 500 }], ['/index.php/apps/stackiq/api/settings/sync/organisations', { dry_run: false, batch_size: 50 }]])
		expect(toasts.map((e) => e[1])).toEqual(['Organisation sync preview completed', 'Organisation sync completed successfully'])
		fetchSpy.mockResolvedValueOnce(json({ success: false }))
		await s.performOrganisationSync()
		expect([s.orgSyncResult, s.performingOrgSync]).toEqual([{ success: false, message: 'Failed to perform organisation sync: Organisation sync failed' }, false])
	})

	it('formats windows, counts and times', () => {
		const s = section()
		expect([s.formatTimeWindow(0), s.formatTimeWindow(30), s.formatTimeWindow(120), s.formatTimeWindow(2880)]).toEqual(['Full sync', '30 minutes', '2 hours', '2 days'])
		expect([s.formatNumber(0), s.formatNumber(1234)]).toEqual(['0', (1234).toLocaleString()])
		expect([s.formatLastSyncTime(null), s.formatLastSyncTime('2026-05-15T10:00:00Z')]).toEqual(['Never', new Date('2026-05-15T10:00:00Z').toLocaleString()])
		expect([0, 9, 99, 100].map((n) => s.getProcessingClass(n))).toEqual(['processing-none', 'processing-low', 'processing-medium', 'processing-high'])
		s.selectedTimeWindow = { value: 15, label: '15 minutes' }
		expect(s.getTimeWindowDescription()).toBe('Incremental synchronization will process organizations updated within the last 15 minutes.')
		s.selectedTimeWindow = null
		expect(s.getTimeWindowDescription()).toBe('')
	})
})

describe('CronjobConfiguration', () => {
	const section = () => context(CronjobConfiguration)
	const answers = (cronjobs, organisations) => async (url) => (url.includes('/cronjobs') ? { data: { success: true, cronjobs } } : { data: { results: organisations } })

	it('loads the jobs and names their organisation', async () => {
		http.get.mockImplementation(answers({ a: { id: 'a', organisationUuid: 'o1' }, b: { id: 'b' } }, [{ uuid: 'o1', name: 'Gemeente A', description: 'd' }]))
		const s = section()
		await CronjobConfiguration.mounted.call(s)
		expect([s.cronjobs.map((j) => j.selectedOrganisation), s.organisationOptions, s.loading]).toEqual([[{ value: 'o1', label: 'Gemeente A' }, null], [{ value: 'o1', label: 'Gemeente A' }], false])
		expect([s.canSaveJob(s.cronjobs[0]), s.canSaveJob(s.cronjobs[1]), s.t('stackiq', 'Text')]).toEqual([true, false, 'Text'])
	})

	it('keeps the uuid as a label for an unknown organisation and says when loading fails', async () => {
		http.get.mockImplementation(answers({ a: { id: 'a', organisationUuid: 'gone' } }, []))
		const s = section()
		await s.loadConfig()
		expect(s.cronjobs[0].selectedOrganisation).toEqual({ value: 'gone', label: 'gone' })
		http.get.mockImplementation(async (url) => {
			if (url.includes('/cronjobs')) {
				throw new Error('x')
			}
			return { data: {} }
		})
		await s.loadConfig()
		expect([toasts, s.loading, s.loadingOrganisations]).toEqual([[['error', 'Failed to load cronjob configuration']], false, false])
		http.get.mockImplementation(async (url) => (url.includes('/cronjobs') ? { data: { success: false } } : Promise.reject(new Error('x'))))
		await s.loadConfig()
		expect(s.cronjobs).toHaveLength(1)
	})

	it('saves a job with its organisation, or says why not', async () => {
		const s = section()
		const job = { id: 'a', enabled: false, selectedOrganisation: { value: 'o1' } }
		s.cronjobs = [job]
		http.post.mockResolvedValueOnce({ data: { success: true } })
		await s.updateJobEnabled('a', true)
		expect([http.post.mock.calls[0], job.organisationUuid, toasts[0], s.savingJob]).toEqual([['/index.php/apps/stackiq/api/settings/cronjobs', { jobId: 'a', organisationUuid: 'o1', enabled: true }], 'o1', ['success', 'Cronjob configuration saved'], null])
		await s.updateJobEnabled('missing', true)
		http.post.mockResolvedValueOnce({ data: { success: false } })
		await s.saveJobConfig({ id: 'b', enabled: true })
		http.post.mockResolvedValueOnce({ data: { success: false, message: 'No access' } })
		await s.saveJobConfig({ id: 'b' })
		http.post.mockRejectedValueOnce(new Error('x'))
		await s.saveJobConfig({ id: 'b' })
		expect([http.post.mock.calls[1][1], toasts.slice(1).map((e) => e[1])]).toEqual([{ jobId: 'b', organisationUuid: null, enabled: true }, ['Failed to save configuration', 'No access', 'Failed to save cronjob configuration']])
	})

	it('runs a job only once an organisation is saved, and reports the run', async () => {
		const s = section()
		await s.runJob({ id: 'a' })
		expect([toasts[0], http.post.mock.calls.length]).toEqual([['error', 'Please configure and save an organisation first'], 0])
		http.post.mockResolvedValueOnce({ data: { success: true } }).mockResolvedValueOnce({ data: { success: false } }).mockResolvedValueOnce({ data: { success: false, message: 'Busy' } }).mockRejectedValueOnce({ response: { data: { message: 'Timeout' } } }).mockRejectedValueOnce(new Error('Offline'))
		for (let i = 0; i < 5; i++) {
			await s.runJob({ id: 'a', organisationUuid: 'o1' })
		}
		expect([http.post.mock.calls[0][0], toasts.slice(1).map((e) => e[1]), s.runningJob]).toEqual(['/index.php/apps/stackiq/api/settings/sync', ['Background job executed successfully', 'Job execution failed', 'Busy', 'Failed to run background job: Timeout', 'Failed to run background job: Offline'], null])
	})

	it('writes an interval in seconds, minutes or hours', () => {
		const s = section()
		expect([30, 60, 150, 3600, 7200].map((n) => s.formatInterval(n))).toEqual(['30 seconds', '1 minute', '2 minutes', '1 hour', '2 hours'])
	})
})
