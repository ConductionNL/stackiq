/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Applying an adopted scenario writes each change to its usage once, and marks
 * the scenario applied only after the last write (design D5).
 *
 * @spec openspec/changes/architecture-future-state-scenarios/specs/future-state-scenarios/spec.md#requirement-req-fss-004-a-scenario-shall-move-through-a-declared-lifecycle-and-an-adopted-scenario-shall-be-applied-to-the-landscape
 */
import { describe, expect, it } from 'vitest'
import {
	ACTIONS,
	applyScenario,
	canApply,
	changeFields,
	orderChanges,
	relationId,
	storeObjects,
} from '../../src/store/modules/scenarioApply.js'

const NOW = () => new Date('2026-11-21T10:00:00Z')

/**
 * A scenario of the test organisation.
 *
 * @param {object} extra Fields to override.
 * @return {object} The scenario.
 */
function scenario(extra = {}) {
	return {
		'@self': { id: 'scn-1' },
		id: 'scn-1',
		name: 'Next year',
		organisation: { '@self': { id: 'org-1' }, name: 'Gemeente' },
		targetDate: '2027-06-30',
		status: 'adopted',
		...extra,
	}
}

/**
 * A fake object client: usages by id, every save recorded, failures on demand.
 *
 * @param {object} options          The fake.
 * @param {object} options.usages   Stored usages by id.
 * @param {Function} [options.fail] Returns true for a save that should fail.
 * @return {object} The client with its `saves`.
 */
function fakeObjects({ usages = {}, fail = () => false } = {}) {
	let created = 0
	const saves = []
	return {
		saves,
		async get(type, id) {
			return type === 'usage' ? (usages[id] ?? null) : null
		},
		async save(type, data) {
			saves.push({ type, data })
			if (fail(type, data)) {
				return null
			}
			if (!data.id) {
				created++
				return { ...data, '@self': { id: `${type}-new-${created}` }, id: `${type}-new-${created}` }
			}
			return { ...data }
		},
	}
}

const ADD = { id: 'chg-add', action: ACTIONS.ADD, module: 'mod-new', referenceComponents: [{ id: 'rc-1' }, 'rc-2'], effectiveDate: '2027-03-01' }
const PHASE_OUT = { id: 'chg-out', action: ACTIONS.PHASE_OUT, usage: 'use-1' }
const REPLACE = { id: 'chg-rep', action: ACTIONS.REPLACE, usage: { '@self': { id: 'use-2' } }, module: 'mod-succ', effectiveDate: '2027-01-01' }

const USAGES = {
	'use-1': { '@self': { id: 'use-1', register: '5' }, id: 'use-1', consumer: 'org-1', module: 'mod-a', status: 'In production' },
	'use-2': { '@self': { id: 'use-2' }, id: 'use-2', consumer: 'org-1', module: 'mod-b', status: 'In production' },
}

describe('canApply', () => {
	it('offers the apply on an adopted scenario that was not applied', () => {
		expect(canApply(scenario())).toBe(true)
		expect(canApply(scenario({ appliedAt: '2026-11-01T00:00:00Z' }))).toBe(false)
		expect(canApply(scenario({ status: 'proposed' }))).toBe(false)
		expect(canApply(null)).toBe(false)
	})
})

describe('changeFields', () => {
	it('writes an addition as a planned usage of the organisation, in production on the date', () => {
		expect(changeFields(ADD, scenario())).toEqual({
			consumer: 'org-1',
			module: 'mod-new',
			usedForReferenceComponents: ['rc-1', 'rc-2'],
			status: 'Planned',
			startDateInProduction: '2027-03-01',
		})
	})

	it('writes a phase out as the phased out date, defaulting to the target date', () => {
		expect(changeFields(PHASE_OUT, scenario())).toEqual({ startDateOutPhased: '2027-06-30' })
	})

	it('writes a replacement as the planned replacement and its date', () => {
		expect(changeFields(REPLACE, scenario())).toEqual({ plannedReplacement: 'mod-succ', plannedReplacementDate: '2027-01-01' })
	})

	it('refuses a change without what its action needs', () => {
		expect(() => changeFields({ action: ACTIONS.ADD }, scenario())).toThrow()
		expect(() => changeFields({ action: ACTIONS.PHASE_OUT }, scenario())).toThrow()
		expect(() => changeFields({ action: ACTIONS.REPLACE, usage: 'use-1' }, scenario())).toThrow()
		expect(() => changeFields({ action: 'merge' }, scenario())).toThrow('Unknown action')
	})
})

describe('orderChanges', () => {
	it('applies the changes by effective date, the target date standing in', () => {
		expect(orderChanges([PHASE_OUT, ADD, REPLACE], scenario()).map((c) => c.id)).toEqual(['chg-rep', 'chg-add', 'chg-out'])
	})
})

describe('relationId', () => {
	it('reads a uuid string, an object id or nothing', () => {
		expect(relationId('abc')).toBe('abc')
		expect(relationId({ '@self': { id: 'x' } })).toBe('x')
		expect(relationId({ uuid: 'y' })).toBe('y')
		expect(relationId('')).toBeNull()
		expect(relationId(null)).toBeNull()
	})
})

