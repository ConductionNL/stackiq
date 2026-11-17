/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The compliance documents pages as the app builds them (manifest.d merged the
 * way src/main.js merges it): the list on the application page, the documents
 * list with its type filters, and the detail page, all on the complianceDocument
 * schema whose read rules decide who sees a document (sharing-compliance-documents).
 */

import addFormats from 'ajv-formats'
import Ajv2020 from 'ajv/dist/2020.js'
import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'
import register from '../../lib/Settings/softwarecatalogus_register.json'
import manifestSchema from '../../node_modules/@conduction/nextcloud-vue/src/schemas/app-manifest-v2.schema.json'
import { buildManifest } from '../../node_modules/@conduction/nextcloud-vue/src/utils/buildManifest.js'
import * as icons from '../../src/icons.js'
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
const page = (id) => merged.pages.find((p) => p.id === id)
const schema = register.components.schemas.complianceDocument
const fieldsOf = (columns) => columns.map((column) => (typeof column === 'string' ? column : column.key))

describe('the compliance documents pages', () => {
	it('builds a manifest the v2 schema accepts', () => {
		const ajv = new Ajv2020({ allErrors: true, strict: false })
		addFormats(ajv)
		const validate = ajv.compile(manifestSchema)
		expect(validate(merged), JSON.stringify(validate.errors)).toBe(true)
	})

	it('lists the documents of the application on its page, soonest expiry first, with publish', () => {
		const list = page('ModuleDetail').config.widgets.find((w) => w.id === 'md-compliance-documents')
		expect(list.content).toMatchObject({
			schema: 'complianceDocument',
			filter: { module: '@objectId' },
			sort: { field: 'validUntil', dir: 'asc' },
			rowRoute: 'ComplianceDocumentDetail',
			viewAllRoute: 'ComplianceDocuments',
		})
		expect(page('ModuleDetail').config.layout.some((cell) => cell.widgetId === 'md-compliance-documents')).toBe(true)
		for (const field of list.content.formIncludeFields) {
			expect(schema.properties, field).toHaveProperty(field)
		}
		expect(list.content.formIncludeFields).toContain('audience')
	})

	it('offers a quick filter for every document type, on fields the schema has', () => {
		const index = page('ComplianceDocuments').config
		expect(index.schema).toBe('complianceDocument')
		for (const field of fieldsOf(index.columns)) {
			expect(schema.properties, field).toHaveProperty(field)
		}
		const types = index.quickFilters.map((q) => q.filter.documentType).filter(Boolean)
		expect(types.sort()).toEqual(schema.properties.documentType.enum.filter((t) => t !== 'other').sort())
		expect(index.detailRoute).toBe('ComplianceDocumentDetail')
	})

	it('shows the audience and the named organisations on the detail page', () => {
		const data = page('ComplianceDocumentDetail').config.widgets.find((w) => w.id === 'cd-data')
		expect(data.content.include).toEqual(expect.arrayContaining(['audience', 'sharedWithOrganisations', 'validUntil', 'publisher']))
		for (const field of data.content.include) {
			expect(schema.properties, field).toHaveProperty(field)
		}
	})

	it('puts the list under Applications with an icon the app registers', () => {
		const modules = merged.menu.find((item) => item.id === 'Modules')
		const entry = (modules.children || []).find((child) => child.id === 'ComplianceDocuments')
		expect(entry).toMatchObject({ route: 'ComplianceDocuments' })
		const registry = icons.default || icons.icons || icons
		expect(JSON.stringify(Object.keys(registry))).toContain('FileCertificateOutline')
	})
})
