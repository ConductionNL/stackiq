/**
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
module.exports = {
	extends: '@nextcloud/stylelint-config',
	rules: {
		// The look comes from the Nextcloud theme, not the code (REQ-PLAT-003):
		// a colour is a theme variable such as var(--color-main-text), never a hex value.
		// @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-the-look-comes-from-the-theme-not-the-code-req-plat-003
		'color-no-hex': true,
	},
}
