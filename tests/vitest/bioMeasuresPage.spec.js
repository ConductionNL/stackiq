/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * The BIO measures page shows the BBN level (bio-measures-shipped-and-not-met
 * task 1): its columns name properties the bioMeasure schema has.
 *
 * @spec openspec/changes/bio-measures-shipped-and-not-met/specs/bio-compliance-assessment/spec.md#requirement-the-bio-20-measures-ship-with-the-app-and-show-their-level-req-bio-010
 */

import { describe, expect, it } from 'vitest'
import register from '../../lib/Settings/softwarecatalogus_register.json'
import manifest from '../../src/manifest.json'

describe('BIO measures page', () => {
	const page = manifest.pages.find((candidate) => candidate.id === 'BioMaatregelen' && candidate.type === 'index')

	it('lists code, name, theme, BIO version and BBN level', () => {
		expect(page.config.columns).toEqual(['code', 'name', 'thema', 'bioVersion', 'bbnLevel'])
	})

	it('only names properties the bioMeasure schema has', () => {
		const properties = Object.keys(register.components.schemas.bioMeasure.properties)
		for (const column of page.config.columns) {
			expect(properties).toContain(column)
		}
	})

	it('names bbnLevel in the detail page note', () => {
		const detail = manifest.pages.find((candidate) => JSON.stringify(candidate).includes('Catalog archetype (a BIO 2.0 measure)'))
		expect(JSON.stringify(detail)).toContain('bbnLevel')
		expect(JSON.stringify(detail)).not.toContain('bbnNiveau')
	})
})
