/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The guided entry wizard (capability-requirements-from-the-matrix task 1):
 * the connection, application and service kinds' defaults, the step checks that keep a step open and
 * name the missing field, the saved record and the summary; the dialog's
 * steps, validation and save; the action that opens the record's page.
 *
 * @spec openspec/specs/guided-entry-wizard/spec.md#requirement-req-gew-001-applications-services-and-connections-are-added-through-a-step-by-step-wizard
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const store = vi.hoisted(() => {
	globalThis.t = (app, text, vars) => Object.entries(vars || {}).reduce((acc, [k, v]) => acc.replace(`{${k}}`, v), text)
	return { objectStore: {} }
})

vi.mock('../../src/store/store.js', () => store)
vi.mock('@conduction/nextcloud-vue', () => ({ CnWizardDialog: {} }))

const {
	buildWizardPayload,
	validateWizardStep,
	wizardDefaults,
	wizardKind,
	wizardRelations,
	wizardSummary,
} = await import('../../src/utils/entryWizard.js')
const { default: EntryWizardDialog } = await import('../../src/dialogs/EntryWizardDialog.vue')
const { default: EntryWizardAction } = await import('../../src/components/entryWizard/EntryWizardAction.vue')

const appA = { uuid: 'a-1', label: 'Zaaksysteem' }
const appB = { uuid: 'b-2', label: 'Burgerportaal' }

/**
 * A component context with its methods bound, as Vue would.
 *
 * @param {object} component The component options.
 * @param {object} extra Props and refs.
 * @return {object}
 */
