/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Data model pages (architecture-data-model-and-ggm, tasks 2 and 5): the
 * Gegevensmodel index lists AMEF elements of type BusinessObject with the All,
 * GGM and Own quick filters and a create dialog that asks for three fields, the
 * entity page lists the usages that name it, the entry sits under the
 * Architecture group, and the usage page shows its data entities.
 *
 * @spec openspec/changes/architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-001-stackiq-shall-show-the-data-entities-of-the-imported-model-on-a-data-model-page
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
function load(skip = []) {
	return fs
		.readdirSync(dir)
		.filter((f) => f.endsWith('.json') && !skip.includes(f))
		.sort()
		.map((f) => JSON.parse(fs.readFileSync(path.join(dir, f), 'utf8')))
}
const merged = buildManifest(base, load(), menuLayout)
const page = (id) => merged.pages.find((p) => p.id === id)

describe('the Data model pages', () => {
	it('the merged manifest is valid against the v2 schema', () => {
		const ajv = new Ajv2020({ allErrors: true, strict: false })
		addFormats(ajv)
		const validate = ajv.compile(manifestSchema)
		validate(merged)
		expect(validate.errors ?? []).toEqual([])
	})

	it('lists BusinessObject elements at /gegevensmodel with the All, GGM and Own quick filters', () => {
		const index = page('Gegevensmodel')
		expect(index).toMatchObject({ type: 'index', route: '/gegevensmodel' })
		expect(index.config.register).toBe('@resolve:amef_register')
		expect(index.config.schema).toBe('element')
		expect(index.config.filter).toEqual({ type: 'BusinessObject' })
		expect(index.config.columns).toEqual(['name', 'ggm-uml-type', 'gemmaType', 'origin'])
		expect(index.config.quickFilters.map((q) => [q.label, q.filter])).toEqual([
			['All', {}],
			['GGM', { origin: 'imported' }],
			['Own', { origin: 'drawn' }],
		])
		expect(index.config.rowRoute).toBe('GegevensobjectDetail')
	})

	it('creates an own BusinessObject asking only for name, documentation and attributes', () => {
		const config = page('Gegevensmodel').config
		expect(config.addLabel).toBe('New data entity')
		expect(config.createDefaults).toEqual({ type: 'BusinessObject', origin: 'drawn' })
		expect(config.includeFields).toEqual(['name', 'documentation', 'attributes'])
	})

	it('creates through the handler that fills the identifier and properties the element schema requires', () => {
		expect(page('Gegevensmodel').config.createOverride).toBe('createDrawnDataEntity')
		const registered = fs.readFileSync(path.resolve(__dirname, '../../src/customComponents.js'), 'utf8')
		expect(registered).toMatch(/^\tcreateDrawnDataEntity,$/m)
		expect(registered).toContain("import { createDrawnDataEntity } from './utils/drawnDataEntity.js'")
	})

	it('puts Data model under the Architecture group without growing the top level', () => {
		const group = merged.menu.find((entry) => entry.id === 'Architecture')
		expect(group.children.map((child) => child.id)).toContain('Gegevensmodel')
		expect(merged.menu.find((entry) => entry.id === 'Gegevensmodel')).toBeUndefined()

		const without = buildManifest(base, load(['architecture-data-model.json']), menuLayout)
		expect(merged.menu.length).toBe(without.menu.length)
	})

	it('draws the entity page with its data, relations, attributes and the usages that hold its data', () => {
		const detail = page('GegevensobjectDetail')
		expect(detail).toMatchObject({ type: 'detail', route: '/gegevensmodel/:id' })
		expect(detail.config.register).toBe('@resolve:amef_register')
		expect(detail.config.schema).toBe('element')

		const data = detail.config.widgets.find((w) => w.type === 'data')
		expect(data.content.include).toEqual(['name', 'documentation', 'ggm-guid', 'ggm-uml-type', 'origin'])

		const usages = detail.config.widgets.find((w) => w.id === 'entity-usages')
		expect(usages).toMatchObject({ type: 'object-list', title: 'Applications that hold this data' })
		expect(usages.content).toMatchObject({
			register: '@resolve:voorzieningen_register',
			schema: 'usage',
			filter: { dataEntities: '@objectId' },
			rowRoute: 'GebruikDetail',
		})

		// Every widget has a place on the grid.
		const placed = detail.config.layout.map((l) => l.widgetId)
		for (const widget of detail.config.widgets) {
			expect(placed).toContain(widget.id)
		}

		expect(detail.config.bodyWidgets.map((w) => [w.component, w.props])).toEqual([
			['DataEntityRelations', { objectId: '@objectId', register: '@resolve:amef_register' }],
			['DataEntityAttributes', { objectId: '@objectId', register: '@resolve:amef_register' }],
		])
	})

	it('shows and edits the data entities on the usage page', () => {
		const data = page('GebruikDetail').config.widgets.find((w) => w.id === 'gb-data')
		expect(data.content.include).toContain('dataEntities')
	})

	it('registers both body widgets in customComponents.js', () => {
		const source = fs.readFileSync(path.resolve(__dirname, '../../src/customComponents.js'), 'utf8')
		for (const name of ['DataEntityRelations', 'DataEntityAttributes']) {
			expect(source).toMatch(new RegExp(`import ${name} from './views/architecture/${name}\\.vue'`))
			expect(source).toMatch(new RegExp(`^\\t${name},$`, 'm'))
		}
	})
})
