// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The Access review page: a custom page under Organisations, and the view
 * that lists one organisation's people, keeps or withdraws their access and,
 * for a Nextcloud admin, disables an account.
 *
 * @spec openspec/changes/organisations-role-mapping-and-access-review/specs/role-mapping-and-access-review/spec.md#requirement-req-rma-003-a-functional-administrator-shall-review-the-access-of-their-organisations-people
 */

import { flushPromises, mount } from '@vue/test-utils'
import * as fs from 'fs'
import * as path from 'path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import { buildManifest } from '../../node_modules/@conduction/nextcloud-vue/src/utils/buildManifest.js'
import base from '../../src/manifest.json'
import menuLayout from '../../src/menu-layout.json'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const toasts = vi.hoisted(() => [])
const currentUser = vi.hoisted(() => ({ value: { uid: 'rev', isAdmin: false } }))
const store = vi.hoisted(() => ({
	objectStore: { collections: {}, getCollection(type) { return this.collections[type] } },
}))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => currentUser.value }))
vi.mock('@nextcloud/dialogs', () => ({
	showError: (m) => toasts.push(['error', m]),
	showSuccess: (m) => toasts.push(['success', m]),
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p, params = {}) => '/index.php' + p.replace(/\{(\w+)\}/g, (m, k) => params[k] ?? m) }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDataTable: {
		props: ['rows', 'columns'],
		render() {
			return h('table', this.rows.map((row) => h('tr', { 'data-row': row.id }, this.columns.map((col) => h('td', { 'data-col': col.key }, this.$slots['column-' + col.key]?.({ row }))))))
		},
	},
}))
vi.mock('../../src/composables/useLiveCollections.js', () => ({ useLiveCollections: vi.fn() }))
vi.mock('../../src/store/store.js', () => store)

const { default: AccessReviewView } = await import('../../src/views/access/AccessReviewView.vue')

const t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))

/**
 * The view's `this`: data, bound methods and computed values as getters.
 *
 * @return {object} The context.
 */
