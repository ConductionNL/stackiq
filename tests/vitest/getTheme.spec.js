// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * getTheme and isDarkTheme: Nextcloud's theming name first, then the dark class, then light.
 *
 * @spec openspec/specs/fe-stores/spec.md
 */

import { afterEach, describe, expect, it } from 'vitest'
import { getTheme, isDarkTheme } from '../../src/services/getTheme.js'

describe('getTheme', () => {
	afterEach(() => {
		delete globalThis.OCA
		document.documentElement.classList.remove('theme--dark')
	})

	it('is light without theming and without the dark class', () => {
		expect(getTheme()).toBe('light')
		expect(isDarkTheme()).toBe(false)
	})

	it('is dark when the document carries the dark class', () => {
		document.documentElement.classList.add('theme--dark')
		expect(getTheme()).toBe('dark')
		expect(isDarkTheme()).toBe(true)
	})

	it('prefers the theming name over the dark class', () => {
		globalThis.OCA = { Theming: { name: 'Gemeente Utrecht' } }
		document.documentElement.classList.add('theme--dark')
		expect(getTheme()).toBe('Gemeente Utrecht')
		expect(isDarkTheme()).toBe(false)
	})

	it('reports dark when theming itself names the dark theme', () => {
		globalThis.OCA = { Theming: { name: 'dark' } }
		expect(isDarkTheme()).toBe(true)
	})

	it('falls through when theming has no name, or OCA has no theming', () => {
		globalThis.OCA = { Theming: { name: '' } }
		expect(getTheme()).toBe('light')
		globalThis.OCA = {}
		document.documentElement.classList.add('theme--dark')
		expect(getTheme()).toBe('dark')
	})
})
