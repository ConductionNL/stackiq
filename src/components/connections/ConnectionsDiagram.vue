<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<!--
ConnectionsDiagram.vue

The Diagram view of the connections list (CnIndexPage `customViews`): the
rows the list shows, after its filters and search, drawn as applications
(senders left, receivers right, the rest in the middle) with one line per
connection, labelled with its transport type. The layout is stable: the same
rows always land in the same places. A line opens its connection.

@spec openspec/changes/archive/2026-11-25-connections-diagram-and-graph-export/specs/connection-diagram-and-export/spec.md#requirement-req-cdx-002-the-connections-list-can-be-shown-as-a-diagram-of-the-filtered-rows
-->

<template>
	<div class="connections-diagram" data-testid="connections-diagram">
		<NcLoadingIcon v-if="loading && rows.length === 0" :size="32" />
		<NcEmptyContent
			v-else-if="flow.nodes.length === 0"
			:name="t('stackiq', 'No connections to draw')"
			:description="t('stackiq', 'Change the filters to show connections in the diagram.')" />
		<CnGraphCanvas
			v-else
			class="connections-diagram__canvas"
			:nodes="flow.nodes"
			:edges="flow.edges"
			:fitView="true"
			:fitViewMaxZoom="1"
			@edgeSelect="onEdgeSelect">
			<template #node="{ node }">
				<span
					class="connections-diagram__node"
					:class="{ 'connections-diagram__node--provision': node.data.provision }">
					{{ node.data.label }}
				</span>
			</template>
		</CnGraphCanvas>
	</div>
</template>

<script>
import { CnGraphCanvas } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { objectStore } from '../../store/store.js'
import { diagramFlow, moduleNames, unnamedModuleIds } from '../../utils/connectionLayout.js'
import { ensureObjectType } from '../../utils/objectTypes.js'

export default {
	name: 'ConnectionsDiagram',

	components: {
		CnGraphCanvas,
		NcEmptyContent,
		NcLoadingIcon,
	},

	props: {
		/** The connections the list shows, after its filters and search. */
		rows: {
			type: Array,
			default: () => [],
		},

		/** Whether the list is loading. */
		loading: {
			type: Boolean,
			default: false,
		},

		/** The row id field. */
		rowKey: {
			type: String,
			default: 'id',
		},
	},

	emits: ['rowClick'],

	data() {
		return {
			/** Application names by id, for ends the rows carry as a bare uuid. */
			names: {},
		}
	},

	computed: {
		/**
		 * The nodes and edges in the canvas's shape.
		 *
		 * @return {{nodes: Array<object>, edges: Array<object>}}
		 * @spec openspec/changes/archive/2026-11-25-connections-diagram-and-graph-export/specs/connection-diagram-and-export/spec.md#requirement-req-cdx-002-the-connections-list-can-be-shown-as-a-diagram-of-the-filtered-rows
		 */
		flow() {
			return diagramFlow(this.rows, this.names)
		},
	},

	watch: {
		rows: {
			handler: 'loadNames',
			immediate: true,
		},
	},

	methods: {
		t,

		/**
		 * Read the names of the applications the rows name only by uuid.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-11-25-connections-diagram-and-graph-export/specs/connection-diagram-and-export/spec.md#requirement-req-cdx-002-the-connections-list-can-be-shown-as-a-diagram-of-the-filtered-rows
		 */
		async loadNames() {
			const missing = unnamedModuleIds(this.rows, this.names)
			if (missing.length === 0) {
				return
			}
			try {
				await ensureObjectType('module')
				const modules = await objectStore.fetchCollectionForOptions('module', { _ids: missing, _limit: missing.length })
				this.names = { ...this.names, ...moduleNames(modules) }
			} catch {
				// The diagram still draws, with the ids as names.
			}
		},


		/**
		 * Open the connection a line stands for.
		 *
		 * @param {object} event The canvas's edge event, with `edge.id`.
		 * @spec openspec/changes/archive/2026-11-25-connections-diagram-and-graph-export/specs/connection-diagram-and-export/spec.md#requirement-req-cdx-002-the-connections-list-can-be-shown-as-a-diagram-of-the-filtered-rows
		 */
		onEdgeSelect(event) {
			const id = String(event?.edge?.id ?? event?.id ?? '')
			const row = this.rows.find((candidate) => String(candidate?.[this.rowKey] ?? candidate?.id ?? '') === id)
			if (row) {
				this.$emit('rowClick', row)
			}
		},
	},
}
</script>

<style scoped>
.connections-diagram {
	min-height: 480px;
	height: 60vh;
	padding: calc(var(--default-grid-baseline) * 2);
}

.connections-diagram__canvas {
	width: 100%;
	height: 100%;
}

.connections-diagram__node--provision {
	font-style: italic;
}
</style>
