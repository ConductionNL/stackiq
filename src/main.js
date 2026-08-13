// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * SoftwareCatalog frontend bootstrap.
 *
 * Mounts CnAppRoot with the bundled manifest, registers icons/translations,
 * and primes the router from the manifest pages.
 *
 * @spec openspec/specs/softwarecatalog-manifest-v1/spec.md
 */

import { createApp, h } from 'vue'
import { createRouter, createWebHashHistory } from 'vue-router'
import {
	translate as t,
	translatePlural as n,
	loadTranslations,
} from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	defaultPageTypes,
	registerIcons,
	registerTranslations,
	registerBuiltinDashboardWidgets,
	useAppManifest,
	resolveManifestSentinels,
	buildManifest,
} from '@conduction/nextcloud-vue'
import pinia from './pinia.js'
import App from './App.vue'
import bundledManifest from './manifest.json'
import menuLayout from './menu-layout.json'
import customComponents from './customComponents.js'
import registry from './registry.js'
import appIcons from './icons.js'
import { routesFromManifest } from './router.js'

// Library CSS — must be explicit import (webpack tree-shakes side-effect imports from aliased packages)
import '@conduction/nextcloud-vue/css/index.css'

// gridstack is a peerDependency of @conduction/nextcloud-vue that no consumer
// declares. The stylesheet is the silent half: v12 sizes dashboard items with
// `width: var(--gs-column-width)`, so without it every widget renders 0 px wide
// with no console error.
import 'gridstack/dist/gridstack.min.css'

// Global (unscoped) app styles
import './assets/app.css'

// Register library-side icon set + lib translations once at bootstrap.
registerIcons(appIcons)

// nc-vue's `sideEffects: ["**/*.css"]` lets webpack drop the bare imports that
// register the built-in `stat` / `object-table` dashboard widgets, which then
// render "Widget not available". This app uses `stat` and `stats-block` widgets
// in detail-page widget slots, so the explicit call is load-bearing.
registerBuiltinDashboardWidgets()
try {
	registerTranslations()
} catch (e) {
	// Non-fatal — lib translations fall back to English source.
	// eslint-disable-next-line no-console
	console.warn(
		'[softwarecatalog] registerTranslations failed; falling back to English',
		e,
	)
}

// Fire-and-forget translation load. Some Nextcloud installs (including
// this repo's standard dev container) only allow the JS/CSS allowlist
// through Apache and rewrite everything else to index.php — there's no
// route for /custom_apps/<app>/l10n/<locale>.json so the request 404s.
// `loadTranslations` rejects on 404, so wrapping the Vue mount inside
// its callback meant boot silently failed when translations couldn't
// load. Strings just fall back to their English source on miss; boot
// MUST not depend on this resolving.
function tryLoadTranslations() {
	try {
		const result = loadTranslations('softwarecatalog', () => {})
		if (result && typeof result.then === 'function') {
			result.then(
				() => {},
				() => {},
			)
		}
	} catch {
		// no-op
	}
}

const fragmentCtx = require.context('./manifest.d/', false, /\.json$/)
const fragments = fragmentCtx
	.keys()
	.sort()
	.map((key) => fragmentCtx(key))
const mergedManifest = buildManifest(bundledManifest, fragments, menuLayout)

tryLoadTranslations()

// Pass shallow copies of the registry maps to CnAppRoot. The lib exports
// `defaultPageTypes` (and consumers' `customComponents`) as FROZEN module
// objects, and anything that writes a cache key onto a component definition
// throws "Cannot add property …, object is not extensible" against them.
// Cloning here yields extensible objects without changing the values the lib
// resolves at render time.
const pageTypesProp = { ...defaultPageTypes }
const customComponentsProp = { ...customComponents }
const registryProp = { ...registry }

/**
 * Resolve `@resolve:<key>` IAppConfig sentinels in `manifest.pages[].config`
 * (e.g. `@resolve:voorzieningen_register`) APP-SIDE, before the router and
 * CnAppRoot ever read a page's `config.register`.
 *
 * Why we resolve here instead of relying on `useAppManifest`'s built-in
 * backend-merge resolution: this app has no real `/api/manifest` JSON
 * endpoint — Nextcloud's catch-all rewrites that path to the SPA's index
 * HTML (HTTP 200). The lib's legacy `loadFromBackend` branch deep-merges
 * that HTML, fails schema validation, and returns WITHOUT setting the
 * resolved manifest — so the bundled manifest keeps its literal
 * `@resolve:` sentinels and every CnIndexPage fires
 * `GET .../objects/@resolve:voorzieningen_register/<schema>` (404).
 *
 * `resolveManifestSentinels` reads each key from `@nextcloud/initial-state`
 * (zero-network) first; `Application::boot()` provisions
 * `voorzieningen_register` there with the numeric register id. We pass the
 * resolved manifest into the in-memory `useAppManifest({ manifest })`
 * branch, which mounts it synchronously and issues NO backend fetch — so
 * nothing can clobber the resolved sentinels afterwards.
 *
 * @return {Promise<void>}
 */
async function bootstrap() {
	const { manifest: resolvedManifest } = await resolveManifestSentinels(
		mergedManifest,
		'softwarecatalog',
	)

	const router = createRouter({
		history: createWebHashHistory(generateUrl('/apps/softwarecatalog')),
		routes: routesFromManifest(resolvedManifest),
	})

	// In-memory branch: mount the already-resolved manifest synchronously,
	// no backend fetch (see bootstrap() docblock for why the fetch path is
	// unusable for this app).
	const { manifest: manifestRef } = useAppManifest({ manifest: resolvedManifest })

	const app = createApp({
		render() {
			return h(App, {
				manifest: manifestRef.value,
				customComponents: customComponentsProp,
				pageTypes: pageTypesProp,
				registry: registryProp,
			})
		},
	})

	// Vue 3 has no global `Vue.mixin` — `t`/`n` are installed on the app
	// instance so every component still resolves them in templates.
	app.mixin({ methods: { t, n } })
	app.use(pinia)
	app.use(router)

	// ⚠️ Vue 2's `$mount('#content')` REPLACED the matched element; Vue 3's
	// `mount()` renders INSIDE it. `#content` is Nextcloud core's own
	// `layout.user.php` wrapper — under Vue 2 the app replaced it outright,
	// but under Vue 3 the same selector would nest the whole app inside core's
	// chrome. `templates/index.php` already emits the app's own host element,
	// so mount onto that and stop reasoning about which div wins.
	app.mount('#softwarecatalog')
}

bootstrap()
