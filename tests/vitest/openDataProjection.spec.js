/**
 * Unit tests for the open-data projection serializer.
 *
 * @spec openspec/changes/open-data-publishing/specs/open-data-publishing/spec.md
 */

import { describe, it, expect } from 'vitest'
import { projectOpenData, isClean, DEFAULT_LICENSE } from '../../src/utils/openDataProjection.js'

describe('openDataProjection.projectOpenData', () => {
	const entry = {
		'@self': { uuid: 'u-1', slug: 'app-1', updated: '2026-06-01T10:00:00Z', owner: 'admin' },
		naam: 'Petstore',
		beschrijvingKort: 'Demo app',
		interneAantekening: 'secret internal note',
		contactpersoon: { voornaam: 'Jan', email: 'jan@example.org' },
		contactpersoonAanbieder: { naam: 'Aanbieder' },
		geregistreerdDoor: 'someuser',
	}

	it('strips PII and internal fields', () => {
		const p = projectOpenData(entry)
		expect(p.interneAantekening).toBeUndefined()
		expect(p.contactpersoon).toBeUndefined()
		expect(p.contactpersoonAanbieder).toBeUndefined()
		expect(p.geregistreerdDoor).toBeUndefined()
		expect(p.owner).toBeUndefined()
	})

	it('retains stable identifiers + public fields', () => {
		const p = projectOpenData(entry)
		expect(p.uuid).toBe('u-1')
		expect(p.slug).toBe('app-1')
		expect(p['@id']).toBe('u-1')
		expect(p.naam).toBe('Petstore')
		expect(p.beschrijvingKort).toBe('Demo app')
	})

	it('carries reuse metadata (license, publisher, last-modified)', () => {
		const p = projectOpenData(entry, { license: 'CC-BY-4.0', publisherName: 'Gemeente Test' })
		expect(p.license).toBe('CC-BY-4.0')
		expect(p.publisher).toBe('Gemeente Test')
		expect(p.lastModified).toBe('2026-06-01T10:00:00Z')
	})

	it('defaults the license to CC0', () => {
		expect(projectOpenData(entry).license).toBe(DEFAULT_LICENSE)
	})

	it('reads OR object envelopes with nested object bag', () => {
		const p = projectOpenData({ '@self': { uuid: 'x' }, object: { naam: 'A', email: 'a@b.c' } })
		expect(p.naam).toBe('A')
		expect(p.email).toBeUndefined()
		expect(p.uuid).toBe('x')
	})

	it('produces a clean projection (isClean passes)', () => {
		expect(isClean(projectOpenData(entry))).toBe(true)
	})
})

describe('openDataProjection.isClean', () => {
	it('rejects an object still carrying a PII field', () => {
		expect(isClean({ naam: 'x', email: 'leak@x' })).toBe(false)
		expect(isClean({ naam: 'x', contactpersoon: {} })).toBe(false)
	})
	it('accepts a clean object', () => {
		expect(isClean({ naam: 'x', uuid: 'u', license: 'CC0-1.0' })).toBe(true)
	})
})
