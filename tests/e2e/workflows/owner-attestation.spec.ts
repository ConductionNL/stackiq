// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Confirmation rounds (landscape-owner-attestation): an information manager
 * starts a round over a municipality's applications in use, the owner confirms
 * an entry from My confirmation requests, and the round page shows an overdue
 * request with its owner.
 *
 * Seeds two municipalities carrying this run's RUN_ID, a contact person whose
 * contactsUid is the logged-in administrator (so the owner resolves to that
 * Nextcloud user), five applications and usages for the first municipality,
 * four of them with that owner, and one owned usage for the second, through the
 * objects API. The administrator becomes a member of the first municipality
 * with it active, so the Start round dialog offers it. The overdue round is
 * started through the round endpoint with yesterday as deadline, and its
 * pending request is then set to overdue, which is what the daily
 * AttestationOverdueJob does (covered by
 * tests/Unit/BackgroundJob/AttestationOverdueJobTest.php); a browser test
 * cannot run a background job. Afterwards the seeded rows, the rounds and
 * their requests are deleted and the administrator's previous active
 * organisation is restored.
 *
 * The scenarios marked `@e2e exclude` are covered by
 * tests/Unit/Settings/OwnerAttestationFragmentTest.php and
 * tests/Unit/EventListener/AttestationCorrectionListenerTest.php.
 *
 * Written from the change's scenarios; not yet run against a live instance.
 *
 * @spec openspec/changes/archive/2026-11-25-landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-001-an-information-manager-starts-a-confirmation-round-for-a-scope
 * @spec openspec/changes/archive/2026-11-25-landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-003-an-owner-confirms-or-corrects-each-entry
 * @spec openspec/changes/archive/2026-11-25-landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-004-the-round-shows-who-answered-and-who-is-overdue
 */
import type { APIRequestContext, Locator, Page } from '@playwright/test'
import type { VoorzieningenConfig } from './_fixtures.ts'

import { expect, test } from '@playwright/test'
import {
	createObject,
	deleteObject,
	findAll,
	NC_ADMIN_USER,
	newApiContext,
	resolveConfig,
	RUN_ID,
} from './_fixtures.ts'
import { dismissSupportDialog, gotoAppRoute, revealSection } from './_ui.ts'

let apiCtx: APIRequestContext
let cfg: VoorzieningenConfig
const seeded: Array<[string, string]> = []
const rounds: string[] = []
const ids: Record<string, string> = {}
const usageIds: string[] = []
let previousActive = ''
const OR_API = '/index.php/apps/openregister/api'
const municipality = `${RUN_ID} confirming municipality`
const lateMunicipality = `${RUN_ID} late municipality`
const ownedApps = [1, 2, 3, 4].map((n) => `${RUN_ID} owned application ${n}`)
const ownerlessApp = `${RUN_ID} ownerless application`
const lateApp = `${RUN_ID} late application`
const roundName = `${RUN_ID} quarterly round`
const lateRoundName = `${RUN_ID} late round`

test.describe.configure({ mode: 'serial' })

/**
 * Create a row and remember it for cleanup.
 *
 * @param schema The schema slug.
 * @param data The object.
 * @return The new id.
 */
async function seed(schema: string, data: Record<string, unknown>): Promise<string> {
	const id = await createObject(apiCtx, cfg.register, schema, data)
	seeded.push([schema, id])
	return id
}

/**
 * The id of an object row.
 *
 * @param row The row.
 * @return The id, or an empty string.
 */
function idOf(row: Record<string, unknown>): string {
	return String((row as { id?: string }).id ?? (row as { '@self'?: { id?: string } })['@self']?.id ?? '')
}

/**
 * A date as YYYY-MM-DD, some days from today.
 *
 * @param days Days from today; negative for the past.
 * @return The date.
 */
function isoDay(days: number): string {
	return new Date(Date.now() + days * 24 * 60 * 60 * 1000).toISOString().slice(0, 10)
}

/**
 * Escape a literal for use in a regular expression.
 *
 * @param text The literal.
 * @return The escaped text.
 */
