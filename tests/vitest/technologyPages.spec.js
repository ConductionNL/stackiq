/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Technology pages as the app builds them: the manifest.d fragment merged
 * into the base manifest the way src/main.js does, valid against the v2
 * schema, reachable from the Applications menu, with the relations shown both
 * ways and the Runs on panel on the usage page (operations-technology-components
 * tasks 2 and 3).
 *
 * @spec openspec/changes/operations-technology-components/specs/technology-components/spec.md#requirement-req-tco-002-the-technology-pages-shall-list-components-and-show-their-relations-both-ways
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
import icons from '../../src/icons.js'
import base from '../../src/manifest.json'
import menuLayout from '../../src/menu-layout.json'
import { exposure } from '../../src/utils/technologyLifecycle.js'

const dir = path.resolve(__dirname, '../../src/manifest.d')
const fragments = fs
	.readdirSync(dir)
	.filter((f) => f.endsWith('.json'))
	.sort()
	.map((f) => JSON.parse(fs.readFileSync(path.join(dir, f), 'utf8')))
const merged = buildManifest(base, fragments, menuLayout)
const page = (id) => merged.pages.find((p) => p.id === id)
const schema = register.components.schemas.technologyComponent

describe('the Technology pages', () => {
	it('the merged manifest is valid against the v2 schema', () => {
		const ajv = new Ajv2020({ allErrors: true, strict: false })
		addFormats(ajv)
		const validate = ajv.compile(manifestSchema)
		const valid = validate(merged)
		expect(validate.errors ?? []).toEqual([])
		expect(valid).toBe(true)
	})

	it('is a child of Applications and adds no top-level entry', () => {
		const baseTop = buildManifest(base, fragments.filter((f) => !String(f._note).startsWith('operations-technology-components')), menuLayout).menu.length
		expect(merged.menu.length).toBe(baseTop)
		const applications = merged.menu.find((m) => m.id === 'Modules')
		expect(applications.children.map((c) => c.route)).toContain('Technologie')
		expect(merged.menu.find((m) => m.id === 'Technologie')).toBeUndefined()
	})

	it('lists components with name, class, organisation, status and end of support', () => {
		const index = page('Technologie')
		expect(index.route).toBe('/technologie')
		expect(index.type).toBe('index')
		expect(index.config.schema).toBe('technologyComponent')
		expect(index.config.columns.map((c) => (typeof c === 'string' ? c : c.key))).toEqual(
			['name', 'class', 'organization', 'status', 'endOfSupport'],
		)
		expect(index.config.defaultSort).toEqual({ field: 'endOfSupport', direction: 'asc' })
	})

	it('quick filters split the classes into hardware and platform software, on values the schema holds', () => {
		const quick = page('Technologie').config.quickFilters
		const hardware = quick.find((q) => q.label === 'Hardware').filter.class
		const platform = quick.find((q) => q.label === 'Platform software').filter.class
		expect(hardware).toEqual(['Physical server', 'Virtual machine', 'Network device', 'Storage', 'End-user device'])
		expect(platform).toEqual(['Container platform', 'Database', 'Middleware', 'Runtime or framework', 'Operating system'])
		expect(hardware.filter((value) => platform.includes(value))).toEqual([])
		for (const value of [...hardware, ...platform]) {
			expect(schema.properties.class.enum).toContain(value)
		}
		for (const q of quick.filter((q) => q.filter.status)) {
			expect(schema.properties.status.enum).toContain(q.filter.status)
		}
		expect(schema.properties.class.facetable).toBe(true)
	})

	it('the detail page shows what runs on the component and the applications in use on it, each opening its own page', () => {
		const detail = page('TechnologieDetail')
		expect(detail.route).toBe('/technologie/:id')
		expect(detail.config.lifecycleActions).toEqual({ field: 'status' })
		const widgets = detail.config.widgets
		const runsHere = widgets.find((w) => w.id === 'tc-runs-here')
		const usages = widgets.find((w) => w.id === 'tc-usages')
		expect(runsHere.content.schema).toBe('technologyComponent')
		expect(runsHere.content.filter).toEqual({ runsOn: '@objectId' })
		expect(runsHere.content.rowRoute).toBe('TechnologieDetail')
		expect(usages.content.schema).toBe('usage')
		expect(usages.content.filter).toEqual({ runsOn: '@objectId' })
		expect(usages.content.rowRoute).toBe('GebruikDetail')
		const data = widgets.find((w) => w.id === 'tc-data').content.include
		expect(data).toEqual(expect.arrayContaining(['class', 'organization', 'runsOn', 'endOfSupport', 'eolSource']))
		for (const key of [...data, ...widgets.find((w) => w.id === 'tc-hardware').content.include]) {
			expect(schema.properties[key], key).toBeDefined()
		}
		expect(detail.config.layout.map((l) => l.widgetId).sort()).toEqual(widgets.map((w) => w.id).sort())
	})

	it('phase out moves a component that is in use to be phased out', () => {
		const transition = schema.configuration['x-openregister-lifecycle'].transitions['phase-out']
		expect(transition.from).toEqual(['In use'])
		expect(transition.to).toBe('To be phased out')
	})

	it('the usage page carries the Runs on panel for the usage it shows', () => {
		const panel = page('GebruikDetail').config.bodyWidgets.find((w) => w.component === 'UsageRunsOnPanel')
		expect(panel.props).toEqual({ objectId: '@objectId' })
	})

	it('every icon the pages name is registered', () => {
		const names = new Set()
		const walk = (value) => {
			if (Array.isArray(value)) {
				value.forEach(walk)
			} else if (value && typeof value === 'object') {
				if (typeof value.icon === 'string') {
					names.add(value.icon)
				}
				Object.values(value).forEach(walk)
			}
		}
		walk([page('Technologie'), page('TechnologieDetail'), merged.menu.find((m) => m.id === 'Modules').children.find((c) => c.id === 'Technologie')])
		for (const name of names) {
			expect(icons[name], name).toBeDefined()
		}
	})
})