function context(component, extra = {}) {
	const ctx = { $emit: vi.fn(), ...extra }
	for (const [name, fn] of Object.entries(component.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	ctx.config = wizardKind(ctx.kind)
	return ctx
}

beforeEach(() => {
	for (const key of Object.keys(store.objectStore)) delete store.objectStore[key]
	store.objectStore.settings = { voorzieningen: { register: '7' } }
})

describe('the connection kind', () => {
	it('starts empty and refuses an unknown kind', () => {
		expect(wizardDefaults('connection')).toEqual({ name: '', shortDescription: '', type: '', status: '', moduleA: null, moduleB: null })
		expect(() => wizardKind('spaceship')).toThrow('Unknown entry wizard kind: spaceship')
	})

	it('keeps the details step open and names the missing name', () => {
		expect(validateWizardStep('connection', 'details', { name: '  ' })).toBe('Name is required.')
		expect(validateWizardStep('connection', 'details', { name: 'Zaken naar portaal' })).toBe(true)
	})

	it('needs both applications on the related step', () => {
		expect(validateWizardStep('connection', 'related', { moduleA: appA, moduleB: null })).toBe('To application is required.')
		expect(validateWizardStep('connection', 'related', { moduleA: null, moduleB: appB })).toBe('From application is required.')
		expect(validateWizardStep('connection', 'related', { moduleA: appA, moduleB: appB })).toBe(true)
		expect(validateWizardStep('connection', 'confirm', {})).toBe(true)
	})

	it('saves the filled fields and both applications by id', () => {
		expect(buildWizardPayload('connection', {
			name: ' Zaken naar portaal ',
			shortDescription: '',
			type: 'api',
			status: 'in use',
			moduleA: appA,
			moduleB: appB,
		})).toEqual({ name: 'Zaken naar portaal', type: 'api', status: 'in use', moduleA: 'a-1', moduleB: 'b-2' })
	})

	it('summarises every field and both applications', () => {
		expect(wizardSummary('connection', { name: 'Zaken naar portaal', moduleA: appA, moduleB: appB })).toEqual([
			{ key: 'name', label: 'Name', value: 'Zaken naar portaal' },
			{ key: 'shortDescription', label: 'Short description', value: '' },
			{ key: 'type', label: 'Type', value: '' },
			{ key: 'status', label: 'Status', value: '' },
			{ key: 'moduleA', label: 'From application', value: 'Zaaksysteem' },
			{ key: 'moduleB', label: 'To application', value: 'Burgerportaal' },
		])
	})
})

const component = { uuid: 'rc-1', label: 'Zaakregistratiecomponent' }
const component2 = { uuid: 'rc-2', label: 'Documentregistratiecomponent' }
const supplier = { uuid: 'org-1', label: 'Leverancier BV' }

describe('the application kind', () => {
	it('starts with no reference components and opens the application page', () => {
		expect(wizardDefaults('module')).toEqual({ name: '', shortDescription: '', type: '', licentietype: '', referenceComponents: [] })
		expect(wizardKind('module').detailRoute).toBe('ModuleDetail')
	})

	it('needs a name, and lets the reference components be left empty', () => {
		expect(validateWizardStep('module', 'details', { name: ' ' })).toBe('Name is required.')
		expect(validateWizardStep('module', 'related', { referenceComponents: [] })).toBe(true)
	})

	it('saves every chosen reference component by id', () => {
		expect(buildWizardPayload('module', {
			name: ' Zaaksysteem ',
			shortDescription: '',
			type: 'Application',
			licentietype: '',
			referenceComponents: [component, component2],
		})).toEqual({ name: 'Zaaksysteem', type: 'Application', referenceComponents: ['rc-1', 'rc-2'] })
		expect(buildWizardPayload('module', { name: 'x', referenceComponents: [] })).toEqual({ name: 'x' })
	})

	it('summarises the reference components as one list', () => {
		const rows = wizardSummary('module', { name: 'Zaaksysteem', referenceComponents: [component, component2] })
		expect(rows.find((row) => row.key === 'referenceComponents')).toEqual({
			key: 'referenceComponents',
			label: 'Reference components',
			value: 'Zaakregistratiecomponent, Documentregistratiecomponent',
		})
	})
})

describe('the service kind', () => {
	it('asks its provider on the details step and the applications after it', () => {
		expect(wizardRelations('catalogService', 'details').map((relation) => relation.key)).toEqual(['provider'])
		expect(wizardRelations('catalogService', 'related').map((relation) => relation.key)).toEqual(['modules'])
		expect(wizardDefaults('catalogService')).toEqual({ name: '', shortDescription: '', type: [], provider: null, modules: [] })
	})

	it('keeps the details step open until name, type and provider are given', () => {
		expect(validateWizardStep('catalogService', 'details', { name: 'Beheer', type: [], provider: supplier })).toBe('Service type is required.')
		expect(validateWizardStep('catalogService', 'details', { name: 'Beheer', type: ['Training'], provider: null })).toBe('Provider is required.')
		expect(validateWizardStep('catalogService', 'details', { name: 'Beheer', type: ['Training'], provider: supplier })).toBe(true)
	})

	it('saves the service with its types, provider and the applications it runs on', () => {
		expect(buildWizardPayload('catalogService', {
			name: 'Beheer',
			shortDescription: '',
			type: ['Functional management', 'Training'],
			provider: supplier,
			modules: [appA, appB],
		})).toEqual({
			name: 'Beheer',
			type: ['Functional management', 'Training'],
			provider: 'org-1',
			modules: ['a-1', 'b-2'],
		})
	})

	it('summarises the types and applications as lists', () => {
		const rows = wizardSummary('catalogService', { name: 'Beheer', type: ['Training'], provider: supplier, modules: [appA, appB] })
		expect(rows.map((row) => row.value)).toEqual(['Beheer', '', 'Training', 'Leverancier BV', 'Zaaksysteem, Burgerportaal'])
	})
})

describe('EntryWizardDialog', () => {
	it('asks the details, then the applications, then a summary', () => {
		const steps = EntryWizardDialog.computed.wizardSteps.call({ config: wizardKind('connection') })
		expect(steps).toEqual([
			{ id: 'details', label: 'Details' },
			{ id: 'related', label: 'Applications' },
			{ id: 'confirm', label: 'Confirm' },
		])
	})

	it('saves the connection and reports its id', async () => {
		const wizard = { setResult: vi.fn(), setError: vi.fn() }
		store.objectStore.registerObjectType = vi.fn()
		store.objectStore.objectTypeRegistry = {}
		store.objectStore.saveObject = vi.fn().mockResolvedValue({ id: 'c-9' })
		const ctx = context(EntryWizardDialog, { kind: 'connection', $refs: { wizard } })

		await ctx.onSubmit({ name: 'Zaken naar portaal', moduleA: appA, moduleB: appB })

		expect(store.objectStore.registerObjectType).toHaveBeenCalledWith('connection', 'connection', '7', { registerSlug: 'stackiq', schemaSlug: 'connection' })
		expect(store.objectStore.saveObject).toHaveBeenCalledWith('connection', { name: 'Zaken naar portaal', moduleA: 'a-1', moduleB: 'b-2' })
		expect(wizard.setResult).toHaveBeenCalledWith({ success: true })
		expect(ctx.$emit).toHaveBeenCalledWith('created', 'c-9')
	})

	it('keeps the summary open with the error when the save fails', async () => {
		const wizard = { setResult: vi.fn(), setError: vi.fn() }
		store.objectStore.saveObject = vi.fn().mockRejectedValue(new Error('moduleB must be a module'))
		const ctx = context(EntryWizardDialog, { kind: 'connection', $refs: { wizard } })

		await ctx.onSubmit({ name: 'x', moduleA: appA, moduleB: appB })

		expect(wizard.setError).toHaveBeenCalledWith('moduleB must be a module')
		expect(wizard.setResult).not.toHaveBeenCalled()
		expect(ctx.$emit).not.toHaveBeenCalledWith('created', expect.anything())
	})

	it('loads the applications to link once, and says so when it cannot', async () => {
		store.objectStore.fetchCollection = vi.fn().mockResolvedValue(undefined)
		const ctx = context(EntryWizardDialog, { kind: 'connection' })
		await ctx.loadRelated()
		expect(store.objectStore.fetchCollection).toHaveBeenCalledTimes(1)
		expect(store.objectStore.fetchCollection).toHaveBeenCalledWith('module', { _limit: 1000 })
		expect(ctx.loadError).toBe('')

		store.objectStore.fetchCollection = vi.fn().mockRejectedValue(new Error('down'))
		await ctx.loadRelated()
		expect(ctx.loadError).toBe('The records to link could not be loaded.')
		expect(ctx.loading).toBe(false)
	})

	it('loads only reference components for an application, and providers and applications for a service', async () => {
		store.objectStore.fetchCollection = vi.fn().mockResolvedValue(undefined)
		await context(EntryWizardDialog, { kind: 'module' }).loadRelated()
		expect(store.objectStore.fetchCollection).toHaveBeenCalledWith('element', { gemmaType: 'referentiecomponent', _limit: 1000 })

		store.objectStore.fetchCollection = vi.fn().mockResolvedValue(undefined)
		await context(EntryWizardDialog, { kind: 'catalogService' }).loadRelated()
		expect(store.objectStore.fetchCollection.mock.calls).toEqual([
			['organization', { _limit: 1000 }],
			['module', { _limit: 1000 }],
		])
	})

	it('saves a service with its applications', async () => {
		const wizard = { setResult: vi.fn(), setError: vi.fn() }
		store.objectStore.saveObject = vi.fn().mockResolvedValue({ uuid: 's-3' })
		const ctx = context(EntryWizardDialog, { kind: 'catalogService', $refs: { wizard } })

		await ctx.onSubmit({ name: 'Beheer', type: ['Training'], provider: supplier, modules: [appA] })

		expect(store.objectStore.saveObject).toHaveBeenCalledWith('catalogService', { name: 'Beheer', type: ['Training'], provider: 'org-1', modules: ['a-1'] })
		expect(ctx.$emit).toHaveBeenCalledWith('created', 's-3')
	})
})

describe('EntryWizardAction', () => {
	it('closes the wizard and opens the new record', () => {
		const push = vi.fn().mockReturnValue(Promise.resolve())
		const ctx = context(EntryWizardAction, { kind: 'connection', open: true, $router: { push } })

		ctx.onCreated('c-9')

		expect(ctx.open).toBe(false)
		expect(push).toHaveBeenCalledWith({ name: 'KoppelingDetail', params: { id: 'c-9' } })
	})

	it('closes the wizard and stays on the list for a kind without a page', () => {
		const push = vi.fn()
		const ctx = context(EntryWizardAction, { kind: 'catalogService', open: true, $router: { push } })
		ctx.onCreated('s-3')
		expect(ctx.open).toBe(false)
		expect(push).not.toHaveBeenCalled()
	})

	it('stays on the list without an id', () => {
		const push = vi.fn()
		const ctx = context(EntryWizardAction, { kind: 'connection', open: true, $router: { push } })
		ctx.onCreated(undefined)
		expect(push).not.toHaveBeenCalled()
	})
})