function escapeRegExp(text: string): string {
	return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

/**
 * A request is labelled by its entry's name, or by the entry's id when the
 * usage carries no name of its own.
 *
 * @param name The application's name.
 * @param usageId The usage's id.
 * @return A pattern matching either.
 */
function entryLabel(name: string, usageId: string): RegExp {
	return new RegExp(`${escapeRegExp(name)}|${usageId}`)
}

/**
 * The titled card of a list widget on a detail page.
 *
 * @param page The page.
 * @param title The card's title.
 * @return The card.
 */
function catalogCard(page: Page, title: string): Locator {
	return page
		.locator('.cn-detail-page__catalog-card')
		.filter({ has: page.getByText(title, { exact: true }) })
		.first()
}

/**
 * A round page's catalogue card, scrolled into the page until it has mounted:
 * the cards below the fold mount only when scrolled into view.
 *
 * @param page The page.
 * @param title The card's title.
 * @return The card.
 */
async function revealCard(page: Page, title: string): Promise<Locator> {
	const card = catalogCard(page, title)
	await page.mouse.move(800, 450)
	await expect
		.poll(async () => {
			if ((await card.getByRole('table').count()) > 0) {
				return 1
			}
			await page.mouse.wheel(0, 600)
			return 0
		}, { timeout: 30000 })
		.toBe(1)
	await card.scrollIntoViewIfNeeded()
	return card
}

/**
 * Wait until a register organisation has synced to an OpenRegister organisation.
 *
 * @param uuid The organisation's uuid.
 * @return Nothing; throws when it never appears.
 */
async function awaitOrganisation(uuid: string): Promise<void> {
	for (let attempt = 0; attempt < 20; attempt++) {
		const res = await apiCtx.get(`${OR_API}/organisations/${uuid}`)
		if (res.ok()) return
		// The sync is cron-only (every five minutes); the administrator's manual sync runs the same pass now.
		if (attempt % 5 === 0) {
			await apiCtx.post('/index.php/apps/stackiq/api/settings/sync', { data: { minutesBack: 5 } })
		}
		await new Promise((resolve) => setTimeout(resolve, 1000))
	}
	throw new Error(`organisation ${uuid} did not sync to OpenRegister`)
}

/**
 * The requests of a round.
 *
 * @param roundId The round's id.
 * @return The request rows.
 */
async function requestsOf(roundId: string): Promise<Array<Record<string, unknown>>> {
	const res = await apiCtx.get(
		`${OR_API}/objects/${cfg.register}/attestationRequest?round=${encodeURIComponent(roundId)}&_limit=200`,
	)
	if (!res.ok()) return []
	const body = await res.json()
	const list = body?.results ?? body ?? []
	return Array.isArray(list) ? list : []
}

/**
 * The round page of a round found by its name on the Confirmation rounds list.
 *
 * @param page The page.
 * @param name The round's name.
 * @return Nothing.
 */
async function openRound(page: Page, name: string): Promise<void> {
	await gotoAppRoute(page, '/confirmation-rounds')
	await dismissSupportDialog(page)
	const row = page.getByRole('row').filter({ hasText: name })
	await expect(row).toBeVisible({ timeout: 30000 })
	// The whole row is a link laid over its cells.
	await row.getByTestId('cn-row-link').first().click()
	await expect(page).toHaveURL(/\/confirmation-rounds\/[^/?]+(\?|$)/, { timeout: 30000 })
	await dismissSupportDialog(page)
}

test.beforeAll(async () => {
	apiCtx = await newApiContext()
	cfg = await resolveConfig(apiCtx)

	const me = await apiCtx.get('/index.php/apps/stackiq/api/me')
	if (me.ok()) {
		previousActive = (await me.json())?.organisations?.active?.uuid ?? ''
	}

	ids.org = await seed('organization', {
		name: municipality,
		type: 'Municipality',
		status: 'Active',
		contactsUid: `${RUN_ID}-confirming-municipality`,
	})
	ids.lateOrg = await seed('organization', {
		name: lateMunicipality,
		type: 'Municipality',
		status: 'Active',
		contactsUid: `${RUN_ID}-late-municipality`,
	})
	await awaitOrganisation(ids.org)
	const join = await apiCtx.post(`${OR_API}/organisations/${ids.org}/join`, { data: {} })
	if (!join.ok() && join.status() !== 409) {
		console.warn(`join ${ids.org} returned ${join.status()}`)
	}
	const active = await apiCtx.post(`${OR_API}/organisations/${ids.org}/set-active`, { data: {} })
	expect(active.ok(), `set-active returned ${active.status()}`).toBe(true)

	// The owner: a contact person whose contactsUid is the administrator's
	// system address book entry, so it resolves to that Nextcloud user.
	ids.owner = await seed('contactPerson', {
		contactsUid: NC_ADMIN_USER,
		role: `${RUN_ID} application owner`,
		organization: ids.org,
	})

	for (const name of ownedApps) {
		const module = await seed('module', { name })
		usageIds.push(await seed('usage', {
			consumer: ids.org,
			module,
			status: 'In production',
			businessOwner: ids.owner,
		}))
	}
	const ownerlessModule = await seed('module', { name: ownerlessApp })
	ids.ownerlessUsage = await seed('usage', { consumer: ids.org, module: ownerlessModule, status: 'In production' })

	const lateModule = await seed('module', { name: lateApp })
	ids.lateUsage = await seed('usage', {
		consumer: ids.lateOrg,
		module: lateModule,
		status: 'In production',
		businessOwner: ids.owner,
	})
})

test.afterAll(async () => {
	if (!apiCtx) return
	for (const row of await findAll(apiCtx, cfg.register, 'attestationRound', RUN_ID)) {
		const id = idOf(row)
		if (id && JSON.stringify(row).includes(RUN_ID) && !rounds.includes(id)) {
			rounds.push(id)
		}
	}
	for (const roundId of rounds) {
		for (const request of await requestsOf(roundId)) {
			const id = idOf(request)
			if (id) {
				await deleteObject(apiCtx, cfg.register, 'attestationRequest', id)
			}
		}
		await deleteObject(apiCtx, cfg.register, 'attestationRound', roundId)
	}
	for (const [schema, id] of seeded.reverse()) {
		await deleteObject(apiCtx, cfg.register, schema, id)
	}
	if (previousActive !== '') {
		await apiCtx.post(`${OR_API}/organisations/${previousActive}/set-active`, { data: {} })
	}
	await apiCtx.dispose()
})

// @e2e owner-attestation::a-round-over-the-applications-in-use
test('a round over the applications in use sends four requests and names the usage without an owner', async ({
	page,
}) => {
	await gotoAppRoute(page, '/confirmation-rounds')
	await dismissSupportDialog(page)
	// Start round is a header action: a button, or an item of the list's Actions menu.
	await expect(page.getByRole('button', { name: /^(Start round|Actions)$/ }).first()).toBeVisible({ timeout: 30000 })
	const startButton = page.getByRole('button', { name: 'Start round' }).first()
	if (await startButton.isVisible().catch(() => false)) {
		await startButton.click()
	} else {
		await page.getByRole('button', { name: 'Actions' }).first().click()
		await page.getByRole('menuitem', { name: 'Start round' }).first().click()
	}
	const dialog = page.getByRole('dialog').filter({ hasText: 'Start confirmation round' })
	await expect(dialog).toBeVisible({ timeout: 30000 })

	await dialog.getByLabel('Name', { exact: true }).first().fill(roundName)
	await dialog.getByRole('combobox', { name: 'Organisation' }).fill(municipality)
	await page.locator('.vs__dropdown-option', { hasText: municipality }).first().click()
	await dialog.getByRole('combobox', { name: 'Entries to confirm' }).click()
	await page.locator('.vs__dropdown-option', { hasText: /^\s*Applications in use\s*$/ }).first().click()
	await dialog.getByLabel('Deadline', { exact: true }).first().fill(isoDay(14))
	await dialog.getByRole('button', { name: 'Start round', exact: true }).click()

	await expect(dialog).toContainText('Requests sent: 4', { timeout: 30000 })
	const ownerless = dialog.locator('section').filter({ has: page.getByRole('heading', { name: 'Entries without an owner' }) })
	await expect(ownerless).toContainText(entryLabel(ownerlessApp, ids.ownerlessUsage))
	for (const [index, name] of ownedApps.entries()) {
		await expect(ownerless).not.toContainText(entryLabel(name, usageIds[index]))
	}
	await dialog.getByRole('button', { name: 'Close', exact: true }).last().click()
	await expect(dialog).toBeHidden({ timeout: 30000 })

	await openRound(page, roundName)
	const pending = catalogCard(page, 'Pending')
	await expect(pending).toBeVisible({ timeout: 30000 })
	await expect(pending.getByText(NC_ADMIN_USER, { exact: true }).first()).toBeVisible({ timeout: 30000 })
	// The card's list fills progressively, so the four requests are counted on the round itself.
	const roundId = new URL(page.url()).pathname.split('/').pop() ?? ''
	await expect
		.poll(async () => (await requestsOf(roundId)).filter((request) => request.status === 'pending').length, { timeout: 30000 })
		.toBe(4)
	// The entries without an owner are reported by the start dialog (asserted above); the round page lists requests only.
})

// @e2e owner-attestation::confirming-from-the-list
test('confirming a request from My confirmation requests marks it confirmed and dates the usage', async ({
	page,
}) => {
	const label = entryLabel(ownedApps[0], usageIds[0])
	await gotoAppRoute(page, '/my-confirmations')
	await dismissSupportDialog(page)
	const row = page.getByRole('row').filter({ hasText: label }).first()
	await expect(row).toBeVisible({ timeout: 30000 })
	await row.getByRole('button', { name: 'Actions' }).first().click()
	await page.locator('.v-popper__popper--shown').last().getByRole('menuitem', { name: 'Confirm', exact: true }).first().click()
	// The list shows pending requests only, so the confirmed one leaves it.
	await expect(page.getByRole('row').filter({ hasText: label })).toHaveCount(0, { timeout: 30000 })

	await openRound(page, roundName)
	const confirmed = await revealCard(page, 'Confirmed')
	await expect(confirmed.getByText(label).first()).toBeVisible({ timeout: 30000 })
	await expect(catalogCard(page, 'Pending').getByText(label)).toHaveCount(0)

	await gotoAppRoute(page, `/gebruik/${usageIds[0]}`)
	await dismissSupportDialog(page)
	// Last confirmed is in the Data quality widget, below the fold.
	await revealSection(page, 'Data quality')
	const today = new Date()
	const day = String(today.getDate())
	const month = String(today.getMonth() + 1)
	const year = String(today.getFullYear())
	const pad = (value: string) => value.padStart(2, '0')
	const formats = [
		`${year}-${pad(month)}-${pad(day)}`,
		`${pad(day)}-${pad(month)}-${year}`,
		`${day}-${month}-${year}`,
		`${month}/${day}/${year}`,
		`${pad(day)}/${pad(month)}/${year}`,
		`${day}/${month}/${year}`,
		today.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }),
		today.toLocaleDateString('en-GB', { year: 'numeric', month: 'short', day: 'numeric' }),
		today.toLocaleDateString('en-GB', { year: 'numeric', month: 'long', day: 'numeric' }),
		today.toLocaleDateString('nl-NL', { year: 'numeric', month: 'long', day: 'numeric' }),
	]
	const lastConfirmed = page.getByText('Last confirmed', { exact: true }).first()
	await expect(lastConfirmed).toBeVisible({ timeout: 30000 })
	await expect(lastConfirmed.locator('xpath=..')).toContainText(new RegExp(formats.map(escapeRegExp).join('|')), { timeout: 30000 })
})

