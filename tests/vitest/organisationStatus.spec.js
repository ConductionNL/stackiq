/**
 * The concept-organisations widget filters on one status and writes another.
 * Both must be members of the real organization schema's status enum, and the
 * accept payload must validate against the real status property, or the widget
 * lists nothing and every accept is refused.
 *
 * @spec openspec/specs/fe-organizations/spec.md
 */

import Ajv2020 from 'ajv/dist/2020.js'
import { describe, expect, it } from 'vitest'
import register from '../../lib/Settings/softwarecatalogus_register.json'
import {
	ACCEPTED_STATUS,
	acceptPayload,
	CONCEPT_STATUS,
	isConceptOrganisation,
} from '../../src/utils/organisationStatus.js'

const statusProperty = register.components.schemas.organization.properties.status

/**
 * Compile a JSON-schema validator for a patch that only carries `status`,
 * using the type and enum of the real register property.
 *
 * @return {Function} The compiled Ajv validator
 */
function compileStatusPatch() {
	const ajv = new Ajv2020({ allErrors: true, strict: false })
	return ajv.compile({
		type: 'object',
		properties: { status: { type: statusProperty.type, enum: statusProperty.enum } },
		required: ['status'],
		additionalProperties: false,
	})
}

describe('organisation status values of the concept-organisations widget', () => {
	it('filters on a status that is in the real enum', () => {
		expect(statusProperty.enum).toContain(CONCEPT_STATUS)
	})

	it('writes an accept payload the real status property accepts', () => {
		const validate = compileStatusPatch()
		const payload = acceptPayload()
		expect(validate(payload), JSON.stringify(validate.errors)).toBe(true)
		expect(payload.status).toBe(ACCEPTED_STATUS)
	})

	it('accepts into a status that is not the concept status', () => {
		expect(ACCEPTED_STATUS).not.toBe(CONCEPT_STATUS)
	})

	it('lists an organisation on the schema default and not an accepted one', () => {
		expect(isConceptOrganisation({ status: statusProperty.default })).toBe(true)
		expect(isConceptOrganisation({ status: ACCEPTED_STATUS })).toBe(false)
		expect(isConceptOrganisation({})).toBe(false)
	})
})

describe('the concept-organisations widget uses these values', () => {
	it('filters and accepts through the shared status helpers', async () => {
		const fs = await import('fs')
		const source = fs.readFileSync(new URL('../../src/views/widgets/ConceptOrganisatiesWidget.vue', import.meta.url), 'utf8')
		expect(source).toContain('.filter(isConceptOrganisation)')
		expect(source).toContain("patchObject('organization', item.id, acceptPayload())")
		expect(source).not.toMatch(/'concept'|'actief'/)
	})
})
