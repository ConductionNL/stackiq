// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// Visual-regression baselines for SoftwareCatalog's key surfaces (GAP-5).
//
// Run:    npx playwright test --project visual
// Update: npx playwright test --project visual --update-snapshots
//
// Baselines live in tests/e2e/visual/<spec>-snapshots/ and ARE committed.
// See _visual-helpers.ts for the platform-rendering caveat.
//
// NOTE: softwarecatalog serves its SPA at /apps/softwarecatalog/index
// (the bare /apps/softwarecatalog/ route 404s), so navigation targets the
// /index entrypoint.
import { test } from '@playwright/test'
import { shootSurface, shootByNav } from './_visual-helpers'

const APP = '/index.php/apps/softwarecatalog/index'

test.describe('SoftwareCatalog — visual baselines', () => {
	test('dashboard', async ({ page }) => {
		await shootSurface(page, `${APP}#/`, 'dashboard.png')
	})

	test('organisations list', async ({ page }) => {
		await shootByNav(page, `${APP}#/`, 'Organisations', 'organisations.png')
	})
})