// @e2e owner-attestation::following-up-overdue-owners
test('a round past its deadline shows its one overdue request with the owner', async ({
	page,
}) => {
	const started = await apiCtx.post('/index.php/apps/stackiq/api/attestation-rounds', {
		data: {
			organisation: ids.lateOrg,
			scopeSchema: 'usage',
			deadline: isoDay(-1),
			name: lateRoundName,
		},
	})
	expect(started.ok(), `starting the round returned ${started.status()}`).toBe(true)
	const roundId = String((await started.json())?.round?.id ?? '')
	expect(roundId, 'the round endpoint returned no round id').not.toBe('')
	rounds.push(roundId)

	// What the daily overdue job does with a pending request past its deadline.
	const requests = await requestsOf(roundId)
	expect(requests).toHaveLength(1)
	const moved = await apiCtx.patch(
		`${OR_API}/objects/${cfg.register}/attestationRequest/${idOf(requests[0])}`,
		{ data: { status: 'overdue' } },
	)
	expect(moved.ok(), `marking the request overdue returned ${moved.status()}`).toBe(true)

	await openRound(page, lateRoundName)
	const overdue = await revealCard(page, 'Overdue')
	await expect(overdue.getByText(NC_ADMIN_USER, { exact: true })).toHaveCount(1, { timeout: 30000 })
	await expect(overdue).toContainText(entryLabel(lateApp, ids.lateUsage))
	await expect(catalogCard(page, 'Pending').getByText(NC_ADMIN_USER, { exact: true })).toHaveCount(0)
})
