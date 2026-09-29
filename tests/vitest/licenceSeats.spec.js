/**
 * Licence seats: the seat position of one contract, the Seats rows of the
 * License posture page, and the seeded contracts validated against the real
 * catalogContract schema (the monolith with the register.d fragment merged in,
 * the way SettingsService::loadSettings() merges it).
 *
 * @spec openspec/changes/contracts-licence-seats/specs/licence-seats/spec.md
 */

import Ajv2020 from 'ajv/dist/2020.js'
import { describe, expect, it } from 'vitest'
import fragment from '../../lib/Settings/register.d/contracts-licence-seats.json'
import register from '../../lib/Settings/softwarecatalogus_register.json'
import mock from '../../lib/Settings/stackiq_mock_register.json'
import {
	SEAT_STATE,
	seatPosition,
	seatRows,
} from '../../src/utils/licensePosture.js'

const SEAT_FIELDS = ['licenceMetric', 'licencesBought', 'licencesInUse']

/**
 * The catalogContract properties as the import sees them: monolith plus fragment.
 *
 * @return {object} Property name to property schema.
 */
function contractProperties() {
	return {
		...register.components.schemas.catalogContract.properties,
		...fragment.components.schemas.catalogContract.properties,
	}
}

/**
 * A validator for the seat fields of a contract, built from the real properties.
 *
 * @return {Function} The compiled Ajv validator.
 */
function compileSeatFields() {
	const props = contractProperties()
	const ajv = new Ajv2020({ allErrors: true, strict: false })
	return ajv.compile({
		type: 'object',
		properties: Object.fromEntries(
			SEAT_FIELDS.map((f) => [
				f,
				{
					type: props[f].type,
					enum: props[f].enum,
					minimum: props[f].minimum,
				},
			]),
		),
	})
}

function contract(id, fields, usage = '') {
	return {
		id,
		contractNumber: id,
		contractType: 'Licence',
		usage,
		...fields,
	}
}

describe('seatPosition', () => {
	it('flags 460 in use against 400 bought as over by 60', () => {
		const p = seatPosition(
			contract('c1', {
				licenceMetric: 'Per named user',
				licencesBought: 400,
				licencesInUse: 460,
			}),
		)
		expect(p.state).toBe(SEAT_STATE.OVER)
		expect(p.over).toBe(60)
	})

	it('reads use at or under what was bought as within', () => {
		const p = seatPosition(
			contract('c1', {
				licenceMetric: 'Per inhabitant',
				licencesBought: 58000,
				licencesInUse: 58000,
			}),
		)
		expect(p.state).toBe(SEAT_STATE.WITHIN)
		expect(p.over).toBe(0)
	})

	it('does not count Per organisation or Other', () => {
		for (const metric of ['Per organisation', 'Other']) {
			const p = seatPosition(
				contract('c1', {
					licenceMetric: metric,
					licencesBought: 1,
					licencesInUse: 5,
				}),
			)
			expect(p.state).toBe(SEAT_STATE.NOT_COUNTED)
		}
	})

	it('is unknown when a count is empty', () => {
		const p = seatPosition(
			contract('c1', { licenceMetric: 'Per device', licencesBought: 10 }),
		)
		expect(p.state).toBe(SEAT_STATE.UNKNOWN)
	})
})

describe('seatRows', () => {
	const usages = [
		{ id: 'u1', module: 'm1', consumer: 'o1' },
		{ id: 'u2', module: 'm2', consumer: 'o2' },
	]

	it('puts over-licence rows first, most over first, and leaves uncounted contracts out', () => {
		const rows = seatRows(
			[
				contract(
					'within',
					{
						licenceMetric: 'Per device',
						licencesBought: 10,
						licencesInUse: 5,
					},
					'u1',
				),
				contract(
					'over10',
					{
						licenceMetric: 'Per device',
						licencesBought: 10,
						licencesInUse: 20,
					},
					'u1',
				),
				contract(
					'over60',
					{
						licenceMetric: 'Per named user',
						licencesBought: 400,
						licencesInUse: 460,
					},
					'u2',
				),
				contract(
					'site',
					{
						licenceMetric: 'Per organisation',
						licencesBought: 1,
						licencesInUse: 1,
					},
					'u1',
				),
				contract('sla', {}, 'u1'),
				contract(
					'noBought',
					{ licenceMetric: 'Per device', licencesInUse: 3 },
					'u1',
				),
			],
			usages,
		)
		expect(rows.map((r) => r.contractId)).toEqual(['over60', 'over10', 'within'])
		expect(rows[0]).toMatchObject({ moduleId: 'm2', consumerId: 'o2', over: 60 })
	})
})

describe('the catalogContract schema and the seeded contracts', () => {
	it('declares the metric with six values and two whole counts of at least 0', () => {
		const props = contractProperties()
		expect(props.licenceMetric.enum).toHaveLength(6)
		for (const f of ['licencesBought', 'licencesInUse']) {
			expect(props[f]).toMatchObject({ type: 'integer', minimum: 0 })
		}
	})

	it('refuses a negative count', () => {
		const validate = compileSeatFields()
		expect(validate({ licencesBought: -5 })).toBe(false)
	})

	it('seeds one over-licence and one within-licence contract that the real schema accepts', () => {
		const validate = compileSeatFields()
		const seeded = mock.components.objects.filter(
			(o) =>
				o['@self'].register === 'stackiq'
				&& o['@self'].schema === 'catalogContract'
				&& o.licenceMetric !== undefined,
		)
		for (const o of seeded) {
			expect(validate(o), JSON.stringify(validate.errors)).toBe(true)
		}
		const states = seeded.map((o) => seatPosition(o).state).sort()
		expect(states).toEqual([SEAT_STATE.OVER, SEAT_STATE.WITHIN])
	})
})
