/**
 * SPDX-FileCopyrightText: 2026 Conduction / SoftwareCatalog Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A manifest page's `config.filter` values must exist in the schema enum they
 * filter on.
 *
 * WHY THIS FILE EXISTS. `src/manifest.json`'s Organisaties page filtered on
 * `status: ["Concept", "Actief", "Deactief"]`. #520 translated that enum to
 * Draft/Active/Inactive/merged AND migrated the stored rows, but did not move
 * the manifest, so the index filtered on three values no row could hold.
 *
 * ⚠️ THAT IS NOT AN ERROR ANYWHERE. OpenRegister answers a filter on a valid
 * property with a non-existent value as `200 {"total": 0}` — measured on a
 * running instance: `?status[]=Draft&status[]=Active` returned the seeded row,
 * `?status[]=Concept&status[]=Actief&status[]=Deactief` returned `total: 0`.
 * So the Organisations index rendered "No items found" and read as an empty
 * catalogue rather than as a broken page: no console error, no failed request,
 * nothing in the log. Three e2e tests failed on the consequences (a missing
 * card, a missing create affordance) rather than on the cause.
 *
 * This check is the cheap half of that day's work: a filter value that is not a
 * member of its property's enum is always a bug, and it is visible from two
 * static files.
 *
 * The last case is a POSITIVE CONTROL: the checker is handed a filter that IS
 * stale and must report it. Without that, a checker that silently resolves no
 * schemas at all would pass this file while proving nothing — the failure mode
 * that makes a green gate worthless.
 */
import { describe, expect, it } from 'vitest'
import manifest from '../../src/manifest.json'
import register from '../../lib/Settings/softwarecatalogus_register.json'

const SCHEMAS = register?.components?.schemas ?? {}

/**
 * Collect every `config.filter` entry whose value is not a member of the
 * enum declared for that property on the page's schema.
 *
 * Only ENUM-typed properties are judged: a filter on a free-text property
 * (a uuid, a slug, a route token) has no closed value set to check against,
 * and `@`-prefixed / `:`-prefixed values are route interpolations resolved at
 * fetch time, not literals.
 *
 * @param {object} man   The app manifest.
 * @param {object} schemas The register's `components.schemas` map.
 * @return {Array<string>} One human-readable line per violation.
 */
function staleFilterValues(man, schemas) {
	const problems = []
	for (const page of man?.pages ?? []) {
		const filter = page?.config?.filter
		const slug = page?.config?.schema
		if (!filter || typeof filter !== 'object' || !slug) continue

		const properties = schemas?.[slug]?.properties
		if (!properties) {
			problems.push(
				`page "${page.id}" filters on schema "${slug}", which the register does not declare`,
			)
			continue
		}

		for (const [property, raw] of Object.entries(filter)) {
			const declared = properties?.[property]?.enum
			if (!Array.isArray(declared) || declared.length === 0) continue

			for (const value of Array.isArray(raw) ? raw : [raw]) {
				if (typeof value !== 'string') continue
				if (value.startsWith('@') || value.startsWith(':')) continue
				if (declared.includes(value)) continue
				problems.push(
					`page "${page.id}" filters ${slug}.${property} on "${value}", `
						+ `which is not in its enum [${declared.join(', ')}]`,
				)
			}
		}
	}
	return problems
}

describe('manifest filters address values the schema enums actually declare', () => {
	it('finds at least one page with an enum-typed filter to judge', () => {
		// A run that judged NOTHING prints the same "no problems" as a clean one.
		const judged = (manifest?.pages ?? []).filter((page) => {
			const filter = page?.config?.filter
			const slug = page?.config?.schema
			if (!filter || !slug) return false
			const properties = SCHEMAS?.[slug]?.properties ?? {}
			return Object.keys(filter).some((key) =>
				Array.isArray(properties?.[key]?.enum),
			)
		})
		expect(judged.length).toBeGreaterThan(0)
	})

	it('has no manifest filter value outside its schema enum', () => {
		expect(staleFilterValues(manifest, SCHEMAS)).toEqual([])
	})

	it('POSITIVE CONTROL: the checker reports a filter left behind by a rename', () => {
		const stale = {
			pages: [
				{
					id: 'Organisaties',
					config: {
						schema: 'organization',
						// The exact list the manifest carried before this fix.
						filter: { status: ['Concept', 'Actief', 'Deactief'] },
					},
				},
			],
		}
		const found = staleFilterValues(stale, SCHEMAS)
		expect(found).toHaveLength(3)
		expect(found[0]).toContain('Concept')
	})
})
