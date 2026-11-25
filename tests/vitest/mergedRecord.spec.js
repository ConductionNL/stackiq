/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Merged records: the survivor of a merged record, and the address of
 * OpenRegister's Duplicate candidates page.
 *
 * @spec openspec/changes/archive/2026-11-25-operations-record-reconciliation
 */

import { describe, expect, it } from 'vitest'
import { duplicateCandidatesUrl, MERGED_STATUS, survivorOf, withoutMerged } from '../../src/utils/mergedRecord.js'

describe('survivorOf', () => {
	it('is the mergedInto uuid of a Merged record', () => {
		expect(survivorOf({ recordStatus: MERGED_STATUS, mergedInto: 'u-2' })).toBe('u-2')
	})

	it('reads the id of an expanded mergedInto', () => {
		expect(survivorOf({ recordStatus: 'Merged', mergedInto: { id: 'u-3', name: 'Suite' } })).toBe('u-3')
		expect(survivorOf({ recordStatus: 'Merged', mergedInto: { uuid: 'u-4' } })).toBe('u-4')
	})

	it('is null for an Active record, even one with a stale mergedInto', () => {
		expect(survivorOf({ recordStatus: 'Active', mergedInto: 'u-2' })).toBeNull()
		expect(survivorOf({ mergedInto: 'u-2' })).toBeNull()
	})

	it('is null for a Merged record without a survivor, and for no record', () => {
		expect(survivorOf({ recordStatus: 'Merged', mergedInto: '' })).toBeNull()
		expect(survivorOf({ recordStatus: 'Merged' })).toBeNull()
		expect(survivorOf({ recordStatus: 'Merged', mergedInto: {} })).toBeNull()
		expect(survivorOf(null)).toBeNull()
	})
})

describe('duplicateCandidatesUrl', () => {
	it("is OpenRegister's duplicates page", () => {
		expect(duplicateCandidatesUrl((path) => '/index.php' + path)).toBe('/index.php/apps/openregister/duplicates')
	})
})

describe('withoutMerged', () => {
	it('adds recordStatus[ne]=Merged to an empty filter', () => {
		expect(withoutMerged({})).toEqual({ 'recordStatus[ne]': 'Merged' })
		expect(withoutMerged(null)).toEqual({ 'recordStatus[ne]': 'Merged' })
	})

	it('keeps the facet id selection beside it', () => {
		expect(withoutMerged({ _ids: ['u-1', 'u-2'] })).toEqual({ _ids: ['u-1', 'u-2'], 'recordStatus[ne]': 'Merged' })
	})
})
