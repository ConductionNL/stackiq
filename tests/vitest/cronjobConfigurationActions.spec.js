// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation test for the cronjob section's save and run actions: the
 * request sent, the job state kept, the toasts shown and the busy markers,
 * for an accepted, a refused and a failed request.
 *
 * @spec openspec/specs/fe-settings-ui/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const mocks = vi.hoisted(() => ({
	post: vi.fn(),
	showError: vi.fn(),
	showSuccess: vi.fn(),
}))

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: mocks.post } }))
vi.mock('@nextcloud/dialogs', () => ({
	showError: mocks.showError,
	showSuccess: mocks.showSuccess,
	showWarning: vi.fn(),
	showInfo: vi.fn(),
}))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDataTable: {}, CnStatusBadge: {} }))
vi.mock('../../src/store/store.js', () => ({ settingsStore: {} }))

const { default: CronjobConfiguration } =
	await import('../../src/views/settings/sections/CronjobConfiguration.vue')

/**
 * Runs one action of the section on a job and records what it did.
 *
 * @param {string} action The method name.
 * @param {object} job The job.
 * @return {Promise<object>} The requests, toasts, job and busy markers.
 */
async function run(action, job) {
	const vm = { savingJob: 'none', runningJob: 'none' }
	for (const [name, fn] of Object.entries(CronjobConfiguration.methods)) {
		vm[name] = fn.bind(vm)
	}
	const busy = []
	mocks.post.mockImplementation(async (...args) => {
		busy.push([vm.savingJob, vm.runningJob])
		return mocks.answer(...args)
	})
	await vm[action](job)
	return {
		requests: mocks.post.mock.calls,
		success: mocks.showSuccess.mock.calls,
		error: mocks.showError.mock.calls,
		job,
		busy,
		after: [vm.savingJob, vm.runningJob],
	}
}

beforeEach(() => {
	mocks.post.mockReset()
	mocks.showError.mockReset()
	mocks.showSuccess.mockReset()
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

describe('CronjobConfiguration saveJobConfig', () => {
	it('saves the organisation and enabled flag', async () => {
		mocks.answer = async () => ({ data: { success: true } })
		expect(
			await run('saveJobConfig', {
				id: 'sync',
				enabled: true,
				selectedOrganisation: { value: 'org-1' },
				organisationUuid: null,
			}),
		).toMatchSnapshot()
	})

	it('saves without an organisation', async () => {
		mocks.answer = async () => ({ data: { success: true } })
		expect(
			await run('saveJobConfig', {
				id: 'sync',
				enabled: false,
				organisationUuid: 'old',
			}),
		).toMatchSnapshot()
	})

	it('reports a refused save with and without a message', async () => {
		mocks.answer = async () => ({ data: { success: false, message: 'Nope' } })
		expect(
			await run('saveJobConfig', {
				id: 'a',
				enabled: true,
				organisationUuid: 'keep',
			}),
		).toMatchSnapshot()
		mocks.post.mockReset()
		mocks.showError.mockReset()
		mocks.answer = async () => ({ data: { success: false } })
		expect(
			await run('saveJobConfig', { id: 'b', enabled: true }),
		).toMatchSnapshot()
	})

	it('reports a failed save', async () => {
		mocks.answer = async () => {
			throw new Error('offline')
		}
		expect(
			await run('saveJobConfig', { id: 'c', enabled: true }),
		).toMatchSnapshot()
		mocks.post.mockReset()
		mocks.showError.mockReset()
		mocks.answer = async () => ({})
		expect(
			await run('saveJobConfig', { id: 'd', enabled: true }),
		).toMatchSnapshot()
	})
})

describe('CronjobConfiguration runJob', () => {
	it('refuses a job without an organisation', async () => {
		mocks.answer = async () => ({ data: { success: true } })
		expect(
			await run('runJob', { id: 'sync', organisationUuid: '' }),
		).toMatchSnapshot()
	})

	it('runs the job', async () => {
		mocks.answer = async () => ({ data: { success: true } })
		expect(
			await run('runJob', { id: 'sync', organisationUuid: 'org' }),
		).toMatchSnapshot()
	})

	it('reports a refused run with and without a message', async () => {
		mocks.answer = async () => ({ data: { success: false, message: 'Busy' } })
		expect(
			await run('runJob', { id: 'sync', organisationUuid: 'org' }),
		).toMatchSnapshot()
		mocks.post.mockReset()
		mocks.showError.mockReset()
		mocks.answer = async () => ({ data: {} })
		expect(
			await run('runJob', { id: 'sync', organisationUuid: 'org' }),
		).toMatchSnapshot()
	})

	it('reports a failed run with the server message or the error', async () => {
		mocks.answer = async () => {
			throw Object.assign(new Error('Request failed'), {
				response: { data: { message: 'Server says no' } },
			})
		}
		expect(
			await run('runJob', { id: 'sync', organisationUuid: 'org' }),
		).toMatchSnapshot()
		mocks.post.mockReset()
		mocks.showError.mockReset()
		mocks.answer = async () => {
			throw new Error('offline')
		}
		expect(
			await run('runJob', { id: 'sync', organisationUuid: 'org' }),
		).toMatchSnapshot()
	})
})
