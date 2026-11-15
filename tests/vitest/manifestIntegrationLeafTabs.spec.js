/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every detail page whose schema declares an integration leaf in
 * lib/Settings/register.d/catalog-integration-leaves.json shows that leaf as a
 * sidebar tab drawn by the IntegrationLeafTab widget. A page that lists its
 * own sidebar tabs does not get the registry's leaf tabs, so without the
 * declared tab the leaf would not show at all (adopt-integration-leaves 3.1).
 *
 * @spec openspec/changes/adopt-integration-leaves
 */
import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'
import fragment from '../../lib/Settings/register.d/catalog-integration-leaves.json'
import { buildManifest } from '../../node_modules/@conduction/nextcloud-vue/src/utils/buildManifest.js'
import appIcons from '../../src/icons.js'
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
 * The leaf ids drawn by IntegrationLeafTab on a page's sidebar.
 *
 * @param {object} page The page.
 * @return {Array<string>} The leaf ids.
 */
function leafTabsOf(page) {
	const tabs = page.config?.sidebar?.tabs || []
	return tabs.flatMap((tab) => (tab.widgets || [])
		.filter((widget) => widget.type === 'IntegrationLeafTab')
		.map((widget) => {
			expect(tab.id, `the tab of the ${widget.props?.integrationId} leaf is named after it`).toBe(widget.props?.integrationId)
			return widget.props.integrationId
		}))
}

const detailPages = mergedManifest().pages.filter((page) => page.type === 'detail')
const declared = Object.entries(fragment.components.schemas)
	.map(([schema, def]) => [schema, def.configuration.linkedTypes])

describe('integration leaf tabs on detail pages', () => {
	it.each(declared)('every detail page of %s shows its leaves %j', (schema, leaves) => {
		const pages = detailPages.filter((page) => page.config?.schema === schema)
		for (const page of pages) {
			expect(leafTabsOf(page).sort(), `${page.id} misses a leaf tab`).toEqual([...leaves].sort())
		}
	})

	it('covers the six detail pages the change names', () => {
		const withLeaves = detailPages.filter((page) => leafTabsOf(page).length > 0).map((page) => page.id).sort()
		expect(withLeaves).toEqual(['ContactpersoonDetail', 'ContractDetail', 'ModuleDetail', 'ModuleversieDetail', 'OrganisatieDetail', 'ReviewDetail'])
	})

	it('resolves the widget and every tab icon', () => {
		const registry = fs.readFileSync(path.resolve(__dirname, '../../src/customComponents.js'), 'utf8')
		expect(registry).toMatch(/^import IntegrationLeafTab from '\.\/components\/integrations\/IntegrationLeafTab\.vue'$/m)
		expect(registry).toMatch(/^\tIntegrationLeafTab,$/m)
		const icons = detailPages.flatMap((page) => (page.config?.sidebar?.tabs || [])
			.filter((tab) => (tab.widgets || []).some((widget) => widget.type === 'IntegrationLeafTab'))
			.map((tab) => tab.icon))
		for (const icon of icons) {
			expect(appIcons[icon], `${icon} is registered`).toBeDefined()
		}
	})
})
