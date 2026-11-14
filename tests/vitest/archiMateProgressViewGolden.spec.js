/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the ArchiMate import progressView over every phase and
 * count shape. Recorded with RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/archimate-import-progress/spec.md
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({
	t: (app, text, vars = {}) =>
		`${app}:` + text.replace(/{(\w+)}/g, (m, k) => String(vars[k] ?? m)),
}))

const { progressView } = await import('../../src/utils/archiMateImportProgress.js')

const GOLDEN = path.resolve(
	import.meta.dirname,
	'golden/archiMateProgressViewGolden.json',
)

const PHASES = [
	'initializing',
	'validating',
	'parsing',
	'analyzing',
	'processing_elements',
	'finalizing',
	'other',
	undefined,
	'toString',
]
const COUNTS = [
	{},
	{ processed_items: 3, total_items: 10, percentage: 30 },
	{ processed_items: '4', total_items: '0', percentage: 'x' },
	{ processed_items: null, total_items: 2 },
]

describe('ArchiMate progress view golden', () => {
	it('answers the same for every phase and count', () => {
		const value = JSON.parse(
			JSON.stringify([
				progressView(null),
				...PHASES.flatMap((phase) =>
					COUNTS.map((c) => progressView({ ...c, phase })),
				),
			]),
		)
		if (process.env.RECORD_GOLDEN) {
			writeFileSync(GOLDEN, JSON.stringify(value, null, '\t') + '\n')
			return
		}
		expect(JSON.stringify(value)).toBe(
			JSON.stringify(JSON.parse(readFileSync(GOLDEN, 'utf8'))),
		)
	})
})
