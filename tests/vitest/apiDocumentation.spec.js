// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The API documentation page (sharing-generated-api-docs task 3): where the two
 * documents live, how a response is read, the page and its footer entry in the
 * manifest, and the panel that fetches one document and hands it to
 * CnApiReference.
 *
 * @spec openspec/changes/sharing-generated-api-docs/specs/generated-api-docs/spec.md#requirement-req-gad-002-a-developer-reads-both-documents-on-one-page-in-stackiq
 */

import { flushPromises, mount } from '@vue/test-utils'
import * as fs from 'fs'
import * as path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import { buildManifest } from '../../node_modules/@conduction/nextcloud-vue/src/utils/buildManifest.js'
import base from '../../src/manifest.json'
import menuLayout from '../../src/menu-layout.json'
import {
	API_DOCUMENT_TABS,
	APP_DOCUMENT_PATH,
	catalogueRegisterId,
	documentFailure,
	documentPaths,
	readDocument,
} from '../../src/utils/apiDocuments.js'

const http = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => '/index.php' + p }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnApiReference: {
		props: ['document', 'downloadName'],
		render() {
			return h('div', {
				'data-testid': 'reference',
				'data-title': this.document.info.title,
				'data-download': this.downloadName,
			})
		},
	},
}))

const { default: ApiDocumentPanel } =
	await import('../../src/components/api/ApiDocumentPanel.vue')

const echo = (text) => text
const SETTINGS = {
	availableRegisters: [
		{ id: 3, slug: 'amef' },
		{ id: 7, slug: 'stackiq' },
	],
}
const DOCUMENT = {
	openapi: '3.0.3',
	info: { title: 'Stackiq', version: '1.0.0' },
	paths: {},
}

describe('where the documents live', () => {
	it('takes the catalogue register id from the register with slug stackiq', () => {
		expect(catalogueRegisterId(SETTINGS)).toBe(7)
		expect(
			catalogueRegisterId({ availableRegisters: [{ id: 3, slug: 'amef' }] }),
		).toBeNull()
		expect(
			catalogueRegisterId({
				availableRegisters: [{ id: '', slug: 'stackiq' }],
			}),
		).toBeNull()
		expect(catalogueRegisterId(null)).toBeNull()
		expect(catalogueRegisterId({ availableRegisters: 'none' })).toBeNull()
	})

	it("points the catalogue tab at OpenRegister's register document and the endpoints tab at the app's own", () => {
		expect(documentPaths(SETTINGS)).toEqual({
			catalogue: '/apps/openregister/api/registers/7/oas',
			endpoints: '/apps/stackiq/api/openapi.json',
		})
		expect(documentPaths(undefined)).toEqual({
			catalogue: null,
			endpoints: APP_DOCUMENT_PATH,
		})
	})

	it('serves the app document from the route OpenApiController answers', () => {
		const routes = fs.readFileSync(
			path.resolve(__dirname, '../../appinfo/routes.php'),
			'utf8',
		)
		expect(routes).toContain("'url' => '/api/openapi.json'")
		expect(APP_DOCUMENT_PATH).toBe('/apps/stackiq/api/openapi.json')
	})

	it('shows the catalogue objects first, then the app endpoints', () => {
		expect(API_DOCUMENT_TABS.map((tab) => tab.id)).toEqual([
			'catalogue',
			'endpoints',
		])
		const en = JSON.parse(
			fs.readFileSync(path.resolve(__dirname, '../../l10n/en.json'), 'utf8'),
		).translations
		const nl = JSON.parse(
			fs.readFileSync(path.resolve(__dirname, '../../l10n/nl.json'), 'utf8'),
		).translations
		for (const tab of API_DOCUMENT_TABS) {
			expect(en[tab.title]).toBe(tab.title)
			expect(nl[tab.title]).toBeTruthy()
		}
	})
})

describe('readDocument', () => {
	it('keeps an OpenAPI or Swagger document, parsed when it came as text', () => {
		expect(readDocument(DOCUMENT)).toBe(DOCUMENT)
		expect(readDocument(JSON.stringify(DOCUMENT))).toEqual(DOCUMENT)
		expect(readDocument({ swagger: '2.0', info: {} })).toEqual({
			swagger: '2.0',
			info: {},
		})
	})

	it('refuses anything else', () => {
		expect(readDocument('<html>')).toBeNull()
		expect(readDocument({ error: 'not installed' })).toBeNull()
		expect(readDocument([DOCUMENT])).toBeNull()
		expect(readDocument(null)).toBeNull()
		expect(readDocument(undefined)).toBeNull()
	})
})

