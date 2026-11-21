// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The application form offers only the licences its licence type allows, and
 * the organisation form only the collaboration types its type allows: the
 * library's CnFormDialog (from @conduction/nextcloud-vue 2.181.0, the version
 * package.json requires) narrows its options with the
 * x-openregister-dependent-values tables of lib/Settings/register.d/
 * dependent-field-options.json.
 *
 * The methods run with the merged schema as `this`, so the test reads the
 * library's real narrowing over the app's real tables.
 *
 * @spec openspec/changes/landscape-dependent-field-options/specs/dependent-field-options/spec.md#requirement-req-dfo-003-the-form-offers-only-the-allowed-options
 */
import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'

const root = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(root, file), 'utf8'))

const monolith = read('lib/Settings/softwarecatalogus_register.json')
const tables = read('lib/Settings/register.d/dependent-field-options.json')

/**
 * A schema of the monolith with the fragment's dependent-values tables merged in.
 *
 * @param {string} slug The schema slug.
 * @return {object} The merged schema.
 */
function mergedSchema(slug) {
	const base = monolith.components.schemas[slug]
	const overlay = tables.components.schemas[slug].properties
	const properties = { ...base.properties }
	for (const [key, value] of Object.entries(overlay)) {
		properties[key] = { ...properties[key], ...value }
	}
	return { ...base, properties }
}

/**
 * The options the form offers for a field, given the controlling value.
 *
 * @param {object} methods  CnFormDialog's methods.
 * @param {object} schema   The schema.
 * @param {string} key      The dependent field.
 * @param {object} formData The form values.
 * @return {Array<string>} The offered option values.
 */
function offered(methods, schema, key, formData) {
	const context = { schema, formData }
	context.dependentValuesOf = methods.dependentValuesOf.bind(context)
	context.dependentAllowed = methods.dependentAllowed.bind(context)
	const property = schema.properties[key]
	const values = property.enum || property.items?.enum || []
	const options = values.map((value) => ({ id: value, label: value }))
	return methods.narrowToDependentValues.call(context, { key }, options).map((option) => option.id)
}

const { default: CnFormDialog } = await import('../../node_modules/@conduction/nextcloud-vue/src/components/CnFormDialog/CnFormDialog.vue')
const { methods } = CnFormDialog

describe('the package the form support comes from', () => {
	it('requires a nextcloud-vue whose CnFormDialog reads the dependent-values tables', () => {
		const pkg = read('package.json')
		expect(pkg.dependencies['@conduction/nextcloud-vue']).toMatch(/^\^2\.(18[1-9]|19\d|[2-9]\d\d)\./)
		expect(typeof methods.narrowToDependentValues).toBe('function')
		expect(typeof methods.dependentAllowed).toBe('function')
	})
})

describe('the application form', () => {
	const schema = mergedSchema('module')

	it('offers no licence when the licence type is Closed source', () => {
		expect(offered(methods, schema, 'licence', { licentietype: 'Closed source' })).toEqual([])
	})

	it('offers the open-source licences when the licence type is Open source', () => {
		const licences = offered(methods, schema, 'licence', { licentietype: 'Open source' })
		expect(licences).toEqual(tables.components.schemas.module.properties.licence['x-openregister-dependent-values'].allowed['Open source'])
		expect(licences).toContain('European Union Public Licence (EUPL), version 1.2')
	})

	it('leaves the licence open while no licence type is chosen', () => {
		const all = schema.properties.licence.enum || schema.properties.licence.items?.enum
		expect(offered(methods, schema, 'licence', {})).toEqual(all)
	})
})

describe('the organisation form', () => {
	const schema = mergedSchema('organization')

	it('offers collaboration types only for a collaboration', () => {
		expect(offered(methods, schema, 'samenwerkingtype', { type: 'Municipality' })).toEqual([])
		expect(offered(methods, schema, 'samenwerkingtype', { type: 'Collaboration' })).toContain('Shared Service Center')
	})
})
