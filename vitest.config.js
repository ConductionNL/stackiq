/**
 * SPDX-FileCopyrightText: 2026 Conduction / SoftwareCatalog Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest configuration for SoftwareCatalog frontend unit tests.
 *
 * This OFFLINE suite (no Nextcloud runtime) targets PURE app-local logic that
 * the rendered UI exercises end-to-end but never asserts exactly:
 *   • src/utils/translationBadge.js — language-code normalisation, source-
 *     language extraction off the OpenRegister `@self` envelope, and the
 *     translated-from badge decision used by every index view.
 *   • src/store/modules/navigation.js — the UI navigation Pinia store
 *     (selected view / organisatie / modal / dialog + the consume-once
 *     transferData handoff), driven through a real Pinia instance.
 *
 * These need no DOM, so the environment is `node`. The few @nextcloud/*
 * runtime packages a store module drags in are aliased to lightweight stubs.
 *
 * The existing Jest suite (jest + co-located *.spec.js under src/) is
 * UNTOUCHED — Vitest only collects tests/vitest/**.
 */

const path = require('path')

module.exports = {
	test: {
		environment: 'node',
		globals: false,
		include: ['tests/vitest/**/*.spec.{js,ts}'],
		exclude: ['tests/e2e/**', 'tests/integration/**', 'src/**', 'node_modules/**'],
	},
	resolve: {
		alias: [
			{ find: '@', replacement: path.resolve(__dirname, 'src') },
			{
				find: /^@nextcloud\/router$/,
				replacement: path.resolve(__dirname, 'tests/vitest/stubs/nextcloud-router.js'),
			},
			{
				find: /^@nextcloud\/dialogs$/,
				replacement: path.resolve(__dirname, 'tests/vitest/stubs/nextcloud-dialogs.js'),
			},
			{
				find: /^@nextcloud\/l10n$/,
				replacement: path.resolve(__dirname, 'tests/vitest/stubs/nextcloud-l10n.js'),
			},
		],
	},
}
