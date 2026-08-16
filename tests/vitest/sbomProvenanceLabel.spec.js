/**
 * SPDX-FileCopyrightText: 2026 Conduction / SoftwareCatalog Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * SbomComponentsPanel — the module-version record must actually reach the
 * computeds that read it.
 *
 * WHY THIS FILE EXISTS. `SbomComponentsPanel` resolves the inspected record in
 * one computed and consumes it in `moduleVersionData`, which every other
 * consumer reads through. When the schema slug `moduleVersie` was translated to
 * `moduleVersion`, the CONSUMER was renamed (`this.moduleVersion`) and the
 * PRODUCER was not (it stayed `moduleVersie()`), so `moduleVersionData` read an
 * identifier that no longer existed on the instance, silently evaluated to `{}`,
 * and every derived value went empty:
 *
 *   - `lastImportedLabel` returned `''`, so the `data-testid="sbom-provenance"`
 *     line is behind `v-if` and NEVER rendered — the e2e assertion
 *     `expect(page.getByTestId('sbom-provenance')).toBeVisible()` failed on
 *     `development` with "element(s) not found" even though the import itself
 *     had succeeded (the success note, the table and the counts all rendered);
 *   - `parentModuleId` returned `''`, which is the scope of the
 *     vulnerability-match heuristic — an empty scope matches nothing and
 *     reports no error.
 *
 * Neither symptom throws. Vue resolves an unknown property to `undefined`, and
 * `undefined` takes the "no import yet" branch, which is a legitimate state —
 * so the broken build is indistinguishable from a module version that has
 * genuinely never been imported.
 *
 * WHAT THIS ASSERTS. The computeds are exercised the way Vue evaluates them:
 * every entry in the component's own `computed` map is installed as a getter on
 * one object, so a producer/consumer name mismatch resolves to `undefined` here
 * exactly as it does in the browser. The test therefore FAILS on the broken
 * code and passes on the fixed code, rather than asserting the new name.
 *
 * The negative control ("never imported" -> empty label) is asserted too, so a
 * label that is non-empty unconditionally cannot pass this file either.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

// `@conduction/nextcloud-vue` re-exports through `@nextcloud/vue`, whose
// package exports map is not resolvable in this offline suite, and
// `src/store/store.js` instantiates real Pinia stores off the same library.
// Nothing here RENDERS and nothing here talks to a server, so both are stubbed;
// what is under test is the component's own computed chain, which runs for
// real.
const stubs = vi.hoisted(() => ({
	active: null,
	collections: {},
}))

vi.mock('@conduction/nextcloud-vue', () => ({
	CnDataTable: { name: 'CnDataTable', render: () => null },
}))

vi.mock('../../src/store/store.js', () => ({
	objectStore: {
		getActiveObject: () => stubs.active,
		getCollection: (type) => stubs.collections[type] ?? { results: [] },
	},
}))

const Panel = (await import('../../src/components/sbom/SbomComponentsPanel.vue'))
	.default

const OBJECT_ID = '11111111-2222-3333-4444-555555555555'
const MODULE_UUID = '99999999-8888-7777-6666-555555555555'

/**
 * Build a record the panel is supposed to find for `OBJECT_ID`.
 *
 * @param {object} data Extra data-bag fields.
 * @return {object} An OpenRegister-shaped moduleVersion record.
 */
function record(data = {}) {
	return {
		uuid: OBJECT_ID,
		object: {
			module: MODULE_UUID,
			...data,
		},
	}
}

/**
 * Install the component's real computeds as getters on a bare object, so the
 * producer -> consumer chain resolves the same way Vue resolves it.
 *
 * @param {object} base Instance data (props / data fields).
 * @return {object} A stand-in component instance.
 */
function instance(base = {}) {
	const vm = { objectId: OBJECT_ID, ...base }
	for (const [name, getter] of Object.entries(Panel.computed)) {
		Object.defineProperty(vm, name, {
			get: () => getter.call(vm),
			configurable: true,
		})
	}
	return vm
}

/**
 * Point the shared object store at a fixed collection for this test.
 *
 * @param {Array<object>} results The moduleVersion collection rows.
 * @return {void}
 */
function seedStore(results) {
	stubs.active = null
	stubs.collections = { moduleVersion: { results } }
}

describe('SbomComponentsPanel — the resolved module version reaches its consumers', () => {
	beforeEach(() => {
		seedStore([])
	})

	it('renders the provenance label once the record carries an import stamp', () => {
		seedStore([
			record({
				sbomLastImportedAt: '2026-08-16T10:00:00+00:00',
				sbomFormat: 'cyclonedx-json',
				sbomFileName: 'bom.json',
			}),
		])

		const label = instance().lastImportedLabel

		// Non-empty is what the `v-if` gates on, and the file name is the part
		// that can only come from the resolved record — checking for the
		// EXPECTED content, not merely "not empty".
		expect(label).toContain('bom.json')
		expect(label).toContain('CycloneDX')
	})

	it('NEGATIVE CONTROL: a module version that was never imported has no label', () => {
		seedStore([record()])

		expect(instance().lastImportedLabel).toBe('')
	})

	it('scopes the vulnerability-match heuristic to the resolved parent module', () => {
		seedStore([record({ sbomLastImportedAt: '2026-08-16T10:00:00+00:00' })])

		expect(instance().parentModuleId).toBe(MODULE_UUID)
	})

	it('NEGATIVE CONTROL: an unresolvable objectId yields no module scope', () => {
		seedStore([record()])

		expect(instance({ objectId: 'no-such-uuid' }).parentModuleId).toBe('')
	})
})
