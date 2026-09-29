/**
 * The words of the seats panel and the Seats section, and the wiring that puts
 * the panel on the contract page and the section on the License posture page.
 *
 * @spec openspec/changes/contracts-licence-seats/specs/licence-seats/spec.md#requirement-req-lsc-002-the-contract-detail-page-must-show-licences-in-use-against-licences-bought
 */

import * as fs from 'fs'
import { describe, expect, it } from 'vitest'
import en from '../../l10n/en.json'
import nl from '../../l10n/nl.json'
import manifest from '../../src/manifest.json'
import { SEAT_STATE } from '../../src/utils/licensePosture.js'
import { licenceMetricLabel, seatStateLabel } from '../../src/utils/seatLabels.js'

const read = (p) => fs.readFileSync(new URL(p, import.meta.url), 'utf8')

describe('seat labels', () => {
	it('reads Over licence by 60 for a contract 60 over', () => {
		expect(seatStateLabel({ state: SEAT_STATE.OVER, over: 60 })).toBe(
			'Over licence by 60',
		)
	})

	it('names every other state', () => {
		expect(seatStateLabel({ state: SEAT_STATE.WITHIN })).toBe('Within licence')
		expect(seatStateLabel({ state: SEAT_STATE.NOT_COUNTED })).toBe('Not counted')
		expect(seatStateLabel({ state: SEAT_STATE.UNKNOWN })).toBe('Unknown')
	})

	it('names a metric and has a Dutch word for every metric and state', () => {
		expect(licenceMetricLabel('Per named user')).toBe('Per named user')
		for (const key of [
			'Per named user',
			'Per concurrent user',
			'Per device',
			'Per inhabitant',
			'Per organisation',
			'Other',
			'Within licence',
			'Over licence by {count}',
			'Not counted',
			'Seats',
			'Licences',
			'Licence metric',
			'Licences bought',
			'Licences in use',
		]) {
			expect(en.translations[key], key).toBe(key)
			expect(nl.translations[key], key).toBeTruthy()
			expect(nl.translations[key], key).not.toBe(key)
		}
	})
})

describe('wiring', () => {
	it('puts the seats panel on the contract page', () => {
		const page = manifest.pages.find((p) => p.id === 'ContractDetail')
		const widget = page.config.bodyWidgets.find(
			(w) => w.component === 'ContractSeatsPanel',
		)
		expect(widget?.props?.objectId).toBe('@objectId')
		expect(read('../../src/customComponents.js')).toMatch(
			/^\tContractSeatsPanel,$/m,
		)
	})

	it('shows the Seats section on the License posture page from seatRows()', () => {
		const view = read('../../src/views/LicensePostureView.vue')
		expect(view).toContain('data-testid="posture-seats"')
		expect(view).toMatch(/seatRows\(this\.contracts, this\.usages\)/)
	})
})
