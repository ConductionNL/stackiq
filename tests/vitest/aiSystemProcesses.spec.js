/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * An AI system names the business processes it supports, its page shows them,
 * and a business process page lists the AI systems that support it
 * (capability-rows-the-code-does-not-yet-meet, task 5).
 *
 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/ai-system-inventory/spec.md#requirement-an-ai-system-names-the-business-processes-it-supports-req-clr-006
 */
import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'
import { buildManifest } from '../../node_modules/@conduction/nextcloud-vue/src/utils/buildManifest.js'
import base from '../../src/manifest.json'
import menuLayout from '../../src/menu-layout.json'

const root = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(root, file), 'utf8'))

const monolith = read('lib/Settings/softwarecatalogus_register.json')
const fragment = read('lib/Settings/register.d/ai-system-processes.json')
const processFragment = read('lib/Settings/register.d/architecture-process-mapping.json')

const manifestDir = path.join(root, 'src/manifest.d')
const merged = buildManifest(
	base,
	fs.readdirSync(manifestDir).filter((f) => f.endsWith('.json')).sort()
		.map((f) => JSON.parse(fs.readFileSync(path.join(manifestDir, f), 'utf8'))),
	menuLayout,
)
const page = (id) => merged.pages.find((p) => p.id === id)

/**
 * Compare two dotted versions.
 *
 * @param {string} a The first version.
 * @param {string} b The second version.
 * @return {number} Negative, zero or positive.
 */
function compareVersions(a, b) {
	const pa = a.split('.').map(Number)
	const pb = b.split('.').map(Number)
	for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
		const diff = (pa[i] ?? 0) - (pb[i] ?? 0)
		if (diff !== 0) {
			return diff
		}
	}
	return 0
}

describe('the AI system schema', () => {
	const schema = fragment.components.schemas.aiSystem

	it('holds a list of related business processes, labelled by name', () => {
		expect(schema.properties.processes).toMatchObject({
			type: 'array',
			title: 'Business processes',
			items: {
				type: 'object',
				objectConfiguration: { handling: 'related-object' },
				$ref: '#/components/schemas/process',
				'x-label-field': 'name',
			},
		})
	})

	it('points at a process schema in the register that holds the AI systems', () => {
		expect(processFragment.components.schemas.process).toBeDefined()
		const holding = (slug) => Object.entries(monolith.components.registers)
			.filter(([, register]) => (register.schemas || []).includes(slug))
			.map(([name]) => name)
		expect(holding('aiSystem')).toEqual(['stackiq'])
		expect(processFragment.components.registers.stackiq.schemas).toContain('process')
	})

	it('raises the schema version so the import updates it', () => {
		const current = monolith.components.schemas.aiSystem.version
		expect(compareVersions(schema.version, current)).toBeGreaterThan(0)
		expect(schema.slug).toBe('aiSystem')
	})

	it('keeps the properties the monolith already has', () => {
		expect(Object.keys(schema.properties)).toEqual(['processes'])
	})
})

describe('the pages', () => {
	it('shows the processes on the AI system page', () => {
		const data = page('AiSystemDetail').config.widgets.find((w) => w.id === 'ai-data')
		expect(data.content.include).toContain('processes')
	})

	it('lists the supporting AI systems on the business process page', () => {
		const detail = page('ProcesDetail')
		const list = detail.config.widgets.find((w) => w.id === 'process-ai-systems')
		expect(list).toMatchObject({
			type: 'object-list',
			content: {
				schema: 'aiSystem',
				filter: { processes: '@objectId' },
				rowRoute: 'AiSystemDetail',
			},
		})
		expect(list.content.columns).toContain('name')
		expect(detail.config.layout.map((l) => l.widgetId)).toContain('process-ai-systems')
		expect(page(list.content.rowRoute)).toBeDefined()
	})
})
