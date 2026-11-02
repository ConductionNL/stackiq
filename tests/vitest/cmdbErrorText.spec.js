/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the CMDB import's error texts: every error code the page
 * knows, with and without the details that change the words, records the
 * title and hint with each translation call's arguments. Recorded with
 * RECORD_GOLDEN=1.
 *
 * @spec openspec/changes/cmdb-export-import/specs/cmdb-export-import/spec.md#requirement-columns-shall-be-resolved-by-header-name-and-a-missing-required-column-shall-stop-the-import-with-422-req-cmdb-003
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { afterAll, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({
	translate: (...args) => JSON.stringify(args),
	getLanguage: () => 'en',
}))

const { errorText } = await import('../../src/utils/cmdbImport.js')

const GOLDEN = path.resolve(import.meta.dirname, 'golden/cmdbErrorText.json')
const recorded = {}
const expected = process.env.RECORD_GOLDEN
	? {}
	: JSON.parse(readFileSync(GOLDEN, 'utf8'))

const CODES = [
	'NO_FILE_UPLOADED',
	'NOT_XLSX',
	'FILE_TOO_LARGE',
	'MISSING_RECORDS_UNSUPPORTED',
	'FIELD_INVALID',
	'UPLOAD_FAILED',
	'MUNICIPALITY_REQUIRED',
	'MUNICIPALITY_INVALID',
	'NO_SOURCE_SHEET',
	'MISSING_COLUMN',
	'TOO_MANY_ROWS',
	'MAPPING_UNAVAILABLE',
	'READER_UNAVAILABLE',
	'NOT_CONFIGURED',
	'WORKBOOK_TOO_LARGE',
	'SCHEMA_OUTDATED',
	'IMPORT_IN_PROGRESS',
	'MUNICIPALITY_AMBIGUOUS',
	'ARCHIVE_UNAVAILABLE',
	'OPERATION_NOT_FOUND',
	'NOT_SIGNED_IN',
	'NOT_ADMIN',
	'CSRF_FAILED',
	'NETWORK_ERROR',
	'IMPORT_INTERRUPTED',
	'IMPORT_FAILED',
	'SOMETHING_ELSE',
	'toString',
	'__proto__',
	'constructor',
	'',
]

const DETAILS = {
	none: undefined,
	empty: {},
	full: {
		maxBytes: 20971520,
		accepted: ['archive', 'keep', 3],
		field: 'missing',
		expected: ['Applicaties', 'Koppelingen'],
		sheet: 'Applicaties',
		column: 'APPID',
		limit: 50000,
		schema: 'application',
		matches: [{ id: 1 }, { id: 2 }, { id: 3 }],
		maxUncompressedBytes: 104857600,
	},
	single: {
		maxBytes: '0',
		accepted: [],
		field: null,
		expected: ['Applicaties'],
		limit: -1,
		matches: [{ id: 1 }],
	},
	notArrays: { accepted: 'archive', expected: 'Applicaties', matches: 'x' },
	part: { maxPartBytes: 1000, part: 'xl/sharedStrings.xml', size: 2000 },
	strings: { maxSharedStrings: 10 },
	referenced: { maxReferencedStringBytes: 5000 },
}

const CONTEXTS = {
	defaults: [],
	one: [['Only'], 'Archive'],
	many: [['A', 'B', 'C'], null],
}

describe('cmdb import error texts', () => {
	afterAll(() => {
		if (process.env.RECORD_GOLDEN) {
			writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
		}
	})

	for (const code of CODES) {
		it('code ' + JSON.stringify(code), () => {
			const out = {}
			for (const [dName, details] of Object.entries(DETAILS)) {
				for (const [cName, context] of Object.entries(CONTEXTS)) {
					out[dName + '/' + cName] = errorText(
						{ error: code, details },
						...context,
					)
				}
			}
			recorded['code:' + code] = out
			if (!process.env.RECORD_GOLDEN) {
				expect(out).toEqual(expected['code:' + code])
			}
		})
	}

	it('missing or odd errors', () => {
		const out = [
			errorText(undefined),
			errorText(null),
			errorText({}),
			errorText({ error: 42 }),
			errorText({ error: { toString: () => 'NOT_XLSX' } }),
			errorText({ error: 'NOT_XLSX', message: 'ignored' }),
		]
		recorded.odd = out
		if (!process.env.RECORD_GOLDEN) {
			expect(out).toEqual(expected.odd)
		}
	})
})
