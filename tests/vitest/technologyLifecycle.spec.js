/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The support state of a component and the worst state an application in use
 * runs on, one level down (operations-technology-components task 3).
 *
 * @spec openspec/changes/archive/2026-11-24-operations-technology-components/specs/technology-components/spec.md#requirement-req-tco-003-an-application-in-use-shall-record-the-components-it-runs-on-and-show-their-support-state
 */

import { describe, expect, it, vi } from 'vitest'
import { exposure, loadUsageExposure, supportState, worse } from '../../src/utils/technologyLifecycle.js'

const TODAY = '2026-11-14'

describe('supportState', () => {
	it.each([
		['2026-03-03', 'ended'],
		['2026-11-13', 'ended'],
		['2026-11-14', 'ending'],
		['2027-05-13', 'ending'],
		['2027-05-14', 'supported'],
		['2029-12-31', 'supported'],
		['', 'unknown'],
		[undefined, 'unknown'],
		['soon', 'unknown'],
	])('an end of support of %s is %s', (endOfSupport, state) => {
		expect(supportState({ endOfSupport }, TODAY)).toBe(state)
	})

	it('reads a date-time as its day, and a missing component as unknown', () => {
		expect(supportState({ endOfSupport: '2026-11-13T23:59:59Z' }, TODAY)).toBe('ended')
		expect(supportState(null, TODAY)).toBe('unknown')
		expect(supportState({ endOfSupport: '2026-11-13' }, new Date('2026-11-14T08:00:00Z'))).toBe('ended')
	})
})

describe('exposure', () => {
	const components = {
		host: { id: 'host', name: 'Host 01', endOfSupport: '2026-01-31' },
		vm: { id: 'vm', name: 'App server 01', runsOn: ['host'] },
		db: { id: 'db', name: 'Database 01', endOfSupport: '2026-03-03', runsOn: [{ id: 'vm' }] },
		new: { id: 'new', name: 'Host 02', endOfSupport: '2030-01-01' },
	}

	it('a virtual machine without a date on an ended host is exposed as ended', () => {
		const result = exposure(['vm'], components, TODAY)

		expect(result.state).toBe('ended')
		expect(result.components).toEqual([
			{ id: 'vm', name: 'App server 01', state: 'unknown', through: null },
			{ id: 'host', name: 'Host 01', state: 'ended', through: 'vm' },
		])
	})

	it('lists the demo database as ended and looks only one level down', () => {
		const result = exposure([{ id: 'db' }, 'new'], components, TODAY)

		expect(result.state).toBe('ended')
		expect(result.components.map((row) => [row.name, row.state, row.through])).toEqual([
			['Database 01', 'ended', null],
			['App server 01', 'unknown', 'db'],
			['Host 02', 'supported', null],
		])
	})

	it('is supported only when every component is', () => {
		expect(exposure(['new'], components, TODAY).state).toBe('supported')
		expect(exposure([], components, TODAY)).toEqual({ state: 'unknown', components: [] })
		expect(exposure(undefined, components, TODAY).state).toBe('unknown')
	})

	it('uses an embedded component when it is not in the lookup', () => {
		const result = exposure([{ id: 'x', name: 'Router', endOfSupport: '2027-01-01' }], {}, TODAY)

		expect(result.components[0]).toEqual({ id: 'x', name: 'Router', state: 'ending', through: null })
	})

	it('orders the states from supported to ended', () => {
		expect(worse('supported', 'unknown')).toBe('unknown')
		expect(worse('ending', 'unknown')).toBe('ending')
		expect(worse('ended', 'ending')).toBe('ended')
	})
})

describe('loadUsageExposure', () => {
	const store = {
		usage: { u1: { object: { runsOn: [{ id: 'db' }, 'gone'] } } },
		technologyComponent: {
			db: { object: { name: 'Database 01', endOfSupport: '2026-03-03', runsOn: ['vm'] } },
			vm: { name: 'App server 01', runsOn: ['host'] },
			host: { name: 'Host 01', endOfSupport: '2029-12-31' },
		},
	}
	const fetchObject = vi.fn(async (type, id) => {
		if (id === 'gone') {
			throw new Error('404')
		}
		return store[type]?.[id] ?? null
	})

	it('loads the usage, its components and those they run on, one level down', async () => {
		fetchObject.mockClear()
		const result = await loadUsageExposure('u1', fetchObject, TODAY)

		expect(fetchObject.mock.calls).toEqual([
			['usage', 'u1'],
			['technologyComponent', 'db'],
			['technologyComponent', 'vm'],
			['technologyComponent', 'gone'],
		])
		expect(result.state).toBe('ended')
		expect(result.components).toEqual([
			{ id: 'db', name: 'Database 01', state: 'ended', through: null, endOfSupport: '2026-03-03' },
			{ id: 'vm', name: 'App server 01', state: 'unknown', through: 'db', endOfSupport: null },
			{ id: 'gone', name: 'gone', state: 'unknown', through: null, endOfSupport: null },
		])
	})

	it('a usage that runs on nothing is unknown, with no component lookups', async () => {
		fetchObject.mockClear()
		const result = await loadUsageExposure('missing', fetchObject, TODAY)

		expect(result).toEqual({ state: 'unknown', components: [] })
		expect(fetchObject).toHaveBeenCalledTimes(1)
	})
})
