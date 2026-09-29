/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every key a detail page lists, in a data widget's `include` or an
 * object-list widget's `columns`, exists on the schema that widget reads.
 * A renamed schema property otherwise leaves the field blank on the page
 * with no error anywhere: the application page showed no descriptions and
 * no contact person for that reason.
 *
 * @spec openspec/specs/application-page/spec.md#requirement-req-apg-001-the-application-page-shows-every-field-it-lists-under-the-schemas-current-keys
 */

import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'
import register from '../../lib/Settings/softwarecatalogus_register.json'
import manifest from '../../src/manifest.json'

/**
 * The schemas of the main register, with the properties register.d fragments add.
 *
 * @return {object} Schemas by slug.
 */
function allSchemas() {
	const schemas = JSON.parse(JSON.stringify(register?.components?.schemas ?? {}))
	const dir = path.resolve(__dirname, '../../lib/Settings/register.d')
	for (const file of fs.readdirSync(dir).filter((f) => f.endsWith('.json'))) {
		const fragment = JSON.parse(fs.readFileSync(path.join(dir, file), 'utf8'))
		for (const [slug, schema] of Object.entries(
			fragment?.components?.schemas ?? {},
		)) {
			schemas[slug] = schemas[slug] ?? { properties: {} }
			schemas[slug].properties = {
				...schemas[slug].properties,
				...(schema.properties ?? {}),
			}
		}
	}
	return schemas
}

/**
 * Keys a detail page lists that its schema does not have.
 *
 * @param {object} man The manifest.
 * @param {object} schemas Schemas by slug.
 * @return {string[]} One line per stale key.
 */
function staleKeys(man, schemas) {
	const problems = []
	const plainKey = (key) =>
		typeof key === 'string'
		&& !key.includes('.')
		&& !key.startsWith('@')
		&& !key.startsWith('_')
	for (const page of man?.pages ?? []) {
		if (page?.type !== 'detail') continue
		for (const widget of page?.config?.widgets ?? []) {
			let slug = null
			let keys = []
			if (widget.type === 'data') {
				slug = page.config.schema
				keys = widget?.content?.include ?? []
			} else if (widget.type === 'object-list') {
				slug = widget?.content?.schema
				keys = (widget?.content?.columns ?? []).map((c) =>
					typeof c === 'string' ? c : c?.key,
				)
			}
			const properties = schemas?.[slug]?.properties
			if (!slug || !properties) continue
			for (const key of keys.filter(plainKey)) {
				if (!(key in properties)) {
					problems.push(
						`page "${page.id}" widget "${widget.id}" lists "${key}", which schema "${slug}" does not have`,
					)
				}
			}
		}
	}
	return problems
}

describe('detail page keys match their schema', () => {
	it('every include key and column key exists on the schema its widget reads', () => {
		expect(staleKeys(manifest, allSchemas())).toEqual([])
	})

	it('names the page and the key when one is stale', () => {
		const man = {
			pages: [
				{
					id: 'P',
					type: 'detail',
					config: {
						schema: 'module',
						widgets: [
							{
								id: 'w',
								type: 'data',
								content: { include: ['name', 'gone'] },
							},
						],
					},
				},
			],
		}
		expect(staleKeys(man, { module: { properties: { name: {} } } })).toEqual([
			'page "P" widget "w" lists "gone", which schema "module" does not have',
		])
	})
})
