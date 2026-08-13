// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * E2e coverage file for openspec/specs/sbom-import/spec.md
 *
 * Coverage status
 * ---------------
 * The parse/replace/batch/matching CONTRACTS are pure server-side or
 * pure-function logic, verified by PHPUnit (`tests/Unit/SbomParserServiceTest`,
 * `tests/Unit/SbomImportServiceTest`, `tests/Unit/Controller/SbomControllerTest`)
 * and vitest (`tests/vitest/sbomVulnerabilityMatch.spec.js`) against real
 * CycloneDX fixtures — excluded from Playwright coverage below:
 *
 * @e2e sbom-import::a-valid-cyclonedx-16-document-parses-into-components
 * @e2e sbom-import::an-unsupported-bomformat-or-specversion-is-rejected
 * @e2e sbom-import::an-oversized-file-is-rejected-before-parsing
 * @e2e sbom-import::a-non-json-file-is-rejected
 * @e2e sbom-import::import-requires-admin-or-manage-acl
 * @e2e sbom-import::a-parsed-component-persists-with-its-moduleversie-relation
 * @e2e sbom-import::a-prior-replaces-trashed-rows-are-not-reprocessed
 * @e2e sbom-import::a-large-sbom-import-reports-incremental-progress
 * @e2e sbom-import::a-small-sbom-import-completes-without-a-progress-operation
 * @e2e sbom-import::a-component-with-vex-declared-cve-data-gets-a-confirmed-match
 * @e2e sbom-import::a-component-name-matching-a-module-scoped-vulnerability-gets-a-possible-match
 * @e2e sbom-import::a-name-match-outside-the-modules-own-vulnerabilities-is-not-surfaced
 * @e2e sbom-import::editing-a-vulnerability-changes-the-match-with-no-re-import
 * @e2e sbom-import::no-outbound-http-call-is-made-during-matching
 * @e2e sbom-import::a-successful-import-records-provenance-on-the-version
 * @e2e sbom-import::existing-versions-are-unaffected-by-the-schema-addition
 *
 * The two REMAINING scenarios describe the rendered Components tab and are
 * covered below by driving the REAL DOM (file input via `setInputFiles`,
 * NcSelect combobox, real button clicks) — no Vue `$data` patching:
 *
 * @e2e sbom-import::the-components-tab-reflects-an-import
 * @e2e sbom-import::a-version-with-no-imported-sbom-shows-an-empty-state
 *
 * Fixture setup (module + moduleVersie) is seeded through the OpenRegister
 * object API per the gate-19 program (setup only — assertions stay on the
 * rendered DOM); a real CycloneDX fixture file already used by the PHPUnit
 * suite (`tests/fixtures/sbom/cyclonedx-1.6-valid.json`,
 * `cyclonedx-1.5-valid.json`) is uploaded through the real file input.
 */

import { test, expect, type Page } from '@playwright/test'
import * as path from 'path'
import {
	newApiContext,
	resolveConfig,
	createObject,
	cleanupByToken,
	RUN_ID,
} from './workflows/_fixtures'
import { dismissWalkthrough } from './spec-coverage/_helpers'
import { APP_PATH } from './base-url'

const FIXTURES_DIR = path.resolve(__dirname, '../fixtures/sbom')
const CYCLONEDX_16 = path.join(FIXTURES_DIR, 'cyclonedx-1.6-valid.json') // 3 components
const CYCLONEDX_15 = path.join(FIXTURES_DIR, 'cyclonedx-1.5-valid.json') // 2 components

const MODULE_NAME = `E2E SBOM Module ${RUN_ID}`

let moduleVersieId: string

test.beforeAll(async () => {
	const ctx = await newApiContext()
	try {
		const config = await resolveConfig(ctx)
		const moduleId = await createObject(
			ctx,
			config.register,
			config.module_schema,
			{
				naam: MODULE_NAME,
			},
		)
		moduleVersieId = await createObject(
			ctx,
			config.register,
			config.moduleVersie_schema,
			{
				module: moduleId,
				versie: '1.0.0-e2e',
			},
		)
	} finally {
		await ctx.dispose()
	}
})

test.afterAll(async () => {
	const ctx = await newApiContext()
	try {
		const config = await resolveConfig(ctx)
		await cleanupByToken(ctx, config, RUN_ID)
	} finally {
		await ctx.dispose()
	}
})

