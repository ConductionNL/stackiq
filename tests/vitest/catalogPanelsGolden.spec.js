// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for CatalogPanels: the two statistics tables (the object types
 * split in halves), and where a row click and a Manage button navigate to.
 * The clock is frozen. Recorded with RECORD_GOLDEN=1.
 *
 * @spec openspec/specs/fe-shell-navigation/spec.md
 */

import { mount } from '@vue/test-utils'
import { readFileSync, writeFileSync } from 'fs'
import path from 'path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

const trace = []
const store = vi.hoisted(() => ({ objectStore: {} }))

vi.mock('../../src/store/store.js', () => ({
	objectStore: store.objectStore,
	navigationStore: { setSelected: (value) => trace.push(['setSelected', value]) },
}))

const { default: CatalogPanels } = await import('../../src/components/CatalogPanels.vue')

const GOLDEN = path.resolve(import.meta.dirname, 'golden/catalogPanels.json')

const schemaSets = {
	'five types': ['organization', 'contactPerson', 'contract', 'standard', 'module'],
	'one type': ['organization'],
	'no types': [],
}

/**
 * Mount with the given schemas and record the markup and the navigation of every click.
 *
 * @param {Array<string>} slugs The schema slugs of the register.
 * @return {Promise<object>} The recording.
 */
async function run(slugs) {
	trace.length = 0
	Object.assign(store.objectStore, {
		settings: {
			availableRegisters: [
				{ slug: 'other', title: 'Other', schemas: [{ slug: 'x', title: 'X' }] },
				{ slug: 'stackiq', title: 'Stackiq', schemas: slugs.map((slug, i) => ({ slug, title: `Type ${slug}`, i })) },
			],
		},
		isLoading: () => false,
		objectTypeRegistry: {},
		fetchCollection: async () => {},
		getCollection: (slug) => ({ results: new Array(slug.length).fill({}) }),
		getPagination: (slug) => (slug === 'contract' ? { total: 12345 } : {}),
	})
	const wrapper = mount(CatalogPanels, { global: { stubs: { NcLoadingIcon: true } } })
	await nextTick()
	await new Promise((resolve) => setImmediate(resolve))
	const html = wrapper.html().replace(/ data-v-[0-9a-f]+(="")?/g, '')
	for (const row of wrapper.findAll('tbody tr')) {
		await row.trigger('click')
	}
	for (const button of wrapper.findAll('td.manageCell button')) {
		await button.trigger('click')
	}
	await wrapper.find('.infoBoxActions button').trigger('click')
	wrapper.unmount()
	return { html, trace: [...trace] }
}

describe('CatalogPanels', () => {
	beforeEach(() => {
		vi.useFakeTimers({ toFake: ['Date'] })
		vi.setSystemTime(new Date('2026-03-04T05:06:07Z'))
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	for (const [name, slugs] of Object.entries(schemaSets)) {
		it(name, async () => {
			const actual = await run(slugs)
			if (process.env.RECORD_GOLDEN) {
				const all = { ...readGolden(), [name]: actual }
				writeFileSync(GOLDEN, JSON.stringify(all, null, '\t') + '\n')
			}
			expect(actual).toEqual(readGolden()[name])
		})
	}
})

/**
 * The recorded golden, or an empty object while recording the first case.
 *
 * @return {object} Results by case name.
 */
function readGolden() {
	try {
		return JSON.parse(readFileSync(GOLDEN, 'utf8'))
	} catch (error) {
		if (process.env.RECORD_GOLDEN) {
			return {}
		}
		throw error
	}
}
