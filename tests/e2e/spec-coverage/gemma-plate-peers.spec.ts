// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * The GEMMA plate layer "What comparable organisations use" (board
 * SkGemmaPlaat, REQ-GPL-002): per reference component the organisation's own
 * applications and what organisations of the same type and inhabitants band
 * use, with counts, never naming an organisation, and "fewer than 3" for a
 * count below three.
 *
 * Seeds, all carrying this run's RUN_ID, two reference components in the
 * GEMMA register and, in the voorzieningen register:
 * - the own municipality (45,000 inhabitants) using "own app" for the case
 *   component and nothing for the knowledge component;
 * - three comparable municipalities (30,000 to 60,000) using "peer app" for
 *   the case component and "knowledge app" for the knowledge component, and
 *   one of them also "rare app" for the case component;
 * - one municipality outside the band (200,000) using "peer app", which must
 *   not raise its count.
 * Exactly those rows are removed afterwards. The counting rules are covered
 * by tests/Unit/Service/PeerUsageServiceTest.php.
 *
 * The endpoint scenario runs against development. The two plate scenarios
 * read the layer switch, table and side panel line of task 4, which are not
 * on development yet, so they are marked fixme until that task lands.
 *
 * Written from the change's scenarios; not yet run against a live instance.
 *
 * @spec openspec/changes/archive/2026-11-24-gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-the-plate-shows-what-comparable-organisations-use-req-gpl-002
 */
import type { APIRequestContext, Page } from '@playwright/test'
import type { VoorzieningenConfig } from '../workflows/_fixtures.ts'

import { expect, test } from '@playwright/test'
import {
	createObject,
	deleteObject,
	newApiContext,
	resolveConfig,
	RUN_ID,
} from '../workflows/_fixtures.ts'
import { dismissSupportDialog, gotoAppRoute } from './_helpers.ts'

const GEMMA_REGISTER = 'vng-gemma'
const PEERS = '/index.php/apps/stackiq/api/reference-component-coverage/peers'

let apiCtx: APIRequestContext
let cfg: VoorzieningenConfig
const seeded: Array<[string, string, string]> = []
const ids: Record<string, string> = {}

const own = `${RUN_ID} own municipality`
const peerNames = [1, 2, 3].map((n) => `${RUN_ID} comparable municipality ${n}`)
const outsider = `${RUN_ID} large municipality`
const caseComponent = `${RUN_ID} case handling component`
const knowledgeComponent = `${RUN_ID} knowledge base component`
const ownApp = `${RUN_ID} own app`
const peerApp = `${RUN_ID} peer app`
const knowledgeApp = `${RUN_ID} knowledge app`
const rareApp = `${RUN_ID} rare app`

/**
 * Create a row and remember it for cleanup.
 *
 * @param register The register slug.
 * @param schema The schema slug.
 * @param data The object.
 * @return The new id.
 */
async function seed(
	register: string,
	schema: string,
	data: Record<string, unknown>,
): Promise<string> {
	const id = await createObject(apiCtx, register, schema, data)
	seeded.push([register, schema, id])
	return id
}

/**
 * Register a usage of a module for reference components.
 *
 * @param consumer The organisation id.
 * @param module The module id.
 * @param components The reference component ids.
 * @return The new id.
 */
async function use(consumer: string, module: string, components: string[]): Promise<string> {
	return seed(cfg.register, 'usage', {
		consumer,
		module,
		status: 'In production',
		usedForReferenceComponents: components,
	})
}

/**
 * Open the plate for the own municipality and switch the peers layer on.
 *
 * @param page The page.
 */
async function openPlateWithPeers(page: Page): Promise<void> {
	await gotoAppRoute(page, '/gemma-plaat')
	await dismissSupportDialog(page)
	await page.getByRole('combobox', { name: 'Organisation' }).fill(own)
	await page.locator('.vs__dropdown-option', { hasText: own }).first().click()
	await page.getByTestId('gemma-plate-layer-peers').getByRole('switch').check()
	await expect(page.getByTestId('gemma-plate-peers')).toBeVisible({ timeout: 30000 })
}

