/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Applications in use: the usage pages as the app builds them (manifest.d
 * merged the way src/main.js merges it), the add action on the application
 * page and the usage list on the organisation page, and the seeded usages
 * validated against the real usage schema.
 *
 * @spec openspec/specs/application-usage-pages/spec.md
 */

import addFormats from 'ajv-formats'
import Ajv2020 from 'ajv/dist/2020.js'
import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'
import register from '../../lib/Settings/softwarecatalogus_register.json'
import mock from '../../lib/Settings/stackiq_mock_register.json'
import manifestSchema from '../../node_modules/@conduction/nextcloud-vue/src/schemas/app-manifest-v2.schema.json'
import { buildManifest } from '../../node_modules/@conduction/nextcloud-vue/src/utils/buildManifest.js'
import base from '../../src/manifest.json'
import menuLayout from '../../src/menu-layout.json'

const dir = path.resolve(__dirname, '../../src/manifest.d')
const merged = buildManifest(
	base,
	fs
		.readdirSync(dir)
		.filter((f) => f.endsWith('.json'))
		.sort()
		.map((f) => JSON.parse(fs.readFileSync(path.join(dir, f), 'utf8'))),
	menuLayout,
)
const page = (id) => merged.pages.find((p) => p.id === id)
function widget(pageId, widgetId) {
	return page(pageId).config.widgets.find((w) => w.id === widgetId)
}

const usage = register.components.schemas.usage

/**
 * A validator built from the real usage properties. A relation is checked as
 * an object or an id string, the way OpenRegister accepts it.
 *
 * @return {Function} The compiled Ajv validator.
 */
function compileUsage() {
	const ajv = new Ajv2020({ allErrors: true, strict: false })
	addFormats(ajv)
	const properties = Object.fromEntries(
		Object.entries(usage.properties).map(([key, prop]) => [
			key,
			prop.$ref
				? { type: ['object', 'string'] }
				: { type: prop.type, enum: prop.enum, format: prop.format },
		]),
	)
	return ajv.compile({ type: 'object', properties })
}

describe('the usage pages', () => {
	it('builds a manifest the v2 schema accepts', () => {
		const ajv = new Ajv2020({ allErrors: true, strict: false })
		addFormats(ajv)
		const validate = ajv.compile(manifestSchema)
		expect(validate(merged), JSON.stringify(validate.errors)).toBe(true)
	})

	it('lists Applications in use under Applications', () => {
		const modules = merged.menu.find((m) => m.id === 'Modules')
		const child = modules.children.find((c) => c.id === 'Gebruik')
		expect(child).toMatchObject({
			label: 'Applications in use',
			route: 'Gebruik',
		})
		expect(merged.menu.find((m) => m.id === 'Gebruik')).toBeUndefined()
	})

	it('lists usages with version, status and both owners, filtered on real status values', () => {
		const index = page('Gebruik')
		expect(index.route).toBe('/gebruik')
		expect(index.config.schema).toBe('usage')
		expect(index.config.columns).toEqual(
			expect.arrayContaining([
				'module',
				'moduleVersion',
				'status',
				'businessOwner',
				'technicalOwner',
			]),
		)
		for (const column of index.config.columns) {
			expect(usage.properties, column).toHaveProperty(column)
		}
		const statuses = index.config.quickFilters
			.map((q) => q.filter.status)
			.filter(Boolean)
		expect(statuses).toEqual(usage.properties.status.enum)
	})

	it('shows version, status and owners on the detail page and offers the lifecycle', () => {
		const detail = page('GebruikDetail')
		expect(detail.route).toBe('/gebruik/:id')
		expect(detail.config.lifecycleActions).toEqual({ field: 'status' })
		const include = widget('GebruikDetail', 'gb-data').content.include
		expect(include).toEqual(
			expect.arrayContaining([
				'module',
				'moduleVersion',
				'status',
				'businessOwner',
				'technicalOwner',
			]),
		)
		for (const field of include) {
			expect(usage.properties, field).toHaveProperty(field)
		}
		const layoutIds = detail.config.layout.map((l) => l.widgetId)
		expect(layoutIds.sort()).toEqual(
			detail.config.widgets.map((w) => w.id).sort(),
		)
	})
})

describe('adding an application to the landscape', () => {
	it('offers Add to our landscape on the application page, with the application filled in', () => {
		const list = widget('ModuleDetail', 'md-usages')
		expect(list.content.allowCreate).not.toBe(false)
		expect(list.content.addLabel).toBe('Add to our landscape')
		expect(list.content.filter).toEqual({ module: '@objectId' })
		expect(list.content.rowRoute).toBe('GebruikDetail')
		expect(list.content.formIncludeFields).toEqual([
			'consumer',
			'moduleVersion',
			'status',
			'businessOwner',
			'technicalOwner',
		])
		for (const field of list.content.formIncludeFields) {
			expect(usage.properties, field).toHaveProperty(field)
		}
	})

	it('offers only versions of the application in the version picker', () => {
		expect(usage.properties.moduleVersion['x-relation-filter']).toEqual({
			module: '@object.module',
		})
	})

	it('lists the applications an organisation uses on its page', () => {
		const list = widget('OrganisatieDetail', 'org-usages')
		expect(list.content).toMatchObject({
			schema: 'usage',
			filter: { consumer: '@objectId' },
			rowRoute: 'GebruikDetail',
		})
		expect(
			page('OrganisatieDetail').config.layout.map((l) => l.widgetId),
		).toContain('org-usages')
	})
})

describe('the seeded usages', () => {
	const validate = compileUsage()
	const seeds = [
		...register.components.objects,
		...mock.components.objects,
	].filter((o) => o['@self'] && o['@self'].schema === 'usage')

	it('are valid against the usage schema', () => {
		expect(seeds.length).toBeGreaterThan(0)
		for (const seed of seeds) {
			expect(
				validate(seed),
				`${seed['@self'].slug}: ${JSON.stringify(validate.errors)}`,
			).toBe(true)
		}
	})

	it('include usages in production with both owners and a planned one', () => {
		const withOwners = seeds.filter(
			(s) => s.businessOwner !== undefined && s.technicalOwner !== undefined,
		)
		expect(withOwners.length).toBeGreaterThan(0)
		expect(seeds.map((s) => s.status)).toEqual(
			expect.arrayContaining(['In production', 'Planned']),
		)
	})
})
