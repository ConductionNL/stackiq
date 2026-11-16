import { describe, expect, it } from 'vitest'
import fragment from '../../lib/Settings/register.d/contracts-expiry-and-owner.json'
import register from '../../lib/Settings/softwarecatalogus_register.json'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The contract form picks the responsible user from the instance's users
 * (contracts-expiry-and-owner REQ-CEO-004, design D4): the property is a
 * `referenceType: nextcloud-user` string, which the library renders as a
 * searchable user picker, so stackiq needs no component of its own.
 *
 * @spec openspec/changes/contracts-expiry-and-owner/specs/contract-expiry-and-owner/spec.md#requirement-req-ceo-004-a-contract-shall-name-one-responsible-nextcloud-user-picked-from-the-users-of-the-instance
 */
import { fieldsFromSchema } from '../../node_modules/@conduction/nextcloud-vue/src/utils/schema.js'

const contract = register.components.schemas.catalogContract
const responsibleUser = fragment.components.schemas.catalogContract.properties.responsibleUser

describe('the responsible user field', () => {
	it('is a user picker in the contract form', () => {
		const schema = { ...contract, properties: { ...contract.properties, responsibleUser } }
		const field = fieldsFromSchema(schema).find((f) => f.key === 'responsibleUser')

		expect(field).toBeDefined()
		expect(field.widget).toBe('user')
	})

	it('is not required, so a contract without one still saves', () => {
		expect(contract.required ?? []).not.toContain('responsibleUser')
		expect(responsibleUser.required).toBeUndefined()
	})
})
