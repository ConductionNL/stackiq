/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The contracts behind an application: those on a usage of it and those on a
 * service that offers it, each listed once.
 *
 * @spec openspec/changes/landscape-application-page/specs/application-page/spec.md#requirement-req-apg-003-the-application-page-lists-the-contracts-behind-the-application
 */

import { describe, expect, it, vi } from 'vitest'
import {
	loadApplicationContracts,
	rowDetailLocation,
} from '../../src/utils/applicationContracts.js'

/**
 * A fake object store lookup over fixed rows, recording each query.
 *
 * @param {object} rows Rows per type.
 * @return {Function} fetchList(type, params).
 */
function fakeFetch(rows) {
	return vi.fn(async (type, params) => {
		return (rows[type] ?? []).filter((row) =>
			Object.entries(params).every(([key, value]) => {
				if (key.startsWith('_')) return true
				const field = row[key]
				if (Array.isArray(value)) return value.includes(field)
				if (Array.isArray(field)) return field.includes(value)
				return field === value
			}),
		)
	})
}

describe('loadApplicationContracts', () => {
	const rows = {
		usage: [
			{ id: 'u1', module: 'app-x' },
			{ id: 'u2', module: 'app-y' },
		],
		catalogService: [
			{ id: 's1', modules: ['app-x', 'app-z'] },
			{ id: 's2', modules: ['app-y'] },
		],
		catalogContract: [
			{ id: 'c1', usage: 'u1', service: 's9', contractNumber: 'CON-1' },
			{ id: 'c2', usage: 'u7', service: 's1', contractNumber: 'CON-2' },
			{ id: 'c3', usage: 'u1', service: 's1', contractNumber: 'CON-3' },
			{ id: 'c4', usage: 'u2', service: 's2', contractNumber: 'CON-4' },
		],
	}

	it('lists the contracts on a usage of the application and on a service that offers it, once each', async () => {
		const contracts = await loadApplicationContracts('app-x', fakeFetch(rows))
		expect(contracts.map((c) => c.id).sort()).toEqual(['c1', 'c2', 'c3'])
	})

	it('does not query contracts when the application has no usage and no service', async () => {
		const fetchList = fakeFetch(rows)
		const contracts = await loadApplicationContracts('app-none', fetchList)
		expect(contracts).toEqual([])
		expect(fetchList.mock.calls.map(([type]) => type)).toEqual([
			'usage',
			'catalogService',
		])
	})

	it('reads ids from @self when a row carries them there', async () => {
		const selfRows = {
			usage: [{ '@self': { id: 'u1' }, module: 'app-x' }],
			catalogService: [],
			catalogContract: [{ '@self': { id: 'c1' }, usage: 'u1' }],
		}
		const contracts = await loadApplicationContracts(
			'app-x',
			fakeFetch(selfRows),
		)
		expect(contracts).toHaveLength(1)
	})
})

describe('rowDetailLocation', () => {
	it('routes a row to the named detail page', () => {
		expect(rowDetailLocation('ModuleDetail', { id: 'app-x' })).toEqual({
			name: 'ModuleDetail',
			params: { id: 'app-x' },
		})
	})

	it('does not route when the page names no detail route', () => {
		expect(rowDetailLocation('', { id: 'app-x' })).toBeNull()
	})

	it('does not route a row without an id', () => {
		expect(rowDetailLocation('ModuleDetail', {})).toBeNull()
	})
})
