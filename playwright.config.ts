// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { defineConfig, devices } from '@playwright/test'
import * as path from 'path'

/**
 * Playwright configuration for softwarecatalog e2e tests.
 * Base URL: http://localhost:8080 (Nextcloud dev container)
 *
 * globalSetup logs in once and persists session state to
 * tests/e2e/.auth/admin.json so all specs start pre-authenticated.
 */
export default defineConfig({
	testDir: './tests/e2e',
	globalSetup: path.resolve(__dirname, 'tests/e2e/global-setup.ts'),
	timeout: 60_000,
	expect: { timeout: 15_000 },
	fullyParallel: false,
	forbidOnly: !!process.env.CI,
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	reporter: 'list',
	outputDir: 'test-results',
	use: {
		baseURL: process.env.BASE_URL ?? process.env.NEXTCLOUD_URL ?? 'http://localhost:8080',
		storageState: path.resolve(__dirname, 'tests/e2e/.auth/admin.json'),
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
	},
	projects: [
		{
			name: 'chromium',
			// Visual specs run only under the opt-in `visual` project (GAP-5).
			testIgnore: ['**/visual/**'],
			use: { ...devices['Desktop Chrome'] },
		},
		// Visual-regression project (GAP-5). Opt-in / non-gating:
		//   npx playwright test --project visual
		//   npx playwright test --project visual --update-snapshots  (rebaseline)
		// Fixed viewport + authenticated session => deterministic shots.
		// Baselines live in tests/e2e/visual/*-snapshots/ and ARE committed.
		// PLATFORM CAVEAT: PNG baselines are host-font/GPU specific, so a CI
		// Linux runner will not byte-match a dev-container baseline; the visual
		// project must regenerate its baselines in-CI before it can gate.
		{
			name: 'visual',
			testMatch: /visual\/.*\.visual\.spec\.ts$/,
			use: {
				...devices['Desktop Chrome'],
				viewport: { width: 1280, height: 800 },
				storageState: path.resolve(__dirname, 'tests/e2e/.auth/admin.json'),
			},
			timeout: 90_000,
		},
	],
})
