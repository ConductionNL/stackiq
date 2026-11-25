/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The data model of one entity (architecture-data-model-and-ggm D4, D5): the
 * relations that join a data entity to other entities, drawn around it, and its
 * attributes with the key attributes first. A data entity is an AMEF `element`
 * of type BusinessObject; a relation names its ends by ArchiMate `identifier`,
 * not by uuid, so every lookup goes through the identifier field.
 *
 * The HTTP client is injected, so the views pass `@nextcloud/axios` and the
 * specs a fake.
 *
 * @spec openspec/changes/archive/2026-11-25-architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-002-a-data-entity-page-shall-draw-the-entitys-relations-to-other-entities
 */

import { generateUrl } from '@nextcloud/router'
import { isImported, uuidOf } from '../store/modules/architectureView.js'

/** The relation types a data model draws. */
export const DATA_RELATION_TYPES = Object.freeze(['Association', 'Aggregation', 'Composition', 'Specialization'])

/** The data types an attribute can take, in the order the editor offers them. */
export const ATTRIBUTE_DATA_TYPES = Object.freeze(['text', 'number', 'date', 'boolean', 'reference'])

/** The cardinalities a drawn relation's ends can take. */
export const CARDINALITIES = Object.freeze(['0..1', '1', '0..*', '1..*'])

/** The most relations one query reads, per end. */
export const RELATION_LIMIT = 200

/**
 * The list out of an OpenRegister list response.
 *
 * @param {object|Array} data The response body.
 * @return {Array<object>}
 */
function resultsOf(data) {
	if (Array.isArray(data)) return data
	if (data && Array.isArray(data.results)) return data.results
	return []
}

/**
 * Whether a relation is one a data model draws.
 *
 * @param {object} relation The relation.
 * @return {boolean}
 *
 * @spec openspec/changes/archive/2026-11-25-architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-002-a-data-entity-page-shall-draw-the-entitys-relations-to-other-entities
 */
export function isDataRelation(relation) {
	return DATA_RELATION_TYPES.includes(relation?.type)
}

/**
 * The text an edge carries: the relation's name, else its type, then both
 * cardinalities when either is set, as `gedaan door, 0..* to 1`.
 *
 * @param {object} relation The relation.
 * @param {function(string, string, object=): string} t The translate function.
 * @return {string}
 * @spec openspec/changes/archive/2026-11-25-architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-002-a-data-entity-page-shall-draw-the-entitys-relations-to-other-entities
 */
export function edgeLabel(relation, t) {
	const name = (relation?.name || '').trim() || relation?.type || ''
	const from = relation?.sourceCardinality || ''
	const to = relation?.targetCardinality || ''
	if (!from && !to) {
		return name
	}

	const cardinalities = t('stackiq', '{from} to {to}', { from: from || '?', to: to || '?' })
	return name ? `${name}, ${cardinalities}` : cardinalities
}

/**
 * The relations of one entity: those with the entity at either end and a data
 * model type, each once, in the order the two queries returned them.
 *
 * @param {string} identifier The entity's ArchiMate identifier.
 * @param {Array<object>} asSource Relations whose source is the entity.
 * @param {Array<object>} asTarget Relations whose target is the entity.
 * @return {Array<object>}
 *
 * @spec openspec/changes/archive/2026-11-25-architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-002-a-data-entity-page-shall-draw-the-entitys-relations-to-other-entities
 */
export function entityRelations(identifier, asSource, asTarget) {
	const seen = new Set()
	const out = []
	for (const relation of [...asSource, ...asTarget]) {
		if (!isDataRelation(relation)) continue
		if (relation.source !== identifier && relation.target !== identifier) continue
		const key = uuidOf(relation) ?? relation.identifier ?? `${relation.source}|${relation.type}|${relation.target}`
		if (seen.has(key)) continue
		seen.add(key)
		out.push(relation)
	}
	return out
}

/**
 * The identifiers at the other end of each relation, each once, the entity itself left out.
 *
 * @param {string} identifier The entity's ArchiMate identifier.
 * @param {Array<object>} relations The entity's relations.
 * @return {Array<string>}
 *
 * @spec openspec/changes/archive/2026-11-25-architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-002-a-data-entity-page-shall-draw-the-entitys-relations-to-other-entities
 */
