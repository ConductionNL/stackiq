// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The change password dialog (its own SHA-1, the breached-password check by
 * hash prefix, the requirements and saving), the portfolio report, the GEMMA
 * reference component plate and the email settings section, each run against
 * a plain component context with their stores and APIs doubled.
 *
 * @spec openspec/specs/portfolio-rationalization-time/spec.md
 */

import { createHash } from 'node:crypto'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const toasts = vi.hoisted(() => [])
const http = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => ({
	showError: (m) => toasts.push(['error', m]),
	showSuccess: (m) => toasts.push(['success', m]),
}))
vi.mock('@conduction/nextcloud-vue', () => ({ CnChartWidget: {}, CnKpiGrid: {}, CnStatsBlock: {}, CnStatusBadge: {} }))
vi.mock('../../src/components/portfolio/ValueFitPlot.vue', () => ({ default: {} }))
vi.mock('../../src/components/AlwaysVisibleSection.vue', () => ({ default: {} }))
vi.mock('../../src/components/StandardTabs.vue', () => ({ default: {} }))
vi.mock('../../src/composables/useLiveCollections.js', () => ({ useLiveCollections: vi.fn() }))
const organisatie = vi.hoisted(() => ({ changePassword: vi.fn() }))
vi.mock('../../src/store/modules/organisatie.js', () => ({ useOrganisatieStore: () => organisatie }))
const store = vi.hoisted(() => ({
	objectStore: { collections: {}, getCollection(type) { return this.collections[type] }, settings: null, objectTypeRegistry: {}, fetchSettings: null, registerObjectType: null, fetchCollection: null },
	settingsStore: { loading: false, emailSettings: { host: 'smtp' }, saveEmailSettings: null, testEmailConnection: null, sendTestEmail: null },
}))
vi.mock('../../src/store/store.js', () => store)

const { default: ChangePasswordDialog } = await import('../../src/dialogs/ChangePasswordDialog.vue')
const { default: PortfolioReport } = await import('../../src/views/organisaties/PortfolioReport.vue')
const { default: GemmaPlate } = await import('../../src/views/organisaties/GemmaPlate.vue')
const { default: EmailConfiguration } = await import('../../src/views/settings/sections/EmailConfiguration.vue')

const t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))

/**
 * A component context: props and extras, methods bound, data, and computed
 * values (plain or with a setter) as properties.
 *
 * @param {object} comp The component definition.
 * @param {object} [props] Its props.
 * @param {object} [extra] Anything else on `this`.
 * @return {object} The context.
 */
function context(comp, props = {}, extra = {}) {
	const ctx = { $emit: vi.fn(), t, ...props, ...extra }
	for (const [name, fn] of Object.entries(comp.methods || {})) {
		ctx[name] = typeof fn === 'function' ? fn.bind(ctx) : fn
	}
	Object.assign(ctx, comp.data ? comp.data.call(ctx) : {})
	for (const [name, def] of Object.entries(comp.computed || {})) {
		const get = typeof def === 'function' ? def : def.get
		Object.defineProperty(ctx, name, { get: () => get.call(ctx), set: def.set ? (v) => def.set.call(ctx, v) : undefined, configurable: true })
	}
	return ctx
}

const sha1 = (text) => createHash('sha1').update(text, 'utf8').digest('hex').toUpperCase()

beforeEach(() => {
	toasts.length = 0
	http.get.mockReset()
	organisatie.changePassword.mockReset()
	vi.spyOn(console, 'error').mockImplementation(() => {})
	vi.spyOn(console, 'info').mockImplementation(() => {})
})

afterEach(() => {
	vi.restoreAllMocks()
	vi.unstubAllGlobals()
	vi.useRealTimers()
})

