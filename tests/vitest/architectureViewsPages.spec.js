/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Views index and the Architecture menu group (architecture-views-editor, task 2):
 * Views lists the AMEF `view` schema with the All, Mine, Drawn and Imported quick
 * filters, and Standards and Views sit under an Architecture group without changing
 * the number of top-level entries.
 *
 * @spec openspec/changes/architecture-views-editor/specs/architecture-views-editor/spec.md#requirement-req-ave-004-the-views-list-shall-filter-on-tag-status-and-owner
 */

import addFormats from 'ajv-formats'
import Ajv2020 from 'ajv/dist/2020.js'
import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'
import manifestSchema from '../../node_modules/@conduction/nextcloud-vue/src/schemas/app-manifest-v2.schema.json'
import { buildManifest } from '../../node_modules/@conduction/nextcloud-vue/src/utils/buildManifest.js'
import base from '../../src/manifest.json'
import menuLayout from '../../src/menu-layout.json'

const dir = path.resolve(__dirname, '../../src/manifest.d')
function load (skip = null) {
  return fs
	.readdirSync(dir)
	.filter((f) => f.endsWith('.json') && f !== skip)
	.sort()
	.map((f) => JSON.parse(fs.readFileSync(path.join(dir, f), 'utf8')))
}
const merged = buildManifest(base, load(), menuLayout)
const page = (id) => merged.pages.find((p) => p.id === id)

describe('the Views page and the Architecture group', () => {
	it('the merged manifest is valid against the v2 schema', () => {
		const ajv = new Ajv2020({ allErrors: true, strict: false })
		addFormats(ajv)
		const validate = ajv.compile(manifestSchema)
		validate(merged)
		expect(validate.errors ?? []).toEqual([])
	})

	it('lists views at /views over the AMEF view schema with the four quick filters', () => {
		const views = page('Views')
		expect(views).toMatchObject({ type: 'index', route: '/views' })
		expect(views.config.register).toBe('@resolve:amef_register')
		expect(views.config.schema).toBe('view')
		expect(views.config.columns).toEqual(['name', 'viewpoint', 'status', 'tags', 'origin'])
		expect(views.config.quickFilters.map((q) => [q.label, q.filter])).toEqual([
			['All', {}],
			['Mine', { _owner: '@me' }],
			['Drawn', { origin: 'drawn' }],
			['Imported', { origin: 'imported' }],
		])
		expect(views.config.columns).not.toContain('xml')
	})

	it('puts Standards and Views under an Architecture group, keeping the top-level count', () => {
		const group = merged.menu.find((entry) => entry.id === 'Architecture')
		expect(group.route).toBeUndefined()
		expect(group.children.map((child) => child.id)).toEqual(expect.arrayContaining(['Standaarden', 'Views']))
		expect(merged.menu.find((entry) => entry.id === 'Standaarden')).toBeUndefined()

		const without = buildManifest(base, load('architecture-views.json'), { ...menuLayout, relocations: { ...menuLayout.relocations, Standaarden: undefined, Views: undefined } })
		expect(merged.menu.length).toBe(without.menu.length)
	})
})
