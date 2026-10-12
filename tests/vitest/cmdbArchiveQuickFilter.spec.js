/**
 * The Applications and Applications in use pages list the archive on request.
 *
 * OpenRegister leaves archived objects out of every list unless the request
 * asks for `_archived`; CnIndexPage spreads the active quick filter's map into
 * the list request as it is, so a quick filter `{ _archived: 'true' }` lists
 * the archive alone. The CMDB import archives applications that moved to the
 * sheet "Gearchiveerde Applicaties", and without this filter an admin could
 * not find them in stackiq any more.
 *
 * @spec openspec/changes/cmdb-import-archive-reconciliation/specs/cmdb-export-import/spec.md#requirement-archived-applications-and-usages-shall-be-listable-in-stackiq-and-shall-stay-out-of-opencatalogi-and-portaliq-by-default-req-cmdb-017
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import fs from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'
import { describe, expect, it } from 'vitest'

const here = path.dirname(fileURLToPath(import.meta.url))
const repoRoot = path.resolve(here, '../..')

/**
 * Every page of the manifest and its fragments.
 *
 * @return {Array<object>} The pages
 */
function pages() {
	const files = [
		path.join(repoRoot, 'src/manifest.json'),
		...fs
			.readdirSync(path.join(repoRoot, 'src/manifest.d'))
			.filter((name) => name.endsWith('.json'))
			.map((name) => path.join(repoRoot, 'src/manifest.d', name)),
	]
	return files.flatMap(
		(file) => JSON.parse(fs.readFileSync(file, 'utf8')).pages ?? [],
	)
}

describe('the archive quick filter', () => {
	it.each(['Modules', 'Gebruik'])(
		'the %s page lists the archive alone under "Archived"',
		(pageId) => {
			const page = pages().find((candidate) => candidate.id === pageId)
			const archived = (page?.config?.quickFilters ?? []).filter(
				(tab) => tab.label === 'Archived',
			)

			expect(archived).toHaveLength(1)
			expect(archived[0].filter).toEqual({ _archived: 'true' })
			expect(archived[0].default).not.toBe(true)
		},
	)

	it('uses an icon the app registers', () => {
		const icons = fs.readFileSync(path.join(repoRoot, 'src/icons.js'), 'utf8')
		expect(icons).toMatch(/^\tArchiveOutline,$/m)
	})
})
