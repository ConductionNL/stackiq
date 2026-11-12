// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The OpenRegister integration settings (when a save is possible, saving
 * and refreshing), the end-of-life feed sync settings (interval, status and
 * a manual run) and the federation settings (peers and a pull), each run
 * against a plain component context with the settings store and the admin
 * API doubled.
 *
 * @spec openspec/specs/fe-shell-navigation/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const toasts = vi.hoisted(() => [])
vi.mock('@nextcloud/dialogs', () => ({
	showError: (m) => toasts.push(['error', m]),
	showSuccess: (m) => toasts.push(['success', m]),
}))
vi.mock('@nextcloud/l10n', () => {
	const translate = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
	return { translate, translatePlural: (app, one, many, count, vars) => translate(app, count === 1 ? one : many, vars), getLanguage: () => 'en' }
})
vi.mock('../../src/components/AlwaysVisibleSection.vue', () => ({ default: {} }))
vi.mock('../../src/components/StandardTabs.vue', () => ({ default: {} }))
const api = vi.hoisted(() => ({ apiRequest: vi.fn() }))
vi.mock('../../src/utils/adminApi.js', async (orig) => ({ ...(await orig()), apiRequest: api.apiRequest }))
const store = vi.hoisted(() => ({ settingsStore: {} }))
vi.mock('../../src/store/store.js', () => store)

const { default: OpenRegisterIntegration } = await import('../../src/views/settings/sections/OpenRegisterIntegration.vue')
const { default: EolSyncSettings } = await import('../../src/views/settings/sections/EolSyncSettings.vue')
const { default: FederationSettings } = await import('../../src/views/settings/sections/FederationSettings.vue')

