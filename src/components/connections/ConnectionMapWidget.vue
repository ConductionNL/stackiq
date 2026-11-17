<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.
-->
<script>
import { CnIcon, CnRelationshipGraph, CnWidgetWrapper } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { NcActionButton, NcActions, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { objectStore } from '../../store/store.js'
import { connectionsCsv, downloadText, svgDocument } from '../../utils/connectionExport.js'
import { connectionLinks, connectionMap } from '../../utils/connectionMap.js'
import { ensureObjectType } from '../../utils/objectTypes.js'

/**
 * The connections of one application as a map, on its page: the application
 * in the centre, every application or national provision it has a readable
 * connection with around it. A node opens that application, a line opens the
 * connection, and the action menu downloads the drawing or the links.
 *
 * @spec openspec/changes/connections-diagram-and-graph-export/specs/connection-diagram-and-export/spec.md#requirement-req-cdx-001-the-application-page-draws-the-applications-connections-as-a-map
 */
export default {
	name: 'ConnectionMapWidget',

	components: {
		CnIcon,
		CnRelationshipGraph,
		CnWidgetWrapper,
		NcActionButton,
		NcActions,
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		/** The application (module) id. */
		objectId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			loading: true,
			error: false,
			connections: [],
		}
	},

	computed: {
		/**
		 * The nodes and edges of the map.
		 *
		 * @return {{nodes: Array<object>, edges: Array<object>}} The graph.
		 * @spec openspec/changes/connections-diagram-and-graph-export/specs/connection-diagram-and-export/spec.md#requirement-req-cdx-001-the-application-page-draws-the-applications-connections-as-a-map
		 */
		graph() {
			return connectionMap(this.objectId, t('stackiq', 'This application'), this.connections)
		},

		/**
		 * One link per connection, the keyboard path to each line on the map.
		 *
		 * @return {Array<{id: string, text: string}>} The links.
		 * @spec openspec/changes/connections-diagram-and-graph-export/specs/connection-diagram-and-export/spec.md#requirement-req-cdx-001-the-application-page-draws-the-applications-connections-as-a-map
		 */
		links() {
			return connectionLinks(this.graph)
		},
	},

	watch: {
		/**
		 * Reload when the page moves to another application.
		 *
		 * @return {void}
		 * @spec openspec/changes/connections-diagram-and-graph-export/specs/connection-diagram-and-export/spec.md#requirement-req-cdx-001-the-application-page-draws-the-applications-connections-as-a-map
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
		 * Load the connections that start or end at the application. The object
		 * store reads them as the user, so a connection the user may not read is
		 * not among them.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/connections-diagram-and-graph-export/specs/connection-diagram-and-export/spec.md#requirement-req-cdx-001-the-application-page-draws-the-applications-connections-as-a-map
		 */
		async load() {
			this.loading = true
			this.error = false
			try {
				ensureObjectType('connection')
				const [outgoing, incoming] = await Promise.all([
					objectStore.fetchCollectionForOptions('connection', { moduleA: this.objectId, _limit: 200 }),
					objectStore.fetchCollectionForOptions('connection', { moduleB: this.objectId, _limit: 200 }),
				])
				this.connections = [...(outgoing || []), ...(incoming || [])]
			} catch {
				this.error = true
				this.connections = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Open the application a node stands for; a national provision has no page.
		 *
		 * @param {object} node The clicked node.
		 * @return {void}
		 * @spec openspec/changes/connections-diagram-and-graph-export/specs/connection-diagram-and-export/spec.md#requirement-req-cdx-001-the-application-page-draws-the-applications-connections-as-a-map
		 */
		openNode(node) {
			if (node?.kind === 'application' && node.id !== this.objectId) {
				this.$router.push({ name: 'ModuleDetail', params: { id: String(node.id) } })
			}
		},

		/**
		 * Open the connection a line stands for.
		 *
		 * @param {MouseEvent} event The click on the map.
		 * @return {void}
		 * @spec openspec/changes/connections-diagram-and-graph-export/specs/connection-diagram-and-export/spec.md#requirement-req-cdx-001-the-application-page-draws-the-applications-connections-as-a-map
		 */
		openEdge(event) {
			const id = event?.target?.closest?.('[data-edge-id]')?.getAttribute('data-edge-id')
			if (id) {
				this.$router.push({ name: 'KoppelingDetail', params: { id: String(id) } })
			}
		},

		/**
		 * The drawn map.
		 *
		 * @return {SVGElement|null} The svg element.
		 * @spec openspec/changes/connections-diagram-and-graph-export/specs/connection-diagram-and-export/spec.md#requirement-req-cdx-001-the-application-page-draws-the-applications-connections-as-a-map
		 */
		drawing() {
			return this.$refs.map?.querySelector('svg') ?? null
		},

		/**
		 * Download the drawing as SVG.
		 *
		 * @return {void}
		 * @spec openspec/changes/connections-diagram-and-graph-export/specs/connection-diagram-and-export/spec.md#requirement-req-cdx-003-a-user-can-download-an-applications-map-and-its-links
		 */
		downloadSvg() {
			const svg = this.drawing()
			if (svg) {
				downloadText(svgDocument(svg), 'connection-map.svg', 'image/svg+xml')
			}
		},

		/**
		 * Download the drawing as PNG: the SVG drawn on a canvas.
		 *
		 * @return {void}
		 * @spec openspec/changes/connections-diagram-and-graph-export/specs/connection-diagram-and-export/spec.md#requirement-req-cdx-003-a-user-can-download-an-applications-map-and-its-links
		 */
		downloadPng() {
			const svg = this.drawing()
			if (!svg) {
				return
			}
			const box = svg.viewBox?.baseVal
			const width = box?.width || 400
			const height = box?.height || 400
			const image = new Image()
			image.onload = () => {
				const canvas = document.createElement('canvas')
				canvas.width = width * 2
				canvas.height = height * 2
				canvas.getContext('2d').drawImage(image, 0, 0, canvas.width, canvas.height)
				canvas.toBlob((blob) => {
					if (!blob) {
						return
					}
					const url = URL.createObjectURL(blob)
					const link = document.createElement('a')
					link.href = url
					link.download = 'connection-map.png'
					link.click()
					URL.revokeObjectURL(url)
				}, 'image/png')
			}
			image.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svgDocument(svg))
		},

		/**
		 * Download the links as CSV, one line per connection.
		 *
		 * @return {void}
		 * @spec openspec/changes/connections-diagram-and-graph-export/specs/connection-diagram-and-export/spec.md#requirement-req-cdx-003-a-user-can-download-an-applications-map-and-its-links
		 */
		downloadCsv() {
			downloadText(connectionsCsv(this.connections), 'connections.csv', 'text/csv;charset=utf-8')
		},
	},
}
</script>

<template>
	<CnWidgetWrapper
		:title="t('stackiq', 'Connection map')"
		titleIconPosition="left"
		:showRefresh="false"
		:showRequestFeature="false">
		<template #title-icon>
			<CnIcon name="TransitConnectionVariant" :size="20" />
		</template>
		<template #actions>
			<NcActions v-if="graph.edges.length > 0" :aria-label="t('stackiq', 'Download')">
				<NcActionButton @click="downloadSvg">
					{{ t('stackiq', 'Download as SVG') }}
				</NcActionButton>
				<NcActionButton @click="downloadPng">
					{{ t('stackiq', 'Download as PNG') }}
				</NcActionButton>
				<NcActionButton @click="downloadCsv">
					{{ t('stackiq', 'Download links as CSV') }}
				</NcActionButton>
			</NcActions>
		</template>

		<NcLoadingIcon v-if="loading" />
		<NcNoteCard v-else-if="error" type="error">
			{{ t('stackiq', 'The connections could not be loaded.') }}
		</NcNoteCard>
		<p v-else-if="graph.edges.length === 0" class="connection-map__empty">
			{{ t('stackiq', 'This application has no connections you can see.') }}
		</p>
		<div v-else ref="map" class="connection-map">
			<!-- A click on a drawn line opens its connection; the list below is
				the same path by keyboard, since a line cannot take focus. -->
			<CnRelationshipGraph
				:nodes="graph.nodes"
				:edges="graph.edges"
				layout="radial"
				:aria-label="t('stackiq', 'Connection map')"
				@click="openEdge"
				@nodeClick="openNode" />
			<ul class="connection-map__links" :aria-label="t('stackiq', 'Connections on the map')">
				<li v-for="link in links" :key="link.id">
					<router-link :to="{ name: 'KoppelingDetail', params: { id: String(link.id) } }">
						{{ link.text }}
					</router-link>
				</li>
			</ul>
		</div>
	</CnWidgetWrapper>
</template>

<style scoped>
.connection-map {
	max-width: 520px;
	margin: 0 auto;
}

.connection-map__links {
	margin: 8px 0 0;
	padding-inline-start: 20px;
}

.connection-map__empty {
	color: var(--color-text-maxcontrast);
}
</style>
