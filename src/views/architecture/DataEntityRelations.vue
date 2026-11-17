<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<template>
	<CnWidgetWrapper
		:title="t('stackiq', 'Relations')"
		titleIconPosition="left"
		:showRefresh="false"
		:showRequestFeature="false">
		<template #title-icon>
			<CnIcon name="Sitemap" :size="20" />
		</template>
		<div class="data-entity-relations" data-testid="data-entity-relations">
			<NcLoadingIcon
				v-if="loading"
				:size="32"
				:name="t('stackiq', 'Loading the relations')" />
			<NcNoteCard v-else-if="error" type="error">
				{{ t('stackiq', 'The relations could not be loaded.') }}
			</NcNoteCard>
			<NcEmptyContent
				v-else-if="graph.rows.length === 0"
				:name="t('stackiq', 'No relations yet')"
				:description="t('stackiq', 'This data entity is not related to another entity.')" />
			<template v-else>
				<CnRelationshipGraph
					class="data-entity-relations__graph"
					layout="radial"
					:nodes="graph.nodes"
					:edges="graph.edges"
					:nodeColor="colours.node"
					:rootColor="colours.root"
					:edgeColor="colours.edge"
					:strokeColor="colours.stroke"
					:ariaLabel="t('stackiq', 'Relations of {name}', { name: entityName })"
					@nodeClick="openNode" />
				<p v-if="limited" class="data-entity-relations__limit" data-testid="data-entity-relations-limit">
					{{ t('stackiq', 'Only the first {count} relations at each end are shown.', { count: limit }) }}
				</p>
				<table class="data-entity-relations__table" data-testid="data-entity-relations-table">
					<caption class="hidden-visually">
						{{ t('stackiq', 'Relations of {name}', { name: entityName }) }}
					</caption>
					<thead>
						<tr>
							<th scope="col">
								{{ t('stackiq', 'Relation') }}
							</th>
							<th scope="col">
								{{ t('stackiq', 'Type') }}
							</th>
							<th scope="col">
								{{ t('stackiq', 'Related entity') }}
							</th>
							<th scope="col">
								{{ t('stackiq', 'Cardinality') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in graph.rows" :key="row.id" :data-relation-id="row.id">
							<td>{{ row.name || '—' }}</td>
							<td>{{ typeLabel(row.type) }}</td>
							<td>
								<NcButton
									v-if="row.otherUuid"
									variant="tertiary"
									:aria-label="t('stackiq', 'Open {name}', { name: row.other })"
									@click="open(row.otherUuid)">
									{{ row.other }}
								</NcButton>
								<span v-else>{{ row.other }}</span>
								<span class="data-entity-relations__direction">
									{{ row.direction === 'outgoing' ? t('stackiq', '(outgoing)') : t('stackiq', '(incoming)') }}
								</span>
							</td>
							<td>{{ cardinalityText(row) }}</td>
						</tr>
					</tbody>
				</table>
			</template>
		</div>
	</CnWidgetWrapper>
</template>

<script>
import { CnIcon, CnRelationshipGraph, CnWidgetWrapper } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { buildEntityGraph, createDataEntityApi, RELATION_LIMIT } from '../../utils/dataEntityGraph.js'

/**
 * Nextcloud CSS variables for every colour the graph draws (ADR-003): the
 * component's own defaults are hex values.
 */
const COLOURS = Object.freeze({
	node: 'var(--color-primary-element-light)',
	root: 'var(--color-primary-element)',
	edge: 'var(--color-border-dark)',
	stroke: 'var(--color-main-background)',
})

/**
 * The relations of one data entity drawn around it, with the same relations
 * as a text table under the graph. Choosing a node or a row opens that
 * entity's page.
 *
 * @spec openspec/changes/architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-002-a-data-entity-page-shall-draw-the-entitys-relations-to-other-entities
 */
export default {
	name: 'DataEntityRelations',

	components: {
		CnIcon,
		CnRelationshipGraph,
		CnWidgetWrapper,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		/** The entity's uuid. */
		objectId: {
			type: String,
			required: true,
		},

		/** The AMEF register (resolved from `@resolve:amef_register`). */
		register: {
			type: [String, Number],
			required: true,
		},

		/** An axios-like client; the specs pass a fake. */
		http: {
			type: Object,
			default: () => axios,
		},
	},

	data() {
		return {
			loading: true,
			error: false,
			entityName: '',
			limited: false,
			limit: RELATION_LIMIT,
			graph: { nodes: [], edges: [], rows: [] },
			colours: COLOURS,
		}
	},

	computed: {
		/**
		 * @return {object} The reads over the AMEF register.
		 *
		 * @spec openspec/changes/architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-002-a-data-entity-page-shall-draw-the-entitys-relations-to-other-entities
		 */
		api() {
			return createDataEntityApi({ http: this.http, register: this.register })
		},
	},

	watch: {
		/**
		 * @spec openspec/changes/architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-002-a-data-entity-page-shall-draw-the-entitys-relations-to-other-entities
		 */
		objectId() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Load the entity, its relations and the entities at their other ends.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-002-a-data-entity-page-shall-draw-the-entitys-relations-to-other-entities
		 */
		async load() {
			this.loading = true
			this.error = false
			try {
				const entity = await this.api.loadEntity(this.objectId)
				this.entityName = entity?.name || entity?.identifier || ''
				const { relations, limited } = await this.api.loadRelations(entity)
				const others = relations.map((r) => (r.source === entity.identifier ? r.target : r.source)).filter((id) => id !== entity.identifier)
				const neighbours = await this.api.loadElements([...new Set(others)])
				this.limited = limited
				this.graph = buildEntityGraph(entity, relations, neighbours, t)
			} catch {
				this.error = true
				this.graph = { nodes: [], edges: [], rows: [] }
			} finally {
				this.loading = false
			}
		},

		/**
		 * Open the page of a node's entity; the root and unreadable nodes open nothing.
		 *
		 * @param {object} node The chosen node.
		 * @return {void}
		 *
		 * @spec openspec/changes/architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-002-a-data-entity-page-shall-draw-the-entitys-relations-to-other-entities
		 */
		openNode(node) {
			if (node && !node.isRoot && node.uuid) {
				this.open(node.uuid)
			}
		},

		/**
		 * Open a data entity's page.
		 *
		 * @param {string} uuid The entity's uuid.
		 * @return {void}
		 *
		 * @spec openspec/changes/architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-002-a-data-entity-page-shall-draw-the-entitys-relations-to-other-entities
		 */
		open(uuid) {
			this.$router?.push({ name: 'GegevensobjectDetail', params: { id: uuid } })
		},

		/**
		 * @param {object} row A table row.
		 * @return {string} Both cardinalities, or a dash.
		 *
		 * @spec openspec/changes/architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-002-a-data-entity-page-shall-draw-the-entitys-relations-to-other-entities
		 */
		cardinalityText(row) {
			if (!row.sourceCardinality && !row.targetCardinality) {
				return '—'
			}
			return t('stackiq', '{from} to {to}', { from: row.sourceCardinality || '?', to: row.targetCardinality || '?' })
		},

		/**
		 * @param {string} type The relation type.
		 * @return {string}
		 *
		 * @spec openspec/changes/architecture-data-model-and-ggm/specs/data-model-and-ggm/spec.md#requirement-req-dmg-002-a-data-entity-page-shall-draw-the-entitys-relations-to-other-entities
		 */
		typeLabel(type) {
			return {
				Association: t('stackiq', 'Association'),
				Aggregation: t('stackiq', 'Aggregation'),
				Composition: t('stackiq', 'Composition'),
				Specialization: t('stackiq', 'Specialization'),
			}[type] || type
		},
	},
}
</script>

<style scoped>
.data-entity-relations__graph {
	max-width: 480px;
	margin: 0 auto;
}

.data-entity-relations__limit,
.data-entity-relations__direction {
	color: var(--color-text-maxcontrast);
}

.data-entity-relations__table {
	width: 100%;
	border-collapse: collapse;
	margin-top: 12px;
}

.data-entity-relations__table th,
.data-entity-relations__table td {
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
	text-align: start;
	color: var(--color-main-text);
}
</style>
