// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { defineConfig, devices } from '@playwright/test'
import * as path from 'path'
import { resolveBaseUrl } from './tests/e2e/base-url'

/**
 * Playwright configuration for softwarecatalog e2e tests.
 *
 * Base URL comes from `tests/e2e/base-url.ts` and has NO default — see the
 * rationale there. Set PLAYWRIGHT_BASE_URL (or CI's BASE_URL) to an isolated
 * instance; never let it fall back to the shared dev container.
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
		baseURL: resolveBaseUrl(),
		storageState: path.resolve(__dirname, 'tests/e2e/.auth/admin.json'),
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
	},
	projects: [
		// Boot smoke check. Runs the same globalSetup (so it is authenticated) but
		// asserts only that the bundle MOUNTS. Run it before the full suite: a
		// dead bundle otherwise burns the whole e2e budget producing failures that
		// look like product defects. See tests/e2e/smoke/app-mounts.spec.ts.
		{
			name: 'smoke',
			testMatch: /smoke\/.*\.spec\.ts$/,
			use: { ...devices['Desktop Chrome'] },
		},
		{
			name: 'chromium',
			// Visual specs run only under the opt-in `visual` project (GAP-5);
			// smoke has its own project and must not run twice.
			testIgnore: ['**/visual/**', '**/smoke/**'],
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
