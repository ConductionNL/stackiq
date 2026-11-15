// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the three bundle entry points, each run for real against a
 * jsdom page: the app shell bootstrap (main.js) — dashboard widget
 * registrations, translation fallbacks, the merged manifest, the router base
 * and the mounted root with its props and `t` mixin — the admin settings
 * bundle (settings.js) and the concept-organisations dashboard widget.
 *
 * @spec openspec/specs/fe-shell-navigation/spec.md
 */

import { flushPromises } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const lib = vi.hoisted(() => ({}))
const l10n = vi.hoisted(() => ({}))

vi.mock('@conduction/nextcloud-vue', () => ({
	buildManifest: (...args) => lib.buildManifest(...args),
	defaultPageTypes: { index: 'IndexPage' },
	registerBuiltinDashboardWidgets: (...args) => lib.registerBuiltinDashboardWidgets(...args),
	registerDashboardWidget: (...args) => lib.registerDashboardWidget(...args),
	registerIcons: (...args) => lib.registerIcons(...args),
	registerTranslations: (...args) => lib.registerTranslations(...args),
	resolveManifestSentinels: (...args) => lib.resolveManifestSentinels(...args),
	useAppManifest: (...args) => lib.useAppManifest(...args),
	CnPageRenderer: { name: 'CnPageRenderer', render: () => null },
}))
vi.mock('@nextcloud/l10n', () => ({
	loadTranslations: (...args) => l10n.loadTranslations(...args),
	translate: (app, text) => app + ':' + text,
	translatePlural: (app, one, many, count) => app + ':' + (count === 1 ? one : many),
}))

/**
 * A module whose default export renders a marker, and `t()` through the mixin.
 *
 * @param {string} name Component name.
 * @param {Array<string>} props Prop names shown as JSON.
 * @return {Promise<object>} The module.
 */
async function marker(name, props = []) {
	const { defineComponent, h } = await import('vue')
	return {
		default: defineComponent({
			name,
			props,
			render() {
				const shown = Object.fromEntries(props.map((p) => [p, this[p]]))
				return h('div', {
					class: 'marker',
					'data-name': name,
					'data-props': JSON.stringify(shown),
					'data-t': this.t('stackiq', 'Hello'),
					'data-n': this.n('stackiq', 'item', 'items', 2),
					'data-base': this.$router ? this.$router.options.history.base : '',
				})
			},
		}),
	}
}

vi.mock('../../src/App.vue', () => marker('App', ['manifest', 'customComponents', 'pageTypes', 'registry']))
vi.mock('../../src/components/CatalogPanels.vue', () => ({ default: { name: 'CatalogPanels' } }))
vi.mock('../../src/components/maintenance/UpcomingMaintenanceWidget.vue', () => ({ default: { name: 'UpcomingMaintenanceWidget' } }))
vi.mock('../../src/views/cmdb/CmdbOverview.vue', () => ({ default: { name: 'CmdbOverview' } }))
vi.mock('../../src/customComponents.js', () => ({ default: { Custom: 'custom' } }))
vi.mock('../../src/icons.js', () => ({ default: { Icon: 'icon' } }))
vi.mock('../../src/registry.js', () => ({ default: { Registry: 'registry' } }))
vi.mock('../../src/views/settings/StackiqSettings.vue', () => marker('StackiqSettings'))
vi.mock('../../src/views/widgets/ConceptOrganisatiesWidget.vue', () => marker('ConceptOrganisatiesWidget', ['title']))

/**
 * The rendered marker's dataset, or null.
 *
 * @return {object|null} The dataset.
 */
function renderedMarker() {
	const el = document.querySelector('.marker')
	return el ? { ...el.dataset } : null
}

