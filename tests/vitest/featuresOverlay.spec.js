/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The overlay the Features and roadmap page groups by status: every entry
 * carries the fields the page renders in both languages and a status that
 * lands in one of the three groups. And the page's manifest note cites the
 * requirement it implements.
 *
 * @spec openspec/changes/features-roadmap-page/specs/features-roadmap/spec.md#requirement-the-features-and-roadmap-page-groups-the-apps-features-by-status-req-frm-001
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const root = resolve(__dirname, '../..')
const overlay = JSON.parse(readFileSync(resolve(root, 'openspec/features.overlay.json'), 'utf8'))
const manifest = JSON.parse(readFileSync(resolve(root, 'src/manifest.json'), 'utf8'))

describe('features overlay', () => {
	it('is a non-empty list', () => {
		expect(Array.isArray(overlay)).toBe(true)
		expect(overlay.length).toBeGreaterThan(0)
	})

	it.each(overlay.map((entry, index) => [entry.slug ?? `#${index}`, entry]))('%s has every field the page renders', (slug, entry) => {
		for (const field of ['slug', 'title', 'title_nl', 'summary', 'summary_nl']) {
			expect(typeof entry[field], `${slug}.${field}`).toBe('string')
			expect(entry[field].trim(), `${slug}.${field}`).not.toBe('')
		}
		expect(['stable', 'beta', 'soon']).toContain(entry.status)
	})

	it('has unique slugs', () => {
		const slugs = overlay.map((entry) => entry.slug)
		expect(new Set(slugs).size).toBe(slugs.length)
	})

	it('the FeaturesRoadmap page note cites REQ-FRM-001', () => {
		const page = manifest.pages.find((candidate) => candidate.id === 'FeaturesRoadmap')
		expect(page._note).toMatch(/REQ-FRM-001/)
	})
})
