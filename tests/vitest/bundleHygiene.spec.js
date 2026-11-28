/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Bundle hygiene: stackiq declares no charting library of its own (charts
 * come from nextcloud-vue's CnChartWidget, which brings apexcharts), and no
 * source file imports the lodash barrel, only the function it uses.
 *
 * @spec openspec/specs/frontend-bundle-hygiene/spec.md#requirement-no-duplicate-charting-library-dependency
 */

import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'
import lock from '../../package-lock.json'
import pkg from '../../package.json'

const root = path.resolve(__dirname, '../..')

/**
 * Every .js and .vue file under a directory.
 *
 * @param {string} dir The directory.
 * @return {string[]} The files.
 */
function sources(dir) {
	return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
		const full = path.join(dir, entry.name)
		if (entry.isDirectory()) {
			return sources(full)
		}
		return /\.(js|vue)$/.test(entry.name) ? [full] : []
	})
}

const files = sources(path.join(root, 'src')).map((file) => ({ file: path.relative(root, file), text: fs.readFileSync(file, 'utf8') }))

describe('no duplicate charting library', () => {
	it('declares no apexcharts in package.json', () => {
		for (const group of ['dependencies', 'devDependencies', 'optionalDependencies']) {
			expect(Object.keys(pkg[group] || {})).not.toContain('apexcharts')
		}
		expect(Object.keys(lock.packages[''].dependencies || {})).not.toContain('apexcharts')
	})

	it('locks one apexcharts, the one nextcloud-vue asks for', () => {
		const copies = Object.keys(lock.packages).filter((key) => key.endsWith('node_modules/apexcharts'))

		expect(copies).toEqual(['node_modules/apexcharts'])
		expect(lock.packages['node_modules/@conduction/nextcloud-vue'].dependencies).toHaveProperty('apexcharts')
	})

	it('imports apexcharts nowhere in src; charts go through CnChartWidget', () => {
		const direct = files.filter(({ text }) => /from\s+['"](vue3-)?apexcharts['"]|require\(\s*['"]apexcharts['"]\s*\)/.test(text))

		expect(direct.map(({ file }) => file)).toEqual([])
	})
})

describe('lodash imports are scoped to the function used', () => {
	it('imports no lodash barrel in src', () => {
		const barrel = files.filter(({ text }) => /from\s+['"]lodash['"]|require\(\s*['"]lodash['"]\s*\)/.test(text))

		expect(barrel.map(({ file }) => file)).toEqual([])
	})

	it('scopes the object modal\'s clone and the search sidebar\'s debounce', () => {
		const text = (file) => files.find((entry) => entry.file === file).text

		expect(text('src/modals/object/ObjectModal.vue')).toContain('import cloneDeep from \'lodash/cloneDeep\'')
		expect(text('src/modals/object/ObjectModal.vue')).not.toMatch(/\b_\.\w+\(/)
		expect(text('src/sidebars/search/SearchSideBar.vue')).toContain('import debounce from \'lodash/debounce\'')
	})
})
