/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the manifest router builder: one route per page named after
 * the page id, route params passed as props, and the vue-router 4 catch-all.
 *
 * @spec openspec/specs/fe-shell-navigation/spec.md
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({
	CnPageRenderer: Object.freeze({ name: 'CnPageRenderer', marker: 'renderer' }),
}))

const { routesFromManifest } = await import('../../src/router.js')

describe('routesFromManifest', () => {
	it('builds one route per page and a catch-all redirect last', () => {
		const routes = routesFromManifest({
			pages: [
				{ id: 'Dashboard', route: '/' },
				{ id: 'ModuleDetail', route: '/modules/:id' },
			],
		})

		expect(routes.map(({ name, path, props }) => ({ name, path, props }))).toEqual([
			{ name: 'Dashboard', path: '/', props: false },
			{ name: 'ModuleDetail', path: '/modules/:id', props: true },
			{ name: undefined, path: '/:pathMatch(.*)*', props: undefined },
		])
		expect(routes[2].redirect).toBe('/')
	})

	it('gives every page an extensible clone of the page renderer', () => {
		const routes = routesFromManifest({ pages: [{ id: 'A', route: '/a' }, { id: 'B', route: '/b' }] })

		expect(routes[0].component).toEqual({ name: 'CnPageRenderer', marker: 'renderer' })
		expect(Object.isExtensible(routes[0].component)).toBe(true)
		expect(routes[1].component).toBe(routes[0].component)
	})

	it('returns only the catch-all for a manifest without pages', () => {
		expect(routesFromManifest({ pages: [] })).toEqual([{ path: '/:pathMatch(.*)*', redirect: '/' }])
	})
})
