/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The application page's connection map names the other applications.
 * OpenRegister answers a connection's ends as bare uuids; on a live instance
 * the map labelled every other application with its uuid.
 *
 * @spec openspec/changes/connections-diagram-and-graph-export/specs/connection-diagram-and-export/spec.md#requirement-req-cdx-001-the-application-page-draws-the-applications-connections-as-a-map
 */

import { describe, expect, it, vi } from 'vitest'
import { connectionMap } from '../../src/utils/connectionMap.js'
import { withEndNames } from '../../src/utils/connectionLayout.js'

const store = vi.hoisted(() => ({ objectStore: {} }))

vi.mock('../../src/store/store.js', () => store)
vi.mock('../../src/utils/objectTypes.js', () => ({ ensureObjectType: async () => undefined }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnIcon: {}, CnRelationshipGraph: {}, CnWidgetWrapper: {} }))
vi.mock('@nextcloud/vue', () => ({ NcActionButton: {}, NcActions: {}, NcLoadingIcon: {}, NcNoteCard: {} }))
vi.mock('@nextcloud/l10n', () => ({ translate: (_app, text) => text }))

const CONNECTIONS = [
	{ id: 'c1', moduleA: 'root', moduleB: 'dms', type: 'api', dataExchangeDirection: 'AtoB' },
	{ id: 'c2', moduleA: 'crm', moduleB: 'root', type: 'digikoppeling', dataExchangeDirection: 'bi-directional' },
]

describe('withEndNames', () => {
	it('replaces a bare id with the id and its name, and leaves unknown ids and objects alone', () => {
		const named = withEndNames(
			[...CONNECTIONS, { id: 'c3', moduleA: { id: 'x', name: 'X' }, moduleB: 'unknown' }],
			{ root: 'Zaaksysteem', dms: 'Documentbeheer', crm: 'Klantcontact' },
		)

		expect(named.map((c) => [c.moduleA, c.moduleB])).toEqual([
			[{ id: 'root', name: 'Zaaksysteem' }, { id: 'dms', name: 'Documentbeheer' }],
			[{ id: 'crm', name: 'Klantcontact' }, { id: 'root', name: 'Zaaksysteem' }],
			[{ id: 'x', name: 'X' }, 'unknown'],
		])
	})

	it('copes with no connections', () => {
		expect(withEndNames(null, {})).toEqual([])
	})
})

describe('ConnectionMapWidget.named', () => {
	it('reads the unnamed applications once and labels the map with their names', async () => {
		const asked = []
		store.objectStore = {
			fetchCollectionForOptions: async (type, params) => {
				asked.push([type, params])
				return [{ id: 'root', name: 'Zaaksysteem' }, { id: 'dms', name: 'Documentbeheer' }, { id: 'crm', name: 'Klantcontact' }]
			},
		}
		const widget = (await import('../../src/components/connections/ConnectionMapWidget.vue')).default

		const named = await widget.methods.named.call({}, CONNECTIONS)
		const labels = connectionMap('root', 'This application', named).nodes.map((node) => node.label)

		expect(asked).toEqual([['module', { _ids: ['root', 'dms', 'crm'], _limit: 3 }]])
		expect(labels).toEqual(expect.arrayContaining(['Documentbeheer', 'Klantcontact']))
		expect(labels.some((label) => ['dms', 'crm'].includes(label))).toBe(false)
	})

	it('does not read anything when every end already has a name', async () => {
		store.objectStore = { fetchCollectionForOptions: vi.fn() }
		const widget = (await import('../../src/components/connections/ConnectionMapWidget.vue')).default
		const named = [{ id: 'c1', moduleA: { id: 'root', name: 'Z' }, moduleB: { id: 'dms', name: 'D' } }]

		expect(await widget.methods.named.call({}, named)).toBe(named)
		expect(store.objectStore.fetchCollectionForOptions).not.toHaveBeenCalled()
	})
})
