/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * SaaS subscriptions: every usage hosted as SaaS with its supplier, the yearly
 * cost of its contracts and who bought it, and the register fields that record
 * the buying department and whether IT was involved.
 *
 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/saas-subscriptions/spec.md#requirement-the-licences-page-lists-saas-subscriptions-with-their-yearly-cost-including-ones-bought-outside-it-req-clr-001
 */
import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'
import { isBoughtOutsideIt, isSaas, saasSubscriptionRows } from '../../src/utils/saasSubscriptions.js'

const root = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(root, file), 'utf8'))

const MODULES = [
	{ uuid: 'm-menti', name: 'Mentimeter', provider: 'org-menti' },
	{ uuid: 'm-zaak', name: 'Zaaksysteem', provider: { uuid: 'org-zaak' } },
	{ uuid: 'm-mail', name: 'Mail', provider: 'org-mail' },
]
const USAGES = [
	{ uuid: 'u-menti', module: 'm-menti', consumer: 'org-gem', cloudDienstverleningsmodel: ['SaaS'], buyingDepartment: 'Communicatie', boughtOutsideIt: true },
	{ uuid: 'u-zaak', module: 'm-zaak', consumer: 'org-gem', cloudDienstverleningsmodel: ['On-premises (self-managed)'] },
	{ object: { id: 'u-mail', module: { uuid: 'm-mail' }, consumer: 'org-gem', cloudDienstverleningsmodel: ['SaaS', 'IaaS'] } },
]
const CONTRACTS = [
	{ uuid: 'c1', usage: 'u-menti', cost: 100, costPeriod: 'Monthly' },
	{ uuid: 'c2', usage: 'u-menti', cost: 250, costPeriod: 'One-off' },
	{ uuid: 'c3', usage: 'u-zaak', cost: 9000, costPeriod: 'Annually' },
]

describe('isSaas and isBoughtOutsideIt', () => {
	it('reads the hosting list and the purchase flag', () => {
		expect(isSaas(USAGES[0])).toBe(true)
		expect(isSaas(USAGES[1])).toBe(false)
		expect(isSaas(USAGES[2])).toBe(true)
		expect(isSaas({ cloudDienstverleningsmodel: 'SaaS' })).toBe(true)
		expect(isSaas({})).toBe(false)
		expect(isBoughtOutsideIt(USAGES[0])).toBe(true)
		expect(isBoughtOutsideIt(USAGES[2])).toBe(false)
	})
})

describe('saasSubscriptionRows', () => {
	it('lists every SaaS usage with its supplier, department and yearly cost, leaving on-premises out', () => {
		expect(saasSubscriptionRows({ usages: USAGES, contracts: CONTRACTS, modules: MODULES })).toEqual([
			{ usageId: 'u-menti', moduleId: 'm-menti', supplierId: 'org-menti', consumerId: 'org-gem', department: 'Communicatie', boughtOutsideIt: true, annualCost: 1200 },
			{ usageId: 'u-mail', moduleId: 'm-mail', supplierId: 'org-mail', consumerId: 'org-gem', department: '', boughtOutsideIt: false, annualCost: null },
		])
	})

	it('keeps only the subscriptions bought outside IT when asked', () => {
		const rows = saasSubscriptionRows({ usages: USAGES, contracts: CONTRACTS, modules: MODULES }, true)
		expect(rows.map((row) => row.usageId)).toEqual(['u-menti'])
	})

	it('reads empty collections as no rows', () => {
		expect(saasSubscriptionRows({})).toEqual([])
	})
})

describe('the usage fields', () => {
	const fragment = read('lib/Settings/register.d/saas-subscriptions.json')
	const usage = fragment.components.schemas.usage

	it('records the buying department and whether IT was involved', () => {
		expect(usage.properties.buyingDepartment).toMatchObject({ type: 'string', title: 'Buying department', facetable: true })
		expect(usage.properties.boughtOutsideIt).toMatchObject({ type: 'boolean', title: 'Bought outside IT', default: false, facetable: true })
	})

	it('raises the usage version above every other definition of it', () => {
		const versions = [read('lib/Settings/softwarecatalogus_register.json').components.schemas.usage.version]
		for (const file of fs.readdirSync(path.join(root, 'lib/Settings/register.d'))) {
			if (file.endsWith('.json') && file !== 'saas-subscriptions.json') {
				const other = read(`lib/Settings/register.d/${file}`).components?.schemas?.usage?.version
				if (other) {
					versions.push(other)
				}
			}
		}
		const toNumbers = (v) => v.split('.').map(Number)
		const greater = (a, b) => {
			const [pa, pb] = [toNumbers(a), toNumbers(b)]
			for (let i = 0; i < 3; i++) {
				if (pa[i] !== pb[i]) {
					return pa[i] > pb[i]
				}
			}
			return false
		}
		for (const version of versions) {
			expect(greater(usage.version, version)).toBe(true)
		}
	})

	it('shows both fields on the usage page', () => {
		const manifest = read('src/manifest.d/usages.json')
		const detail = manifest.pages.find((p) => p.id === 'GebruikDetail')
		const data = detail.config.widgets.find((w) => w.id === 'gb-data')
		expect(data.content.include).toEqual(expect.arrayContaining(['buyingDepartment', 'boughtOutsideIt']))
	})
})