describe('documentFailure', () => {
	it('says why a document did not load', () => {
		expect(documentFailure({ response: { status: 404 } }, echo)).toBe(
			'This API document is not available on this instance.',
		)
		expect(documentFailure({ response: { status: 403 } }, echo)).toBe(
			'You may not read this API document.',
		)
		expect(documentFailure({ response: { status: 401 } }, echo)).toBe(
			'You may not read this API document.',
		)
		expect(documentFailure(new Error('network'), echo)).toBe(
			'The API document could not be loaded.',
		)
		expect(documentFailure(undefined, echo)).toBe(
			'The API document could not be loaded.',
		)
	})
})

describe('API documentation page in the manifest', () => {
	const dir = path.resolve(__dirname, '../../src/manifest.d')
	const merged = buildManifest(
		base,
		fs
			.readdirSync(dir)
			.filter((f) => f.endsWith('.json'))
			.sort()
			.map((f) => JSON.parse(fs.readFileSync(path.join(dir, f), 'utf8'))),
		menuLayout,
	)

	it('is a dashboard page on /api-docs whose one widget is the api-documentation type main.js registers', () => {
		const page = merged.pages.find((p) => p.id === 'ApiDocumentation')
		expect(page).toMatchObject({ route: '/api-docs', type: 'dashboard' })
		expect(page.config.widgets.map((w) => w.type)).toEqual(['api-documentation'])
		expect(page.config.layout.map((l) => l.widgetId)).toEqual(['api-documentation'])
		const main = fs.readFileSync(path.resolve(__dirname, '../../src/main.js'), 'utf8')
		expect(main).toMatch(/registerDashboardWidget\('api-documentation', \{\n\trenderer: ApiDocumentationWidget,/)
	})

	it('has a footer entry beside Documentation, with a registered icon', () => {
		const entry = merged.menu.find((m) => m.id === 'ApiDocumentationMenu')
		expect(entry).toMatchObject({ route: 'ApiDocumentation', section: 'footer' })
		const documentation = merged.menu.find((m) => m.id === 'Documentation')
		expect(entry.order).toBeGreaterThan(documentation.order)
		const read = (file) =>
			fs.readFileSync(path.resolve(__dirname, '../../src', file), 'utf8')
		expect(read('icons.js')).toMatch(new RegExp('^\\t' + entry.icon + ',$', 'm'))
	})

	it('loads no third-party viewer', () => {
		const sources = [
			'components/api/ApiDocumentationWidget.vue',
			'components/api/ApiDocumentPanel.vue',
			'utils/apiDocuments.js',
		]
			.map((file) =>
				fs.readFileSync(path.resolve(__dirname, '../../src', file), 'utf8'),
			)
			.join('\n')
		expect(sources).not.toMatch(/redoc|swagger-ui|https?:\/\//i)
	})
})

describe('ApiDocumentPanel', () => {
	beforeEach(() => {
		http.get.mockReset()
	})

	const stubs = {
		NcLoadingIcon: true,
		NcNoteCard: { template: '<div class="note"><slot /></div>' },
	}

	it('fetches the document from this instance and hands it to CnApiReference', async () => {
		http.get.mockResolvedValue({ data: DOCUMENT })
		const wrapper = mount(ApiDocumentPanel, {
			props: {
				path: '/apps/stackiq/api/openapi.json',
				downloadName: 'stackiq-endpoints',
			},
			global: { stubs },
		})
		await flushPromises()
		expect(http.get).toHaveBeenCalledWith(
			'/index.php/apps/stackiq/api/openapi.json',
		)
		const reference = wrapper.find('[data-testid="reference"]')
		expect(reference.attributes('data-title')).toBe('Stackiq')
		expect(reference.attributes('data-download')).toBe('stackiq-endpoints')
		wrapper.unmount()
	})

	it('says the document is not available when there is no path, without a request', async () => {
		const wrapper = mount(ApiDocumentPanel, {
			props: { path: '' },
			global: { stubs },
		})
		await flushPromises()
		expect(http.get).not.toHaveBeenCalled()
		expect(wrapper.find('.note').text()).toBe(
			'This API document is not available on this instance.',
		)
		wrapper.unmount()
	})

	it('says why when the request fails or the answer is not a document', async () => {
		http.get.mockRejectedValueOnce({ response: { status: 403 } })
		const refused = mount(ApiDocumentPanel, {
			props: { path: '/apps/openregister/api/registers/7/oas' },
			global: { stubs },
		})
		await flushPromises()
		expect(refused.find('.note').text()).toBe(
			'You may not read this API document.',
		)
		refused.unmount()

		http.get.mockResolvedValueOnce({ data: '<html></html>' })
		const wrong = mount(ApiDocumentPanel, {
			props: { path: '/apps/stackiq/api/openapi.json' },
			global: { stubs },
		})
		await flushPromises()
		expect(wrong.find('.note').text()).toBe(
			'The API document could not be loaded.',
		)
		expect(wrong.find('[data-testid="reference"]').exists()).toBe(false)
		wrong.unmount()
	})
})