function view() {
	const ctx = { t }
	Object.assign(ctx, AccessReviewView.data.call(ctx))
	for (const [name, fn] of Object.entries(AccessReviewView.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	for (const [name, def] of Object.entries(AccessReviewView.computed)) {
		Object.defineProperty(ctx, name, { get: () => def.call(ctx), configurable: true })
	}
	return ctx
}

const ROWS = [
	{
		id: 'cp-1',
		username: 'ann',
		displayName: 'Ann',
		roles: [
			{ role: 'Gebruik-beheerder', group: 'gebruik-beheerder', sources: [{ type: 'contactPerson' }], removable: true },
			{ role: 'Gebruik-raadpleger', group: 'gebruik-raadpleger', sources: [{ type: 'group', group: 'Inkoop' }, { type: 'organisationType', organisationType: 'Municipality' }], removable: false },
		],
		lastLogin: '2025-09-01T10:00:00+00:00',
		enabled: true,
		accessReviewedAt: null,
		accessReviewedBy: null,
		due: true,
		self: false,
	},
	{ id: 'cp-2', username: 'rev', displayName: 'Reviewer', roles: [], lastLogin: null, enabled: true, accessReviewedAt: '2026-11-01T00:00:00+00:00', accessReviewedBy: 'admin', due: false, self: true },
]

beforeEach(() => {
	http.get.mockReset()
	http.post.mockReset()
	toasts.length = 0
	currentUser.value = { uid: 'rev', isAdmin: false }
	store.objectStore.collections = { organization: { results: [{ uuid: 'org-a', object: { name: 'Gemeente Voorbeeld' } }] } }
})

afterEach(() => {
	vi.restoreAllMocks()
})

describe('Access review page in the manifest', () => {
	const dir = path.resolve(__dirname, '../../src/manifest.d')
	const merged = buildManifest(
		base,
		fs.readdirSync(dir).filter((f) => f.endsWith('.json')).sort().map((f) => JSON.parse(fs.readFileSync(path.join(dir, f), 'utf8'))),
		menuLayout,
	)

	it('is a custom page on /organisaties/access-review rendered by AccessReviewView', () => {
		expect(merged.pages.find((p) => p.id === 'AccessReview')).toMatchObject({
			route: '/organisaties/access-review',
			type: 'custom',
			component: 'AccessReviewView',
		})
	})

	it('is a child of Organisations gated on access.review, with a registered icon and component', () => {
		const organisations = merged.menu.find((m) => m.id === 'Organisaties')
		const entry = (organisations.children || []).find((c) => c.id === 'AccessReview')
		expect(entry).toMatchObject({ route: 'AccessReview', permission: 'access.review' })
		expect(merged.menu.map((m) => m.id)).not.toContain('AccessReview')
		const read = (file) => fs.readFileSync(path.resolve(__dirname, '../../src', file), 'utf8')
		expect(read('icons.js')).toMatch(new RegExp('^\\t' + entry.icon + ',$', 'm'))
		expect(read('customComponents.js')).toMatch(/^\tAccessReviewView,$/m)
	})
})

describe('AccessReviewView', () => {
	it('selects the only organisation and loads its review', async () => {
		http.get.mockResolvedValue({ data: { organisation: 'org-a', reviewIntervalDays: 365, results: ROWS } })
		const v = view()

		AccessReviewView.watch.organisationOptions.handler.call(v, v.organisationOptions)
		await vi.waitFor(() => expect(v.review).not.toBeNull())

		expect(v.selectedOrg).toEqual({ uuid: 'org-a', label: 'Gemeente Voorbeeld' })
		expect(http.get).toHaveBeenCalledWith('/index.php/apps/stackiq/api/access-review/org-a')
		expect(v.dueCount).toBe(1)
		expect(v.summary).toBe('1 of 2 people are due for review. A review is due when access was not reviewed in the last 365 days.')
		expect(v.columns.map((c) => c.key)).toEqual(['person', 'roles', 'lastLogin', 'enabled', 'reviewed', 'due', 'actions'])
	})

	it('loads the organisations when the page is opened straight from a link', async () => {
		const asked = []
		const before = store.objectStore
		store.objectStore = {
			...before,
			settings: {},
			objectTypeRegistry: { organization: {} },
			fetchCollection: async (type, params) => asked.push([type, params]),
		}
		try {
			await AccessReviewView.mounted.call(view())
		} finally {
			store.objectStore = before
		}

		expect(asked).toEqual([['organization', { _limit: 1000 }]])
	})

	it('shows the refusal of another organisation', async () => {
		http.get.mockRejectedValue({ response: { status: 403, data: { message: 'You may not review the access of this organisation.' } } })
		const v = view()
		v.selectedOrg = { uuid: 'org-b' }

		await v.loadReview()

		expect([v.review, v.error, v.loading]).toEqual([null, 'You may not review the access of this organisation.', false])
	})

	it('keeps access: the row shows the review and is no longer due', async () => {
		const row = { ...ROWS[0] }
		http.post.mockResolvedValue({ data: { id: 'cp-1', accessReviewedAt: '2026-11-14T12:00:00+00:00', accessReviewedBy: 'rev', due: false } })
		const v = view()

		await v.confirm(row)

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/stackiq/api/access-review/cp-1/confirm')
		expect([row.accessReviewedAt, row.accessReviewedBy, row.due]).toEqual(['2026-11-14T12:00:00+00:00', 'rev', false])
		expect(toasts).toEqual([['success', 'Access of Ann confirmed']])
		expect(v.busy).toBeNull()
	})

	it('removes a role and reloads, and shows a refusal naming the group', async () => {
		const v = view()
		v.selectedOrg = { uuid: 'org-a' }
		http.get.mockResolvedValue({ data: { results: [] } })
		http.post.mockResolvedValueOnce({ data: { role: 'Gebruik-beheerder' } })

		await v.withdraw(ROWS[0], 'Gebruik-beheerder')

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/stackiq/api/access-review/cp-1/withdraw', { role: 'Gebruik-beheerder' })
		expect(http.get).toHaveBeenCalledTimes(1)
		expect(toasts).toEqual([['success', 'Gebruik-beheerder removed from Ann']])

		http.post.mockRejectedValueOnce({ response: { status: 409, data: { message: 'This role comes from the group Inkoop. Change that group instead.' } } })
		await v.withdraw(ROWS[0], 'Gebruik-raadpleger')
		expect(toasts[1]).toEqual(['error', 'This role comes from the group Inkoop. Change that group instead.'])
	})

	it('disables an account for an admin', async () => {
		currentUser.value = { uid: 'admin', isAdmin: true }
		const row = { ...ROWS[0] }
		http.post.mockResolvedValue({ data: {} })
		const v = view()

		expect(v.isAdmin).toBe(true)
		await v.disable(row)

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/stackiq/api/contactpersonen/cp-1/disable')
		expect(row.enabled).toBe(false)
	})

	it('says where each role comes from', () => {
		const v = view()
		expect(v.sourceText(ROWS[0].roles[0])).toBe('on the contact person')
		expect(v.sourceText(ROWS[0].roles[1])).toBe('from the group Inkoop, from the organisation type Municipality')
		expect(v.isAdmin).toBe(false)
	})

	it('renders the rows: a group role offers no Remove role, and the own row cannot be kept', async () => {
		http.get.mockResolvedValue({ data: { organisation: 'org-a', reviewIntervalDays: 365, results: ROWS.map((r) => ({ ...r })) } })
		const wrapper = mount(AccessReviewView, {
			global: {
				mocks: { t },
				stubs: {
					NcSelect: true,
					NcLoadingIcon: true,
					NcEmptyContent: true,
					NcNoteCard: { template: '<div class="note"><slot /></div>' },
					NcButton: { props: ['disabled'], template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' },
				},
			},
		})
		await flushPromises()

		const ann = wrapper.find('[data-row="cp-1"]')
		expect(ann.find('[data-testid="access-review-role-Gebruik-beheerder"]').text()).toContain('Remove role')
		const groupRole = ann.find('[data-testid="access-review-role-Gebruik-raadpleger"]')
		expect(groupRole.text()).toContain('from the group Inkoop')
		expect(groupRole.find('button').exists()).toBe(false)
		expect(ann.find('[data-col="due"]').text()).toBe('Due')
		expect(ann.find('[data-col="lastLogin"]').text()).not.toBe('Never')

		const own = wrapper.find('[data-row="cp-2"]')
		expect(own.find('[data-testid="access-review-keep-cp-2"]').attributes('disabled')).toBeDefined()
		expect(own.find('[data-col="reviewed"]').text()).toContain('by admin')
		expect(wrapper.find('[data-testid="access-review-disable-cp-1"]').exists()).toBe(false)
		wrapper.unmount()
	})
})
