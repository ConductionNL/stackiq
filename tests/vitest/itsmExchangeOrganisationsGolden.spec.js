// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the organisations the ITSM exchange section offers: the
 * requests, the organisations (suppliers left out) and the messages when the
 * register is not configured or a request fails. Recorded with RECORD_GOLDEN=1.
 *
 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-001-an-administrator-sets-up-the-exchange-without-stackiq-holding-a-credential
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'

const trace = []
const answers = []

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: async (url, config) => {
			trace.push(['axios.get', url, config ?? null])
			const answer = answers.shift()
			if (answer instanceof Error) throw answer
			return answer
		},
	},
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params) =>
		params ? `${url} ${JSON.stringify(params)}` : url,
}))

const { default: ItsmExchange } =
	await import('../../src/views/settings/sections/ItsmExchange.vue')

const GOLDEN = path.resolve(
	import.meta.dirname,
	'golden/itsmExchangeOrganisations.json',
)

const CONFIG = { data: { config: { register: 'r', organisatie_schema: 's' } } }

const LOADS = {
	'not configured': [{ data: { config: { organisatie_schema: 's' } } }],
	'no config': [{}],
	listed: [
		CONFIG,
		{
			data: {
				results: [
					{ id: 'a', name: 'Gemeente A', type: 'Municipality' },
					{ '@self': { id: 'b', name: 'Self B' }, type: 'Collaboration' },
					{ id: 'c', '@self': { id: 'c2' } },
					{ id: 'd', name: 'Leverancier', type: 'Supplier' },
				],
			},
		},
	],
	'no results': [CONFIG, { data: {} }],
	'config fails': [new Error('down')],
	'list fails': [CONFIG, new Error('down')],
}

describe('ITSM exchange organisations golden', () => {
	it('loads the same organisations and messages', async () => {
		const out = {}
		for (const [name, loads] of Object.entries(LOADS)) {
			trace.length = 0
			answers.length = 0
			answers.push(...loads)
			const vm = {
				organisations: ['before'],
				organisationLoadError: 'before',
				loadingOrganisations: false,
			}
			for (const [method, fn] of Object.entries(ItsmExchange.methods)) {
				vm[method] = fn.bind(vm)
			}
			await vm.loadOrganisations()
			out[name] = {
				trace: structuredClone(trace),
				organisations: vm.organisations,
				organisationLoadError: vm.organisationLoadError,
				loadingOrganisations: vm.loadingOrganisations,
			}
		}
		if (process.env.RECORD_GOLDEN === '1') {
			writeFileSync(GOLDEN, JSON.stringify(out, null, '\t') + '\n')
		}
		expect(out).toEqual(JSON.parse(readFileSync(GOLDEN, 'utf8')))
	})
})
