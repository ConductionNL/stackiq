/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Reads and writes drawn architecture views through OpenRegister's objects API
 * (architecture-views-editor, design D2, D3, D5 and D6). It adds no controller
 * and no service: every write is a plain object write in the `vng-gemma`
 * register, so OpenRegister applies RBAC, multitenancy and the audit trail.
 *
 * The HTTP client is injected, so the editor passes `@nextcloud/axios` and the
 * specs pass a fake. `src/store/modules/view.js` (the read-only GEMMA views
 * list) is left alone.
 */

import { generateUrl } from '@nextcloud/router'
import { fromCanvas } from '../../utils/viewGraph.js'

/** The AMEF schema slugs this module writes. */
export const SCHEMAS = Object.freeze({
	view: 'view',
	element: 'element',
	relation: 'relation',
	version: 'view-version',
})

/** The ArchiMate element types the palette offers, by layer. */
export const ELEMENT_TYPES = Object.freeze([
	{ type: 'BusinessActor', layer: 'business' },
	{ type: 'BusinessRole', layer: 'business' },
	{ type: 'BusinessProcess', layer: 'business' },
	{ type: 'BusinessService', layer: 'business' },
	{ type: 'BusinessObject', layer: 'business' },
	{ type: 'ApplicationComponent', layer: 'application' },
	{ type: 'ApplicationService', layer: 'application' },
	{ type: 'ApplicationInterface', layer: 'application' },
	{ type: 'ApplicationFunction', layer: 'application' },
	{ type: 'DataObject', layer: 'application' },
	{ type: 'Node', layer: 'technology' },
	{ type: 'SystemSoftware', layer: 'technology' },
	{ type: 'TechnologyService', layer: 'technology' },
	{ type: 'Grouping', layer: 'other' },
])

/** The ArchiMate relation types a connection can take. */
export const RELATION_TYPES = Object.freeze([
	'Association',
	'Flow',
	'Serving',
	'Realization',
	'Composition',
	'Aggregation',
	'Assignment',
	'Access',
	'Triggering',
	'Specialization',
	'Influence',
])

/**
 * Whether a view, element or relation is GEMMA content: `imported`, or no
 * origin at all (written before the field existed). Anything else, today
 * `drawn`, is the organisation's own.
 *
 * @param {object|null|undefined} object The object.
 * @return {boolean}
 * @spec openspec/changes/architecture-views-editor/specs/architecture-views-editor/spec.md#requirement-req-ave-002-imported-gemma-views-shall-open-read-only-and-shall-be-copied-before-editing
 */
export function isImported(object) {
	const origin = object?.origin
	return origin === undefined || origin === null || origin === '' || origin === 'imported'
}

/**
 * The OpenRegister uuid of an object.
 *
 * @param {object|null|undefined} object The object.
 * @return {string|null}
 */
export function uuidOf(object) {
	return object?.['@self']?.id ?? object?.id ?? null
}

/**
 * The list out of an OpenRegister list response.
 *
 * @param {object} data The response body.
 * @return {Array<object>}
 */
function resultsOf(data) {
	if (Array.isArray(data)) return data
	if (data && Array.isArray(data.results)) return data.results
	return []
}

/**
 * A fresh ArchiMate identifier, `id-<uuid>`, the form the import's identifiers take.
 *
 * @return {string}
 */
function defaultIdentifier() {
	const random = typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
		? crypto.randomUUID()
		: `${Date.now().toString(16)}-${Math.random().toString(16).slice(2)}`
	return `id-${random}`
}

/**
 * The store over one register.
 *
 * @param {object} options The collaborators.
 * @param {{get: function(string, object=): Promise<{data: object}>, post: function(string, object): Promise<{data: object}>, put: function(string, object): Promise<{data: object}>}} options.http An axios-like client.
 * @param {string|number} options.register The AMEF register id or slug.
 * @param {string} [options.user] The signed-in user id, recorded on a version.
 * @param {function(): Date} [options.now] The clock.
 * @param {function(): string} [options.newIdentifier] Makes a fresh ArchiMate identifier.
 * @return {object}
 * @spec openspec/changes/architecture-assistant-drafted-views/specs/architecture-assistant-views/spec.md#requirement-req-aav-003-every-object-the-draft-tool-writes-shall-carry-the-assistant-mark-in-the-same-write
 */