describe('the seeded technology components', () => {
	const seeded = mock.components.objects.filter(
		(o) => o['@self'].register === 'stackiq' && o['@self'].schema === 'technologyComponent',
	)
	const bySlug = Object.fromEntries(seeded.map((o) => [o['@self'].slug, o]))

	it('are accepted by the real technologyComponent schema', () => {
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
		const validate = ajv.compile({ type: 'object', required: schema.required, properties, additionalProperties: false })
		expect(seeded.map((o) => o.name)).toEqual(['Host 01', 'App server 01', 'Database 01'])
		for (const o of seeded) {
			const { '@self': self, ...fields } = o
			expect(validate(fields), `${self.slug}: ${JSON.stringify(validate.errors)}`).toBe(true)
		}
	})

	it('hang together: the database on the virtual machine on the host, every reference seeded', () => {
		expect(bySlug['technology-vm-1'].runsOn).toEqual(['@ref:technology-host-1'])
		expect(bySlug['technology-db-1'].runsOn).toEqual(['@ref:technology-vm-1'])
		const slugs = new Set(mock.components.objects.map((o) => o['@self'].slug))
		for (const o of seeded) {
			for (const value of [o.organization, o.software, ...(o.runsOn ?? [])].filter(Boolean)) {
				expect(slugs.has(value.replace('@ref:', '')), value).toBe(true)
			}
		}
	})

	it('make the demo usage show Database 01 past its end of support', () => {
		const usages = mock.components.objects.filter((o) => o['@self'].slug === 'usage-usage-3-3')
		expect(usages.length).toBeGreaterThan(0)
		const byRef = Object.fromEntries(
			seeded.map((o) => ['@ref:' + o['@self'].slug, { ...o, runsOn: o.runsOn }]),
		)
		for (const usage of usages) {
			expect(usage.runsOn).toEqual(['@ref:technology-db-1', '@ref:technology-vm-1'])
			const result = exposure(usage.runsOn, byRef, '2026-11-14')
			expect(result.state).toBe('ended')
			expect(result.components[0]).toMatchObject({ name: 'Database 01', state: 'ended' })
		}
	})
})
