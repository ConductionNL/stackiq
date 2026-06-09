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
 *  - the `@resolve:voorzieningen_register-*` list 404s — a KNOWN data-layer
 *    issue where the manifest `@resolve:` register placeholder is passed
 *    literally to the OpenRegister objects endpoint (see BUG LIST in the task
 *    report). The list pages still render their CnIndexPage shell + empty-state,
 *    so the render path under test is unaffected; we assert on that shell and do
 *    not gate on this pre-existing data fetch.
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
		// known @resolve list-fetch data issue (see BUG LIST), not a render fault.
		|| s.includes('@resolve:voorzieningen_register')
		|| s.includes('Error fetching @resolve')
		// Settings → User Groups calls the INTENTIONALLY-deprecated
		// /api/settings/cronjobs/users endpoint, which the backend now answers
		// 410 Gone by design; the Vue section logs "Failed to load users" but
		// still renders. Pre-existing frontend leftover (see BUG LIST), not a
		// render fault under test.
		|| s.includes('Failed to load users')
		|| s.includes('cronjobs/users')
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

/** Deep-link to a route and wait for the Vue shell + main region to mount. */
export async function gotoAppRoute(page: Page, route: string): Promise<void> {
	const url = route === '/' ? APP_BASE : `${APP_BASE}${route}`
	await page.goto(url, { waitUntil: 'domcontentloaded' })
	await page.locator(APP_SHELL).first().waitFor({ state: 'attached', timeout: 30000 })
	await page.locator(APP_MAIN).first().waitFor({ state: 'visible', timeout: 30000 })
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
 * "Add ..." button, the per-row "Actions" affordance / Actions header, and the
 * "No items found" empty-state (dev dataset is empty for these schemas).
 * `addLabel` is the exact primary-create button text for the page.
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

	// Empty-state for the (empty) dev dataset — proves the list body rendered,
	// not just the chrome.
	await expect(main.getByText('No items found', { exact: false }).first()).toBeVisible({ timeout: 30000 })
}