describe('ChangePasswordDialog', () => {
	const dialog = () => context(ChangePasswordDialog, { username: 'ann' })

	it('hashes like SHA-1, including multi-block and non-ASCII input', async () => {
		const d = dialog()
		for (const text of ['', 'abc', 'Correct-Horse-9!', 'ü€ 漢字', 'x'.repeat(130)]) {
			expect(await d.sha1(text)).toBe(sha1(text))
		}
	})

	it('names each requirement the password meets', () => {
		const d = dialog()
		d.newPassword = 'short'
		expect(d.passwordValidation).toEqual({ minLength: false, hasUppercase: false, hasLowercase: true, hasNumber: false, hasSpecialChar: false, notPwned: false })
		d.newPassword = 'Long-enough-1!'
		d.isPasswordPwned = false
		expect([Object.values(d.passwordValidation).every(Boolean), d.isPasswordValid]).toEqual([true, true])
		d.pwnedCheckLoading = true
		expect(d.isPasswordValid).toBe(false)
	})

	it('sends only the first five hash characters and spots a breached password', async () => {
		const password = 'Breached-Pass-1!'
		const hash = sha1(password)
		const fetchSpy = vi.fn(async () => ({ ok: true, text: async () => `0000000000000000000000000000000000A:3\n${hash.slice(5).toLowerCase()}:42` }))
		vi.stubGlobal('fetch', fetchSpy)
		const d = dialog()
		d.isPasswordPwned = false
		await d.checkPasswordPwned(password)
		expect(fetchSpy.mock.calls[0][0]).toBe(`https://api.pwnedpasswords.com/range/${hash.slice(0, 5)}`)
		expect([d.isPasswordPwned, d.pwnedCheckLoading]).toEqual([true, false])
		fetchSpy.mockResolvedValueOnce({ ok: true, text: async () => 'FFFFF:1\n' })
		await d.checkPasswordPwned(password)
		expect(d.isPasswordPwned).toBe(false)
	})

	it('treats a short password as unsafe and lets a failing check pass', async () => {
		const d = dialog()
		d.isPasswordPwned = false
		await d.checkPasswordPwned('short')
		expect(d.isPasswordPwned).toBe(true)
		vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, status: 503, statusText: 'Down' })))
		await d.checkPasswordPwned('Long-enough-1!')
		expect(d.isPasswordPwned).toBe(false)
		d.isPasswordPwned = true
		vi.stubGlobal('fetch', vi.fn(async () => { throw new Error('offline') }))
		await d.checkPasswordPwned('Long-enough-1!')
		expect([d.isPasswordPwned, d.pwnedCheckLoading]).toEqual([false, false])
	})

	it('checks a long password after a pause, and drops the pending check on close', async () => {
		vi.useFakeTimers()
		const d = dialog()
		const check = vi.fn()
		d.checkPasswordPwned = check
		ChangePasswordDialog.watch.newPassword.call(d, 'short')
		expect(d.pwnedCheckLoading).toBe(false)
		ChangePasswordDialog.watch.newPassword.call(d, 'Long-enough-1')
		ChangePasswordDialog.watch.newPassword.call(d, 'Long-enough-12')
		expect(d.pwnedCheckLoading).toBe(true)
		vi.advanceTimersByTime(500)
		expect(check.mock.calls).toEqual([['Long-enough-12']])
		ChangePasswordDialog.watch.newPassword.call(d, 'Long-enough-123')
		ChangePasswordDialog.beforeUnmount.call(d)
		vi.advanceTimersByTime(500)
		expect(check).toHaveBeenCalledTimes(1)
	})

	it('saves only a sound password and explains every refusal', async () => {
		const d = dialog()
		await d.savePassword()
		d.newPassword = 'long-enough-but-weak'
		d.isPasswordPwned = true
		await d.savePassword()
		d.isPasswordPwned = false
		await d.savePassword()
		expect(toasts.map((e) => e[1])).toEqual([
			'Password must be at least 10 characters long',
			'This password has been found in data breaches and is not secure. Please choose a different password.',
			'Password does not meet all requirements',
		])
		expect(organisatie.changePassword).not.toHaveBeenCalled()
		d.newPassword = 'Long-enough-1!'
		await d.savePassword()
		expect([organisatie.changePassword.mock.calls[0], toasts.at(-1), d.$emit.mock.calls[0], d.passwordLoading]).toEqual([['ann', 'Long-enough-1!'], ['success', 'Password changed successfully'], ['saved'], false])
		organisatie.changePassword.mockRejectedValueOnce(new Error('Too recent'))
		await d.savePassword()
		expect(toasts.at(-1)).toEqual(['error', 'Failed to change password: Too recent'])
	})
})

