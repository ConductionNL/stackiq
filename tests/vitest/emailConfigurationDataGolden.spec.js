// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the email settings section's initial state, including the
 * catalogue of templates with their variables, and for each instance getting
 * its own copy of that state. Recorded with RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/fe-settings-ui/spec.md
 */

import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/dialogs', () => ({ showError: () => {}, showSuccess: () => {} }))
vi.mock('@nextcloud/vue', async () => {
	const names = [
		'NcButton',
		'NcCheckboxRadioSwitch',
		'NcLoadingIcon',
		'NcNoteCard',
		'NcSelect',
		'NcTextArea',
		'NcTextField',
		'NcPasswordField',
		'NcActions',
		'NcActionButton',
	]
	return Object.fromEntries(names.map((name) => [name, {}]))
})
vi.mock('../../src/components/AlwaysVisibleSection.vue', () => ({ default: {} }))
vi.mock('../../src/components/StandardTabs.vue', () => ({ default: {} }))
vi.mock('../../src/store/store.js', () => ({ settingsStore: {} }))

const { default: EmailConfiguration } =
	await import('../../src/views/settings/sections/EmailConfiguration.vue')

const GOLDEN = path.resolve(
	import.meta.dirname,
	'golden/emailConfigurationData.json',
)

describe('EmailConfiguration data golden', () => {
	it('matches the recorded initial state', () => {
		const actual = EmailConfiguration.data.call({})
		if (process.env.RECORD_GOLDEN === '1') {
			writeFileSync(GOLDEN, JSON.stringify(actual, null, '\t') + '\n')
		}
		expect(actual).toEqual(JSON.parse(readFileSync(GOLDEN, 'utf8')))
	})

	it('gives every instance its own state', () => {
		const first = EmailConfiguration.data.call({})
		const second = EmailConfiguration.data.call({})
		expect(second.availableTemplates).not.toBe(first.availableTemplates)
		expect(second.availableTemplates[0]).not.toBe(first.availableTemplates[0])
		expect(second.availableTemplates[0].variables).not.toBe(
			first.availableTemplates[0].variables,
		)
		expect(second.templates).not.toBe(first.templates)
	})
})