export function neighbourIdentifiers(identifier, relations) {
	const out = []
	for (const relation of relations) {
		const other = relation.source === identifier ? relation.target : relation.source
		if (other && other !== identifier && !out.includes(other)) {
			out.push(other)
		}
	}
	return out
}

/**
 * The graph and the table of one entity's relations.
 *
 * Nodes are keyed by identifier, the entity is the root, and a neighbour that
 * could not be read keeps its identifier as label and opens nothing. A relation
 * of the entity to itself is listed in the table but draws no second node.
 *
 * @param {object} entity The entity.
 * @param {Array<object>} relations The entity's relations.
 * @param {Map<string, object>} neighbours The elements at the other ends, by identifier.
 * @param {function(string, string, object=): string} t The translate function.
 * @return {{nodes: Array<object>, edges: Array<object>, rows: Array<object>}}
 * @spec openspec/changes/archive/2026-11-25-architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-002-a-data-entity-page-shall-draw-the-entitys-relations-to-other-entities
 */
export function buildEntityGraph(entity, relations, neighbours, t) {
	const identifier = entity.identifier
	const nodes = [{ id: identifier, label: entity.name || identifier, isRoot: true, uuid: uuidOf(entity) }]
	for (const other of neighbourIdentifiers(identifier, relations)) {
		const element = neighbours.get(other) ?? null
		nodes.push({ id: other, label: element?.name || other, uuid: element ? uuidOf(element) : null })
	}

	const edges = []
	const rows = []
	for (const relation of relations) {
		const label = edgeLabel(relation, t)
		const outgoing = relation.source === identifier
		const other = outgoing ? relation.target : relation.source
		const element = other === identifier ? entity : (neighbours.get(other) ?? null)
		edges.push({
			id: uuidOf(relation) ?? relation.identifier,
			source: relation.source,
			target: relation.target,
			label,
			dashed: relation.type === 'Specialization',
		})
		rows.push({
			id: uuidOf(relation) ?? relation.identifier,
			name: (relation.name || '').trim(),
			type: relation.type,
			direction: outgoing ? 'outgoing' : 'incoming',
			other: element?.name || other,
			otherUuid: element ? uuidOf(element) : null,
			sourceCardinality: relation.sourceCardinality || '',
			targetCardinality: relation.targetCardinality || '',
			label,
		})
	}

	return { nodes, edges, rows }
}

/**
 * An entity's attributes, the key attributes first, otherwise in their stored order.
 *
 * @param {object|null} entity The entity.
 * @return {Array<{name: string, dataType: string, isKey: boolean, description: string}>}
 * @spec openspec/changes/archive/2026-11-25-architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-004-a-municipality-shall-add-its-own-data-entities-with-attributes-keys-and-cardinalities
 */
export function sortedAttributes(entity) {
	const list = Array.isArray(entity?.attributes) ? entity.attributes : []
	const normal = list
		.filter((attribute) => attribute && typeof attribute === 'object')
		.map((attribute) => ({
			name: attribute.name ?? '',
			dataType: attribute.dataType || 'text',
			isKey: attribute.isKey === true,
			description: attribute.description ?? '',
		}))
	return [...normal.filter((a) => a.isKey), ...normal.filter((a) => !a.isKey)]
}

/**
 * The attributes to store: trimmed, without rows that have no name, the key attributes first.
 *
 * @param {Array<object>} attributes The rows the editor holds.
 * @return {Array<object>}
 *
 * @spec openspec/changes/archive/2026-11-25-architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-004-a-municipality-shall-add-its-own-data-entities-with-attributes-keys-and-cardinalities
 */
export function attributesToSave(attributes) {
	const kept = attributes
		.map((attribute) => {
			const out = {
				name: (attribute.name ?? '').trim(),
				dataType: ATTRIBUTE_DATA_TYPES.includes(attribute.dataType) ? attribute.dataType : 'text',
				isKey: attribute.isKey === true,
			}
			const description = (attribute.description ?? '').trim()
			if (description) out.description = description
			return out
		})
		.filter((attribute) => attribute.name !== '')
	return sortedAttributes({ attributes: kept }).map((attribute) => {
		const out = { name: attribute.name, dataType: attribute.dataType, isKey: attribute.isKey }
		if (attribute.description) out.description = attribute.description
		return out
	})
}

