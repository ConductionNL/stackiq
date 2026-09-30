/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Record reconciliation in the catalogue: who is led to OpenRegister's
 * duplicate candidates, merged records out of the lists, the Merged into
 * banner on the application page, and the removed app-local merge modal.
 *
 * @spec openspec/specs/record-reconciliation/spec.md
 */

import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'
import { buildManifest } from '../../node_modules/@conduction/nextcloud-vue/src/utils/buildManifest.js'
import base from '../../src/manifest.json'
import menuLayout from '../../src/menu-layout.json'
import {
	activeRecordsFilter,
	canFindDuplicates,
	DUPLICATE_CANDIDATES_PATH,
	mergedIntoId,
} from '../../src/utils/recordReconciliation.js'

const root = path.resolve(__dirname, '../..')
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8')
const dir = path.join(root, 'src/manifest.d')
const merged = buildManifest(
	base,
	fs
		.readdirSync(dir)
		.filter((f) => f.endsWith('.json'))
		.sort()
		.map((f) => JSON.parse(fs.readFileSync(path.join(dir, f), 'utf8'))),
	menuLayout,
)
const page = (id) => merged.pages.find((p) => p.id === id)

describe('Find duplicates', () => {
	it('is offered to admins and functional administrators only', () => {
		expect(canFindDuplicates({ isAdmin: true, isFunctionalAdmin: false })).toBe(true)
		expect(canFindDuplicates({ isAdmin: false, isFunctionalAdmin: true })).toBe(true)
		expect(canFindDuplicates({ isAdmin: false, isFunctionalAdmin: false })).toBe(false)
		expect(canFindDuplicates(null)).toBe(false)
	})

	it("opens OpenRegister's duplicate candidates page", () => {
		expect(DUPLICATE_CANDIDATES_PATH).toBe('/apps/openregister/duplicates')
	})

	it('sits in the toolbar of the Applications and Services pages, gated on the right', () => {
		const view = read('src/views/FacetedCatalogIndexView.vue')
		expect(view).toMatch(/v-if="showFindDuplicates"/)
		expect(view).toContain("t('stackiq', 'Find duplicates')")
		expect(page('Modules').component).toBe('FacetedCatalogIndexView')
		expect(page('Diensten').component).toBe('FacetedCatalogIndexView')
	})

	it('sits in the admin merge panel of an organisation', () => {
		const panel = read('src/components/organisations/OrganisationMergePanel.vue')
		expect(panel).toContain("t('stackiq', 'Find duplicates')")
		expect(panel).toContain('DUPLICATE_CANDIDATES_PATH')
	})
})

describe('merged records', () => {
	it('are filtered out of the list while other filters stay', () => {
		expect(activeRecordsFilter({})).toEqual({ 'recordStatus[ne]': 'Merged' })
		expect(activeRecordsFilter({ id: ['a', 'b'] })).toEqual({ id: ['a', 'b'], 'recordStatus[ne]': 'Merged' })
	})

	it('point to the record they were merged into', () => {
		expect(mergedIntoId({ recordStatus: 'Merged', mergedInto: 'orig' })).toBe('orig')
		expect(mergedIntoId({ recordStatus: 'Merged', mergedInto: { id: 'orig' } })).toBe('orig')
		expect(mergedIntoId({ recordStatus: 'Active', mergedInto: 'orig' })).toBe(null)
		expect(mergedIntoId({ recordStatus: 'Merged' })).toBe(null)
		expect(mergedIntoId(null)).toBe(null)
	})

	it('show the Merged into banner first on the application page', () => {
		const banner = (page('ModuleDetail').config.bodyWidgets || []).find((w) => w.component === 'MergedRecordBanner')
		expect(banner).toBeTruthy()
		expect(banner.props.objectId).toBe('@objectId')
		expect(read('src/customComponents.js')).toMatch(/^\s*MergedRecordBanner,$/m)
	})
})

describe('the app-local merge modal', () => {
	it('is gone, with its modal branch and store action', () => {
		expect(fs.existsSync(path.join(root, 'src/modals/object/MergeObject.vue'))).toBe(false)
		expect(read('src/modals/Modals.vue')).not.toContain('mergeOrganisatie')
		expect(read('src/store/plugins/stackiqPlugin.js')).not.toMatch(/mergeObjects\s*\(/)
	})
})
