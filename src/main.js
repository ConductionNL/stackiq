// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Conduction B.V.

/**
 * SoftwareCatalog frontend bootstrap.
 *
 * Mounts CnAppRoot with the bundled manifest, registers icons/translations,
 * and primes the router from the manifest pages.
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-softwarecatalog/tasks.md#task-11
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
} from '@conduction/nextcloud-vue'
import Tooltip from '@nextcloud/vue/dist/Directives/Tooltip.js'
import pinia from './pinia.js'
import App from './App.vue'
import bundledManifest from './manifest.json'
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

const router = new VueRouter({
	mode: 'history',
	base: generateUrl('/apps/softwarecatalog'),
	routes: routesFromManifest(bundledManifest),
})

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

// Resolve `@resolve:<key>` IAppConfig sentinels in `manifest.pages[].config`
// (e.g. `@resolve:voorzieningen_register`) via the lib's
// `manifest-resolve-sentinel` capability. The default `getAppConfigValue`
// resolver consults `@nextcloud/initial-state` first (zero-network), then
// falls back to `/apps/softwarecatalog/api/configs/{key}`. We don't pass
// an override — the lib's default chain matches our backend provisioning.
//
// `useAppManifest` returns a reactive ref. The render function below reads
// `manifestRef.value` so Vue re-renders App when sentinel resolution
// completes. Until then, the bundled manifest (with raw sentinels) is the
// initial value — fine for the first paint because the router is built
// from page IDs / routes, which never carry sentinels per the lib's
// schema validator.
const { manifest: manifestRef } = useAppManifest('softwarecatalog', bundledManifest)

new Vue({
	pinia,
	router,
	// Wrap manifestRef in a computed via `data()` so Vue re-renders App
	// when sentinel resolution swaps the manifest value. Reading
	// `manifestRef.value` inside `render` registers the dependency on the
	// composable's reactive ref.
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
