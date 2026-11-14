// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The version information section as it renders: the version, status and
 * OpenRegister lines, which header actions an auto-configured or
 * unconfigured instance offers, the configuration steps of a successful
 * auto-configure, its errors, and the reset outcome.
 *
 * @spec openspec/specs/settings-admin-controller/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { reactive } from 'vue'

const toasts = vi.hoisted(() => [])
vi.mock('@nextcloud/dialogs', () => ({
	showError: (m) => toasts.push(['error', m]),
	showSuccess: (m) => toasts.push(['success', m]),
	showWarning: (m) => toasts.push(['warning', m]),
}))
const store = vi.hoisted(() => ({ settingsStore: null }))
vi.mock('../../src/store/store.js', () => store)

const { default: VersionInformation } = await import('../../src/views/settings/sections/VersionInformation.vue')

/**
 * Mount the section over this version info.
 *
 * @param {object} versionInfo The store's version info.
 * @param {object} [actions] Store actions to override.
 * @return {object} The wrapper.
 */
function mountSection(versionInfo, actions = {}) {
	store.settingsStore = reactive({
		loadingVersionInfo: false,
		versionInfo,
		consolidatedAutoConfigure: vi.fn(),
		resetAutoConfig: vi.fn(),
		forceUpdate: vi.fn(),
		loadVersionInfo: vi.fn().mockResolvedValue(undefined),
		...actions,
	})
	return mount(VersionInformation)
}

const lines = (wrapper) => wrapper.findAll('.version-item').map((i) => i.text().replace(/\s+/g, ' ').replace(/:\s*/, ': '))
const actionLabels = (wrapper) => wrapper.findAll('.header-buttons button, .section-header-actions button').map((b) => b.text()).filter((t) => ['Auto Configure', 'Force Update', 'Reset Auto-Config'].includes(t))
const action = (wrapper, label) => wrapper.findAll('button').find((b) => b.text() === label)