describe('PortfolioReport', () => {
	const report = () => context(PortfolioReport)
	const { objectStore } = store

	beforeEach(() => {
		Object.assign(objectStore, { collections: {}, settings: null, objectTypeRegistry: {}, fetchSettings: null, registerObjectType: null, fetchCollection: null })
	})

	it('lists the organisations under whichever id they carry', () => {
		objectStore.collections.organization = { results: [{ uuid: ' u1 ', object: { name: 'Gemeente A' } }, { '@self': { id: 'u2' }, title: 'B' }, { id: 'u3' }] }
		expect(report().organisationOptions).toEqual([{ uuid: 'u1', label: 'Gemeente A' }, { uuid: 'u2', label: 'B' }, { uuid: 'u3', label: 'u3' }])
		objectStore.collections.organization = undefined
		expect(report().organisationOptions).toEqual([])
	})

	it('registers the organisation type from the settings and loads them', async () => {
		objectStore.fetchSettings = vi.fn(async () => { objectStore.settings = { voorzieningen: { register: 'reg1' } } })
		objectStore.registerObjectType = vi.fn()
		objectStore.fetchCollection = vi.fn()
		await PortfolioReport.mounted.call(report())
		expect([objectStore.registerObjectType.mock.calls[0], objectStore.fetchCollection.mock.calls[0]]).toEqual([['organization', 'organization', 'reg1', { registerSlug: 'stackiq', schemaSlug: 'organization' }], ['organization', { _limit: 1000 }]])
		objectStore.objectTypeRegistry = { organization: {} }
		await report().loadOrganisations()
		expect([objectStore.fetchSettings.mock.calls.length, objectStore.registerObjectType.mock.calls.length]).toEqual([1, 1])
		objectStore.fetchCollection = vi.fn(async () => { throw new Error('x') })
		await report().loadOrganisations()
		expect(console.error).toHaveBeenCalled()
	})

	it('loads the report for the chosen organisation, and nothing without one', async () => {
		const r = report()
		r.report = { rows: [] }
		await r.loadReport()
		expect([r.report, http.get.mock.calls.length]).toEqual([null, 0])
		r.selectedOrg = { uuid: 'u1' }
		http.get.mockResolvedValueOnce({ data: { quadrants: { Invest: { count: 2, eolExposedCount: 1, cloudTransition: { SaaS: 2 }, annualisedCost: 1000, oneOffCost: 0 } }, rows: [{ quadrant: 'Invest', timeMismatch: true }, { quadrant: 'Migrate' }] } })
		await r.loadReport()
		expect(http.get).toHaveBeenCalledWith('/index.php/apps/stackiq/api/portfolio-report', { params: { organisation: 'u1' } })
		expect(r.quadrantSummary.map((q) => [q.key, q.count, q.cloudTransitionLabel])).toEqual([['Tolerate', 0, '—'], ['Invest', 2, 'SaaS: 2'], ['Migrate', 0, '—'], ['Eliminate', 0, '—'], ['Unclassified', 0, '—']])
		expect([r.chartSeries[0].data, r.chartCategories]).toEqual([[0, 2, 0, 0, 0], ['Tolerate', 'Invest', 'Migrate', 'Eliminate', 'Unclassified']])
		expect(Object.keys(r.chartColorMap)).toEqual(['Tolerate', 'Invest', 'Migrate', 'Eliminate', 'Unclassified'])
		expect(r.groupedRows.map((g) => g.rows.length)).toEqual([0, 1, 1, 0, 0])
		r.onlyMismatch = true
		expect(r.groupedRows.map((g) => g.rows.length)).toEqual([0, 1, 0, 0, 0])
	})

	it('says why the report could not be loaded', async () => {
		const r = report()
		r.selectedOrg = { uuid: 'u1' }
		http.get.mockRejectedValueOnce({ response: { data: { message: 'No access' } } })
		await r.loadReport()
		http.get.mockRejectedValueOnce({})
		const s = report()
		s.selectedOrg = { uuid: 'u1' }
		await s.loadReport()
		expect([r.error, s.error, s.report, s.loading, s.quadrantSummary, s.groupedRows]).toEqual(['No access', 'Failed to load the portfolio report.', null, false, [], []])
	})

	it('exports the CSV for the chosen organisation and labels scores', () => {
		const open = vi.fn()
		vi.stubGlobal('open', open)
		const r = report()
		r.exportCsv()
		r.selectedOrg = { uuid: 'u 1' }
		r.exportCsv()
		expect(open.mock.calls).toEqual([['/index.php/apps/stackiq/api/portfolio-report?organisation=u%201&format=csv', '_blank']])
		expect([r.scoresLabel({}), r.scoresLabel({ businessValue: 4, riskScore: 2 }), r.quadrantLabel('Invest'), r.quadrantLabel('Odd')]).toEqual(['—', '4 / - / 2', 'Invest', 'Odd'])
	})
})

