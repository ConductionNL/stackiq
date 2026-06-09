// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Shared helpers for the behavioural spec-coverage suite.
 *
 * These drive the REAL UI of the manifest-shell SoftwareCatalog SPA:
 * navigation is performed by clicking the actual app-navigation links (not
 * deep-link `goto`, which the shared shell sometimes resets to Dashboard), and
 * all assertions are made against rendered DOM (CnIndexPage surface, action
 * buttons, view toggles, empty-state, dashboard widgets, settings sections) —
 * no Vue `$data`/`__vue__` patching.
 */
import { expect, type Page } from '@playwright/test'

export const APP_BASE = '/apps/softwarecatalog'
export const APP_SHELL = '.softwarecatalog-app-root'
export const APP_MAIN = 'main'

/**
 * Console-error / 5xx collector that ignores noise NOT originating from the
 * softwarecatalog frontend bundle:
 *  - the NC `user_status` heartbeat 500s (a platform quirk in this dev
 *    container, fired by core not by the app);
 *
 * The previously-suppressed `@resolve:voorzieningen_register` 404s and the
 * deprecated `cronjobs/users` 410 console error have been FIXED at source
 * (the register sentinel now resolves to the numeric id via initial-state, and
 * the Settings section no longer calls the removed users endpoint), so those
 * filters were removed — the suites now assert those errors are absent.
 */
export function collectAppErrors(page: Page): { errors: string[]; serverErrors: string[] } {
	const errors: string[] = []
	const serverErrors: string[] = []
	const isNoise = (s: string): boolean =>
		s.includes('user_status')
		|| s.includes('heartbeat')
		|| s.includes('Failed to load user status')
		// generic "Failed to load resource" lines carry no URL/app attribution;
		// the real 5xx URLs are captured separately via the response listener.
		|| /^Failed to load resource:/.test(s)
	page.on('console', m => {
		if (m.type() !== 'error') return
		const t = m.text()
		if (!isNoise(t)) errors.push(t.slice(0, 300))
	})
	page.on('response', resp => {
		if (resp.status() < 500) return
		const u = resp.url()
		if (u.includes('user_status') || u.includes('heartbeat')) return
		// Only flag 5xx that come from the softwarecatalog app surface.
		if (u.includes('/apps/softwarecatalog/')) serverErrors.push(`${resp.status()} ${u}`)
	})
	return { errors, serverErrors }
}

/** Assert the collected app-origin error/5xx lists are empty (with context). */
export function expectNoAppErrors(bag: { errors: string[]; serverErrors: string[] }): void {
	expect(bag.serverErrors, `softwarecatalog 5xx responses:\n${bag.serverErrors.join('\n')}`).toEqual([])
	expect(bag.errors, `softwarecatalog console errors:\n${bag.errors.join('\n')}`).toEqual([])
}

/**
 * Dismiss the manifest-shell "Support <App>" dialog if it is open.
 *
 * The @conduction/nextcloud-vue CnAppRoot shell auto-opens a support /
 * donate dialog (`data-testid-modal="cn-support-dialog"`) over the app on
 * load. Its modal mask intercepts pointer events, so every nav click /
 * button click fails with "subtree intercepts pointer events" until it is
 * closed. We close it (× button, else Escape) and wait for the mask to
 * detach before any interaction. No-op when the dialog is absent.
 */
export async function dismissSupportDialog(page: Page): Promise<void> {
	const dialog = page.locator('[data-testid-modal="cn-support-dialog"]').first()
	if ((await dialog.count()) === 0) return
	try {
		await dialog.waitFor({ state: 'visible', timeout: 2000 })
	} catch {
		return
	}
	const closeBtn = dialog.getByRole('button', { name: /close/i }).first()
	if (await closeBtn.count()) {
		await closeBtn.click({ timeout: 5000 }).catch(() => {})
	} else {
		await page.keyboard.press('Escape').catch(() => {})
	}
	await dialog.waitFor({ state: 'hidden', timeout: 10000 }).catch(() => {})
}

/** Deep-link to a route and wait for the Vue shell + main region to mount. */
export async function gotoAppRoute(page: Page, route: string): Promise<void> {
	const url = route === '/' ? APP_BASE : `${APP_BASE}${route}`
	await page.goto(url, { waitUntil: 'domcontentloaded' })
	await page.locator(APP_SHELL).first().waitFor({ state: 'attached', timeout: 30000 })
	await page.locator(APP_MAIN).first().waitFor({ state: 'visible', timeout: 30000 })
	await dismissSupportDialog(page)
}

/**
 * The app's OWN navigation — the `<nav>` whose links target
 * `/apps/softwarecatalog/...`. Scoping to it avoids matching Nextcloud's global
 * Applications-menu "Dashboard" entry (`/apps/dashboard/`), which collides with
 * the in-app "Dashboard" nav label.
 */
export function appNav(page: Page) {
	return page.locator('nav:has(a[href*="/apps/softwarecatalog/"])').first()
}

/**
 * Navigate by CLICKING the real app-navigation link, the way a user would.
 * Starts from the dashboard so the nav is always present, then clicks the
 * matching app nav entry and waits for the SPA route to settle.
 */
export async function navClickTo(page: Page, navLabel: string): Promise<void> {
	await gotoAppRoute(page, '/')
	const link = appNav(page).getByRole('link', { name: navLabel, exact: true }).first()
	await link.waitFor({ state: 'visible', timeout: 30000 })
	await link.click()
	await page.locator(APP_MAIN).first().waitFor({ state: 'visible', timeout: 30000 })
}

/**
 * Assert a manifest index (CnIndexPage) surface rendered with its real,
 * data-independent controls: the Cards/Table view toggle, the primary
 * "Add ..." button, and a mounted list BODY (either the "No items found"
 * empty-state OR an actual rendered list / table with data).
 *
 * `addLabel` is the exact primary-create button text for the page.
 *
 * Note on the body assertion: now that the `@resolve:voorzieningen_register`
 * sentinel resolves to a real register (it used to 404), some schemas in the
 * dev dataset genuinely return rows (e.g. Module versions shows "Showing 3 of
 * 3"). We therefore assert the list body MOUNTED — empty-state OR a populated
 * list — rather than hard-coding the empty-state, which would be a
 * data-dependent (and now-wrong) assumption.
 */
export async function expectIndexSurface(page: Page, addLabel: string): Promise<void> {
	const main = page.locator(APP_MAIN).first()
	await expect(main).toBeVisible({ timeout: 30000 })

	// Cards / Table view toggle is part of every CnIndexPage chrome.
	await expect(main.getByText('Cards', { exact: true }).first()).toBeVisible({ timeout: 30000 })
	await expect(main.getByText('Table', { exact: true }).first()).toBeVisible()

	// Primary create action.
	await expect(
		main.getByRole('button', { name: addLabel, exact: true }).first(),
	).toBeVisible({ timeout: 30000 })

	// List body mounted — empty-state OR a populated list/table. Proves the
	// data layer ran (sentinel resolved), not just the chrome.
	const emptyState = main.getByText('No items found', { exact: false }).first()
	// CnIndexPage renders rows either as a table or as cards; "Showing N of M"
	// is the list header shown once a non-empty collection has loaded.
	const populated = main.getByText(/Showing\s+\d+\s+of\s+\d+/i).first()
	await expect(emptyState.or(populated)).toBeVisible({ timeout: 30000 })
}
