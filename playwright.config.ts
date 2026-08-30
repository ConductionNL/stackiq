// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { defineConfig, devices } from '@playwright/test'
import * as path from 'path'
import { resolveBaseUrl } from './tests/e2e/base-url'

/**
 * Playwright configuration for stackiq e2e tests.
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
	// The shared quality.yml Playwright job is `timeout-minutes: 45`, and a job
	// cancelled by that cap produces NO verdict: Playwright never prints its
	// tally, the `if: failure()` trace upload never fires, and the
	// `if: always()` report upload does not run on a cancelled job either — the
	// run you most need to read is the one that leaves nothing behind, and it
	// still renders as "fail" in `gh pr checks` while carrying no information.
	// Runs cancelled at ~45m16s have been observed in this fleet. Measured
	// overhead before `Run Playwright tests` starts is 2.0-2.4 min and the
	// uploads after it take seconds, so 38m keeps ~7 min of margin while
	// guaranteeing both a tally and the artifacts that explain it.
	globalTimeout: 38 * 60_000,
	reporter: 'list',
	outputDir: 'test-results',
	use: {
		baseURL: resolveBaseUrl(),
		storageState: path.resolve(__dirname, 'tests/e2e/.auth/admin.json'),
		// `on-first-retry` writes a trace only when a retry actually happens, so
		// the trace artifact is a function of `retries`. Off CI `retries` is 0
		// above, so a local failure has never produced a trace at all; on CI it
		// traces the SECOND attempt only, which means the failure that does not
		// reproduce — the one actually worth a trace — leaves no record of the
		// attempt that failed. `retain-on-failure` traces every attempt and
		// keeps the ones that failed: strictly more informative, and
		// independent of the retry count.
		trace: 'retain-on-failure',
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
