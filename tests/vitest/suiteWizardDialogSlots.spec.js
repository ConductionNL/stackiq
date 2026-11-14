// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Mounts SuiteWizardDialog against a wizard double that renders the named
 * step slots: the wizard only appears when shown, gets its labels, steps,
 * seed data and validator, each step slot hands its step component the shared
 * step data and writes its updates back, and submit and close reach the
 * dialog. A mis-named slot renders nothing and throws nowhere, so only a
 * mount sees it.
 *
 * @spec openspec/specs/suite-wizard/spec.md
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

const store = vi.hoisted(() => {
	globalThis.t = (app, text) => text
	return { objectStore: {} }
})

const wizard = vi.hoisted(() => ({ stepData: {}, setStepData: null, api: null }))

vi.mock('../../src/store/store.js', () => store)
vi.mock('@conduction/nextcloud-vue', async () => {
	const { defineComponent: define, h: render } = await import('vue')
	return {
		CnWizardDialog: define({
			name: 'CnWizardDialog',
			props: ['dialogTitle', 'steps', 'defaults', 'validate', 'submitLabel', 'successText'],
			emits: ['submit', 'close'],
			setup(props, { slots, expose }) {
				expose(wizard.api)
				return () => render('div', { class: 'wizard-stub' }, props.steps.map((step) => {
					const slot = slots['step-' + step.id]
					return render('section', { class: 'step-' + step.id }, slot
						? slot({ stepData: wizard.stepData, setStepData: wizard.setStepData })
						: [])
				}))
			},
		}),
	}
})

/**
 * A step component double that shows its payload and can emit an update.
 *
 * @param {string} name The component name.
 * @return {object} The component.
 */
function stepDouble(name) {
	return defineComponent({
		name,
		props: ['payload'],
		emits: ['update:payload'],
		setup(props) {
			return () => h('span', { class: name }, JSON.stringify(props.payload))
		},
	})
}

vi.mock('../../src/dialogs/SuiteWizard/Step1Details.vue', () => ({ default: stepDouble('Step1Details') }))
vi.mock('../../src/dialogs/SuiteWizard/Step2Applications.vue', () => ({ default: stepDouble('Step2Applications') }))
vi.mock('../../src/dialogs/SuiteWizard/Step3Confirm.vue', () => ({ default: stepDouble('Step3Confirm') }))

const { default: SuiteWizardDialog } = await import('../../src/dialogs/SuiteWizardDialog.vue')

beforeEach(() => {
	wizard.stepData = { name: 'Suite', applications: [{ id: 'm1' }] }
	wizard.setStepData = vi.fn()
	wizard.api = { setResult: vi.fn(), setError: vi.fn() }
	for (const key of Object.keys(store.objectStore)) delete store.objectStore[key]
	store.objectStore.settings = {}
})

describe('SuiteWizardDialog mounted', () => {
	it('renders nothing while hidden', () => {
		const wrapper = mount(SuiteWizardDialog, { props: { show: false } })

		expect(wrapper.find('.wizard-stub').exists()).toBe(false)
	})

	it('passes the wizard its labels, steps, seed data and validator', () => {
		const wrapper = mount(SuiteWizardDialog, { props: { show: true } })

		const dialog = wrapper.findComponent({ name: 'CnWizardDialog' })
		expect(dialog.props('dialogTitle')).toBe('New suite')
		expect(dialog.props('submitLabel')).toBe('Create suite')
		expect(dialog.props('successText')).toBe('Suite created.')
		expect(dialog.props('steps').map((s) => s.id)).toEqual(['details', 'applications', 'confirm'])
		expect(dialog.props('defaults').applications).toEqual([])
		expect(dialog.props('validate')('applications', { applications: [] }))
			.toBe('Attach at least one existing application before continuing.')
	})

	it('hands every step the shared step data', () => {
		const wrapper = mount(SuiteWizardDialog, { props: { show: true } })
		const shown = JSON.stringify(wizard.stepData)

		expect(wrapper.get('.step-details .Step1Details').text()).toBe(shown)
		expect(wrapper.get('.step-applications .Step2Applications').text()).toBe(shown)
		expect(wrapper.get('.step-confirm .Step3Confirm').text()).toBe(shown)
	})

	it('writes the details and applications updates back to the wizard', async () => {
		const wrapper = mount(SuiteWizardDialog, { props: { show: true } })

		wrapper.findComponent({ name: 'Step1Details' }).vm.$emit('update:payload', { name: 'New' })
		wrapper.findComponent({ name: 'Step2Applications' }).vm.$emit('update:payload', { applications: [] })

		expect(wizard.setStepData.mock.calls).toEqual([[{ name: 'New' }], [{ applications: [] }]])
	})

	it('creates the suite on submit and hides on close', async () => {
		store.objectStore.saveObject = vi.fn().mockResolvedValue({ id: 'suite-1' })
		const wrapper = mount(SuiteWizardDialog, { props: { show: true } })
		const dialog = wrapper.findComponent({ name: 'CnWizardDialog' })

		dialog.vm.$emit('submit', { name: 'Suite', shortDescription: 'Kort', applications: [{ id: 'm1' }] })
		await vi.waitFor(() => expect(wrapper.emitted('created')).toEqual([['suite-1']]))
		expect(store.objectStore.saveObject).toHaveBeenCalledWith('suite', expect.objectContaining({ name: 'Suite' }))
		expect(wizard.api.setResult).toHaveBeenCalledWith({ success: true })

		dialog.vm.$emit('close')
		expect(wrapper.emitted('update:show')).toEqual([[false]])
	})
})
