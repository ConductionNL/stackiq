/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The look comes from the theme, not the code (platform-properties,
 * REQ-PLAT-003): the app's stylelint rule refuses a hex colour anywhere but as
 * the fallback of a var(), and the app's own styles pass it.
 *
 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md
 */
import { createRequire } from 'module'
import * as path from 'path'
import stylelint from 'stylelint'
import { describe, expect, it } from 'vitest'

const require = createRequire(import.meta.url)
const { findHexOutsideVar } = require('../../scripts/stylelint/color-no-hex-outside-var.js')
const root = path.resolve(__dirname, '../..')

/**
 * Lint a piece of CSS with the app's stylelint config.
 *
 * @param {string} code The CSS to lint.
 * @return {Promise<Array<object>>} The stackiq/color-no-hex warnings.
 */
async function lint(code) {
	const result = await stylelint.lint({
		code,
		configFile: path.join(root, 'stylelint.config.js'),
		configBasedir: root,
	})
	return result.results[0].warnings.filter((w) => w.rule === 'stackiq/color-no-hex')
}

describe('findHexOutsideVar', () => {
	it('keeps a hex that is the fallback of a var()', () => {
		expect(findHexOutsideVar('var(--color-success, #46ba61)')).toEqual([])
	})

	it('keeps a hex inside a nested var() fallback', () => {
		expect(findHexOutsideVar('var(--a, var(--b, #fff))')).toEqual([])
	})

	it('reports a bare hex with its offset', () => {
		expect(findHexOutsideVar('1px solid #c3e6cb')).toEqual([{ hex: '#c3e6cb', index: 10 }])
	})

	it('reports the hex after a var() but not the one inside it', () => {
		expect(findHexOutsideVar('var(--x, #111) #222')).toEqual([{ hex: '#222', index: 15 }])
	})

	it('reports 3, 4 and 8 digit forms and ignores longer words', () => {
		expect(findHexOutsideVar('#abc #abcd #aabbccdd').map((f) => f.hex)).toEqual(['#abc', '#abcd', '#aabbccdd'])
		expect(findHexOutsideVar('url(#abcdefgh)')).toEqual([])
	})
})

describe('stackiq/color-no-hex', () => {
	it('fails on a hex colour outside a var() fallback', async () => {
		const warnings = await lint('.a {\n\tcolor: #155724;\n}\n')
		expect(warnings).toHaveLength(1)
		expect(warnings[0].text).toContain('#155724')
		expect(warnings[0].severity).toBe('error')
	})

	it('passes a theme variable with a hex fallback', async () => {
		expect(await lint('.a {\n\tcolor: var(--color-success-text, #155724);\n}\n')).toEqual([])
	})

	it('passes every style file of the app', async () => {
		const result = await stylelint.lint({
			files: ['src/**/*.{vue,scss,css}', 'css/**/*.{vue,scss,css}'],
			cwd: root,
			configFile: path.join(root, 'stylelint.config.js'),
			configBasedir: root,
		})
		const offending = result.results.flatMap((r) => r.warnings
			.filter((w) => w.rule === 'stackiq/color-no-hex')
			.map((w) => `${path.relative(root, r.source)}:${w.line} ${w.text}`))
		expect(offending).toEqual([])
	}, 120000)
})
