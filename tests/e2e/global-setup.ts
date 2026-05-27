// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Playwright globalSetup — logs into Nextcloud once and persists the resulting
 * cookie jar to `tests/e2e/.auth/admin.json` so every spec reuses the session.
 *
 * Nextcloud 34 renders the login form via JavaScript so the inputs don't appear
 * in the static HTML. We must wait for the input fields to hydrate before filling.
 */

import { chromium, type FullConfig } from '@playwright/test'
import * as path from 'path'
import * as fs from 'fs'

const AUTH_DIR = path.resolve(__dirname, '.auth')
const STORAGE_STATE = path.join(AUTH_DIR, 'admin.json')

export default async function globalSetup(config: FullConfig): Promise<void> {
	const baseURL = (config.projects[0]?.use?.baseURL as string | undefined)
		?? process.env.NEXTCLOUD_URL
		?? 'http://localhost:8080'
	const username = process.env.NC_ADMIN_USER ?? 'admin'
	const password = process.env.NC_ADMIN_PASS ?? 'admin'

	fs.mkdirSync(AUTH_DIR, { recursive: true })

	const browser = await chromium.launch()
	const context = await browser.newContext({ baseURL })
	const page = await context.newPage()

	// Nextcloud 34 renders the login form entirely via JavaScript.
	// Load the page and wait until the input fields hydrate before filling.
	await page.goto('/index.php/login', { timeout: 60_000 })

	// Try multiple selector strategies covering different NC versions:
	//   - NC 34+ JS-rendered login: inputs get autocomplete attributes
	//   - NC 28-30 server-rendered: inputs have name="user" / name="password"
	const userSelector = 'input[autocomplete="username"], input[name="user"], input[id="user"]'
	const passSelector = 'input[autocomplete="current-password"], input[type="password"], input[name="password"]'

	await page.locator(userSelector).waitFor({ state: 'visible', timeout: 45_000 })
	await page.locator(userSelector).fill(username)
	await page.locator(passSelector).fill(password)

	// Click the submit button (may be "Log in" or just type="submit")
	const submitSelector = 'button[type="submit"], button:has-text("Log in"), input[type="submit"]'
	await page.locator(submitSelector).first().click()

	// Wait for redirect away from /login — the #header only renders when authenticated
	await page.waitForFunction(
		() => !window.location.pathname.includes('/login'),
		{ timeout: 30_000 },
	)

	if (page.url().includes('/login')) {
		throw new Error(
			`Nextcloud login failed — still on ${page.url()}. `
			+ 'Check NC_ADMIN_USER / NC_ADMIN_PASS env vars (default: admin/admin).',
		)
	}

	// Persist cookies + localStorage for test reuse
	await context.storageState({ path: STORAGE_STATE })
	await browser.close()
}
