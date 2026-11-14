/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Business processes: the pages as the app builds them (manifest.d merged the
 * way src/main.js merges it), their columns and filters against the real
 * process and processStep schemas, and the step list on the usage page.
 *
 * @spec openspec/changes/architecture-process-mapping/specs/business-process-mapping/spec.md
 */

import addFormats from 'ajv-formats'
import Ajv2020 from 'ajv/dist/2020.js'
import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'
import fragment from '../../lib/Settings/register.d/architecture-process-mapping.json'
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
const withoutProcesses = buildManifest(
	base,
	fragments.filter((f) => !(f.pages || []).some((p) => p.id === 'Processen')),
	menuLayout,
)
// The registry imports the components, which need a browser; read its source instead.
const registry = fs.readFileSync(path.resolve(__dirname, '../../src/customComponents.js'), 'utf8')
const page = (id) => merged.pages.find((p) => p.id === id)
const widget = (pageId, widgetId) => page(pageId).config.widgets.find((w) => w.id === widgetId)
const columnKey = (column) => (typeof column === 'string' ? column : column.key)
const props = (slug) => fragment.components.schemas[slug].properties

describe('the business process pages', () => {
	it('builds a manifest the v2 schema accepts', () => {
		const ajv = new Ajv2020({ allErrors: true, strict: false })
		addFormats(ajv)
		const validate = ajv.compile(manifestSchema)
		expect(validate(merged), JSON.stringify(validate.errors)).toBe(true)
	})

	it('has the three pages on the routes of the design', () => {
		expect(page('Processen')).toMatchObject({ type: 'index', route: '/processen' })
		expect(page('ProcesDetail')).toMatchObject({ type: 'detail', route: '/processen/:id' })
		expect(page('ProcesStapDetail')).toMatchObject({ type: 'detail', route: '/processtappen/:id' })
		for (const id of ['Processen', 'ProcesDetail']) {
			expect(page(id).config.schema).toBe('process')
		}
		expect(page('ProcesStapDetail').config.schema).toBe('processStep')
	})

	it('puts Business processes under a group, without a new top-level entry', () => {
		expect(merged.menu.length).toBe(withoutProcesses.menu.length)
		const parent = merged.menu.find((item) => (item.children || []).some((child) => child.id === 'Processen'))
		expect(parent?.id).toBe('Modules')
	})

	it('lists processes on real properties and filters on real status values', () => {
		const config = page('Processen').config
		for (const column of config.columns) {
			expect(props('process'), columnKey(column)).toHaveProperty(columnKey(column))
		}
		for (const quick of config.quickFilters) {
			if (quick.filter.status) {
				expect(props('process').status.enum).toContain(quick.filter.status)
			}
		}
	})

	it('shows the steps of a process sorted on position, with the lifecycle and the flow', () => {
		const config = page('ProcesDetail').config
		expect(config.lifecycleActions).toEqual({ field: 'status' })
		const steps = widget('ProcesDetail', 'process-steps').content
		expect(steps.filter).toEqual({ process: '@objectId' })
		expect(Object.keys(steps.sort)[0]).toBe('position')
		expect(steps.rowRoute).toBe('ProcesStapDetail')
		for (const column of steps.columns) {
			expect(props('processStep')).toHaveProperty(columnKey(column))
		}
		for (const field of steps.formIncludeFields) {
			expect(props('processStep')).toHaveProperty(field)
		}
		expect(config.bodyWidgets.map((w) => w.component)).toEqual(['ProcessStepFlow'])
		expect(registry).toMatch(/^\tProcessStepFlow,$/m)
	})

	it('shows every field of a step and its supporting applications', () => {
		const config = page('ProcesStapDetail').config
		for (const field of config.widgets[0].content.include) {
			expect(props('processStep')).toHaveProperty(field)
		}
		expect(config.bodyWidgets.map((w) => w.component)).toEqual(['ProcessStepUsages'])
		expect(registry).toMatch(/^\tProcessStepUsages,$/m)
	})

	it('lists the steps an application supports on the usage page', () => {
		const list = widget('GebruikDetail', 'usage-process-steps')
		expect(list.title).toBe('Process steps this application supports')
		expect(list.content).toMatchObject({ schema: 'processStep', filter: { usages: '@objectId' }, rowRoute: 'ProcesStapDetail' })
		for (const column of list.content.columns) {
			expect(props('processStep')).toHaveProperty(columnKey(column))
		}
		const layout = page('GebruikDetail').config.layout.map((l) => l.widgetId)
		expect(layout).toContain('usage-process-steps')
	})
})
