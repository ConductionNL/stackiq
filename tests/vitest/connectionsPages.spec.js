/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Connections pages as the app builds them: the manifest.d fragment merged
 * into the base manifest the way src/main.js does, valid against the v2 schema,
 * reachable from the Applications menu, and linked from the application page.
 *
 * @spec openspec/specs/catalogue-connection-pages/spec.md#requirement-req-ccp-001-a-user-can-browse-every-connection-they-may-read-in-one-list
 */

import addFormats from 'ajv-formats'
import Ajv2020 from 'ajv/dist/2020.js'
import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'
import register from '../../lib/Settings/softwarecatalogus_register.json'
import manifestSchema from '../../node_modules/@conduction/nextcloud-vue/src/schemas/app-manifest-v2.schema.json'
import { buildManifest } from '../../node_modules/@conduction/nextcloud-vue/src/utils/buildManifest.js'
import base from '../../src/manifest.json'
import menuLayout from '../../src/menu-layout.json'

const dir = path.resolve(__dirname, '../../src/manifest.d')
const fragments = fs
	.readdirSync(dir)
	.filter((f) => f.endsWith('.json'))
	.sort()
	.map((f) => JSON.parse(fs.readFileSync(path.join(dir, f), 'utf8')))
const merged = buildManifest(base, fragments, menuLayout)
const page = (id) => merged.pages.find((p) => p.id === id)

describe('the Connections pages', () => {
	it('the merged manifest is valid against the v2 schema', () => {
		const ajv = new Ajv2020({ allErrors: true, strict: false })
		addFormats(ajv)
		const validate = ajv.compile(manifestSchema)
		const valid = validate(merged)
		expect(validate.errors ?? []).toEqual([])
		expect(valid).toBe(true)
	})

	it('lists connections with type and status, filterable, and opens each one', () => {
		const index = page('Koppelingen')
		expect(index.route).toBe('/koppelingen')
		expect(index.config.schema).toBe('connection')
		expect(index.config.columns).toEqual(
			expect.arrayContaining(['type', 'status', 'moduleA', 'moduleB']),
		)
		expect(index.config.filterMenu).toBe(true)
		const detail = page('KoppelingDetail')
		expect(detail.route).toBe('/koppelingen/:id')
		expect(detail.config.lifecycleActions).toEqual({ field: 'status' })
	})

	it('filters on columns the schema lets the list count', () => {
		const properties = register.components.schemas.connection.properties
		for (const key of ['type', 'status', 'dataExchangeDirection']) {
			expect(properties[key].facetable, key).toBe(true)
		}
		const quick = page('Koppelingen')
			.config.quickFilters.map((q) => q.filter.status)
			.filter(Boolean)
		expect(quick.every((value) => properties.status.enum.includes(value))).toBe(
			true,
		)
	})

	it('is reachable from the Applications menu, without a new top-level entry', () => {
		const applications = merged.menu.find((m) => m.id === 'Modules')
		expect(applications.children.map((c) => c.route)).toContain('Koppelingen')
		expect(merged.menu.find((m) => m.id === 'Koppelingen')).toBeUndefined()
	})

	it('the application page lists the connections that start and end there, each opening the connection', () => {
		const widgets = page('ModuleDetail').config.widgets
		const out = widgets.find((w) => w.id === 'md-connections-out')
		const inbound = widgets.find((w) => w.id === 'md-connections-in')
		expect(out.content.filter).toEqual({ moduleA: '@objectId' })
		expect(inbound.content.filter).toEqual({ moduleB: '@objectId' })
		for (const widget of [out, inbound]) {
			expect(widget.content.rowRoute).toBe('KoppelingDetail')
		}
		const layoutIds = page('ModuleDetail').config.layout.map((l) => l.widgetId)
		expect(layoutIds).toEqual(
			expect.arrayContaining(['md-connections-out', 'md-connections-in']),
		)
	})
})
