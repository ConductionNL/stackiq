// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Move to organisation from a detail page: the header action of the four
 * detail pages opens the registered launcher with its schema, and the
 * launcher opens the dialog for the record in the route and closes itself.
 *
 * @spec openspec/changes/landscape-move-between-organisations
 */

import { mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import MoveToOrganisationLauncher from '../../src/components/organisations/MoveToOrganisationLauncher.vue'
import { MOVE_DIALOG, MOVE_LAUNCHER, openMoveForRecord } from '../../src/services/moveToOrganisation.js'

const navigation = vi.hoisted(() => ({ setDialog: vi.fn() }))
vi.mock('../../src/store/store.js', () => ({ navigationStore: navigation }))

const root = resolve(__dirname, '../..')
const json = (path) => JSON.parse(readFileSync(resolve(root, path), 'utf8'))

describe('openMoveForRecord', () => {
	it('opens the dialog with the schema and the one id as a string', () => {
		const setDialog = vi.fn()

		expect(openMoveForRecord(setDialog, 'connection', 42)).toBe(true)
		expect(setDialog).toHaveBeenCalledWith(MOVE_DIALOG, { schema: 'connection', ids: ['42'] })
	})

	it('opens nothing without a schema or an id', () => {
		const setDialog = vi.fn()

		expect(openMoveForRecord(setDialog, '', 'm-1')).toBe(false)
		expect(openMoveForRecord(setDialog, 'module', undefined)).toBe(false)
		expect(openMoveForRecord(setDialog, 'module', null)).toBe(false)
		expect(openMoveForRecord(setDialog, 'module', '')).toBe(false)
		expect(setDialog).not.toHaveBeenCalled()
	})
})

describe('MoveToOrganisationLauncher', () => {
	beforeEach(() => {
		navigation.setDialog.mockClear()
	})

	it('opens the dialog for the record in the route and closes itself', () => {
		const wrapper = mount(MoveToOrganisationLauncher, {
			props: { schema: 'usage' },
			global: { mocks: { $route: { params: { id: 'u-7' } } } },
		})

		expect(navigation.setDialog).toHaveBeenCalledTimes(1)
		expect(navigation.setDialog).toHaveBeenCalledWith(MOVE_DIALOG, { schema: 'usage', ids: ['u-7'] })
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('only closes when the route names no record', () => {
		const wrapper = mount(MoveToOrganisationLauncher, {
			props: { schema: 'usage' },
			global: { mocks: { $route: { params: {} } } },
		})

		expect(navigation.setDialog).not.toHaveBeenCalled()
		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})

describe('the detail pages', () => {
	const pages = [
		...json('src/manifest.json').pages,
		...json('src/manifest.d/connections.json').pages,
		...json('src/manifest.d/usages.json').pages,
	]
	const moveAction = (id) => (pages.find((page) => page.id === id)?.config?.headerActions || [])
		.find((action) => action.id === 'move-to-organisation')

	it.each([
		['ModuleDetail', 'module'],
		['ContractDetail', 'catalogContract'],
		['KoppelingDetail', 'connection'],
		['GebruikDetail', 'usage'],
	])('%s opens the launcher for its own schema', (id, schema) => {
		const page = pages.find((p) => p.id === id)
		const action = moveAction(id)

		expect(page.config.schema).toBe(schema)
		expect(action).toMatchObject({ type: 'open-modal', target: MOVE_LAUNCHER, label: 'Move to organisation', props: { schema } })
	})

	it('the launcher is registered as a modal', () => {
		// Read as text: importing the registry mounts every app page component.
		const source = readFileSync(resolve(root, 'src/registry.js'), 'utf8')

		expect(source).toMatch(new RegExp(`${MOVE_LAUNCHER}: \\{ kind: 'modal', component: MoveToOrganisationLauncher \\}`))
	})
})