/** Navigate to a moduleVersie's detail page and open the Components sidebar tab. */
async function openComponentsTab(page: Page): Promise<void> {
	// The in-app router is hash-mode: a bare `/apps/softwarecatalog/moduleversies/:id`
	// path boots the SPA with an empty hash, so vue-router falls back to the
	// Dashboard and the detail page (with its Components tab) never mounts. Deep
	// links MUST carry the `#` route.
	//
	// `/index.php/...`, not the pretty path — see the APP_PATH docblock in
	// tests/e2e/base-url.ts. `domcontentloaded`, not `networkidle`: the SPA keeps
	// a background poll alive so the network never goes idle; the app-root wait
	// below is the real readiness signal.
	await page.goto(`${APP_PATH}/#/moduleversies/${moduleVersieId}`, {
		waitUntil: 'domcontentloaded',
	})
	await page
		.locator('.softwarecatalog-app-root')
		.first()
		.waitFor({ state: 'attached', timeout: 30000 })
	// The first-run walkthrough overlay intercepts pointer events — dismiss it
	// before touching anything.
	await dismissWalkthrough(page)
	// The detail page mounts with its right-hand sidebar collapsed, and the
	// Components / History tabs live INSIDE that sidebar — present in the DOM but
	// not visible, so a direct tab click waits forever. Open the sidebar first.
	const openSidebar = page.getByRole('button', { name: 'Open sidebar' })
	if (await openSidebar.isVisible().catch(() => false)) {
		await openSidebar.click()
	}
	await page.getByRole('tab', { name: 'Components' }).click()
}

// ---------------------------------------------------------------------------
// Scenario: A version with no imported SBOM shows an empty state
// @e2e sbom-import::a-version-with-no-imported-sbom-shows-an-empty-state
// ---------------------------------------------------------------------------
test('sbom-import empty-state: a freshly-created moduleVersie Components tab shows the empty state and upload control', async ({
	page,
}) => {
	await openComponentsTab(page)

	await expect(page.getByTestId('sbom-empty')).toBeVisible({ timeout: 15000 })
	await expect(page.getByTestId('sbom-file-input')).toBeVisible()
	await expect(page.getByTestId('sbom-import-button')).toBeVisible()

	// Summary tiles read zero — "no summary counts shown as non-zero".
	const summary = page.getByTestId('sbom-summary')
	await expect(summary).toContainText('0')
})

// ---------------------------------------------------------------------------
// Scenario: The Components tab reflects an import
// @e2e sbom-import::the-components-tab-reflects-an-import
//
// Also exercises re-import-replaces (design Decision 3): a second upload
// with a different fixture leaves only the new set's 2 rows, not 3+2.
// ---------------------------------------------------------------------------
test('sbom-import upload-and-replace: uploading a CycloneDX file renders the component list and summary counts; a second import replaces the first', async ({
	page,
}) => {
	// Two full import round-trips (parse + persist + vulnerability match) run
	// back-to-back here; on a shared/loaded instance each can approach the
	// default 30s slice, so give this test the tripled budget.
	test.slow()

	await openComponentsTab(page)

	// First import: 3-component fixture.
	await page.getByTestId('sbom-file-input').setInputFiles(CYCLONEDX_16)
	await page.getByTestId('sbom-import-button').click()
	await expect(page.getByTestId('sbom-upload-success')).toBeVisible({
		timeout: 40000,
	})

	const table = page.getByTestId('sbom-component-table')
	await expect(table).toBeVisible()
	await expect(table.getByText('lodash')).toBeVisible()
	await expect(table.locator('tbody tr')).toHaveCount(3)

	const summary = page.getByTestId('sbom-summary')
	await expect(summary).toContainText('3')

	// Provenance line renders after a successful import.
	await expect(page.getByTestId('sbom-provenance')).toBeVisible()

	// Second import (different fixture, 2 components) REPLACES the first —
	// only the new set is live afterwards.
	await page.getByTestId('sbom-file-input').setInputFiles(CYCLONEDX_15)
	await page.getByTestId('sbom-import-button').click()
	await expect(page.getByTestId('sbom-upload-success')).toBeVisible({
		timeout: 40000,
	})

	await expect(table.locator('tbody tr')).toHaveCount(2)
	await expect(table.getByText('lodash')).toHaveCount(0)
	await expect(table.getByText('express')).toBeVisible()
})
