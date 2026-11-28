/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Components take their colours from the theme: stylelint fails on a hex
 * colour outside a var() fallback, and the components under src/ pass.
 *
 * @spec openspec/specs/platform-properties/spec.md#requirement-the-look-comes-from-the-theme-not-the-code-req-plat-003
 */
import { createRequire } from 'node:module'
import path from 'node:path'
import stylelint from 'stylelint'
import { describe, expect, it } from 'vitest'

const require = createRequire(import.meta.url)
const root = path.resolve(__dirname, '../..')
const { hexOutsideVarFallback } = require('../../scripts/stylelint/color-no-hex-outside-var.js')

const RULE = 'stackiq/color-no-hex-outside-var'

/**
 * Lint a component's style block with the repository's stylelint config.
 *
 * @param {string} css The style block's body.
 * @return {Promise<object[]>} The warnings of the theme-colour rule.
 */
async function lintComponent(css) {
	const { results } = await stylelint.lint({
		code: `<template><div /></template>\n<style scoped>\n${css}\n</style>\n`,
		codeFilename: path.join(root, 'src/components/Example.vue'),
		configFile: path.join(root, 'stylelint.config.js'),
	})
	return results[0].warnings.filter((w) => w.rule === RULE)
}

describe('hexOutsideVarFallback', () => {
	it('finds a bare hex colour', () => {
		expect(hexOutsideVarFallback('#c62828')).toEqual(['#c62828'])
		expect(hexOutsideVarFallback('1px solid #fff')).toEqual(['#fff'])
	})

	it('accepts a hex as the fallback of a var()', () => {
		expect(hexOutsideVarFallback('var(--color-error-text, #c62828)')).toEqual([])
		expect(hexOutsideVarFallback('1px solid var(--color-border, #dee2e6)')).toEqual([])
		expect(hexOutsideVarFallback('var(--a, var(--b, #123456))')).toEqual([])
	})

	it('finds a hex next to a var() rather than in its fallback', () => {
		expect(hexOutsideVarFallback('linear-gradient(var(--color-primary), #000000)')).toEqual(['#000000'])
	})

	it('ignores words that only look like a colour', () => {
		expect(hexOutsideVarFallback('var(--color-main-text)')).toEqual([])
		expect(hexOutsideVarFallback('"#c62828"')).toEqual([])
	})
})

describe('stylelint theme colours on components', () => {
	it('fails on a hex colour outside a var() fallback', async () => {
		const warnings = await lintComponent('.badge {\n\tcolor: #c62828;\n}')
		expect(warnings).toHaveLength(1)
		expect(warnings[0].line).toBe(4)
		expect(warnings[0].severity).toBe('error')
	})

	it('passes a theme variable with a hex fallback', async () => {
		expect(await lintComponent('.badge {\n\tcolor: var(--color-error-text, #c62828);\n}')).toEqual([])
	})

	it('leaves custom property definitions alone', async () => {
		expect(await lintComponent('.badge {\n\t--badge-colour: #c62828;\n}')).toEqual([])
	})

	it('finds no hard-coded colour in the components under src/', async () => {
		const { results } = await stylelint.lint({
			files: 'src/**/*.vue',
			cwd: root,
			configFile: path.join(root, 'stylelint.config.js'),
		})
		const offenders = results.flatMap((r) => r.warnings
			.filter((w) => w.rule === RULE)
			.map((w) => `${path.relative(root, r.source)}:${w.line} ${w.text}`))
		expect(offenders).toEqual([])
	}, 120000)
})
