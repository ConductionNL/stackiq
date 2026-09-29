/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Planned maintenance and the supplier roadmap: which windows an organisation
 * sees, the roadmap events, the pages as the app builds them (manifest.d
 * merged the way src/main.js merges it), and the seeded maintenance windows
 * validated against the real maintenanceWindow schema in the register.
 *
 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md
 */

import addFormats from 'ajv-formats'
import Ajv2020 from 'ajv/dist/2020.js'
import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it, vi } from 'vitest'
import register from '../../lib/Settings/softwarecatalogus_register.json'
import mock from '../../lib/Settings/stackiq_mock_register.json'
import manifestSchema from '../../node_modules/@conduction/nextcloud-vue/src/schemas/app-manifest-v2.schema.json'
import { buildManifest } from '../../node_modules/@conduction/nextcloud-vue/src/utils/buildManifest.js'
import base from '../../src/manifest.json'
import menuLayout from '../../src/menu-layout.json'
import {
	loadUpcomingMaintenance,
	roadmapEvents,
	upcomingMaintenance,
} from '../../src/utils/maintenance.js'

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
const schema = register.components.schemas.maintenanceWindow
const now = new Date('2026-10-01T09:00:00Z')

const windows = [
	{
		id: 'w1',
		module: 'x',
		status: 'planned',
		startsAt: '2026-10-03T06:00:00Z',
		endsAt: '2026-10-03T10:00:00Z',
		impact: 'unavailable',
	},
	{
		id: 'w2',
		module: { id: 'x' },
		status: 'planned',
		startsAt: '2026-10-02T06:00:00Z',
		endsAt: '2026-10-02T07:00:00Z',
	},
	{
		id: 'w3',
		module: 'y',
		status: 'planned',
		startsAt: '2026-10-03T06:00:00Z',
		endsAt: '2026-10-03T07:00:00Z',
	},
	{
		id: 'w4',
		module: 'x',
		status: 'cancelled',
		startsAt: '2026-10-03T06:00:00Z',
		endsAt: '2026-10-03T07:00:00Z',
	},
	{
		id: 'w5',
		module: 'x',
		status: 'planned',
		startsAt: '2026-12-01T06:00:00Z',
		endsAt: '2026-12-01T07:00:00Z',
	},
	{
		id: 'w6',
		module: 'x',
		status: 'planned',
		startsAt: '2026-09-01T06:00:00Z',
		endsAt: '2026-09-01T07:00:00Z',
	},
]

/**
 * A validator built from the real maintenanceWindow properties. A relation is
 * checked as an object or an id string, the way OpenRegister accepts it.
 *
 * @return {Function} The compiled Ajv validator.
 */
function compileWindow() {
	const ajv = new Ajv2020({ allErrors: true, strict: false })
	addFormats(ajv)
	const properties = Object.fromEntries(
		Object.entries(schema.properties).map(([key, prop]) => [
			key,
			prop.$ref
				? { type: ['object', 'string'] }
				: { type: prop.type, enum: prop.enum, format: prop.format },
		]),
	)
	return ajv.compile({ type: 'object', required: schema.required, properties })
}

describe('upcoming maintenance', () => {
	it('lists the planned windows of the next 30 days on the products in use, earliest first', () => {
		expect(upcomingMaintenance(windows, ['x'], now).map((w) => w.id)).toEqual([
			'w2',
			'w1',
		])
	})

	it('reads the usages of the organisation and then the windows on their products', async () => {
		const fetchList = vi.fn(async (type) =>
			type === 'usage'
				? [{ module: 'x' }, { module: { id: 'x' } }, { module: null }]
				: windows,
		)
		const result = await loadUpcomingMaintenance('org-1', fetchList, now)
		expect(fetchList).toHaveBeenNthCalledWith(
			1,
			'usage',
			expect.objectContaining({ consumer: 'org-1' }),
		)
		expect(fetchList).toHaveBeenNthCalledWith(
			2,
			'maintenanceWindow',
			expect.objectContaining({ module: ['x'], status: 'planned' }),
		)
		expect(result.map((w) => w.id)).toEqual(['w2', 'w1'])
	})

	it('reads nothing without an organisation or usages', async () => {
		const fetchList = vi.fn(async () => [])
		expect(await loadUpcomingMaintenance(null, fetchList, now)).toEqual([])
		expect(await loadUpcomingMaintenance('org-1', fetchList, now)).toEqual([])
		expect(fetchList).toHaveBeenCalledTimes(1)
	})
})

