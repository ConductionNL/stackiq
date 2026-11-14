// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Client-side downloads of an application's connections (design D3): the
 * links as CSV, the drawn map as SVG. Nothing goes to the server, so a
 * download holds exactly what the user may read.
 *
 * @spec openspec/changes/connections-diagram-and-graph-export/specs/connection-diagram-and-export/spec.md#requirement-req-cdx-003-a-user-can-download-an-applications-map-and-its-links
 */

import { connectionEnds } from './connectionLayout.js'

/** The CSV header, in column order. */
export const CSV_COLUMNS = ['Application A', 'Direction', 'Application B or national provision', 'Type', 'Status']

/**
 * One CSV field, quoted when it holds a separator, a quote or a line break
 * (RFC 4180), and with a leading formula character neutralised so a
 * spreadsheet never evaluates a name as a formula.
 *
 * @param {string|number|boolean|null|undefined} value The value.
 * @return {string} The field.
 */
export function csvField(value) {
	let text = value === null || value === undefined ? '' : String(value)
	if (/^[=+\-@\t\r]/.test(text)) {
		text = `'${text}`
	}
	if (/[",\r\n]/.test(text)) {
		return `"${text.replace(/"/g, '""')}"`
	}
	return text
}

/**
 * The connections as CSV, one line per connection, CRLF line ends.
 *
 * @param {Array<object>} connections The connections.
 * @return {string} The CSV text.
 * @spec openspec/changes/connections-diagram-and-graph-export/specs/connection-diagram-and-export/spec.md#requirement-req-cdx-003-a-user-can-download-an-applications-map-and-its-links
 */
export function connectionsCsv(connections) {
	const lines = [CSV_COLUMNS.map(csvField).join(',')]
	for (const connection of connections || []) {
		const { a, b } = connectionEnds(connection)
		lines.push([
			a ? a.name : '',
			connection?.dataExchangeDirection ?? '',
			b ? b.name : '',
			connection?.type ?? '',
			connection?.status ?? '',
		].map(csvField).join(','))
	}
	return lines.join('\r\n') + '\r\n'
}

/**
 * A rendered SVG element as a standalone SVG document.
 *
 * @param {SVGElement} svg The drawn map.
 * @return {string} The SVG markup, with the namespace declared.
 * @spec openspec/changes/connections-diagram-and-graph-export/specs/connection-diagram-and-export/spec.md#requirement-req-cdx-003-a-user-can-download-an-applications-map-and-its-links
 */
export function svgDocument(svg) {
	const clone = svg.cloneNode(true)
	if (!clone.getAttribute('xmlns')) {
		clone.setAttribute('xmlns', 'http://www.w3.org/2000/svg')
	}
	return '<?xml version="1.0" encoding="UTF-8"?>\n' + new XMLSerializer().serializeToString(clone)
}

/**
 * Offer text as a file download.
 *
 * @param {string} text The content.
 * @param {string} fileName The file name.
 * @param {string} type The media type.
 * @return {void}
 */
export function downloadText(text, fileName, type) {
	const url = URL.createObjectURL(new Blob([text], { type }))
	const link = document.createElement('a')
	link.href = url
	link.download = fileName
	document.body.appendChild(link)
	link.click()
	link.remove()
	URL.revokeObjectURL(url)
}
