/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * SuiteWizardDialog beyond type registration: the seed data and step list,
 * per-step validation, submitting the suite payload (result or in-place error
 * on the wizard) and closing.
 *
 * @spec openspec/specs/suite-wizard/spec.md#requirement-submitting-the-wizard-shall-create-a-suite-object-with-the-attached-applications
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const store = vi.hoisted(() => {
	globalThis.t = (app, text) => text
	return { objectStore: {} }
})

vi.mock('../../src/store/store.js', () => store)
vi.mock('@conduction/nextcloud-vue', () => ({ CnWizardDialog: {} }))
vi.mock('../../src/dialogs/SuiteWizard/Step1Details.vue', () => ({ default: {} }))
vi.mock('../../src/dialogs/SuiteWizard/Step2Applications.vue', () => ({ default: {} }))
vi.mock('../../src/dialogs/SuiteWizard/Step3Confirm.vue', () => ({ default: {} }))

const { default: SuiteWizardDialog } =
	await import('../../src/dialogs/SuiteWizardDialog.vue')

/**
 * A component context with the methods bound and a wizard ref.
 *
 * @param {object|null} wizard The `$refs.wizard` value.
 * @return {object} The context.
 */
function context(wizard = { setResult: vi.fn(), setError: vi.fn() }) {
	const ctx = { $emit: vi.fn(), $refs: { wizard } }
	for (const [name, fn] of Object.entries(SuiteWizardDialog.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	return ctx
}

beforeEach(() => {
	for (const key of Object.keys(store.objectStore)) delete store.objectStore[key]
	store.objectStore.settings = {}
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

describe('SuiteWizardDialog', () => {
	it('seeds empty step data and three steps in order', () => {
		expect(SuiteWizardDialog.computed.defaults.call({})).toEqual({
			name: '',
			shortDescription: '',
			longDescription: '',
			website: '',
			applications: [],
			_step1Valid: false,
		})
		expect(SuiteWizardDialog.computed.wizardSteps.call({})).toEqual([
			{ id: 'details', label: 'Details' },
			{ id: 'applications', label: 'Applications' },
			{ id: 'confirm', label: 'Confirm' },
		])
	})

	it('blocks the details step until name and short description are set', () => {
		const ctx = context()
		expect(ctx.validateStep('details', { name: 'Suite', shortDescription: ' ' }))
			.toBe('Enter a name and a short description.')
		expect(ctx.validateStep('details', { name: 'Suite', shortDescription: 'Short' }))
			.toBe(true)
	})

	it('blocks the applications step until one is attached', () => {
		const ctx = context()
		expect(ctx.validateStep('applications', { applications: [] }))
			.toBe('Attach at least one existing application before continuing.')
		expect(ctx.validateStep('applications', { applications: [{ id: 'm1' }] }))
			.toBe(true)
		expect(ctx.validateStep('confirm', {})).toBe(true)
	})

	it('saves the built payload, reports success and emits the new id', async () => {
		store.objectStore.saveObject = vi.fn().mockResolvedValue({ id: 'new-1' })
		const ctx = context()

		await ctx.onSubmit({
			name: ' Suite ',
			shortDescription: 'Short',
			applications: [{ id: 'm1' }, 'm2', null],
		})

		expect(store.objectStore.saveObject).toHaveBeenCalledWith('suite', {
			name: 'Suite',
			shortDescription: 'Short',
			longDescription: '',
			website: '',
			applications: ['m1', 'm2'],
		})
		expect(ctx.$refs.wizard.setResult).toHaveBeenCalledWith({ success: true })
		expect(ctx.$refs.wizard.setError).not.toHaveBeenCalled()
		expect(ctx.$emit).toHaveBeenCalledWith('created', 'new-1')
	})

	it('emits created without an id when the save returns nothing and there is no wizard', async () => {
		store.objectStore.saveObject = vi.fn().mockResolvedValue(undefined)
		const ctx = context(null)

		await ctx.onSubmit({})

		expect(ctx.$emit).toHaveBeenCalledWith('created', undefined)
	})

	it('surfaces the save error on the wizard and emits nothing', async () => {
		store.objectStore.saveObject = vi.fn().mockRejectedValue(new Error('Conflict'))
		const ctx = context()

		await ctx.onSubmit({ name: 'S' })

		expect(ctx.$refs.wizard.setError).toHaveBeenCalledWith('Conflict')
		expect(ctx.$refs.wizard.setResult).not.toHaveBeenCalled()
		expect(ctx.$emit).not.toHaveBeenCalled()
	})

	it('falls back to a generic message for an error without one', async () => {
		store.objectStore.saveObject = vi.fn().mockRejectedValue(null)
		const ctx = context()

		await ctx.onSubmit({})

		expect(ctx.$refs.wizard.setError).toHaveBeenCalledWith('Failed to create the suite.')
	})

	it('does not throw on a failure when the wizard ref is gone', async () => {
		store.objectStore.saveObject = vi.fn().mockRejectedValue(new Error('x'))
		const ctx = context(null)

		await expect(ctx.onSubmit({})).resolves.toBeUndefined()
		expect(ctx.$emit).not.toHaveBeenCalled()
	})

	it('closes by emitting update:show false', () => {
		const ctx = context()
		ctx.onClose()
		expect(ctx.$emit).toHaveBeenCalledWith('update:show', false)
	})
})
