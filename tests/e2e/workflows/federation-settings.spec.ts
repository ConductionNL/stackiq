// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Federation settings: an admin removes a peer catalogue in the Catalog
 * federation section of the app's admin settings
 * (src/views/settings/sections/FederationSettings.vue).
 *
 * Seeds one peer whose host carries this run's RUN_ID through the admin
 * federation API (the call the "Add peer" button makes), removes it through
 * the rendered "Remove peer" button, and checks the peer list the pull reads
 * no longer holds it. Afterwards the peer is removed again if a failed run
 * left it behind.
 *
 * Switching federation on shows the registration status (task 21): the
 * section's switch sends PUT /api/federation/enabled and the line under it
 * says registered, or why not (OpenCatalogi missing, the directory refused).
 * The switch is put back as it was afterwards.
 *
 * Written from the change's scenarios; not yet run against a live instance.
 *
 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/federated-catalog-sync/spec.md#requirement-an-admin-removes-a-peer-catalogue-req-clr-015
 */
import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { newApiContext, RUN_ID } from './_fixtures.ts'
import { dismissSupportDialog } from './_ui.ts'

const FEDERATION_API = '/index.php/apps/stackiq/api/federation'
const peerUrl = `https://${RUN_ID}.catalog.example.org`

let apiCtx: APIRequestContext

/**
 * The peer URLs the federation status lists; a pull reads exactly these.
 *
 * @return The subscribed peer URLs.
 */
async function subscribedPeers(): Promise<string[]> {
	const res = await apiCtx.get(`${FEDERATION_API}/status`)
	expect(res.ok()).toBe(true)
	const body = await res.json()
	const peers: Array<{ url?: string }> = Array.isArray(body?.peers) ? body.peers : []
	return peers.map((peer) => String(peer.url ?? ''))
}

/**
 * Open the app's admin settings and wait for the federation section.
 *
 * @param page The page.
 * @return Nothing.
 */
async function gotoFederationSettings(page: Page): Promise<void> {
	await page.goto('/settings/admin/stackiq', { waitUntil: 'domcontentloaded' })
	await dismissSupportDialog(page)
	await expect(page.getByRole('heading', { name: 'Catalog federation' }).first()).toBeVisible({
		timeout: 30000,
	})
	await expect(page.getByRole('heading', { name: 'Subscribed peers' }).first()).toBeVisible({
		timeout: 30000,
	})
}

test.beforeAll(async () => {
	apiCtx = await newApiContext()
	const res = await apiCtx.post(`${FEDERATION_API}/peers`, { data: { peerUrl } })
	if (!res.ok()) {
		throw new Error(`add peer ${peerUrl} returned ${res.status()}: ${await res.text()}`)
	}
})

test.afterAll(async () => {
	if (!apiCtx) return
	if ((await subscribedPeers()).includes(peerUrl)) {
		await apiCtx.delete(`${FEDERATION_API}/peers`, { data: { peerUrl } })
	}
	await apiCtx.dispose()
})

// @e2e federated-catalog-sync::an-admin-removes-a-peer
test('an admin removes a peer and it is gone from the list and from the next pull', async ({
	page,
}) => {
	await gotoFederationSettings(page)
	const peer = page.locator('li', { hasText: peerUrl })
	await expect(peer).toBeVisible({ timeout: 30000 })
	await peer.getByRole('button', { name: 'Remove peer' }).click()
	await expect(page.getByText('Peer removed').first()).toBeVisible({ timeout: 30000 })
	await expect(peer).toHaveCount(0, { timeout: 30000 })

	// The list stays without the peer after a fresh load.
	await gotoFederationSettings(page)
	await expect(page.locator('li', { hasText: peerUrl })).toHaveCount(0)

	// The pull iterates the subscribed peers the status lists, so a peer
	// missing there is not read by the next pull.
	expect(await subscribedPeers()).not.toContain(peerUrl)
})

// @e2e federated-catalog-sync::admin-enables-federation-and-the-instance-announces-itself
test('an admin switches federation on and the section shows the registration status', async ({
	page,
}) => {
	const before = await apiCtx.get(`${FEDERATION_API}/status`)
	const wasEnabled = (await before.json())?.enabled === true
	try {
		if (wasEnabled) {
			await apiCtx.put(`${FEDERATION_API}/enabled`, { data: { enabled: false } })
		}
		await gotoFederationSettings(page)
		const toggle = page.getByRole('checkbox', { name: 'Federation is on' }).first()
		await expect(toggle).not.toBeChecked()
		await expect(page.getByText('Not registered: federation is switched off.').first()).toBeVisible()

		await toggle.click({ force: true })
		await expect(toggle).toBeChecked({ timeout: 30000 })
		const line = page.locator('[data-testid="federation-registration"]').first()
		await expect(line).toHaveText(
			/Registered with the directory|Registration with the directory failed: .+|Not registered: the OpenCatalogi app is not installed\./,
			{ timeout: 30000 },
		)

		// The status the section shows is the one the server keeps.
		const after = await (await apiCtx.get(`${FEDERATION_API}/status`)).json()
		expect(after.enabled).toBe(true)
		expect(['registered', 'failed', 'unavailable']).toContain(after.registration?.state)
	} finally {
		await apiCtx.put(`${FEDERATION_API}/enabled`, { data: { enabled: wasEnabled } })
	}
})