/**
 * A component context: extras, methods bound, data, and computed values
 * (plain or with a setter) as properties.
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
		const get = typeof def === 'function' ? def : def.get
		Object.defineProperty(ctx, name, { get: () => get.call(ctx), set: def.set ? (v) => def.set.call(ctx, v) : undefined, configurable: true })
	}
	return ctx
}

beforeEach(() => {
	toasts.length = 0
	api.apiRequest.mockReset()
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	vi.restoreAllMocks()
})

describe('OpenRegisterIntegration', () => {
	const { settingsStore } = store
	const section = () => context(OpenRegisterIntegration, { store: settingsStore })

	beforeEach(() => {
		for (const key of Object.keys(settingsStore)) {
			delete settingsStore[key]
		}
		Object.assign(settingsStore, {
			loadingOpenRegisterConfig: false,
			isLoadingRegisters: true,
			isLoadingVoorzieningenSchemas: false,
			isLoadingAmefSchemas: false,
			versionInfo: { version: '1' },
			configuration: {},
			registerOptions: ['r'],
			voorzieningenSchemaOptions: ['v'],
			amefSchemaOptions: ['a'],
			voorzieningenSchemas: [],
			amefSchemas: [],
			voorzieningenRegister: null,
			amefRegister: null,
			loadOpenRegisterEssentials: vi.fn(),
			handleVoorzieningenRegisterChange: vi.fn(),
			handleAmefRegisterChange: vi.fn(),
			validateConfiguration: vi.fn(),
			saveConfiguration: vi.fn(),
		})
	})

	it('reads the store and lists the schema items of both registers', () => {
		const s = section()
		expect([s.loading, s.loadingRegisters, s.versionInfo, s.registerOptions, s.voorzieningenItems.length, s.amefItems.length]).toEqual([false, true, { version: '1' }, ['r'], 13, 6])
		expect([s.loadingVoorzieningenSchemas, s.loadingAmefSchemas, s.voorzieningenSchemaOptions, s.amefSchemaOptions, s.voorzieningenSchemas, s.amefSchemas]).toEqual([false, false, ['v'], ['a'], [], []])
	})

	it('allows a save only with a register and a schema picked for it', () => {
		const s = section()
		expect(s.canSave).toBeFalsy()
		s.amefRegister = { id: 'amef' }
		expect([settingsStore.amefRegister, s.canSave]).toEqual([{ id: 'amef' }, false])
		settingsStore.configuration = { amef_view_schema: { schema: { value: 's1' } } }
		expect(s.canSave).toBe(true)
		s.amefRegister = null
		s.voorzieningenRegister = { id: 'vz' }
		expect(s.canSave).toBe(false)
		settingsStore.configuration = { voorzieningen_sector_schema: { schema: { value: 's2' } }, voorzieningen_suite_schema: { schema: {} } }
		expect([s.canSave, s.hasAmefConfigChanges()]).toEqual([true, false])
	})

	it('passes register changes and validation to the store', () => {
		const s = section()
		s.handleVoorzieningenRegisterChange('vz')
		s.handleAmefRegisterChange('amef')
		s.validateConfiguration()
		expect([settingsStore.handleVoorzieningenRegisterChange.mock.calls[0], settingsStore.handleAmefRegisterChange.mock.calls[0], settingsStore.validateConfiguration.mock.calls.length]).toEqual([['vz'], ['amef'], 1])
	})

	it('loads on mount, saves and refreshes, saying how it went', async () => {
		const s = section()
		await OpenRegisterIntegration.mounted.call(s)
		settingsStore.loadOpenRegisterEssentials.mockRejectedValueOnce(new Error('no OR'))
		await OpenRegisterIntegration.mounted.call(s)
		await s.saveConfiguration()
		settingsStore.saveConfiguration.mockRejectedValueOnce(new Error('denied'))
		await s.saveConfiguration()
		await s.refreshSettings()
		settingsStore.loadOpenRegisterEssentials.mockRejectedValueOnce(new Error('offline'))
		await s.refreshSettings()
		expect(toasts).toEqual([
			['error', 'Failed to load OpenRegister configuration: no OR'],
			['success', 'OpenRegister configuration saved successfully'],
			['error', 'Failed to save OpenRegister configuration: denied'],
			['success', 'OpenRegister configuration refreshed successfully'],
			['error', 'Failed to refresh OpenRegister configuration: offline'],
		])
		expect(s.saving).toBe(false)
	})
})

describe('EolSyncSettings', () => {
	const section = () => context(EolSyncSettings)

	it('loads the configuration and the status', async () => {
		api.apiRequest.mockImplementation(async (path) => (path === 'eol-sync/config' ? { config: { enabled: true, intervalSeconds: 3600 } } : { status: { available: true, lastRunAt: '2026-05-15T10:00:00Z' } }))
		const s = section()
		await EolSyncSettings.created.call(s)
		expect([s.config.enabled, s.config.register, s.intervalMinutesInput, s.status.available, s.formattedLastRunAt, s.loading]).toEqual([true, '', '60', true, new Date('2026-05-15T10:00:00Z').toLocaleString(), false])
	})

	it('keeps the defaults and says why when loading fails', async () => {
		api.apiRequest.mockRejectedValue(new Error('down'))
		const s = section()
		await s.loadAll()
		expect([s.config.intervalSeconds, s.formattedLastRunAt, toasts.map((e) => e[1])]).toEqual([86400, 'never', ['Could not load EOL sync configuration: down', 'Could not load EOL sync status: down']])
		api.apiRequest.mockResolvedValue(null)
		await s.loadAll()
		expect(s.status.reason).toBe('not-yet-run')
	})

	it('takes the interval in whole minutes', () => {
		const s = section()
		s.intervalMinutesInput = '15'
		expect(s.config.intervalSeconds).toBe(900)
		s.intervalMinutesInput = '0'
		s.intervalMinutesInput = 'soon'
		expect(s.config.intervalSeconds).toBe(900)
		s.config.intervalSeconds = 20
		expect(s.intervalMinutesInput).toBe('1')
	})

	it('saves the configuration as the server returns it', async () => {
		const s = section()
		api.apiRequest.mockResolvedValueOnce({ config: { register: 'eol' } })
		await s.saveConfig()
		expect([api.apiRequest.mock.calls[0], s.config.register, toasts[0], s.saving]).toEqual([['eol-sync/config', { method: 'POST', body: { enabled: false, register: '', productSchema: '', cycleSchema: '', intervalSeconds: 86400 } }], 'eol', ['success', 'EOL sync settings saved'], false])
		api.apiRequest.mockRejectedValueOnce(new Error('denied'))
		await s.saveConfig()
		expect(toasts[1]).toEqual(['error', 'Could not save EOL sync settings: denied'])
	})

	it('runs a sync and says what it matched, or why it did not run', async () => {
		const s = section()
		api.apiRequest.mockResolvedValueOnce({ status: { available: true, matched: 4, skipped: 1 } })
		await s.triggerSync()
		api.apiRequest.mockResolvedValueOnce({ status: { available: false, reason: 'openregister-not-installed' } })
		await s.triggerSync()
		api.apiRequest.mockResolvedValueOnce({ status: { reason: 'brand-new' } })
		await s.triggerSync()
		api.apiRequest.mockRejectedValueOnce(new Error('timeout'))
		await s.triggerSync()
		expect(toasts.map((e) => e[1])).toEqual(['EOL sync completed: 4 matched, 1 skipped.', 'EOL sync did not run: OpenRegister is not installed', 'EOL sync did not run: brand-new', 'EOL sync failed: timeout'])
		expect([api.apiRequest.mock.calls[0], s.syncing]).toEqual([['eol-sync/trigger', { method: 'POST' }], false])
	})

	it('names every reason it knows', () => {
		const s = section()
		for (const reason of ['disabled', 'not-yet-run', 'object-service-unavailable', 'module-schema-not-configured', 'eol-register-or-schema-not-found']) {
			s.status.reason = reason
			expect(s.unavailableReasonLabel).not.toBe(reason)
		}
		s.status.reason = ''
		expect(s.unavailableReasonLabel).toBe('not yet run')
	})
})

describe('FederationSettings', () => {
	const section = () => context(FederationSettings)

	it('loads the status in its normal shape', async () => {
		api.apiRequest.mockResolvedValueOnce({ available: true, enabled: true, peers: ['https://a', { url: 'https://b', failures: 2, stale: true, allowed: false }] })
		const s = section()
		await FederationSettings.created.call(s)
		expect([api.apiRequest.mock.calls[0], s.status.peers, s.status.staleAfter, s.loading]).toEqual([['federation/status'], [{ url: 'https://a', failures: 0, stale: false, allowed: true }, { url: 'https://b', failures: 2, stale: true, allowed: false }], 3, false])
		api.apiRequest.mockRejectedValueOnce(new Error('down'))
		await s.loadStatus()
		expect(toasts).toEqual([['error', 'Could not load federation status: down']])
	})

	it('adds a trimmed peer and reloads, ignoring an empty one', async () => {
		const s = section()
		s.newPeerUrl = '   '
		await s.addPeer()
		expect(api.apiRequest).not.toHaveBeenCalled()
		s.newPeerUrl = ' https://peer.nl '
		api.apiRequest.mockResolvedValue({})
		await s.addPeer()
		expect([api.apiRequest.mock.calls[0], api.apiRequest.mock.calls[1][0], s.newPeerUrl, toasts[0], s.adding]).toEqual([['federation/peers', { method: 'POST', body: { peerUrl: 'https://peer.nl' } }], 'federation/status', '', ['success', 'Peer added'], false])
		api.apiRequest.mockRejectedValueOnce(new Error('Not allowed'))
		s.newPeerUrl = 'https://x'
		await s.addPeer()
		expect([toasts[1], s.newPeerUrl]).toEqual([['error', 'Could not add peer: Not allowed'], 'https://x'])
	})

	it('removes a peer and pulls from all of them', async () => {
		const s = section()
		api.apiRequest.mockResolvedValue({})
		await s.removePeer('https://a')
		expect([api.apiRequest.mock.calls[0], toasts[0], s.removingUrl]).toEqual([['federation/peers', { method: 'DELETE', body: { peerUrl: 'https://a' } }], ['success', 'Peer removed'], null])
		api.apiRequest.mockResolvedValueOnce({ peers: ['a', 'b'] })
		await s.triggerPull()
		expect([s.pullSummary, toasts[1], s.pulling]).toEqual(['Pulled 2 peer(s).', ['success', 'Federation pull completed'], false])
		api.apiRequest.mockResolvedValueOnce({})
		await s.triggerPull()
		expect(s.pullSummary).toBe('Pulled 0 peer(s).')
		api.apiRequest.mockRejectedValueOnce(new Error('gone'))
		await s.removePeer('https://a')
		api.apiRequest.mockRejectedValueOnce(new Error('offline'))
		await s.triggerPull()
		expect([toasts.slice(-2), s.pullSummary]).toEqual([[['error', 'Could not remove peer: gone'], ['error', 'Federation pull failed: offline']], ''])
	})
})
