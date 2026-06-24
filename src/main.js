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
	resolveManifestSentinels,
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

/**
 * Merge an array of incoming menu items into a target array, keyed by `id`.
 * New ids are appended; existing ids are merged in place: the first
 * definition of `label` / `icon` / `route` / `order` wins (the base manifest
 * loads first, so its canonical group definitions take precedence), and
 * `children` are unioned recursively by the same rule. Fragments may
 * therefore extend an existing group by re-declaring only its `id` plus
 * their own `children`.
 *
 * @param {Array<object>} target The accumulated menu (mutated in place).
 * @param {Array<object>} incoming Menu items from a fragment.
 * @return {void}
 */
function mergeMenuItems(target, incoming) {
	incoming.forEach((item) => {
		const existing = target.find((t) => t.id === item.id)
		if (!existing) {
			target.push({ ...item, children: Array.isArray(item.children) ? [...item.children] : item.children })
			return
		}
		for (const key of ['label', 'icon', 'route', 'order', 'section', 'featureFlag', 'permission', 'visibleIf', 'href', 'action']) {
			if (existing[key] === undefined && item[key] !== undefined) {
				existing[key] = item[key]
			}
		}
		if (Array.isArray(item.children) && item.children.length > 0) {
			if (!Array.isArray(existing.children)) {
				existing.children = []
			}
			mergeMenuItems(existing.children, item.children)
		}
	})
}

/**
 * ADR-037: merge modular manifest fragments from src/manifest.d/*.json onto the
 * bundled base manifest. Each OpenSpec change drops its own fragment (pages/menu)
 * instead of editing the monolith src/manifest.json, so concurrent builds touch
 * disjoint files. `pages` are concatenated; `menu` items are merged by `id`
 * (top-level and children) so fragments that re-declare an existing group extend
 * it instead of duplicating it in the navigation. After merging,
 * src/menu-layout.json relocations, sections, removals and settingsSection are
 * applied to consolidate entries into their canonical navigation clusters and to
 * lift config/admin entries into the Nextcloud settings foldout.
 *
 * @param {object} base The bundled base manifest.
 * @return {object} The manifest with all fragment pages/menu merged in.
 */
function mergeManifestFragments(base) {
	const merged = { ...base, pages: [...(base.pages || [])], menu: [] }
	mergeMenuItems(merged.menu, base.menu || [])
	// require.context is resolved at build time; src/manifest.d/ must exist (it
	// ships with a _placeholder.json). It is a no-op when no real fragments exist.
	const ctx = require.context('./manifest.d/', false, /\.json$/)
	ctx.keys().sort().forEach((key) => {
		const frag = ctx(key)
		if (Array.isArray(frag.pages)) {
			merged.pages.push(...frag.pages)
		}
		if (Array.isArray(frag.menu)) {
			mergeMenuItems(merged.menu, frag.menu)
		}
	})
	merged.menu = applyMenuRelocations(merged.menu, menuLayout.relocations)
	merged.menu = applyMenuSections(merged.menu, menuLayout.sections)
	merged.menu = applyMenuRemovals(merged.menu, menuLayout.removals)
	merged.menu = applySettingsSection(merged.menu, menuLayout.settingsSection)
	return merged
}

/**
 * Assign top-level menu leaves to a navigation section declared by
 * `src/menu-layout.json#sections` (`{ menuEntryId: sectionName }`). Section
 * `settings` makes the entry render inside the NC gear-icon settings foldout
 * (CnAppNav) instead of as a top-level item. Fragments stay the source of WHAT
 * exists (ADR-037); this map keeps the WHERE decision in the canonical layout
 * file. Only top-level entries are sectioned; unknown ids are inert.
 *
 * @param {Array<object>} menu The merged menu (mutated in place).
 * @param {Record<string, string>|undefined} sections Menu-entry id → section name.
 * @return {Array<object>} The menu with sections applied.
 */
function applyMenuSections(menu, sections) {
	if (!sections || typeof sections !== 'object') return menu
	menu.forEach((node) => {
		const section = sections[node.id]
		if (section) node.section = section
	})
	return menu
}

/**
 * Re-home merged menu entries onto the canonical navigation layout declared
 * by `src/menu-layout.json#relocations` (`{ sourceId: targetGroupId }`).
 *
 * Fragments stay the canonical source of WHAT exists in the menu (per
 * ADR-037 they drop entries wherever their change authored them); this map
 * is the single place that decides WHERE entries live, so the navigation
 * can be consolidated without rewriting dozens of fragments:
 *
 *  - A relocated GROUP dissolves: its children merge (by id) into the
 *    target group and the now-empty shell is dropped.
 *  - A relocated LEAF (top-level or child of any group) moves under the
 *    target group.
 *  - A child group relocated onto its own parent flattens into it.
 *  - Unknown source ids are inert; a missing target group keeps the entry
 *    at the top level so nothing silently disappears.
 *
 * Runs in passes until stable (children freed by a dissolved group can
 * themselves be relocated on the next pass).
 *
 * @param {Array<object>} menu The merged menu (mutated in place).
 * @param {Record<string, string>|undefined} relocations Source-id → target-group-id map.
 * @return {Array<object>} The menu with relocations applied.
 */
