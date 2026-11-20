/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The knowledge base as the app builds it: src/manifest.json plus the
 * src/manifest.d fragments and src/menu-layout.json, merged the way
 * src/main.js merges them. Articles are Nextcloud Collectives pages, so the
 * whole feature is configuration over the library's `collectives` leaf and
 * `kb-search` widget; these specs pin that configuration, and run the library's
 * own default provider against the OpenRegister page-search response shape.
 *
 * @spec openspec/changes/insight-knowledge-base/specs/application-knowledge-base/spec.md
 */

import addFormats from 'ajv-formats'
import Ajv2020 from 'ajv/dist/2020.js'
import * as fs from 'fs'
import * as path from 'path'
import { afterEach, describe, expect, it, vi } from 'vitest'
import manifestSchema from '../../node_modules/@conduction/nextcloud-vue/src/schemas/app-manifest-v2.schema.json'
import { buildManifest } from '../../node_modules/@conduction/nextcloud-vue/src/utils/buildManifest.js'
import { defaultKbProvider } from '../../node_modules/@conduction/nextcloud-vue/src/utils/kbSearchProviders.js'
import base from '../../src/manifest.json'
import menuLayout from '../../src/menu-layout.json'

const FRAGMENT = 'insight-knowledge-base.json'
const PAGE_SEARCH = '/apps/openregister/api/integrations/collectives/available'

/**
 * The manifest as the app runs it, optionally without one fragment.
 *
 * @param {string} [without] - a fragment file name to leave out.
 * @return {object}
 */
function mergedManifest(without = '') {
	const dir = path.resolve(__dirname, '../../src/manifest.d')
	const fragments = fs.readdirSync(dir)
		.filter((name) => name.endsWith('.json') && name !== without)
		.sort()
		.map((name) => JSON.parse(fs.readFileSync(path.join(dir, name), 'utf8')))
	// buildManifest mutates menu nodes during relocation; give it its own copy.
	return buildManifest(JSON.parse(JSON.stringify(base)), fragments, JSON.parse(JSON.stringify(menuLayout)))
}

const merged = mergedManifest()

/**
 * A page of the merged manifest by id.
 *
 * @param {string} id - the page id.
 * @return {object}
 */
function page(id) {
	const found = merged.pages.find((p) => p.id === id)
	expect(found, `page ${id}`).toBeTruthy()
	return found
}

describe('the merged manifest', () => {
	it('is valid against the v2 schema', () => {
		const ajv = new Ajv2020({ allErrors: true, strict: false })
		addFormats(ajv)
		const validate = ajv.compile(manifestSchema)
		validate(merged)
		expect(validate.errors ?? []).toEqual([])
	})
})

describe('the Knowledge articles panel on the application page (REQ-AKB-001, 002, 004)', () => {
	const detail = page('ModuleDetail')
	const widget = detail.config.widgets.find((w) => w.id === 'md-knowledge')

	it('is the Collectives leaf, gated on the Collectives app', () => {
		expect(widget).toMatchObject({
			type: 'integration',
			integrationId: 'collectives',
			requiredApp: 'collectives',
			title: 'Knowledge articles',
		})
	})

	it('keeps the Documentation files panel', () => {
		const files = detail.config.widgets.find((w) => w.id === 'md-files')
		expect(files).toMatchObject({ type: 'integration', integrationId: 'files', title: 'Documentation' })
		expect(detail.config.layout.some((l) => l.widgetId === 'md-files')).toBe(true)
	})

	it('sits in its own full-width row below every other row', () => {
		const layout = detail.config.layout
		const row = layout.filter((l) => l.widgetId === 'md-knowledge')
		expect(row).toHaveLength(1)
		expect(row[0]).toMatchObject({ gridX: 0, gridWidth: 12 })
		const others = layout.filter((l) => l.widgetId !== 'md-knowledge')
		const lowestBottom = Math.max(...others.map((l) => l.gridY + l.gridHeight))
		expect(row[0].gridY).toBe(lowestBottom)
	})

	it('gives every layout item a unique id and a declared widget', () => {
		const layout = detail.config.layout
		const ids = layout.map((l) => l.id)
		expect(new Set(ids).size).toBe(ids.length)
		const widgetIds = new Set(detail.config.widgets.map((w) => w.id))
		layout.forEach((l) => expect(widgetIds.has(l.widgetId), l.widgetId).toBe(true))
	})
})