describe('GemmaPlate', () => {
	const plate = () => context(GemmaPlate)
	const { objectStore } = store

	it('picks the only organisation by itself and loads its coverage', async () => {
		http.get.mockResolvedValueOnce({ data: { components: [{ group: 'Zaak', state: 'gap', fillable: true, applications: [] }, { state: 'covered', applications: [{ moduleName: 'Zaaksysteem' }, {}] }] } })
		const p = plate()
		GemmaPlate.watch.organisationOptions.handler.call(p, [{ uuid: 'u1', label: 'A' }])
		await new Promise((resolve) => setTimeout(resolve, 0))
		expect([http.get.mock.calls[0][1], p.selectedOrg]).toEqual([{ params: { organisation: 'u1' } }, { uuid: 'u1', label: 'A' }])
		expect(p.groups.map((g) => [g.name, g.components.length])).toEqual([['Zaak', 1], ['Other', 1]])
		p.filter = 'fillable'
		expect(p.groups.map((g) => g.name)).toEqual(['Zaak'])
		expect(p.csvUrl).toBe('/index.php/apps/stackiq/api/reference-component-coverage?organisation=u1&format=csv')
		GemmaPlate.watch.organisationOptions.handler.call(p, [{ uuid: 'u2' }])
		GemmaPlate.watch.organisationOptions.handler.call(plate(), [{ uuid: 'a' }, { uuid: 'b' }])
		expect(http.get).toHaveBeenCalledTimes(1)
		objectStore.collections.organization = { results: [{ uuid: 'u9', name: 'N' }] }
		expect(plate().organisationOptions).toEqual([{ uuid: 'u9', label: 'N' }])
	})

	it('clears the coverage without an organisation and explains a failure', async () => {
		const p = plate()
		Object.assign(p, { coverage: { components: [] }, selected: { id: 1 } })
		await p.loadCoverage()
		expect([p.coverage, p.selected, http.get.mock.calls.length, p.csvUrl]).toEqual([null, null, 0, '/index.php/apps/stackiq/api/reference-component-coverage?organisation=&format=csv'])
		p.selectedOrg = { uuid: 'u1' }
		const errors = []
		for (const failure of [{ response: { data: { message: 'M' } } }, { response: { data: { error: 'E' } } }, {}]) {
			http.get.mockRejectedValueOnce(failure)
			await p.loadCoverage()
			errors.push(p.error)
		}
		expect([errors, p.loading, p.coverage]).toEqual([['M', 'E', 'The coverage could not be loaded.'], false, null])
	})

	it('labels a component by its state and its applications', () => {
		const p = plate()
		expect([p.stateLabel({ state: 'overlap' }), p.stateLabel({ state: 'covered' }), p.stateLabel({ state: 'gap', fillable: true }), p.stateLabel({ state: 'gap' })]).toEqual(['Overlap', 'Covered', 'Gap, fillable', 'Gap'])
		expect([p.stateVariant('gap'), p.stateVariant('covered'), p.stateVariant('overlap'), p.stateVariant('odd')]).toEqual(['error', 'success', 'warning', 'default'])
		expect([p.applicationNames({ applications: [{ moduleName: 'A' }, { moduleName: 'B' }] }), p.applicationNames({ applications: [{}] })]).toEqual(['A, B', 'No application'])
		expect(p.filterOptions.map((o) => o.id)).toEqual(['all', 'gap', 'fillable', 'overlap'])
	})
})

