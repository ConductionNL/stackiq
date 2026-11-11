// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation test for the display rows of the license posture view
 * (vendor names with their fallbacks, licence mix counts, cost labels, the
 * order by deployments) and of the vulnerability exposure panel (application
 * and organisation names with their fallbacks, version names).
 *
 * @spec openspec/specs/software-license-posture/spec.md
 */

import { describe, expect, it, vi } from 'vitest'

const rollups = vi.hoisted(() => ({ vendors: [], exposure: [] }))

vi.mock('../../src/store/store.js', () => ({
	navigationStore: {},
	objectStore: { getCollection: () => undefined },
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDataTable: {},
	CnDetailCard: {},
	CnIndexPage: {},
}))
vi.mock('../../src/composables/useLiveCollections.js', () => ({
	useLiveCollections: () => ({}),
}))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => '[' + text + ']' }))
vi.mock('../../src/utils/licensePosture.js', async (importOriginal) => ({
	...(await importOriginal()),
	perVendorRollup: () => rollups.vendors,
}))
vi.mock('../../src/utils/vulnerabilityExposure.js', () => ({
	computeExposure: () => rollups.exposure,
}))

const { default: LicensePostureView } =
	await import('../../src/views/LicensePostureView.vue')
const { default: VulnerabilityExposurePanel } =
	await import('../../src/components/vulnerabilities/VulnerabilityExposurePanel.vue')
const { LICENSE_TYPE } = await import('../../src/utils/licensePosture.js')

describe('LicensePostureView vendorRows', () => {
	it('names vendors, counts the licence mix, labels costs and orders by deployments', () => {
		rollups.vendors = [
			{
				vendorId: 'v1',
				deployments: 2,
				mix: { [LICENSE_TYPE.OPEN]: 2 },
				annualCost: 1200,
			},
			{
				vendorId: 'v2',
				deployments: 5,
				mix: { [LICENSE_TYPE.CLOSED]: 3, [LICENSE_TYPE.UNKNOWN]: 2 },
				annualCost: null,
			},
			{ vendorId: '', deployments: 1, mix: {}, annualCost: 0 },
		]
		const vm = {
			modules: [],
			usages: [],
			contracts: [],
			organisatieIndex: { v1: 'Vendor One' },
			formatCurrency: (amount) => '€' + amount,
		}
		expect(LicensePostureView.computed.vendorRows.call(vm)).toEqual([
			{
				vendorId: 'v2',
				vendorName: 'v2',
				deployments: 5,
				mix: { openCount: 0, closedCount: 3, unknownCount: 2 },
				costLabel: '—',
			},
			{
				vendorId: 'v1',
				vendorName: 'Vendor One',
				deployments: 2,
				mix: { openCount: 2, closedCount: 0, unknownCount: 0 },
				costLabel: '€1200',
			},
			{
				vendorId: '',
				vendorName: '[Unknown vendor]',
				deployments: 1,
				mix: { openCount: 0, closedCount: 0, unknownCount: 0 },
				costLabel: '€0',
			},
		])
	})
})

describe('VulnerabilityExposurePanel rows', () => {
	it('names the affected applications and organisations', () => {
		rollups.exposure = [
			{
				gebruikId: 'g1',
				moduleId: 'm1',
				afnemerId: 'o1',
				moduleVersieId: 'mv1',
			},
			{
				gebruikId: 'g2',
				moduleId: 'm2',
				afnemerId: 'o2',
				moduleVersieId: 'mvX',
			},
			{ gebruikId: 'g3', moduleId: '', afnemerId: '', moduleVersieId: '' },
		]
		const vm = {
			vulnerability: { id: 'v' },
			usages: [],
			moduleIndex: { m1: 'App One' },
			organisatieIndex: { o1: 'Org One' },
			moduleVersieIndex: { mv1: '1.0' },
		}
		for (const [name, fn] of Object.entries(
			VulnerabilityExposurePanel.methods,
		)) {
			if (typeof fn === 'function') {
				vm[name] = fn.bind(vm)
			}
		}
		expect(VulnerabilityExposurePanel.computed.rows.call(vm)).toEqual([
			{
				gebruikId: 'g1',
				moduleName: 'App One',
				afnemerName: 'Org One',
				versionName: '1.0',
			},
			{
				gebruikId: 'g2',
				moduleName: 'm2',
				afnemerName: 'o2',
				versionName: '',
			},
			{
				gebruikId: 'g3',
				moduleName: '[Unknown application]',
				afnemerName: '[Unknown organisation]',
				versionName: '',
			},
		])
		expect(
			VulnerabilityExposurePanel.computed.rows.call({
				...vm,
				vulnerability: null,
			}),
		).toEqual([])
	})
})
