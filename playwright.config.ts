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
			use: { ...devices['Desktop Chrome'] },
		},
	],
})
