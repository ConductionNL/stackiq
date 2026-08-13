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
 *   • src/components/*Section.vue — the settings-section SLOT CONTRACT, which
 *     is the one thing about those components that no other check can see:
 *     a mis-named slot renders NOTHING and throws no error anywhere.
 *
 * Most specs need no DOM, so the default environment stays `node`. The specs
 * that mount a component opt into jsdom per file with a
 * `// @vitest-environment jsdom` docblock, and `@vitejs/plugin-vue` compiles
 * the SFCs they import.
 *
 * The few @nextcloud/* runtime packages a store module or a component drags in
 * are aliased to lightweight stubs.
 *
 * The existing Jest suite (jest + co-located *.spec.js under src/) is
 * UNTOUCHED — Vitest only collects tests/vitest/**.
 */

const path = require('path')

// `@vitejs/plugin-vue` v6 is ESM-only while this config is CommonJS, so the
// plugin is pulled in through a dynamic import from an async config factory
// (Vite awaits a config exported as a function).
module.exports = async () => {
	const vue = (await import('@vitejs/plugin-vue')).default

	return {
		plugins: [vue()],
		test: {
			environment: 'node',
			globals: false,
			include: ['tests/vitest/**/*.spec.{js,ts}'],
			exclude: [
				'tests/e2e/**',
				'tests/integration/**',
				'src/**',
				'node_modules/**',
			],
		},
		resolve: {
			alias: [
				{ find: '@', replacement: path.resolve(__dirname, 'src') },
				{
					find: /^@nextcloud\/router$/,
					replacement: path.resolve(
						__dirname,
						'tests/vitest/stubs/nextcloud-router.js',
					),
				},
				{
					find: /^@nextcloud\/dialogs$/,
					replacement: path.resolve(
						__dirname,
						'tests/vitest/stubs/nextcloud-dialogs.js',
					),
				},
				{
					find: /^@nextcloud\/l10n$/,
					replacement: path.resolve(
						__dirname,
						'tests/vitest/stubs/nextcloud-l10n.js',
					),
				},
				{
					find: /^@nextcloud\/vue$/,
					replacement: path.resolve(
						__dirname,
						'tests/vitest/stubs/nextcloud-vue.js',
					),
				},
			],
		},
	}
}
