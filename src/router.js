// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Router builder for softwarecatalog's manifest-driven app shell.
//
// Mirrors decidesk's `routesFromManifest()` pattern. Each manifest page
// becomes one vue-router route; the route's `name` IS `page.id` (per
// the lib's manifest contract). Pages whose path declares a `:`
// parameter (`/contracten/:id`) get `props: true` so the renderer
// receives the route param as a prop.

import { CnPageRenderer } from '@conduction/nextcloud-vue'

// Shallow-clone CnPageRenderer because the lib's barrel exports are
// non-extensible (webpack ESM module records) and anything that caches state
// on the component definition throws "Cannot add property …, object is not
// extensible" against them. Cloning gives Vue Router an extensible
// component-options object without altering the lib's internals.
const RoutePageRenderer = { ...CnPageRenderer }

/**
 * Build the vue-router config from the manifest. Each manifest page
 * becomes one route; the route's `name` IS `page.id`.
 *
 * @param {object} manifest The bundled manifest (with `pages[]`).
 * @return {Array<object>} vue-router 4 routes config.
 */
export function routesFromManifest(manifest) {
	const routes = manifest.pages.map((page) => ({
		name: page.id,
		path: page.route,
		component: RoutePageRenderer,
		props: page.route.includes(':'),
	}))
	// Catch-all redirect to dashboard, preserving prior router behaviour.
	// ⚠️ vue-router 4 REMOVED the bare `path: '*'` wildcard and matches it
	// against nothing — silently, with the shell rendering and `<main>` empty.
	// The v4 spelling is a named param with a custom regexp.
	routes.push({ path: '/:pathMatch(.*)*', redirect: '/' })
	return routes
}
