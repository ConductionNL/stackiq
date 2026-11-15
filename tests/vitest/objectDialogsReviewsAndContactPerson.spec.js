// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The lock and delete object dialogs (what they send, closing after a
 * success), the reviews panel (loading the aggregate for a subject) and the
 * add contact person dialog (validation and the object it saves), each run
 * against a plain component context with the stores doubled.
 *
 * @spec openspec/specs/fe-shell-navigation/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const toasts = vi.hoisted(() => [])
vi.mock('@nextcloud/dialogs', () => ({
	showError: (m) => toasts.push(['error', m]),
	showSuccess: (m) => toasts.push(['success', m]),
}))
vi.mock('@conduction/nextcloud-vue', () => ({ CnIcon: {}, CnWidgetWrapper: {} }))
vi.mock('../../src/modals/SubmitReviewModal.vue', () => ({ default: {} }))
const api = vi.hoisted(() => ({ apiRequest: vi.fn() }))
vi.mock('../../src/utils/adminApi.js', () => api)
const store = vi.hoisted(() => ({
	navigationStore: { setModal: () => {}, setDialog: () => {}, setTransferData: () => {} },
	objectStore: { objectItem: { id: 'o1' }, lockObject: null, deleteObject: null, getSchemaConfig: null, saveObject: null },
}))
vi.mock('../../src/store/store.js', () => store)

const { default: LockObject } = await import('../../src/modals/object/LockObject.vue')
const { default: DeleteObject } = await import('../../src/modals/object/DeleteObject.vue')
const { default: ReviewsPanel } = await import('../../src/components/reviews/ReviewsPanel.vue')
const { default: AddContactpersoonModal } = await import('../../src/modals/AddContactpersoonModal.vue')

const t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))

/**
 * A component context: props and extras, methods bound, data, and computed
 * values as getters.
 *
 * @param {object} comp The component definition.
 * @param {object} [props] Its props.
 * @return {object} The context.
 */
function context(comp, props = {}) {
	const ctx = { $emit: vi.fn(), t, ...props }
	for (const [name, fn] of Object.entries(comp.methods || {})) {
		ctx[name] = fn.bind(ctx)
	}
	Object.assign(ctx, comp.data ? comp.data.call(ctx) : {})
	for (const [name, def] of Object.entries(comp.computed || {})) {
		Object.defineProperty(ctx, name, { get: () => def.call(ctx), configurable: true })
	}
	return ctx
}

const { navigationStore, objectStore } = store

beforeEach(() => {
	toasts.length = 0
	api.apiRequest.mockReset()
	navigationStore.setModal = vi.fn()
	navigationStore.setDialog = vi.fn()
	navigationStore.setTransferData = vi.fn()
	Object.assign(objectStore, { objectItem: { id: 'o1' }, lockObject: vi.fn(), deleteObject: vi.fn(), getSchemaConfig: vi.fn(() => ({ register: 'r', schema: 'contactPerson' })), saveObject: vi.fn(async (obj) => ({ data: { id: 'p1', ...obj } })) })
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	vi.restoreAllMocks()
	vi.useRealTimers()
})

describe('LockObject', () => {
	it('locks the object for the process and duration, then closes itself', async () => {
		vi.useFakeTimers()
		const d = context(LockObject)
		Object.assign(d, { process: 'review', duration: 600 })
		await d.lockObject()
		expect([objectStore.lockObject.mock.calls[0], d.success, d.loading]).toEqual([[{ id: 'o1' }, 'review', 600], true, false])
		vi.advanceTimersByTime(2000)
		expect([navigationStore.setModal.mock.calls[0], d.success, d.process, d.duration]).toEqual([[false], null, '', 3600])
	})

	it('sends no process or duration it was not given, and shows a failure', async () => {
		const d = context(LockObject)
		Object.assign(d, { process: '', duration: 0 })
		objectStore.lockObject.mockRejectedValueOnce(new Error('Already locked'))
		await d.lockObject()
		expect([objectStore.lockObject.mock.calls[0], d.error, d.success]).toEqual([[{ id: 'o1' }, undefined, undefined], 'Already locked', null])
		objectStore.lockObject.mockRejectedValueOnce({})
		await d.lockObject()
		expect([d.error, d.loading]).toEqual(['Failed to lock object', false])
	})
})

