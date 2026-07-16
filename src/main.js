// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Conduction B.V.

/**
 * SoftwareCatalog frontend bootstrap.
 *
 * Mounts CnAppRoot with the bundled manifest, registers icons/translations,
 * and primes the router from the manifest pages.
 *
 * @spec openspec/specs/softwarecatalog-manifest-v1/spec.md
 */

import Vue from 'vue'
import VueRouter from 'vue-router'
import { PiniaVuePlugin } from 'pinia'
import { translate as t, translatePlural as n, loadTranslations } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	defaultPageTypes,
	registerIcons,
	registerTranslations,
	useAppManifest,
	resolveManifestSentinels,
	buildManifest,
} from '@conduction/nextcloud-vue'
import Tooltip from '@nextcloud/vue/dist/Directives/Tooltip.js'
import pinia from './pinia.js'
import App from './App.vue'
import bundledManifest from './manifest.json'
import menuLayout from './menu-layout.json'
import customComponents from './customComponents.js'
import registry from './registry.js'
import { routesFromManifest } from './router.js'

// Library CSS — must be explicit import (webpack tree-shakes side-effect imports from aliased packages)
import '@conduction/nextcloud-vue/css/index.css'

// Global (unscoped) app styles
import './assets/app.css'

Vue.mixin({ methods: { t, n } })
Vue.directive('tooltip', Tooltip)

Vue.use(PiniaVuePlugin)
Vue.use(VueRouter)

// Register library-side icon set + lib translations once at bootstrap.
registerIcons()
try {
	registerTranslations()
} catch (e) {
	// Non-fatal — lib translations fall back to English source.
	// eslint-disable-next-line no-console
	console.warn('[softwarecatalog] registerTranslations failed; falling back to English', e)
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
			result.then(() => {}, () => {})
		}
	} catch {
		// no-op
	}
}

const fragmentCtx = require.context('./manifest.d/', false, /\.json$/)
const fragments = fragmentCtx.keys().sort().map((key) => fragmentCtx(key))
const mergedManifest = buildManifest(bundledManifest, fragments, menuLayout)

tryLoadTranslations()

// Pass shallow copies of the registry maps to CnAppRoot. The lib exports
// `defaultPageTypes` (and consumers' `customComponents`) as frozen module
// objects in some bundle shapes — Vue 2's `Vue.extend()` mutates component
// definitions to attach an internal `_Ctor` cache, which throws
// "Cannot add property _Ctor, object is not extensible" against a frozen
// source map. Cloning here yields extensible objects without changing
// the values the lib resolves at render time.
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
	const { manifest: resolvedManifest } = await resolveManifestSentinels(mergedManifest, 'softwarecatalog')

	const router = new VueRouter({
		mode: 'hash',
		base: generateUrl('/apps/softwarecatalog'),
		routes: routesFromManifest(resolvedManifest),
	})

	// In-memory branch: mount the already-resolved manifest synchronously,
	// no backend fetch (see bootstrap() docblock for why the fetch path is
	// unusable for this app).
	const { manifest: manifestRef } = useAppManifest({ manifest: resolvedManifest })

	new Vue({
		pinia,
		router,
		render(h) {
			return h(App, {
				props: {
					manifest: manifestRef.value,
					customComponents: customComponentsProp,
					pageTypes: pageTypesProp,
					registry: registryProp,
				},
			})
		},
	}).$mount('#content')
}

bootstrap()