beforeEach(() => {
	toasts.length = 0
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

describe('VersionInformation rendering', () => {
	it('shows an up-to-date, auto-configured instance with OpenRegister active', () => {
		const wrapper = mountSection({
			appName: 'stackiq', appVersion: '2.1.0', configuredVersion: '2.1.0',
			versionsMatch: true, openRegisterEnabled: true, autoConfigCompleted: true,
		})

		expect(lines(wrapper)).toEqual([
			'Application: stackiq v2.1.0',
			'Configured Version: 2.1.0',
			'Status: ✓ Up to date',
			'Open Register: ✓ Installed and active',
		])
		expect(actionLabels(wrapper)).toEqual(['Force Update', 'Reset Auto-Config'])
	})

	it('shows an unconfigured instance needing an update with OpenRegister disabled', () => {
		const wrapper = mountSection({
			appName: 'stackiq', appVersion: '2.2.0', configuredVersion: '',
			versionsMatch: false, needsUpdate: true, openRegisterInstalled: true, autoConfigCompleted: false,
		})

		expect(lines(wrapper).slice(1)).toEqual([
			'Configured Version: Not configured',
			'Status: ⚠ Update needed',
			'Open Register: ⚠ Installed but not enabled',
		])
		expect(actionLabels(wrapper)).toEqual(['Auto Configure', 'Force Update'])
	})

	it('shows a version mismatch and a missing OpenRegister', () => {
		const wrapper = mountSection({ appName: 'stackiq', appVersion: '1.0.0', configuredVersion: '0.9.0', versionsMatch: false, needsUpdate: false })

		expect(lines(wrapper).slice(2)).toEqual(['Status: ✗ Version mismatch', 'Open Register: ✗ Not installed'])
		expect(actionLabels(wrapper)).toEqual(['Force Update'])
	})

	it('lists every configuration step of a successful auto-configure', async () => {
		const consolidatedAutoConfigure = vi.fn().mockResolvedValue({
			success: true,
			message: 'Configured',
			steps: {
				configurationLoad: { success: true, message: 'loaded' },
				voorzieningenConfiguration: { success: true, message: 'ok', configured: { register: 'voorz', organisatieSchema: 'org' } },
				amefConfiguration: { success: true, message: 'amef ok', configured: { registerId: 7 } },
				groupsConfiguration: { success: true, message: 'groups ok', created: ['a', 'b'], existing: ['c'] },
			},
		})
		const wrapper = mountSection({ autoConfigCompleted: false }, { consolidatedAutoConfigure })

		await action(wrapper, 'Auto Configure').trigger('click')
		await flushPromises()

		expect(consolidatedAutoConfigure).toHaveBeenCalledTimes(1)
		expect(wrapper.findAll('.config-steps li').map((li) => li.text().replace(/\s+/g, ' '))).toEqual([
			'✅ Configuration Loading: loaded',
			'🇳🇱 Voorzieningen: ok',
			'📋 Voorzieningen Register: voorz',
			'📊 Organisatie Schema: org',
			'🏗️ AMEF: amef ok',
			'📋 AMEF Register: 7',
			'👥 User Groups: groups ok',
			'➕ Created Groups: a, b',
			'✓ Existing Groups: 1 groups already exist',
		])
	})

	it('lists only the steps that succeeded', async () => {
		const consolidatedAutoConfigure = vi.fn().mockResolvedValue({
			success: true,
			message: 'Partly configured',
			steps: { configurationLoad: { success: false }, groupsConfiguration: { success: true, message: 'groups ok', created: [], existing: [] } },
		})
		const wrapper = mountSection({ autoConfigCompleted: false }, { consolidatedAutoConfigure })

		await action(wrapper, 'Auto Configure').trigger('click')
		await flushPromises()

		expect(wrapper.findAll('.config-steps li').map((li) => li.text())).toEqual(['👥 User Groups: groups ok'])
	})

	it('shows a failed auto-configure with its errors', async () => {
		const consolidatedAutoConfigure = vi.fn().mockResolvedValue({ success: false, message: 'Could not configure', errors: ['No register', 'No schema'] })
		const wrapper = mountSection({ autoConfigCompleted: false }, { consolidatedAutoConfigure })

		await action(wrapper, 'Auto Configure').trigger('click')
		await flushPromises()

		expect(wrapper.find('.config-result').text()).toContain('Could not configure')
		expect(wrapper.findAll('.config-errors li').map((li) => li.text())).toEqual(['No register', 'No schema'])
	})

	it('shows a thrown auto-configure as a failure without an error list', async () => {
		const consolidatedAutoConfigure = vi.fn().mockRejectedValue(new Error('timeout'))
		const wrapper = mountSection({ autoConfigCompleted: false }, { consolidatedAutoConfigure })

		await action(wrapper, 'Auto Configure').trigger('click')
		await flushPromises()

		expect(wrapper.find('.config-result').text()).toBe('Failed to perform auto-configuration: timeout')
		expect(wrapper.find('.config-errors').exists()).toBe(false)
		expect(toasts).toContainEqual(['error', 'Failed to perform auto-configuration: timeout'])
	})

	it('shows the reset outcome and reloads the version info', async () => {
		const resetAutoConfig = vi.fn().mockResolvedValueOnce({ success: true, message: 'Reset done' }).mockResolvedValueOnce({ success: false, message: 'Reset refused' })
		const wrapper = mountSection({ autoConfigCompleted: true }, { resetAutoConfig })

		await action(wrapper, 'Reset Auto-Config').trigger('click')
		await flushPromises()
		expect(wrapper.find('.reset-result').text()).toBe('Reset done')
		expect(store.settingsStore.loadVersionInfo).toHaveBeenCalledTimes(1)

		await action(wrapper, 'Reset Auto-Config').trigger('click')
		await flushPromises()
		expect(wrapper.find('.reset-result').text()).toBe('Reset refused')
	})

	it('shows the force update result in the configuration result', async () => {
		const forceUpdate = vi.fn().mockResolvedValue({ success: false, message: 'Update failed' })
		const wrapper = mountSection({ autoConfigCompleted: true }, { forceUpdate })

		await action(wrapper, 'Force Update').trigger('click')
		await flushPromises()

		expect(forceUpdate).toHaveBeenCalledTimes(1)
		expect(store.settingsStore.loadVersionInfo).toHaveBeenCalledTimes(1)
		expect(wrapper.find('.config-result').text()).toBe('Update failed')
	})
})
