// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the address line of the organisation card, for every
 * combination of an absent, empty, zero or filled street, postcode and
 * place. Recorded with RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/fe-organizations/spec.md
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/vue', () => ({
	NcActionButton: {},
	NcActions: {},
	NcButton: {},
}))
vi.mock('../../src/components/ContactpersonenList.vue', () => ({ default: {} }))

const { default: OrganisatieCard } =
	await import('../../src/components/cards/OrganisatieCard.vue')

const GOLDEN = path.resolve(import.meta.dirname, 'golden/organisatieCardAdres.json')

const VALUES = { absent: undefined, empty: '', zero: 0, filled: null }
const FILLED = { adres: 'Dorpsstraat 1', postcode: '1234 AB', plaats: 'Zuiddrecht' }

describe('OrganisatieCard getOrganisatieAdres golden', () => {
	it('matches the recorded address lines', () => {
		const actual = {}
		for (const [a, av] of Object.entries(VALUES)) {
			for (const [p, pv] of Object.entries(VALUES)) {
				for (const [c, cv] of Object.entries(VALUES)) {
					const item = {}
					for (const [field, kind, value] of [
						['adres', a, av],
						['postcode', p, pv],
						['plaats', c, cv],
					]) {
						if (kind !== 'absent') {
							item[field] = kind === 'filled' ? FILLED[field] : value
						}
					}
					actual[`adres ${a}, postcode ${p}, plaats ${c}`] =
						OrganisatieCard.methods.getOrganisatieAdres.call({ item })
				}
			}
		}
		actual['no item'] = OrganisatieCard.methods.getOrganisatieAdres.call({
			item: null,
		})
		if (process.env.RECORD_GOLDEN === '1') {
			writeFileSync(GOLDEN, JSON.stringify(actual, null, '\t') + '\n')
		}
		expect(actual).toEqual(JSON.parse(readFileSync(GOLDEN, 'utf8')))
	})
})
