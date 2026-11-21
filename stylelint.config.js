/**
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Components take their colours from the Nextcloud theme: a hex colour in a
 * `.vue` file under `src/` is an error unless it is the fallback of a var().
 *
 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-the-look-comes-from-the-theme-not-the-code-req-plat-003
 */
module.exports = {
	extends: '@nextcloud/stylelint-config',
	plugins: [require.resolve('./scripts/stylelint/color-no-hex-outside-var.js')],
	overrides: [
		{
			files: ['src/**/*.vue'],
			rules: {
				'stackiq/color-no-hex-outside-var': true,
			},
		},
	],
}