describe('the roadmap', () => {
	it('places each version on its go-live date, planned ones marked planned, undated ones left out', () => {
		const events = roadmapEvents([
			{
				id: 'v3',
				version: '3.0',
				status: 'in development',
				dateInUse: '2027-03-01',
			},
			{ id: 'v2', version: '2.1', status: 'in use', dateInUse: '2026-05-01' },
			{
				id: 'v4',
				version: '4.0',
				status: 'in development',
				dateInDevelopment: '2026-09-01',
			},
			{ id: 'v1', version: '1.0', status: 'withdrawn' },
		])
		expect(events.map((e) => [e.title, e.start, e.kind])).toEqual([
			['3.0', '2027-03-01', 'planned'],
			['2.1', '2026-05-01', 'released'],
			['4.0', '2026-09-01', 'planned'],
		])
	})
})

describe('the pages', () => {
	it('builds a manifest the v2 schema accepts', () => {
		const ajv = new Ajv2020({ allErrors: true, strict: false })
		addFormats(ajv)
		const validate = ajv.compile(manifestSchema)
		expect(validate(merged), JSON.stringify(validate.errors)).toBe(true)
	})

	it('lists the planned maintenance on the application page, with Announce maintenance', () => {
		const list = widget('ModuleDetail', 'md-maintenance')
		expect(list.content).toMatchObject({
			schema: 'maintenanceWindow',
			filter: { module: '@objectId' },
			sort: { field: 'startsAt', dir: 'asc' },
			addLabel: 'Announce maintenance',
		})
		for (const column of list.content.columns) {
			expect(schema.properties, column.key).toHaveProperty(column.key)
		}
		for (const field of list.content.formIncludeFields) {
			expect(schema.properties, field).toHaveProperty(field)
		}
		expect(page('ModuleDetail').config.layout.map((l) => l.widgetId)).toContain(
			'md-maintenance',
		)
	})

	it('shows the roadmap on the application page', () => {
		const body = page('ModuleDetail').config.bodyWidgets.find(
			(w) => w.id === 'md-roadmap',
		)
		expect(body).toMatchObject({
			component: 'ProductRoadmap',
			props: { objectId: '@objectId' },
		})
		const registry = fs.readFileSync(
			path.resolve(__dirname, '../../src/customComponents.js'),
			'utf8',
		)
		expect(registry).toMatch(/\bProductRoadmap,/)
	})

	it('shows planned maintenance on the dashboard', () => {
		const dashboard = page('Dashboard').config
		const w = dashboard.widgets.find((x) => x.id === 'upcoming-maintenance')
		expect(w.type).toBe('upcoming-maintenance')
		expect(dashboard.layout.map((l) => l.widgetId)).toContain(
			'upcoming-maintenance',
		)
		const main = fs.readFileSync(
			path.resolve(__dirname, '../../src/main.js'),
			'utf8',
		)
		expect(main).toMatch(
			/registerDashboardWidget\('upcoming-maintenance',\s*\{\s*renderer: UpcomingMaintenanceWidget/,
		)
	})

	it('filters the module versions on planned releases, a real status value', () => {
		const index = page('Moduleversies').config
		const planned = index.quickFilters.find(
			(q) => q.label === 'Planned releases',
		)
		expect(
			register.components.schemas.moduleVersion.properties.status.enum,
		).toContain(planned.filter.status)
		expect(index.columns).toContain('dateInDevelopment')
	})
})

describe('the maintenanceWindow schema', () => {
	it('offers start, complete and cancel on its own status values', () => {
		const lifecycle = schema.configuration['x-openregister-lifecycle']
		const states = schema.properties.status.enum
		for (const transition of Object.values(lifecycle.transitions)) {
			expect(states).toContain(transition.to)
			transition.from.forEach((from) => expect(states).toContain(from))
		}
		expect(states).toContain(lifecycle.initial)
	})

	it('notifies the owners it resolved, on a field the schema declares', () => {
		for (const rule of Object.values(schema['x-openregister-notifications'])) {
			expect(rule.recipients).toEqual([
				{ kind: 'relation', relation: 'notifyUserIds' },
			])
		}
		expect(schema.properties.notifyUserIds.type).toBe('array')
		const announced =
			schema['x-openregister-notifications']['maintenance-announced'].trigger
		expect(schema.properties).toHaveProperty(announced.condition.field)
	})

	it('has seeded windows in both registers that the schema accepts', () => {
		const validate = compileWindow()
		const seeds = mock.components.objects.filter(
			(o) => o['@self']?.schema === 'maintenanceWindow',
		)
		expect(new Set(seeds.map((s) => s['@self'].register))).toEqual(
			new Set(['stackiq', 'vng-gemma']),
		)
		for (const seed of seeds) {
			expect(
				validate(seed),
				`${seed['@self'].slug}: ${JSON.stringify(validate.errors)}`,
			).toBe(true)
		}
	})
})
