/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the licence posture helpers over seeded random catalogues:
 * modules, usages and contracts in every id and wrapping shape the helpers
 * accept. Each scenario records every exported helper's answer. Recorded with
 * RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/fe-stores/spec.md
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import {
	perOrganisationPosture,
	perVendorRollup,
	portfolioPosture,
	seatRows,
} from '../../src/utils/licensePosture.js'

const GOLDEN = path.resolve(import.meta.dirname, 'golden/licensePostureGolden.json')

/**
 * A small seeded generator, so every run builds the same catalogues.
 *
 * @param {number} seed The seed
 * @return {Function} A function returning the next number in [0, 1)
 */
function generator(seed) {
	let state = seed
	return () => {
		state = (state * 1103515245 + 12345) % 2147483648
		return state / 2147483648
	}
}

/**
 * One random catalogue.
 *
 * @param {number} seed The seed
 * @return {object} Modules, usages, contracts and organisation ids
 */
function catalogue(seed) {
	const rnd = generator(seed)
	const pick = (list) => list[Math.floor(rnd() * list.length)]
	const wrap = (data, id) =>
		pick([
			() => ({ id, ...data }),
			() => ({ uuid: id, ...data }),
			() => ({ '@self': { id }, ...data }),
			() => ({ id, object: data }),
			() => ({ '@self': { id }, object: { ...data, id } }),
			() => ({ ...data }),
		])()
	const modules = Array.from({ length: 4 }, (_, i) =>
		wrap(
			{
				licentietype: pick([
					'Open source',
					'Closed source',
					'Unknown',
					'open',
					null,
				]),
				licence: pick(['EUPL-1.2', 'MIT', '', '  ', 7, undefined]),
				provider: pick(['v1', 'v2', { id: 'v3' }, '', null]),
			},
			'm' + i,
		),
	)
	const usages = Array.from({ length: 6 }, (_, i) =>
		wrap(
			{
				module: pick(['m0', 'm1', 'm2', 'm3', { id: 'm1' }, 'mx', '']),
				consumer: pick(['o1', 'o2', { id: 'o1' }, '']),
				startDateInProduction: pick(['2025-01-01', '', null, 'nope']),
				startDateOutPhased: pick([null, null, '2025-06-01']),
			},
			'u' + i,
		),
	)
	const contracts = Array.from({ length: 5 }, (_, i) =>
		wrap(
			{
				usage: pick(['u0', 'u1', 'u2', 'u3', { id: 'u4' }, '', 'ux']),
				contractNumber: pick(['C-1', '', undefined]),
				licenceMetric: pick([
					'Named user',
					'Per organisation',
					'Other',
					'',
					3,
					undefined,
				]),
				licencesBought: pick([10, '5', '', null, -1, 2.5, 0]),
				licencesInUse: pick([12, '5', 3, null, '', 0]),
				cost: pick([1000, '250', null]),
				costPeriod: pick(['Annually', 'Monthly', 'One-off', undefined]),
			},
			'c' + i,
		),
	)
	return { modules, usages, contracts }
}

describe('licence posture golden', () => {
	it('answers the same for every seeded catalogue', () => {
		const recorded = {}
		for (let seed = 1; seed <= 300; seed++) {
			const { modules, usages, contracts } = catalogue(seed)
			recorded[seed] = JSON.parse(
				JSON.stringify(
					{
						portfolio: portfolioPosture(modules, usages),
						vendors: perVendorRollup(modules, usages, contracts),
						org1: perOrganisationPosture('o1', modules, usages),
						org2: perOrganisationPosture('o2', modules, usages),
						seats: seatRows(contracts, usages),
					},
					(k, v) => (v === undefined ? '(undefined)' : v),
				),
			)
		}
		recorded.empty = {
			portfolio: portfolioPosture(undefined, undefined),
			vendors: perVendorRollup(null, null, null),
			seats: seatRows(null, null),
		}
		if (process.env.RECORD_GOLDEN) {
			writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
			return
		}
		expect(JSON.stringify(recorded)).toBe(
			JSON.stringify(JSON.parse(readFileSync(GOLDEN, 'utf8'))),
		)
	})
})