function applyMenuRelocations(menu, relocations) {
	if (!relocations || typeof relocations !== 'object') return menu
	for (let pass = 0; pass < 5; pass++) {
		const moves = []
		for (let i = menu.length - 1; i >= 0; i--) {
			const node = menu[i]
			const target = relocations[node.id]
			if (target && target !== node.id) {
				menu.splice(i, 1)
				moves.push({ node, target })
				continue
			}
			if (!Array.isArray(node.children)) continue
			for (let j = node.children.length - 1; j >= 0; j--) {
				const child = node.children[j]
				const childTarget = relocations[child.id]
				if (!childTarget) continue
				if (childTarget === node.id && !Array.isArray(child.children)) continue
				node.children.splice(j, 1)
				moves.push({ node: child, target: childTarget })
			}
		}
		if (moves.length === 0) break
		moves.forEach(({ node, target }) => {
			const group = menu.find((m) => m.id === target)
			if (!group) {
				menu.push(node)
				return
			}
			if (!Array.isArray(group.children)) group.children = []
			if (Array.isArray(node.children)) {
				mergeMenuItems(group.children, node.children)
			} else {
				mergeMenuItems(group.children, [node])
			}
		})
	}
	return menu.filter((m) => m.type === 'caption' || m.route || m.href || m.action
		|| (Array.isArray(m.children) && m.children.length > 0))
}

/**
 * Remove individual menu entries by id after relocation — used to retire
 * duplicate navigation entries whose PAGE must stay routable (deep links
 * and e2e specs hit the route directly). Declared in
 * `src/menu-layout.json#removals`. Only leaf entries are removed; group ids
 * are ignored so a removal can never silently hide a whole cluster.
 *
 * @param {Array<object>} menu The merged menu (mutated in place).
 * @param {Array<string>|undefined} removals Menu-entry ids to drop.
 * @return {Array<object>} The menu without the removed entries.
 */
function applyMenuRemovals(menu, removals) {
	if (!Array.isArray(removals) || removals.length === 0) return menu
	const drop = new Set(removals)
	const isLeaf = (n) => !Array.isArray(n.children) || n.children.length === 0
	menu.forEach((node) => {
		if (Array.isArray(node.children)) {
			node.children = node.children.filter((c) => !(drop.has(c.id) && isLeaf(c)))
		}
	})
	return menu.filter((node) => !(drop.has(node.id) && isLeaf(node)))
}

/**
 * Promote the menu entries listed in `src/menu-layout.json#settingsSection`
 * into Nextcloud's settings foldout — the NcAppNavigationSettings gear at the
 * bottom-left of the navigation, OUTSIDE the scrollable list. CnAppNav renders
 * every TOP-LEVEL item carrying `section: "settings"` as a flat entry inside
 * that foldout (with an auto-prepended "Personal settings"). This lifts each
 * listed id out of wherever it currently sits, tags it `section: "settings"`,
 * flattens it (the foldout has no nested groups), and appends it to the top
 * level. Empty non-clickable groups left behind are dropped; a clickable group
 * (one with route/href/action) is kept.
 *
 * @param {Array<object>} menu        The merged + relocated + pruned menu.
 * @param {Array<string>|undefined} settingsIds Entry ids to move to the foldout.
 * @return {Array<object>} The menu with the settings entries lifted out.
 */
function applySettingsSection(menu, settingsIds) {
	if (!Array.isArray(settingsIds) || settingsIds.length === 0) return menu
	const want = new Set(settingsIds)
	const isClickable = (n) => n.route !== undefined || n.href !== undefined || n.action !== undefined
	const lifted = []
	const strip = (nodes) => nodes.reduce((acc, n) => {
		if (want.has(n.id)) {
			const { children, ...leaf } = n
			lifted.push({ ...leaf, section: 'settings' })
			return acc
		}
		if (Array.isArray(n.children)) {
			const children = strip(n.children)
			if (children.length === 0 && n.children.length > 0 && !isClickable(n)) return acc
			acc.push({ ...n, children })
			return acc
		}
		acc.push(n)
		return acc
	}, [])
	const remaining = strip(menu)
	return [...remaining, ...lifted]
}

const mergedManifest = mergeManifestFragments(bundledManifest)

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
