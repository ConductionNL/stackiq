// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The applications that support a process step, and the ones a reader may not see (REQ-BPM-002).

import { describe, expect, it, vi } from 'vitest'
import { loadProcessSteps, loadStepUsages, storedUsageIds, usageName } from '../../src/utils/processStepUsages.js'

function store(objects, lists = {}) {
	return {
		fetchObject: vi.fn(async (type, id) => objects[type + ':' + id] ?? null),
		fetchList: vi.fn(async (type, params) => (lists[type] ?? []).filter((row) => row.process === params.process)),
	}
}

describe('processStepUsages', () => {
	it('shows the visible usage and counts the one the reader may not see', async () => {
		const { fetchObject } = store({
			'processStep:s1': { id: 's1', usages: ['u1', { id: 'u2' }, 'u1'] },
			'usage:u1': { id: 'u1', '@self': { name: 'TOPdesk (Gemeente Leiden)' } },
		})
		const result = await loadStepUsages('s1', fetchObject)
		expect(result).toEqual({ visible: [{ id: 'u1', name: 'TOPdesk (Gemeente Leiden)' }], hidden: 1 })
		expect(fetchObject).toHaveBeenCalledWith('usage', 'u2')
	})

	it('a step without usages, or a step the reader cannot open, shows nothing hidden', async () => {
		expect(await loadStepUsages('s1', store({ 'processStep:s1': { id: 's1' } }).fetchObject)).toEqual({ visible: [], hidden: 0 })
		expect(await loadStepUsages('gone', store({}).fetchObject)).toEqual({ visible: [], hidden: 0 })
	})

	it('a fetch that throws counts as not visible', async () => {
		const fetchObject = vi.fn(async (type) => {
			if (type === 'processStep') return { usages: ['u1'] }
			throw new Error('403')
		})
		expect(await loadStepUsages('s1', fetchObject)).toEqual({ visible: [], hidden: 1 })
	})

	it('reads the steps of one process with the names of their applications', async () => {
		const s = store(
			{ 'usage:u1': { name: 'Zaaksysteem' } },
			{ processStep: [{ id: 'a', process: 'p1', usages: ['u1', 'u9'] }, { id: 'b', process: 'p2' }] },
		)
		const result = await loadProcessSteps('p1', s)
		expect(s.fetchList).toHaveBeenCalledWith('processStep', { process: 'p1', _limit: 500 })
		expect(result.steps.map((step) => step.id)).toEqual(['a'])
		expect(result.usageNames).toEqual({ u1: 'Zaaksysteem' })
	})

	it('names a usage by its object name, then its own name, then its id', () => {
		expect(usageName({ '@self': { name: 'A' }, name: 'B' }, 'id')).toBe('A')
		expect(usageName({ name: 'B' }, 'id')).toBe('B')
		expect(usageName({}, 'id')).toBe('id')
		expect(storedUsageIds(null)).toEqual([])
	})
})