export function createArchitectureViewStore({ http, register, user = '', now = () => new Date(), newIdentifier = defaultIdentifier }) {
	const url = (schema, id = null, suffix = '') => generateUrl(
		`/apps/openregister/api/objects/${encodeURIComponent(register)}/${encodeURIComponent(schema)}`
		+ (id ? `/${encodeURIComponent(id)}` : '')
		+ suffix,
	)

	const list = async (schema, params) => resultsOf((await http.get(url(schema), { params })).data)
	const create = async (schema, body) => (await http.post(url(schema), body)).data
	const update = async (schema, id, body) => (await http.put(url(schema, id), body)).data

	/**
	 * One view.
	 *
	 * @param {string} id The view uuid.
	 * @return {Promise<object>}
	 */
	async function loadView(id) {
		return (await http.get(url(SCHEMAS.view, id))).data
	}

	/**
	 * A new, empty drawn view.
	 *
	 * @param {{name: string, viewpoint?: string}} fields The name and an optional viewpoint.
	 * @return {Promise<object>}
	 * @spec openspec/changes/architecture-views-editor/specs/architecture-views-editor/spec.md#requirement-req-ave-001-an-application-owner-shall-draw-and-save-an-architecture-view
	 */
	async function createView({ name, viewpoint = '' }) {
		return create(SCHEMAS.view, {
			identifier: newIdentifier(),
			name,
			...(viewpoint && { viewpoint }),
			origin: 'drawn',
			status: 'draft',
			tags: [],
			nodes: [],
			connections: [],
			xml: { viewNodes: [], viewRelationships: [] },
		})
	}

	/**
	 * Copy to edit: a drawn copy of a view, with a fresh identifier, status
	 * draft and `basedOn` naming the source. The source is not changed.
	 *
	 * @param {object} source The view to copy.
	 * @param {string} name The copy's name.
	 * @return {Promise<object>}
	 * @spec openspec/changes/architecture-views-editor/specs/architecture-views-editor/spec.md#requirement-req-ave-002-imported-gemma-views-shall-open-read-only-and-shall-be-copied-before-editing
	 */
	async function copyToEdit(source, name) {
		const xml = source?.xml ?? {}
		const viewNodes = JSON.parse(JSON.stringify(xml.viewNodes ?? []))
		const viewRelationships = JSON.parse(JSON.stringify(xml.viewRelationships ?? []))
		return create(SCHEMAS.view, {
			identifier: newIdentifier(),
			name,
			...(source?.viewpoint && { viewpoint: source.viewpoint }),
			...(source?.documentation && { documentation: source.documentation }),
			origin: 'drawn',
			status: 'draft',
			tags: Array.isArray(source?.tags) ? [...source.tags] : [],
			basedOn: uuidOf(source),
			nodes: viewNodes,
			connections: viewRelationships,
			xml: { ...xml, viewNodes, viewRelationships },
		})
	}

	/**
	 * Elements to place, matched on name.
	 *
	 * @param {string} term The search text.
	 * @param {number} [limit] The most results.
	 * @return {Promise<Array<object>>}
	 */
	async function searchElements(term, limit = 20) {
		return list(SCHEMAS.element, { _search: term, _limit: limit })
	}

	/**
	 * A new element from the palette, `origin: drawn`.
	 *
	 * @param {{type: string, name: string}} fields The ArchiMate type and the name.
	 * @return {Promise<object>}
	 * @spec openspec/changes/architecture-views-editor/specs/architecture-views-editor/spec.md#requirement-req-ave-001-an-application-owner-shall-draw-and-save-an-architecture-view
	 */
	async function createElement({ type, name }) {
		return create(SCHEMAS.element, { identifier: newIdentifier(), type, name, origin: 'drawn' })
	}

	/**
	 * The relation of `type` from `source` to `target`: the existing one, or a
	 * new one with `origin: drawn`. Returns the identifier a connection stores
	 * as `modelRelationshipId`.
	 *
	 * @param {{type: string, source: string, target: string}} ends The relation type and the two element identifiers.
	 * @return {Promise<string>}
	 * @spec openspec/changes/architecture-views-editor/specs/architecture-views-editor/spec.md#requirement-req-ave-003-a-drawn-connection-shall-reference-a-relation-object
	 */
	async function ensureRelation({ type, source, target }) {
		const found = (await list(SCHEMAS.relation, { type, source, target, _limit: 1 }))
			.find((relation) => relation.type === type && relation.source === source && relation.target === target)
		if (found) {
			return found.identifier ?? uuidOf(found)
		}
		const created = await create(SCHEMAS.relation, { identifier: newIdentifier(), type, source, target, origin: 'drawn' })
		return created.identifier ?? uuidOf(created)
	}

	/**
	 * Save a drawn view from the canvas.
	 *
	 * Order matters for a retry (design, Risks): new elements first, then the
	 * relations behind the connections, then the view. Each element and
	 * relation written is put on the canvas node or edge it belongs to, so a
	 * save that fails at the view write reuses them on the next try instead of
	 * writing them twice. An imported view is refused: it is copied first.
	 *
	 * @param {object} view The view being edited.
	 * @param {{nodes: Array<object>, edges: Array<object>}} canvas The canvas nodes and edges, updated in place.
	 * @return {Promise<object>} The saved view.
	 * @spec openspec/changes/architecture-views-editor/specs/architecture-views-editor/spec.md#requirement-req-ave-001-an-application-owner-shall-draw-and-save-an-architecture-view
	 */
	async function saveView(view, canvas) {
		if (isImported(view)) {
			throw new Error('An imported view is read-only; copy it to edit')
		}
		const nodes = canvas.nodes ?? []
		const edges = canvas.edges ?? []

		for (const node of nodes) {
			if (!node.data?.elementRef && node.data?.type) {
				const element = await createElement({ type: node.data.type, name: node.data.name ?? '' })
				node.data.elementRef = element.identifier ?? uuidOf(element)
			}
		}

		const elementOf = new Map(nodes.map((node) => [String(node.id), node.data?.elementRef ?? null]))
		for (const edge of edges) {
			if (edge.data?.modelRelationshipId) continue
			const source = elementOf.get(String(edge.source))
			const target = elementOf.get(String(edge.target))
			if (!source || !target) continue
			edge.data = { ...(edge.data ?? {}), type: edge.data?.type || 'Association' }
			edge.data.modelRelationshipId = await ensureRelation({ type: edge.data.type, source, target })
		}

		const { viewNodes, viewRelationships } = fromCanvas(nodes, edges)
		const fields = { ...view }
		delete fields['@self']
		return update(SCHEMAS.view, uuidOf(view), {
			...fields,
			// An assistant's draft keeps its mark when a person edits it (architecture-assistant-drafted-views D3).
			origin: view.origin === 'assistant' ? 'assistant' : 'drawn',
			nodes: viewNodes,
			connections: viewRelationships,
			xml: { ...(view.xml ?? {}), viewNodes, viewRelationships },
		})
	}

	/**
	 * The saved versions of a view, oldest first.
	 *
	 * @param {string} viewUuid The view uuid.
	 * @return {Promise<Array<object>>}
	 * @spec openspec/changes/architecture-views-editor/specs/architecture-views-editor/spec.md#requirement-req-ave-005-an-owner-shall-save-named-versions-and-compare-two-of-them
	 */
	async function listVersions(viewUuid) {
		const versions = await list(SCHEMAS.version, { view: viewUuid, _limit: 500 })
		return versions
			.filter((version) => version.view === viewUuid)
			.sort((a, b) => Number(a.versionNumber) - Number(b.versionNumber))
	}

	/**
	 * Save version: a `view-version` with the next number, the label, the
	 * saving user, the time and a copy of the saved nodes and connections.
	 *
	 * @param {object} view The saved view.
	 * @param {string} label What the owner calls this version.
	 * @return {Promise<object>}
	 * @spec openspec/changes/architecture-views-editor/specs/architecture-views-editor/spec.md#requirement-req-ave-005-an-owner-shall-save-named-versions-and-compare-two-of-them
	 */
	async function saveVersion(view, label) {
		const viewUuid = uuidOf(view)
		const versions = await listVersions(viewUuid)
		const last = versions.reduce((max, version) => Math.max(max, Number(version.versionNumber) || 0), 0)
		const xml = view.xml ?? {}
		return create(SCHEMAS.version, {
			view: viewUuid,
			versionNumber: last + 1,
			label,
			savedBy: user,
			savedAt: now().toISOString(),
			nodes: JSON.parse(JSON.stringify(xml.viewNodes ?? view.nodes ?? [])),
			connections: JSON.parse(JSON.stringify(xml.viewRelationships ?? view.connections ?? [])),
		})
	}

	/**
	 * A view or a version as the `{nodes, connections}` snapshot `diffViews` compares.
	 *
	 * @param {object} source A `view` or a `view-version`.
	 * @return {{nodes: Array<object>, connections: Array<object>}}
	 */
	function snapshotOf(source) {
		if (source && source.versionNumber !== undefined) {
			return { nodes: source.nodes ?? [], connections: source.connections ?? [] }
		}
		const xml = source?.xml ?? {}
		return { nodes: xml.viewNodes ?? source?.nodes ?? [], connections: xml.viewRelationships ?? source?.connections ?? [] }
	}

	return {
		loadView,
		createView,
		copyToEdit,
		searchElements,
		createElement,
		ensureRelation,
		saveView,
		listVersions,
		saveVersion,
		snapshotOf,
		url,
	}
}
