// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The CSV and SVG downloads of an application's connections.
 *
 * @spec openspec/changes/connections-diagram-and-graph-export/specs/connection-diagram-and-export/spec.md#requirement-req-cdx-003-a-user-can-download-an-applications-map-and-its-links
 */

import { describe, expect, it } from 'vitest'
import { connectionsCsv, CSV_COLUMNS, csvField, svgDocument } from '../../src/utils/connectionExport.js'

describe('connectionsCsv', () => {
	it('writes the header and one line per connection', () => {
		const csv = connectionsCsv([
			{ moduleA: { id: 'z', name: 'Zaaksysteem' }, moduleB: { id: 'd', name: 'DMS' }, dataExchangeDirection: 'AtoB', type: 'api', status: 'in use' },
			{ moduleA: { id: 'z', name: 'Zaaksysteem' }, nonMunicipalProvision: { id: 'p', name: 'BRP' }, dataExchangeDirection: 'bi-directional', type: 'digikoppeling', status: 'in development' },
		])

		expect(csv).toBe(
			'Application A,Direction,Application B or national provision,Type,Status\r\n'
			+ 'Zaaksysteem,AtoB,DMS,api,in use\r\n'
			+ 'Zaaksysteem,bi-directional,BRP,digikoppeling,in development\r\n',
		)
		expect(CSV_COLUMNS).toHaveLength(5)
	})

	it('quotes separators, quotes and line breaks', () => {
		expect(csvField('Zaken, Documenten')).toBe('"Zaken, Documenten"')
		expect(csvField('De "nieuwe" app')).toBe('"De ""nieuwe"" app"')
		expect(csvField('twee\nregels')).toBe('"twee\nregels"')
		expect(csvField(null)).toBe('')
	})

	it('neutralises a value a spreadsheet would read as a formula', () => {
		expect(csvField('=HYPERLINK("x")')).toBe('"\'=HYPERLINK(""x"")"')
		expect(csvField('+31')).toBe("'+31")
		expect(csvField('@sum')).toBe("'@sum")
	})

	it('leaves a missing end empty', () => {
		expect(connectionsCsv([{ moduleA: { id: 'z', name: 'Z' }, type: 'api' }]).split('\r\n')[1]).toBe('Z,,,api,')
	})
})

describe('svgDocument', () => {
	it('serialises the drawn map as a standalone SVG with its nodes', () => {
		const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg')
		const text = document.createElementNS('http://www.w3.org/2000/svg', 'text')
		text.textContent = 'Zaaksysteem'
		svg.appendChild(text)

		const out = svgDocument(svg)

		expect(out.startsWith('<?xml version="1.0" encoding="UTF-8"?>\n<svg')).toBe(true)
		expect(out).toContain('xmlns="http://www.w3.org/2000/svg"')
		expect(out).toContain('<text>Zaaksysteem</text>')
	})
})