describe('the Knowledge base page (REQ-AKB-003, 004)', () => {
	const kb = page('KnowledgeBase')

	it('is a dashboard at /knowledge that needs Collectives', () => {
		expect(kb.route).toBe('/knowledge')
		expect(kb.type).toBe('dashboard')
		expect(kb.title).toBe('Knowledge base')
		expect(kb.requiresApp).toEqual({ id: 'collectives', name: 'Collectives' })
	})

	it('has exactly one kb-search widget on the OpenRegister page search', () => {
		const searches = kb.config.widgets.filter((w) => w.type === 'kb-search')
		expect(searches).toHaveLength(1)
		expect(searches[0].content.endpoint).toBe(PAGE_SEARCH)
		expect(searches[0].content.queryParam).toBe('search')
		expect(searches[0].content.provider).toBeUndefined()
		expect(searches[0].content.unavailableFallback).toMatch(/Collectives/)
	})

	it('says the search matches titles and links to Collectives for full text search', () => {
		const hint = kb.config.widgets.find((w) => w.type === 'text')
		expect(hint.content.contentMode).toBe('markdown')
		expect(hint.content.text).toMatch(/titles/)
		expect(hint.content.text).toMatch(/\]\(\/index\.php\/apps\/collectives\/\)/)
	})

	it('places every widget in the layout', () => {
		const placed = kb.config.layout.map((l) => l.widgetId).sort()
		expect(placed).toEqual(kb.config.widgets.map((w) => w.id).sort())
	})
})

describe('the Knowledge base menu entry (REQ-AKB-003, 004)', () => {
	it('is a child of Applications, hidden without Collectives', () => {
		const modules = merged.menu.find((m) => m.id === 'Modules')
		const entry = (modules.children || []).find((c) => c.id === 'KnowledgeBaseMenu')
		expect(entry).toMatchObject({
			label: 'Knowledge base',
			route: 'KnowledgeBase',
			visibleIf: { appInstalled: 'collectives' },
		})
	})

	it('is not a top-level entry and does not grow the top level', () => {
		expect(merged.menu.some((m) => m.id === 'KnowledgeBaseMenu')).toBe(false)
		expect(merged.menu).toHaveLength(mergedManifest(FRAGMENT).menu.length)
	})
})

describe('the library default provider against the OpenRegister page search', () => {
	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('queries ?search= and reads the { results, total } answer', async () => {
		const results = [{ title: 'Restore procedure', url: '/index.php/apps/collectives/Ops/Restore-procedure' }]
		const fetchMock = vi.fn(async () => ({ ok: true, json: async () => ({ results, total: 1 }) }))
		vi.stubGlobal('fetch', fetchMock)
		const content = page('KnowledgeBase').config.widgets.find((w) => w.type === 'kb-search').content

		const found = await defaultKbProvider.search('restore', content)

		expect(found).toEqual(results)
		expect(fetchMock).toHaveBeenCalledTimes(1)
		const url = new URL(fetchMock.mock.calls[0][0], 'http://localhost')
		expect(url.pathname).toBe('/index.php' + PAGE_SEARCH)
		expect(url.searchParams.get('search')).toBe('restore')
		expect(url.searchParams.get('limit')).toBe(String(content.limit))
		expect(url.searchParams.has('q')).toBe(false)
	})

	it('throws on the 501 OpenRegister answers without Collectives, so the widget shows its unavailable text', async () => {
		vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, status: 501, json: async () => ({}) })))
		const content = page('KnowledgeBase').config.widgets.find((w) => w.type === 'kb-search').content

		await expect(defaultKbProvider.search('restore', content)).rejects.toThrow('501')
	})
})
