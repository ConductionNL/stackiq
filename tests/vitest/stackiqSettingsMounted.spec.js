// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * StackiqSettings as rendered: the version-card maintenance actions the shell
 * shows for each auto-config state, the General Settings section's catalog
 * location field and save gating, and the three maintenance handlers' store
 * calls and toasts. The section children are stubbed to inert placeholders.
 *
 * @spec openspec/specs/fe-settings-ui/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const store = vi.hoisted(() => ({}))
const toasts = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
const feedback = vi.hoisted(() => ({
	reportActionResult: vi.fn(),
	reportAutoConfigResult: vi.fn(),
	FORCE_UPDATE_TEXTS: { success: 'fu-ok', failure: 'fu-bad' },
	RESET_AUTO_CONFIG_TEXTS: { success: 'reset-ok', failure: 'reset-bad' },
}))

vi.mock('@conduction/nextcloud-vue', () => ({ CnAdminSettingsShell: {}, CnDataTable: {}, CnStatusBadge: {} }))
vi.mock('@nextcloud/dialogs', () => ({ ...toasts, showWarning: vi.fn(), showInfo: vi.fn() }))
vi.mock('@nextcloud/initial-state', () => ({ loadState: (app, key, fallback) => fallback }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, singular, plural, count) => (count === 1 ? singular : plural),
	getLanguage: () => 'en',
}))
vi.mock('../../src/store/store.js', () => ({ settingsStore: store }))
vi.mock('../../src/views/settings/autoConfigFeedback.js', () => feedback)

const { default: StackiqSettings } = await import('../../src/views/settings/StackiqSettings.vue')

const inert = { template: '<div />' }
const stubs = {
	CnAdminSettingsShell: {
		props: ['appVersion', 'configuredVersion', 'isUpToDate'],
		template: '<div data-testid="shell" :data-version="appVersion" :data-configured="configuredVersion" :data-uptodate="String(isUpToDate)"><div data-testid="actions"><slot name="actions" /></div><slot /></div>',
	},
	AlwaysVisibleSection: {
		props: ['canSave', 'saving'],
		emits: ['save', 'refresh'],
		template: '<section><button data-testid="save-general" :disabled="!canSave || saving" @click="$emit(\'save\')" /><button data-testid="refresh-general" @click="$emit(\'refresh\')" /><slot /></section>',
	},
	NcButton: {
		props: ['disabled'],
		emits: ['click'],
		template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcTextField: {
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		template: '<input :data-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)"><slot name="icon" /></input>',
	},
	Web: true,
	StatisticsOverview: inert,
	OpenRegisterIntegration: inert,
	UserGroupsConfiguration: inert,
	OrganizationSynchronization: inert,
	ArchiMateImportExport: inert,
	CmdbImport: inert,
	EmailConfiguration: inert,
	ModerationQueue: inert,
	FederationSettings: inert,
	EolSyncSettings: inert,
	ItsmExchange: inert,
	CronjobConfiguration: inert,
}

/**
 * Mount the page over a store double.
 *
 * @param {object} extra Store fields to merge in (versionInfo, settings, action methods).
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountPage(extra = {}) {
	Object.assign(store, {
		loadingGeneralSettings: false,
		loading: false,
		settings: { catalogLocation: 'https://stored' },
		versionInfo: {},
		loadSettings: vi.fn(async () => {}),
		loadVersionInfo: vi.fn(async () => {}),
		...extra,
	})
	const wrapper = mount(StackiqSettings, { global: { stubs } })
	await flushPromises()
	return wrapper
}

const actionButtons = (wrapper) => wrapper.find('[data-testid="actions"]').findAll('button').map((b) => b.text())

beforeEach(() => {
	vi.clearAllMocks()
	for (const key of Object.keys(store)) delete store[key]
})

describe('StackiqSettings mounted', () => {
	it('passes the version info to the shell and reads the stored catalog location', async () => {
		const wrapper = await mountPage({ versionInfo: { appVersion: '2.0', configuredVersion: '1.9', versionsMatch: false } })
		const shell = wrapper.find('[data-testid="shell"]')
		expect([shell.attributes('data-version'), shell.attributes('data-configured'), shell.attributes('data-uptodate')]).toEqual(['2.0', '1.9', 'false'])
		expect(wrapper.find('[data-label="Stackiq Location URL"]').element.value).toBe('https://stored')
		expect(store.loadSettings).toHaveBeenCalledTimes(1)
	})

	it('offers Auto Configure and Reset by auto-config state, Force Update always', async () => {
		expect(actionButtons(await mountPage({ versionInfo: { autoConfigCompleted: false } }))).toEqual(['Auto Configure', 'Force Update'])
		expect(actionButtons(await mountPage({ versionInfo: { autoConfigCompleted: true } }))).toEqual(['Force Update', 'Reset Auto-Config'])
		expect(actionButtons(await mountPage({ versionInfo: {} }))).toEqual(['Force Update'])
	})

	it('gates the General Settings save on an edited location', async () => {
		const wrapper = await mountPage()
		const save = () => wrapper.find('[data-testid="save-general"]')
		expect(save().attributes('disabled')).toBe('')
		await wrapper.find('[data-label="Stackiq Location URL"]').setValue('https://edited')
		expect(save().attributes('disabled')).toBeUndefined()
	})

	it('auto configures through the store and reports the result', async () => {
		const result = { success: true }
		const wrapper = await mountPage({
			versionInfo: { autoConfigCompleted: false },
			consolidatedAutoConfigure: vi.fn(async () => result),
		})
		await wrapper.find('[data-testid="actions"]').findAll('button')[0].trigger('click')
		await flushPromises()
		expect(store.consolidatedAutoConfigure).toHaveBeenCalledTimes(1)
		expect(feedback.reportAutoConfigResult).toHaveBeenCalledWith(result)
		expect(store.loadVersionInfo).toHaveBeenCalledTimes(1)
	})

	it('force updates and resets through the store, each with its own texts', async () => {
		const forced = { success: true }
		const reset = { success: false }
		const wrapper = await mountPage({
			versionInfo: { autoConfigCompleted: true },
			forceUpdate: vi.fn(async () => forced),
			resetAutoConfig: vi.fn(async () => reset),
		})
		const buttons = wrapper.find('[data-testid="actions"]').findAll('button')
		await buttons[0].trigger('click')
		await flushPromises()
		expect(store.forceUpdate).toHaveBeenCalledTimes(1)
		expect(feedback.reportActionResult).toHaveBeenLastCalledWith(forced, feedback.FORCE_UPDATE_TEXTS)

		await buttons[1].trigger('click')
		await flushPromises()
		expect(store.resetAutoConfig).toHaveBeenCalledTimes(1)
		expect(feedback.reportActionResult).toHaveBeenLastCalledWith(reset, feedback.RESET_AUTO_CONFIG_TEXTS)
		expect(store.loadVersionInfo).toHaveBeenCalledTimes(2)
	})

	it('shows the auto-config toast when consolidated auto-configuration throws', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		const wrapper = await mountPage({
			versionInfo: { autoConfigCompleted: false },
			consolidatedAutoConfigure: vi.fn(async () => { throw new Error('no register') }),
		})
		await wrapper.find('[data-testid="actions"]').findAll('button')[0].trigger('click')
		await flushPromises()
		expect(toasts.showError).toHaveBeenCalledWith('Failed to perform auto-configuration: no register')
		expect(feedback.reportAutoConfigResult).not.toHaveBeenCalled()
	})
})