describe('main.js', () => {
	let fragments
	let requireContext

	beforeEach(() => {
		vi.resetModules()
		document.body.innerHTML = '<div id="stackiq"></div>'
		window.history.replaceState(null, '', '/')
		lib.buildManifest = vi.fn(() => ({ pages: [{ id: 'Dashboard', route: '/' }], merged: true }))
		lib.registerBuiltinDashboardWidgets = vi.fn()
		lib.registerDashboardWidget = vi.fn()
		lib.registerIcons = vi.fn()
		lib.registerTranslations = vi.fn()
		lib.resolveManifestSentinels = vi.fn(async (manifest) => ({ manifest: { ...manifest, resolved: true } }))
		lib.useAppManifest = vi.fn(({ manifest }) => ({ manifest: { value: manifest } }))
		l10n.loadTranslations = vi.fn(() => Promise.resolve())
		fragments = { './b.json': { id: 'b' }, './a.json': { id: 'a' } }
		const context = Object.assign((key) => fragments[key], { keys: () => Object.keys(fragments) })
		requireContext = vi.fn(() => context)
		// main.js calls webpack's `require.context`. Under vitest each module
		// gets its own Node `require`, which no global stub reaches, so the
		// method is lent to every function for the duration of a test.
		Object.defineProperty(Function.prototype, 'context', { configurable: true, writable: true, value: requireContext })
	})

	afterEach(() => {
		delete Function.prototype.context
		vi.restoreAllMocks()
	})

	it('registers the icons and the three app dashboard widgets', async () => {
		await import('../../src/main.js')
		await flushPromises()

		expect(lib.registerIcons).toHaveBeenCalledWith({ Icon: 'icon' })
		expect(lib.registerBuiltinDashboardWidgets).toHaveBeenCalledTimes(1)
		expect(lib.registerDashboardWidget.mock.calls.map(([id, def]) => [id, def.renderer.name, def.displayName, def.icon, def.card])).toEqual([
			['catalog-panels', 'CatalogPanels', 'Catalogue panels', 'DatabaseOutline', true],
			['upcoming-maintenance', 'UpcomingMaintenanceWidget', 'Planned maintenance', 'Calendar', true],
			['cmdb-overview', 'CmdbOverview', 'CMDB', 'Sitemap', true],
		])
		expect(l10n.loadTranslations).toHaveBeenCalledWith('stackiq', expect.any(Function))
	})

	it('merges the bundled manifest with the sorted fragments and the menu layout', async () => {
		const bundled = (await import('../../src/manifest.json')).default
		const menuLayout = (await import('../../src/menu-layout.json')).default
		await import('../../src/main.js')
		await flushPromises()

		expect(requireContext).toHaveBeenCalledWith('./manifest.d/', false, /\.json$/)
		expect(lib.buildManifest).toHaveBeenCalledTimes(1)
		const [manifest, parts, layout] = lib.buildManifest.mock.calls[0]
		expect(manifest).toEqual(bundled)
		expect(parts).toEqual([{ id: 'a' }, { id: 'b' }])
		expect(layout).toEqual(menuLayout)
		expect(lib.resolveManifestSentinels).toHaveBeenCalledWith({ pages: [{ id: 'Dashboard', route: '/' }], merged: true }, 'stackiq')
	})

	it('mounts the app root with the resolved manifest, the app props and the t/n mixin', async () => {
		await import('../../src/main.js')
		await flushPromises()

		const shown = renderedMarker()
		expect(shown.name).toBe('App')
		expect(JSON.parse(shown.props)).toEqual({
			manifest: { pages: [{ id: 'Dashboard', route: '/' }], merged: true, resolved: true },
			customComponents: { Custom: 'custom' },
			pageTypes: { index: 'IndexPage' },
			registry: { Registry: 'registry' },
		})
		expect(shown.t).toBe('stackiq:Hello')
		expect(shown.n).toBe('stackiq:items')
		expect(shown.base).toBe('/index.php/apps/stackiq')
	})

	it('takes the router base from the current app URL', async () => {
		window.history.replaceState(null, '', '/custom/index.php/apps/stackiq/modules/1')
		await import('../../src/main.js')
		await flushPromises()
		expect(renderedMarker().base).toBe('/custom/index.php/apps/stackiq')
	})

	it('falls back to English when translations cannot be registered or loaded', async () => {
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		lib.registerTranslations = vi.fn(() => {
			throw new Error('no bundle')
		})
		l10n.loadTranslations = vi.fn(() => Promise.reject(new Error('offline')))
		await import('../../src/main.js')
		await flushPromises()
		expect(warn).toHaveBeenCalledWith('[stackiq] registerTranslations failed; falling back to English', expect.any(Error))
		expect(renderedMarker().name).toBe('App')
	})

	it('survives a loader that throws synchronously', async () => {
		l10n.loadTranslations = vi.fn(() => {
			throw new Error('sync')
		})
		await import('../../src/main.js')
		await flushPromises()
		expect(renderedMarker().name).toBe('App')
	})
})

describe('settings.js', () => {
	beforeEach(() => {
		vi.resetModules()
		document.body.innerHTML = '<div id="settings"></div><div id="stackiq-settings"></div>'
	})

	it('mounts the admin settings inside the app-named host with the t/n mixin', async () => {
		await import('../../src/settings.js')
		await flushPromises()
		const host = document.getElementById('stackiq-settings')
		expect(host.querySelector('.marker').dataset.name).toBe('StackiqSettings')
		expect(host.querySelector('.marker').dataset.t).toBe('stackiq:Hello')
		expect(document.getElementById('settings').innerHTML).toBe('')
	})
})

describe('conceptOrganisatiesWidget.js', () => {
	beforeEach(() => {
		vi.resetModules()
		document.body.innerHTML = '<div id="widget"></div>'
	})

	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('registers the dashboard widget under its frozen id and mounts it with the title', async () => {
		const register = vi.fn()
		vi.stubGlobal('OCA', { Dashboard: { register } })
		await import('../../src/conceptOrganisatiesWidget.js')

		expect(register).toHaveBeenCalledTimes(1)
		const [id, callback] = register.mock.calls[0]
		expect(id).toBe('softwarecatalog_concept_organisaties_widget')

		const el = document.getElementById('widget')
		await callback(el, { widget: { title: 'Concept organisaties' } })
		await flushPromises()
		const shown = el.querySelector('.marker').dataset
		expect(shown.name).toBe('ConceptOrganisatiesWidget')
		expect(JSON.parse(shown.props)).toEqual({ title: 'Concept organisaties' })
		expect(shown.n).toBe('stackiq:items')
	})
})
