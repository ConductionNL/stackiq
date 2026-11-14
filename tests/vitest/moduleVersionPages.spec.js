/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest: module versions (REQ-MVR-001). The merged manifest's Module versions
 * page lists version, application, in-development date, in-use date and
 * status, offers the "Planned releases" quick filter, and both version pages
 * cite the requirement.
 *
 * @spec openspec/changes/landscape-services-and-module-versions/specs/module-versions/spec.md#requirement-a-released-version-of-an-application-is-recorded-with-its-lifecycle-dates-req-mvr-001
 */

import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'
import register from '../../lib/Settings/softwarecatalogus_register.json'
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
const page = (id) => merged.pages.find((p) => p.id === id)

describe('module versions (REQ-MVR-001)', () => {
	it('lists version, application, lifecycle dates and status', () => {
		const versions = page('Moduleversies')

		expect(versions.type).toBe('index')
		expect(versions.config.schema).toBe('moduleVersion')
		expect(versions.config.columns.map((c) => c.key)).toEqual(['version', 'module', 'dateInDevelopment', 'dateInUse', 'status'])
	})

	it('offers planned releases as a quick filter on a status the schema knows', () => {
		const planned = page('Moduleversies').config.quickFilters.find((f) => f.label === 'Planned releases')

		expect(planned.filter).toEqual({ status: 'in development' })
		expect(register.components.schemas.moduleVersion.properties.status.enum).toContain('in development')
	})

	it('records every column the page shows on the schema', () => {
		const properties = Object.keys(register.components.schemas.moduleVersion.properties)

		for (const key of page('Moduleversies').config.columns.map((c) => c.key)) {
			expect(properties).toContain(key)
		}
	})

	it('cites the requirement on both version pages', () => {
		expect(page('Moduleversies')._note).toContain('REQ-MVR-001')
		expect(page('ModuleversieDetail').type).toBe('detail')
		expect(page('ModuleversieDetail').config._note).toContain('REQ-MVR-001')
	})
})
