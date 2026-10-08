/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * AI systems: the evidence checklist and the missing-FRIA rule, the pages as
 * the app builds them (manifest.d merged the way src/main.js merges it), and
 * the seeded AI systems validated against the real aiSystem schema in the
 * register.
 *
 * @spec openspec/specs/ai-system-inventory/spec.md
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
import {
	EVIDENCE_TAGS,
	evidenceChecklist,
	friaMissing,
	friaStatus,
} from '../../src/utils/aiAct.js'

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
const schema = register.components.schemas.aiSystem

/**
 * A validator built from the real aiSystem properties. A relation is checked
 * as an object or an id string, the way OpenRegister accepts it.
 *
 * @return {Function} The compiled Ajv validator.
 */
function compileAiSystem() {
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
	return ajv.compile({
		type: 'object',
		required: schema.required,
		properties,
	})
}

describe('the missing FRIA rule', () => {
	it('flags a high-risk system without a FRIA reference', () => {
		expect(friaMissing({ aiActRiskCategory: 'high risk' })).toBe(true)
		expect(
			friaMissing({ aiActRiskCategory: 'high risk', friaDocumentRef: '  ' }),
		).toBe(true)
	})

	it('does not flag a high-risk system with a FRIA, or a system of another category', () => {
		expect(
			friaMissing({
				aiActRiskCategory: 'high risk',
				friaDocumentRef: '/AI/fria.pdf',
			}),
		).toBe(false)
		expect(friaMissing({ aiActRiskCategory: 'minimal risk' })).toBe(false)
		expect(friaMissing({})).toBe(false)
	})

	it('reads FRIA missing in the list only for a flagged system', () => {
		expect(friaStatus('', { aiActRiskCategory: 'high risk' })).toBe(
			'FRIA missing',
		)
		expect(
			friaStatus('/AI/fria.pdf', {
				aiActRiskCategory: 'high risk',
				friaDocumentRef: '/AI/fria.pdf',
			}),
		).toBe('')
		expect(friaStatus('', { aiActRiskCategory: 'limited risk' })).toBe('')
	})
})

describe('the evidence checklist', () => {
	it('marks each of the four tags present or missing from the files', () => {
		const list = evidenceChecklist([
			{ name: 'tech.pdf', labels: ['Technical documentation'] },
			{ name: 'untagged.pdf', labels: [] },
			{ name: 'x.pdf' },
		])
		expect(list.map((r) => r.tag)).toEqual(EVIDENCE_TAGS)
		expect(list.find((r) => r.tag === 'FRIA').present).toBe(false)
		expect(list.find((r) => r.tag === 'Technical documentation').present).toBe(
			true,
		)
		expect(list.find((r) => r.tag === 'Technical documentation').files).toEqual([
			'tech.pdf',
		])
	})

	it('uses the same four tags the schema allows on files', () => {
		expect(schema.configuration.allowedTags).toEqual(EVIDENCE_TAGS)
	})

	it('reads an empty or broken response as nothing present', () => {
		expect(evidenceChecklist(undefined).every((r) => !r.present)).toBe(true)
	})
})

describe('the AI systems pages', () => {
	it('the merged manifest is valid against the v2 schema', () => {
		const ajv = new Ajv2020({ allErrors: true, strict: false })
		addFormats(ajv)
		const validate = ajv.compile(manifestSchema)
		validate(merged)
		expect(validate.errors ?? []).toEqual([])
	})

	it('lists AI systems with kind and risk category, filterable, under Applications', () => {
		const index = page('AiSystems')
		expect(index.route).toBe('/ai-systems')
		expect(index.config.schema).toBe('aiSystem')
		expect(index.config.filterMenu).toBe(true)
		const keys = index.config.columns.map((c) =>
			typeof c === 'string' ? c : c.key,
		)
		expect(keys).toEqual(
			expect.arrayContaining(['name', 'kind', 'module', 'aiActRiskCategory']),
		)
		const modules = merged.menu.find((m) => m.id === 'Modules')
		expect(modules.children.map((c) => c.route)).toContain('AiSystems')
	})

	it('filters on high risk, and on high risk without a FRIA', () => {
		const quick = page('AiSystems').config.quickFilters
		const high = quick.find((q) => q.label === 'High risk')
		expect(high.filter).toEqual({ aiActRiskCategory: 'high risk' })
		const noFria = quick.find((q) => q.label === 'High risk without FRIA')
		expect(noFria.filter).toEqual({
			aiActRiskCategory: 'high risk',
			friaDocumentRef: 'IS NULL',
		})
		for (const q of quick) {
			for (const [key, value] of Object.entries(q.filter)) {
				expect(schema.properties, key).toHaveProperty(key)
				if (schema.properties[key].enum && value !== 'IS NULL') {
					expect(schema.properties[key].enum, key).toContain(value)
				}
			}
		}
	})

	it('shows the FRIA warning column through the app formatter', () => {
		const column = page('AiSystems').config.columns.find(
			(c) => typeof c === 'object' && c.key === 'friaDocumentRef',
		)
		expect(column.formatter).toBe('friaStatus')
	})

	it('opens an AI system with its lifecycle, evidence files and checklist', () => {
		const detail = page('AiSystemDetail')
		expect(detail.route).toBe('/ai-systems/:id')
		expect(detail.config.lifecycleActions).toEqual({ field: 'status' })
		expect(
			detail.config.widgets.some(
				(w) => w.type === 'integration' && w.integrationId === 'files',
			),
		).toBe(true)
		expect(detail.config.bodyWidgets.map((w) => w.component)).toContain(
			'AiActChecklist',
		)
	})

	it('lists the AI systems on the application page', () => {
		const widget = page('ModuleDetail').config.widgets.find(
			(w) => w.id === 'md-ai-systems',
		)
		expect(widget.content.schema).toBe('aiSystem')
		expect(widget.content.filter).toEqual({ module: '@objectId' })
		expect(widget.content.rowRoute).toBe('AiSystemDetail')
		expect(
			page('ModuleDetail').config.layout.some(
				(l) => l.widgetId === 'md-ai-systems',
			),
		).toBe(true)
	})
})

describe('the seeded AI systems', () => {
	const seeded = mock.components.objects.filter(
		(o) => o['@self'].register === 'stackiq' && o['@self'].schema === 'aiSystem',
	)

	it('are accepted by the real aiSystem schema', () => {
		const validate = compileAiSystem()
		expect(seeded.length).toBeGreaterThanOrEqual(2)
		for (const o of seeded) {
			const { '@self': self, ...fields } = o
			expect(validate(fields), JSON.stringify(validate.errors)).toBe(true)
		}
	})

	it('include one high-risk system without a FRIA, so the warning shows', () => {
		expect(seeded.filter((o) => friaMissing(o))).toHaveLength(1)
		expect(seeded.some((o) => o.aiActRiskCategory === 'limited risk')).toBe(true)
	})

	it('carry no schema copy, so the demo import validates against the live schema', () => {
		expect(mock.components.schemas).toBeUndefined()
	})
})
