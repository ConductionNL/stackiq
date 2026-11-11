// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the facts the CMDB import mapping shows about the import
 * profile: the sheets read and the match, name, required and date columns,
 * for well-formed and malformed profiles. Recorded with RECORD_GOLDEN=1.
 *
 * @spec openspec/changes/cmdb-import-mapping-view/specs/cmdb-export-import/spec.md#requirement-the-admin-settings-shall-show-the-mapping-the-import-uses-req-cmdb-020
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => '[' + text + ']' }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {},
	NcLoadingIcon: {},
	NcNoteCard: {},
}))
vi.mock('@conduction/nextcloud-vue', () => ({ CnStatusBadge: {} }))

const { default: CmdbImportMapping } =
	await import('../../src/views/settings/sections/CmdbImportMapping.vue')

const GOLDEN = path.resolve(import.meta.dirname, 'golden/cmdbProfileFacts.json')

const MAPPINGS = {
	'no mapping': null,
	'no profile': {},
	'empty profile': { profile: {} },
	'full profile': {
		profile: {
			sheets: [
				{ name: 'Applicaties' },
				'Leveranciers',
				{ name: '' },
				null,
				{ other: 1 },
				7,
			],
			keyColumn: 'ID',
			nameColumn: 0,
			requiredColumns: ['ID', '', 'Naam', 3, null],
			dateColumns: ['Ingangsdatum'],
		},
	},
	'malformed profile': {
		profile: {
			sheets: 'Applicaties',
			keyColumn: null,
			nameColumn: '',
			requiredColumns: 'ID',
			dateColumns: { a: 1 },
		},
	},
	'boolean columns': { profile: { keyColumn: false, nameColumn: true } },
}

describe('CmdbImportMapping profileFacts golden', () => {
	it('matches the recorded facts', () => {
		const actual = {}
		const computed =
			CmdbImportMapping.computed || CmdbImportMapping.options?.computed
		const methods =
			CmdbImportMapping.methods || CmdbImportMapping.options?.methods || {}
		for (const [name, mapping] of Object.entries(MAPPINGS)) {
			const vm = { mapping }
			for (const [method, fn] of Object.entries(methods)) {
				vm[method] = fn.bind(vm)
			}
			actual[name] = computed.profileFacts.call(vm)
		}
		if (process.env.RECORD_GOLDEN === '1') {
			writeFileSync(GOLDEN, JSON.stringify(actual, null, '\t') + '\n')
		}
		expect(JSON.stringify(actual)).toBe(
			JSON.stringify(JSON.parse(readFileSync(GOLDEN, 'utf8'))),
		)
	})
})