describe('applyScenario', () => {
	it('writes add, phase out and replace, records the usage on each change and sets appliedAt last', async () => {
		const objects = fakeObjects({ usages: USAGES })
		const result = await applyScenario({ scenario: scenario(), changes: [ADD, PHASE_OUT, REPLACE], objects, now: NOW })

		expect(result.failed).toBeNull()
		expect(result.applied).toEqual(['chg-rep', 'chg-add', 'chg-out'])
		expect(result.appliedAt).toBe('2026-11-21T10:00:00.000Z')

		const usageWrites = objects.saves.filter((s) => s.type === 'usage').map((s) => s.data)
		expect(usageWrites[0]).toMatchObject({ id: 'use-2', module: 'mod-b', plannedReplacement: 'mod-succ', plannedReplacementDate: '2027-01-01' })
		// An addition is created in the lifecycle's initial status (no status sent), then moved to Planned.
		expect(usageWrites[1]).toMatchObject({ consumer: 'org-1', module: 'mod-new', startDateInProduction: '2027-03-01' })
		expect(usageWrites[1].id).toBeUndefined()
		expect(usageWrites[1].status).toBeUndefined()
		expect(usageWrites[2]).toMatchObject({ id: 'usage-new-1', module: 'mod-new', status: 'Planned' })
		expect(usageWrites[3]).toMatchObject({ id: 'use-1', status: 'In production', startDateOutPhased: '2027-06-30' })
		expect(usageWrites[3]['@self']).toBeUndefined()

		const changeWrites = objects.saves.filter((s) => s.type === 'scenarioChange').map((s) => [s.data.id, s.data.appliedTo])
		expect(changeWrites).toEqual([['chg-rep', 'use-2'], ['chg-add', 'usage-new-1'], ['chg-out', 'use-1']])

		const last = objects.saves[objects.saves.length - 1]
		expect(last).toEqual({ type: 'scenario', data: expect.objectContaining({ id: 'scn-1', appliedAt: '2026-11-21T10:00:00.000Z' }) })
	})

	it('skips a change that already carries appliedTo', async () => {
		const objects = fakeObjects({ usages: USAGES })
		const result = await applyScenario({
			scenario: scenario(),
			changes: [{ ...ADD, appliedTo: 'usage-earlier' }, PHASE_OUT],
			objects,
			now: NOW,
		})

		expect(result.skipped).toEqual(['chg-add'])
		expect(result.applied).toEqual(['chg-out'])
		expect(objects.saves.filter((s) => s.type === 'usage')).toHaveLength(1)
		expect(result.appliedAt).not.toBeNull()
	})

	it('stops at a failed write, names the change and leaves appliedAt empty', async () => {
		const objects = fakeObjects({ usages: USAGES, fail: (type, data) => type === 'usage' && data.id === 'use-1' })
		const result = await applyScenario({ scenario: scenario(), changes: [ADD, PHASE_OUT, REPLACE], objects, now: NOW })

		expect(result.applied).toEqual(['chg-rep', 'chg-add'])
		expect(result.failed.change.id).toBe('chg-out')
		expect(result.failed.message).toMatch(/could not be written/)
		expect(result.appliedAt).toBeNull()
		expect(objects.saves.some((s) => s.type === 'scenario')).toBe(false)
	})

	it('stops when the usage to change cannot be read', async () => {
		const objects = fakeObjects({ usages: {} })
		const result = await applyScenario({ scenario: scenario(), changes: [PHASE_OUT], objects, now: NOW })

		expect(result.failed.change.id).toBe('chg-out')
		expect(result.failed.message).toMatch(/could not be read/)
		expect(objects.saves).toEqual([])
	})

	it('leaves appliedAt empty when the scenario itself cannot be marked', async () => {
		const objects = fakeObjects({ usages: USAGES, fail: (type) => type === 'scenario' })
		const result = await applyScenario({ scenario: scenario(), changes: [PHASE_OUT], objects, now: NOW })

		expect(result.applied).toEqual(['chg-out'])
		expect(result.failed).toEqual({ change: null, message: 'The scenario could not be marked as applied.' })
		expect(result.appliedAt).toBeNull()
	})

	it('refuses a scenario that is not adopted or was applied before', async () => {
		const objects = fakeObjects()
		await expect(applyScenario({ scenario: scenario({ status: 'draft' }), changes: [], objects })).rejects.toThrow()
		await expect(applyScenario({ scenario: scenario({ appliedAt: 'x' }), changes: [], objects })).rejects.toThrow()
		expect(objects.saves).toEqual([])
	})
})

describe('storeObjects', () => {
	it('reads and writes through the object store', async () => {
		const calls = []
		const store = {
			fetchObject: async (type, id) => { calls.push(['get', type, id]); return { id } },
			saveObject: async (type, data) => { calls.push(['save', type, data.id]); return data },
		}
		const objects = storeObjects(store)
		await objects.get('usage', 'u1')
		await objects.save('usage', { id: 'u1' })
		expect(calls).toEqual([['get', 'usage', 'u1'], ['save', 'usage', 'u1']])
	})
})