describe('DeleteObject', () => {
	it('deletes the object and closes after two seconds', async () => {
		vi.useFakeTimers()
		const d = context(DeleteObject)
		await d.deleteObject()
		expect([objectStore.deleteObject.mock.calls[0], d.success, d.error]).toEqual([[{ id: 'o1' }], true, false])
		vi.advanceTimersByTime(1999)
		expect(navigationStore.setDialog).not.toHaveBeenCalled()
		vi.advanceTimersByTime(1)
		expect([navigationStore.setDialog.mock.calls[0], d.success]).toEqual([[false], null])
	})

	it('shows why the delete failed', async () => {
		const d = context(DeleteObject)
		objectStore.deleteObject.mockRejectedValueOnce(new Error('Locked by Ann'))
		await d.deleteObject()
		expect([d.success, d.error]).toEqual([false, 'Locked by Ann'])
		objectStore.deleteObject.mockRejectedValueOnce({})
		await d.deleteObject()
		expect([d.error, d.loading]).toEqual(['An error occurred while deleting the publication', false])
	})
})

describe('ReviewsPanel', () => {
	it('loads the review aggregate for the subject', async () => {
		api.apiRequest.mockResolvedValueOnce({ average: 4.5, count: 2, items: [{ id: 'r1' }] })
		const p = context(ReviewsPanel, { objectId: 42, subjectType: 'service' })
		await ReviewsPanel.created.call(p)
		expect([api.apiRequest.mock.calls[0], p.average, p.count, p.items, p.loading]).toEqual([['reviews/aggregate?subjectType=service&subjectId=42'], 4.5, 2, [{ id: 'r1' }], false])
		api.apiRequest.mockResolvedValueOnce({ average: 'x' })
		await p.loadAggregate()
		expect([p.average, p.count, p.items]).toEqual([null, 0, []])
		p.openSubmitModal()
		expect(p.showSubmitModal).toBe(true)
	})

	it('loads nothing without a subject and says when it fails', async () => {
		const none = context(ReviewsPanel, { objectId: '', subjectType: 'module' })
		await none.loadAggregate()
		expect([api.apiRequest.mock.calls.length, none.loading]).toEqual([0, false])
		const p = context(ReviewsPanel, { objectId: 'm1', subjectType: 'module' })
		api.apiRequest.mockRejectedValueOnce(new Error('offline'))
		await p.loadAggregate()
		expect([p.error, p.loading]).toEqual(['Could not load reviews: offline', false])
	})
})

describe('AddContactpersoonModal', () => {
	const modal = (organisation = { id: 'org1' }) => context(AddContactpersoonModal, { show: true, organisation })

	it('needs a first name, a last name and a sound email', async () => {
		const m = modal()
		expect(m.isFormValid).toBeFalsy()
		m.formData = { voornaam: 'Ann', achternaam: 'Smit', 'e-mailadres': 'ann@' }
		expect(m.isFormValid).toBe(false)
		await m.saveContactpersoon()
		expect([toasts, objectStore.saveObject.mock.calls.length]).toEqual([[['error', 'Please fill in all required fields with valid data']], 0])
		m.formData['e-mailadres'] = 'ann@smit.nl'
		expect(m.isFormValid).toBe(true)
		m.formData.achternaam = '  '
		expect(m.isFormValid).toBeFalsy()
	})

	it('saves the contact person under the organisation and closes', async () => {
		vi.useFakeTimers({ now: new Date('2026-05-15T12:00:00Z'), toFake: ['Date'] })
		const m = modal({ uuid: 'org-u' })
		m.formData = { voornaam: 'Ann', achternaam: 'Smit', 'e-mailadres': 'ann@smit.nl' }
		await m.saveContactpersoon()
		const [object, target] = objectStore.saveObject.mock.calls[0]
		expect([object.name, object.organization, object['@self'], target]).toEqual(['Ann Smit', 'org-u', { created: '2026-05-15T12:00:00.000Z', updated: '2026-05-15T12:00:00.000Z', organisation: 'org-u' }, { register: 'r', schema: 'contactPerson' }])
		expect([objectStore.getSchemaConfig.mock.calls[0], toasts[0], m.$emit.mock.calls.map((c) => c[0]), navigationStore.setTransferData.mock.calls[0], m.formData.voornaam]).toEqual([['contactPerson'], ['success', 'Contactpersoon added successfully'], ['contactpersoon-added', 'close'], [{ action: 'contactpersoonAdded' }], ''])
		expect(m.$emit.mock.calls[0][1].id).toBe('p1')
	})

	it('keeps the form and says why a save failed', async () => {
		const m = modal()
		m.formData = { voornaam: 'Ann', achternaam: 'Smit', 'e-mailadres': 'ann@smit.nl' }
		objectStore.saveObject.mockRejectedValueOnce(new Error('Duplicate'))
		await m.saveContactpersoon()
		expect([toasts, m.formData.voornaam, m.loading, m.$emit.mock.calls.length]).toEqual([[['error', 'Failed to add contact person: Duplicate']], 'Ann', false, 0])
		expect(m.generateUuid()).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/)
	})
})