/**
 * Whether the entity is the organisation's own, so its attributes can be edited.
 *
 * @param {object|null} entity The entity.
 * @return {boolean}
 *
 * @spec openspec/changes/archive/2026-11-25-architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-004-a-municipality-shall-add-its-own-data-entities-with-attributes-keys-and-cardinalities
 */
export function isOwnEntity(entity) {
	return Boolean(entity) && !isImported(entity)
}

/**
 * The reads and the one write the entity page needs, over the AMEF register.
 *
 * @param {object} options The collaborators.
 * @param {{get: function(string, object=): Promise<{data: object}>, put: function(string, object): Promise<{data: object}>}} options.http An axios-like client.
 * @param {string|number} options.register The AMEF register id or slug.
 * @return {object}
 *
 * @spec openspec/changes/archive/2026-11-25-architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-002-a-data-entity-page-shall-draw-the-entitys-relations-to-other-entities
 * @spec openspec/changes/archive/2026-11-25-architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-004-a-municipality-shall-add-its-own-data-entities-with-attributes-keys-and-cardinalities
 */
export function createDataEntityApi({ http, register }) {
	const url = (schema, id = null) => generateUrl(
		`/apps/openregister/api/objects/${encodeURIComponent(register)}/${encodeURIComponent(schema)}`
		+ (id ? `/${encodeURIComponent(id)}` : ''),
	)
	const list = async (schema, params) => resultsOf((await http.get(url(schema), { params })).data)

	return {
		/**
		 * One entity by uuid.
		 *
		 * @param {string} id The uuid.
		 * @return {Promise<object>}
		 *
		 * @spec openspec/changes/archive/2026-11-25-architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-001-stackiq-shall-show-the-data-entities-of-the-imported-model-on-a-data-model-page
		 */
		async loadEntity(id) {
			return (await http.get(url('element', id))).data
		},

		/**
		 * The entity's relations, found through its identifier at either end.
		 *
		 * @param {object} entity The entity.
		 * @return {Promise<{relations: Array<object>, limited: boolean}>}
		 * @spec openspec/changes/archive/2026-11-25-architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-002-a-data-entity-page-shall-draw-the-entitys-relations-to-other-entities
		 */
		async loadRelations(entity) {
			const identifier = entity?.identifier
			if (!identifier) {
				return { relations: [], limited: false }
			}

			const [asSource, asTarget] = await Promise.all([
				list('relation', { source: identifier, _limit: RELATION_LIMIT }),
				list('relation', { target: identifier, _limit: RELATION_LIMIT }),
			])
			return {
				relations: entityRelations(identifier, asSource, asTarget),
				limited: asSource.length >= RELATION_LIMIT || asTarget.length >= RELATION_LIMIT,
			}
		},

		/**
		 * The elements with these identifiers, by identifier. One that cannot be read is left out.
		 *
		 * @param {Array<string>} identifiers The identifiers.
		 * @return {Promise<Map<string, object>>}
		 *
		 * @spec openspec/changes/archive/2026-11-25-architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-002-a-data-entity-page-shall-draw-the-entitys-relations-to-other-entities
		 */
		async loadElements(identifiers) {
			const found = new Map()
			await Promise.all(identifiers.map(async (identifier) => {
				try {
					const [element] = await list('element', { identifier, _limit: 1 })
					if (element) found.set(identifier, element)
				} catch {
					// Unreadable: the node keeps its identifier as label.
				}
			}))
			return found
		},

		/**
		 * Store the attributes of an own entity, keeping every other field.
		 * The AMEF element schema requires `properties`, which a read does not
		 * return while the list is empty, so an empty list is sent then
		 * (OpenRegister main refused the save as 'properties missing').
		 *
		 * @param {object} entity The entity as loaded.
		 * @param {Array<object>} attributes The attributes to store.
		 * @return {Promise<object>} The saved entity.
		 * @spec openspec/changes/archive/2026-11-25-architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-004-a-municipality-shall-add-its-own-data-entities-with-attributes-keys-and-cardinalities
		 */
		async saveAttributes(entity, attributes) {
			if (!isOwnEntity(entity)) {
				throw new Error('Only an own data entity takes attributes')
			}

			const body = {
				...entity,
				properties: Array.isArray(entity.properties) ? entity.properties : [],
				attributes: attributesToSave(attributes),
			}
			delete body['@self']
			return (await http.put(url('element', uuidOf(entity)), body)).data
		},
	}
}
