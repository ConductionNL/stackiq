/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * The English browser catalogues (l10n/en.js, l10n/en_*.js) carry only the
 * entries whose text differs from the key: `t()` answers the key itself when a
 * catalogue has none, so identity entries were ~200 KB per page for nothing.
 * l10n/en.json stays complete for PHP and the source-key checks.
 */
import fs from 'fs'
import path from 'path'
import vm from 'vm'
import { createRequire } from 'module'
import { describe, expect, it } from 'vitest'

const require = createRequire(import.meta.url)
const { browserEntries } = require('../../scripts/build-l10n-js.js')
const L10N = path.resolve(__dirname, '../../l10n')

/**
 * Read the translations an OC.L10N.register(...) file registers.
 *
 * @param {string} file - absolute path of the .js catalogue
 * @return {object} key -> translation
 */
function loadJs(file) {
	let captured = null
	const sandbox = { OC: { L10N: { register: (id, obj) => { captured = obj } } } }
	vm.createContext(sandbox)
	vm.runInContext(fs.readFileSync(file, 'utf8'), sandbox)
	return captured
}

describe('browser catalogue entries', () => {
	it('drops identity entries for en and en_*', () => {
		const translations = { Save: 'Save', Colour: 'Color', _a_: ['_a_', '_b_'] }
		expect(browserEntries('en', translations)).toEqual({ Colour: 'Color', _a_: ['_a_', '_b_'] })
		expect(browserEntries('en_US', translations)).toEqual({ Colour: 'Color', _a_: ['_a_', '_b_'] })
	})

	it('keeps every entry for other locales', () => {
		const translations = { Save: 'Save', Cancel: 'Annuleren' }
		expect(browserEntries('nl', translations)).toEqual(translations)
	})
})

describe('l10n/en*.js as committed', () => {
	const english = fs.readdirSync(L10N).filter((f) => /^en(_[A-Za-z]+)?\.js$/.test(f))

	it('finds the English catalogues', () => {
		expect(english).toContain('en.js')
	})

	for (const file of english) {
		it(`${file} carries no identity entry and agrees with its .json`, () => {
			const js = loadJs(path.join(L10N, file))
			const json = JSON.parse(fs.readFileSync(path.join(L10N, file.replace(/\.js$/, '.json')), 'utf8')).translations
			const identity = Object.keys(js).filter((k) => js[k] === k)
			expect(identity).toEqual([])
			for (const key of Object.keys(js)) {
				expect(json[key]).toEqual(js[key])
			}
		})
	}
})