describe('EmailConfiguration', () => {
	const { settingsStore } = store
	const section = () => context(EmailConfiguration, {}, { store: settingsStore })

	beforeEach(() => {
		Object.assign(settingsStore, { loading: false, emailSettings: { host: 'smtp' }, saveEmailSettings: vi.fn(), testEmailConnection: vi.fn(), sendTestEmail: vi.fn() })
	})

	it('reads and writes the email settings through the store', async () => {
		const s = section()
		await EmailConfiguration.created.call(s)
		expect([s.templates, s.loading, s.canSave, s.emailSettings]).toEqual([{}, false, true, { host: 'smtp' }])
		s.emailSettings = { host: 'mail' }
		expect(settingsStore.emailSettings).toEqual({ host: 'mail' })
		await s.saveEmailSettings()
		settingsStore.saveEmailSettings.mockRejectedValueOnce(new Error('x'))
		await s.saveEmailSettings()
		expect([settingsStore.saveEmailSettings.mock.calls.length, s.saving]).toEqual([2, false])
	})

	it('tests the connection and sends a test email, keeping a failure as a result', async () => {
		const s = section()
		settingsStore.testEmailConnection.mockResolvedValueOnce({ success: true })
		await s.testEmailConnection()
		expect(s.connectionTestResult).toEqual({ success: true })
		settingsStore.testEmailConnection.mockRejectedValueOnce(new Error('refused'))
		await s.testEmailConnection()
		expect([s.connectionTestResult, s.testingConnection]).toEqual([{ success: false, message: 'Connection test failed: refused' }, false])
		s.testEmailAddress = 'a@b.nl'
		settingsStore.sendTestEmail.mockResolvedValueOnce({ success: true })
		await s.sendTestEmail()
		expect([settingsStore.sendTestEmail.mock.calls[0], s.testEmailResult]).toEqual([['a@b.nl'], { success: true }])
		settingsStore.sendTestEmail.mockRejectedValueOnce(new Error('bounced'))
		await s.sendTestEmail()
		expect([s.testEmailResult, s.testingEmail]).toEqual([{ success: false, message: 'Test email failed: bounced' }, false])
	})

	it('describes the active template and inserts variables into it', () => {
		const s = section()
		expect([s.getActiveTemplateName(), s.getActiveTemplateDescription(), Object.keys(s.getActiveTemplateVariables())]).toEqual(['Organization Registration', 'Email sent when a new organization is registered', ['organization.name', 'organization.email', 'organization.website', 'user.name', 'user.email', 'activation_url']])
		s.insertVariable('user.name')
		s.insertVariable('activation_url')
		expect(s.getActiveTemplateContent()).toBe('{{ user.name }}{{ activation_url }}')
		s.activeTemplate = 'password_reset'
		expect([s.getActiveTemplateContent(), s.getActiveTemplateName()]).toEqual(['', 'Password Reset'])
		s.activeTemplate = 'gone'
		expect([s.getActiveTemplateName(), s.getActiveTemplateDescription(), s.getActiveTemplateVariables()]).toEqual(['', '', {}])
	})

	it('says templates are not saved on the server yet', async () => {
		const s = section()
		await s.resetTemplate()
		await s.saveTemplate()
		expect([s.templateSaveResult, s.savingTemplate, toasts.map((e) => e[0])]).toEqual([{ success: true, message: 'Template saved locally (backend implementation pending)' }, false, ['success', 'success']])
	})
})