test.beforeAll(async () => {
	apiCtx = await newApiContext()
	cfg = await resolveConfig(apiCtx)

	for (const [key, name] of [['case', caseComponent], ['knowledge', knowledgeComponent]]) {
		ids[key] = await seed(GEMMA_REGISTER, 'element', {
			identifier: `${RUN_ID}-${key}`,
			type: 'ApplicationComponent',
			properties: [],
			name,
			gemmaType: 'referentiecomponent',
			grouping: RUN_ID,
		})
	}

	for (const [key, name] of [['own', ownApp], ['peer', peerApp], ['knowledge', knowledgeApp], ['rare', rareApp]]) {
		ids[`app-${key}`] = await seed(cfg.register, 'module', { name })
	}

	ids.own = await seed(cfg.register, 'organization', { name: own, type: 'Municipality', inhabitants: 45000 })
	await use(ids.own, ids['app-own'], [ids.case])

	for (const [index, name] of peerNames.entries()) {
		const peer = await seed(cfg.register, 'organization', {
			name,
			type: 'Municipality',
			inhabitants: 32000 + (index * 10000),
		})
		await use(peer, ids['app-peer'], [ids.case])
		await use(peer, ids['app-knowledge'], [ids.knowledge])
		if (index === 0) {
			await use(peer, ids['app-rare'], [ids.case])
		}
	}

	const large = await seed(cfg.register, 'organization', { name: outsider, type: 'Municipality', inhabitants: 200000 })
	await use(large, ids['app-peer'], [ids.case])
})

test.afterAll(async () => {
	if (!apiCtx) return
	for (const [register, schema, id] of seeded.reverse()) {
		await deleteObject(apiCtx, register, schema, id)
	}
	await apiCtx.dispose()
})

// @e2e gemma-plate::a-municipality-compares-its-case-handling
// @e2e gemma-plate::a-rare-choice-stays-anonymous
test('the peers endpoint counts comparable organisations only and never names one', async () => {
	const response = await apiCtx.get(`${PEERS}?organisation=${encodeURIComponent(ids.own)}`)
	expect(response.ok()).toBeTruthy()
	const body = await response.text()

	// No organisation is ever named, not the peers and not the one outside the band.
	for (const name of [...peerNames, outsider]) {
		expect(body).not.toContain(name)
	}

	const json = JSON.parse(body)
	expect(json?.comparable?.type).toBe('Municipality')
	expect(json?.comparable?.band).toEqual({ min: 30000, max: 60000 })

	const serialised = JSON.stringify(json)
	expect(serialised).toContain(peerApp)
	expect(serialised).toContain(knowledgeApp)
	expect(serialised).toContain(rareApp)
})

// @e2e gemma-plate::a-municipality-compares-its-case-handling
// @e2e gemma-plate::a-rare-choice-stays-anonymous
test.fixme('the table shows own applications and what comparable organisations use', async ({ page }) => {
	await openPlateWithPeers(page)

	const table = page.getByTestId('gemma-plate-peers')
	await expect(table).toContainText('What comparable organisations use: Municipality')
	const row = table.locator('tr', { hasText: caseComponent })
	await expect(row.locator('.peer-usage__own')).toContainText(ownApp)
	await expect(row.locator('.peer-usage__peers')).toContainText(`${peerApp} (3)`)
	await expect(row.locator('.peer-usage__peers')).toContainText(`${rareApp} (fewer than 3)`)
	for (const name of [...peerNames, outsider]) {
		await expect(table).not.toContainText(name)
	}
})

// @e2e gemma-plate::a-gap-that-peers-fill
test.fixme('a gap shows in the side panel with what comparable organisations use for it', async ({ page }) => {
	await openPlateWithPeers(page)

	const tile = page.getByTestId('gemma-plate-groups').getByRole('button', { name: new RegExp(knowledgeComponent) })
	await expect(tile).toHaveAttribute('data-state', 'gap')
	await tile.click()

	const detail = page.getByTestId('gemma-plate-detail')
	await expect(detail).toContainText(knowledgeComponent)
	await expect(detail.getByTestId('gemma-plate-detail-peers')).toContainText(`${knowledgeApp} (3)`)
})
