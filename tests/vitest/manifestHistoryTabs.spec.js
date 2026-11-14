/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every detail page of the merged manifest (src/manifest.json plus the
 * src/manifest.d fragments, merged as src/main.js merges them) declares a
 * History tab drawn by the `audit` widget, so every catalogue entry shows who
 * changed what (platform-properties, REQ-PLAT-006).
 *
 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md
 */
import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'
import { buildManifest } from '../../node_modules/@conduction/nextcloud-vue/src/utils/buildManifest.js'
import base from '../../src/manifest.json'
import menuLayout from '../../src/menu-layout.json'

/**
 * The manifest as the app runs it.
 *
 * @return {object}
 */
function mergedManifest() {
	const dir = path.resolve(__dirname, '../../src/manifest.d')
	const fragments = fs.readdirSync(dir)
		.filter((name) => name.endsWith('.json'))
		.sort()
		.map((name) => JSON.parse(fs.readFileSync(path.join(dir, name), 'utf8')))
	return buildManifest(base, fragments, menuLayout)
}

/**
 * A page's sidebar tabs, from the object form of `sidebar` or `sidebarProps`.
 *
 * @param {object} page - the page.
 * @return {Array<object>}
 */
function tabsOf(page) {
	const config = page.config || {}
	const sidebar = config.sidebar && typeof config.sidebar === 'object' ? config.sidebar : {}
	return sidebar.tabs || (config.sidebarProps && config.sidebarProps.tabs) || []
}

describe('History tabs on detail pages', () => {
	const detailPages = mergedManifest().pages.filter((page) => page.type === 'detail')

	it('finds the detail pages at all', () => {
		expect(detailPages.length).toBeGreaterThan(10)
	})

	it.each(detailPages.map((page) => [page.id, page]))('%s has a tab drawn by the audit widget', (id, page) => {
		const audit = tabsOf(page).filter((tab) => (tab.widgets || []).some((widget) => widget.type === 'audit'))
		expect(audit, `${id} has no History tab`).toHaveLength(1)
	})
})
