/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Data quality page as the app builds it (manifest.d merged the way
 * src/main.js merges it): a custom page under Reports & Compliance whose
 * component is registered.
 *
 * @spec openspec/changes/landscape-completeness-score/specs/catalogue-data-quality/spec.md#requirement-req-cdq-003-a-data-quality-page-shows-how-complete-and-current-an-organisation-s-register-is
 */

import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'
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

describe('Data quality page (board SkDatakwaliteit)', () => {
	it('is a custom page on /datakwaliteit rendered by DataQualityReportView', () => {
		const page = merged.pages.find((p) => p.id === 'DataQuality')
		expect(page).toMatchObject({ route: '/datakwaliteit', type: 'custom', component: 'DataQualityReportView' })
	})

	it('is in the menu under Reports & Compliance, not at the top level', () => {
		const group = merged.menu.find((m) => m.id === 'ReportsCompliance')
		expect((group.children || []).map((c) => c.id)).toContain('DataQuality')
		expect(merged.menu.map((m) => m.id)).not.toContain('DataQuality')
	})

	it('registers its component', () => {
		const source = fs.readFileSync(path.resolve(__dirname, '../../src/customComponents.js'), 'utf8')
		expect(source).toMatch(/^\tDataQualityReportView,$/m)
	})
})
