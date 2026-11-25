/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Data model page's New data entity (architecture-data-model-and-ggm task 4):
 * the AMEF element schema requires an ArchiMate identifier and a properties
 * list, which the create form never asks for, so the handler fills both.
 *
 * @spec openspec/changes/architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-004-a-municipality-shall-add-its-own-data-entities-with-attributes-keys-and-cardinalities
 */

import { describe, expect, it, vi } from 'vitest'
import { createDrawnDataEntityHandler, drawnDataEntityBody, newArchimateIdentifier } from '../../src/utils/drawnDataEntity.js'

vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))
vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn() } }))

const form = {
	type: 'BusinessObject',
	origin: 'drawn',
	name: 'Melding openbare ruimte',
	documentation: 'Een melding over de openbare ruimte.',
	attributes: [{ name: 'meldingnummer', dataType: 'text', isKey: true }],
}

describe('a new drawn data entity', () => {
	it('gets a fresh id-<uuid> identifier and an empty properties list', () => {
		expect(drawnDataEntityBody(form, () => 'id-fixed')).toEqual({ ...form, identifier: 'id-fixed', properties: [] })
	})

	it('keeps an identifier and properties the form already carries', () => {
		const body = drawnDataEntityBody({ ...form, identifier: ' id-given ', properties: [{ key: 'k' }] }, () => 'id-other')
		expect(body.identifier).toBe('id-given')
		expect(body.properties).toEqual([{ key: 'k' }])
	})

	it('replaces an empty identifier', () => {
		expect(drawnDataEntityBody({ ...form, identifier: '' }, () => 'id-new').identifier).toBe('id-new')
	})

	it('makes identifiers in the import\'s form, different each time', () => {
		const one = newArchimateIdentifier()
		expect(one).toMatch(/^id-[0-9a-f-]+$/)
		expect(newArchimateIdentifier()).not.toBe(one)
	})
})

describe('the create handler', () => {
	it('posts the completed body to the page\'s register and schema and returns the created object', async () => {
		const post = vi.fn().mockResolvedValue({ data: { id: 'uuid-1', name: form.name } })
		const create = createDrawnDataEntityHandler({ http: { post }, newIdentifier: () => 'id-fixed' })

		const created = await create(form, { register: 7, schema: { id: 42, slug: 'element' } })

		expect(created).toEqual({ id: 'uuid-1', name: form.name })
		expect(post).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/7/42', { ...form, identifier: 'id-fixed', properties: [] })
	})

	it('accepts a schema slug', async () => {
		const post = vi.fn().mockResolvedValue({ data: {} })
		await createDrawnDataEntityHandler({ http: { post }, newIdentifier: () => 'id-x' })(form, { register: 'amef', schema: 'element' })
		expect(post.mock.calls[0][0]).toBe('/index.php/apps/openregister/api/objects/amef/element')
	})

	it('surfaces OpenRegister\'s refusal in the form', async () => {
		const post = vi.fn().mockRejectedValue({ message: 'Request failed', response: { data: { message: 'name is required' } } })
		await expect(createDrawnDataEntityHandler({ http: { post } })(form, { register: 7, schema: 42 })).rejects.toThrow('name is required')
	})

	it('refuses without a register or schema', async () => {
		const post = vi.fn()
		await expect(createDrawnDataEntityHandler({ http: { post } })(form, { register: '', schema: 42 })).rejects.toThrow()
		expect(post).not.toHaveBeenCalled()
	})
})
