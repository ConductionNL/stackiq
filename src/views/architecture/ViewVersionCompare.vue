<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<template>
	<div class="view-compare" data-testid="view-version-compare">
		<NcLoadingIcon v-if="loading" :size="32" :name="t('stackiq', 'Loading the versions')" />
		<NcEmptyContent
			v-else-if="loadError"
			:name="t('stackiq', 'The versions could not be loaded.')"
			:description="loadError" />
		<template v-else>
			<header class="view-compare__header">
				<h2 class="view-compare__title">
					{{ t('stackiq', 'Compare versions of {name}', { name: view.name ?? '' }) }}
				</h2>
				<NcButton :to="{ name: 'ViewEditor', params: { id: viewId } }">
					{{ t('stackiq', 'Back to the view') }}
				</NcButton>
			</header>

			<NcEmptyContent
				v-if="versions.length === 0"
				:name="t('stackiq', 'No saved versions yet')"
				:description="t('stackiq', 'Use Save version in the editor to keep a version you can compare with later.')" />

			<template v-else>
				<div class="view-compare__pickers">
					<NcSelect
						v-model="left"
						class="view-compare__picker"
						:inputLabel="t('stackiq', 'Earlier')"
						:options="leftOptions"
						label="label"
						:clearable="false" />
					<NcSelect
						v-model="right"
						class="view-compare__picker"
						:inputLabel="t('stackiq', 'Later')"
						:options="rightOptions"
						label="label"
						:clearable="false" />
				</div>

				<p class="view-compare__legend">
					<span class="view-compare__swatch view-compare__swatch--added" /> {{ t('stackiq', 'Added') }}
					<span class="view-compare__swatch view-compare__swatch--removed" /> {{ t('stackiq', 'Removed') }}
					<span class="view-compare__swatch view-compare__swatch--changed" /> {{ t('stackiq', 'Changed') }}
				</p>

				<div class="view-compare__sides">
					<section v-for="side in sides" :key="side.key" class="view-compare__side" :aria-label="side.title">
						<h3>{{ side.title }}</h3>
						<CnGraphCanvas
							class="view-compare__canvas"
							:nodes="side.canvas.nodes"
							:edges="side.canvas.edges"
							:readOnly="true"
							:showMiniMap="false">
							<template #node="{ node }">
								<div class="view-compare__node" :class="node.data.status ? `view-compare__node--${node.data.status}` : ''">
									<strong>{{ node.data.name }}</strong>
									<span>{{ node.data.type }}</span>
									<span v-if="node.data.status" class="view-compare__status">{{ changeLabel(node.data.status) }}</span>
								</div>
							</template>
						</CnGraphCanvas>
					</section>
				</div>

				<section class="view-compare__summary" aria-live="polite">
					<h3>{{ t('stackiq', 'Changes') }}</h3>
					<p>{{ summaryLine }}</p>
					<ul v-if="entries.length">
						<li v-for="entry in entries" :key="`${entry.kind}-${entry.id}`">
							{{ entryLine(entry) }}
						</li>
					</ul>
				</section>
			</template>
		</template>
	</div>
</template>

<script>
import { CnGraphCanvas } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { n, translate as t } from '@nextcloud/l10n'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcSelect } from '@nextcloud/vue'
import { createArchitectureViewStore } from '../../store/modules/architectureView.js'
import { describeDiff, diffViews, statusesFor } from '../../utils/viewDiff.js'
import { toCanvas } from '../../utils/viewGraph.js'

/** The option id of the current view. */
const CURRENT = 'current'

/**
 * Compares two saved versions of a view, or a version and the current view,
 * on two read-only canvases with added, removed and changed items marked, and
 * the same changes as text for screen readers.
 *
 * @spec openspec/changes/architecture-views-editor/specs/architecture-views-editor/spec.md#requirement-req-ave-005-an-owner-shall-save-named-versions-and-compare-two-of-them
 */
export default {
	name: 'ViewVersionCompare',

	components: {
		CnGraphCanvas,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
	},

	props: {
		/** The AMEF register (resolved from `@resolve:amef_register`). */
		register: {
			type: [String, Number],
			required: true,
		},
	},

	data() {
		return {
			loading: true,
			loadError: '',
			view: null,
			versions: [],
			left: null,
			right: null,
		}
	},

	computed: {
		/** @return {object} The store over the AMEF register. */
		api() {
			return createArchitectureViewStore({ http: axios, register: this.register })
		},

		/** @return {string} The view uuid from the route. */
		viewId() {
			return String(this.$route?.params?.id ?? '')
		},

		/** @return {Array<{id: string, label: string, source: object}>} Every version, then the current view. */
		options() {
			const versions = this.versions.map((version) => ({
				id: String(version.versionNumber),
				label: version.label
					? t('stackiq', 'Version {number}: {label}', { number: version.versionNumber, label: version.label })
					: t('stackiq', 'Version {number}', { number: version.versionNumber }),
				source: version,
			}))
			return [...versions, { id: CURRENT, label: t('stackiq', 'Current view'), source: this.view }]
		},

		/** @return {Array<object>} The earlier side's choices: versions only. */
		leftOptions() {
			return this.options.filter((option) => option.id !== CURRENT)
		},

		/** @return {Array<object>} The later side's choices: versions and the current view. */
		rightOptions() {
			return this.options
		},

		/** @return {object|null} The diff of the two chosen sides. */
		diff() {
			if (!this.left || !this.right) return null
			return diffViews(this.api.snapshotOf(this.left.source), this.api.snapshotOf(this.right.source))
		},

		/** @return {Array<object>} Both sides as marked canvases. */
		sides() {
			if (!this.diff) return []
			return [
				{ key: 'before', title: this.left.label, canvas: this.marked(this.left.source, 'before') },
				{ key: 'after', title: this.right.label, canvas: this.marked(this.right.source, 'after') },
			]
		},

		/** @return {Array<object>} The changes as a flat list. */
		entries() {
			return this.diff ? describeDiff(this.diff) : []
		},

		/** @return {string} The counts, in words. */
		summaryLine() {
			if (!this.diff) return ''
			if (this.diff.unchanged) return t('stackiq', 'No added, removed or changed nodes or connections.')
			const { nodes } = this.diff
			return [
				n('stackiq', '%n added node', '%n added nodes', nodes.added.length),
				n('stackiq', '%n removed node', '%n removed nodes', nodes.removed.length),
				n('stackiq', '%n changed node', '%n changed nodes', nodes.changed.length),
			].join(', ')
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Load the view and its versions, and pick the last version against the current view.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.loadError = ''
			try {
				this.view = await this.api.loadView(this.viewId)
				this.versions = await this.api.listVersions(this.viewId)
				const versions = this.leftOptions
				this.left = versions.at(-1) ?? null
				this.right = this.options.at(-1)
			} catch {
				this.loadError = t('stackiq', 'Try again in a moment.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * One side as canvas nodes and edges, each carrying its change status.
		 *
		 * @param {object} source A view or a version.
		 * @param {'before'|'after'} side Which side.
		 * @return {{nodes: Array<object>, edges: Array<object>}}
		 */
		marked(source, side) {
			const snapshot = this.api.snapshotOf(source)
			const statuses = statusesFor(this.diff, side)
			const { nodes, edges } = toCanvas(snapshot.nodes, snapshot.connections)
			return {
				nodes: nodes.map((node) => ({ ...node, data: { ...node.data, status: statuses.nodes.get(node.id) ?? '' } })),
				edges: edges.map((edge) => {
					const status = statuses.connections.get(edge.id)
					return status ? { ...edge, class: `view-compare__edge--${status}`, label: this.changeLabel(status) } : edge
				}),
			}
		},

		/**
		 * @param {string} status `added`, `removed` or `changed`.
		 * @return {string}
		 */
		changeLabel(status) {
			return { added: t('stackiq', 'Added'), removed: t('stackiq', 'Removed'), changed: t('stackiq', 'Changed') }[status] || ''
		},

		/**
		 * One change as a sentence.
		 *
		 * @param {{kind: string, change: string, name: string, fields: string[]}} entry The change.
		 * @return {string}
		 */
		entryLine(entry) {
			const params = { name: entry.name, fields: entry.fields.join(', ') }
			if (entry.kind === 'node') {
				return {
					added: t('stackiq', 'Node {name} added', params),
					removed: t('stackiq', 'Node {name} removed', params),
					changed: t('stackiq', 'Node {name} changed: {fields}', params),
				}[entry.change]
			}
			return {
				added: t('stackiq', 'Connection {name} added', params),
				removed: t('stackiq', 'Connection {name} removed', params),
				changed: t('stackiq', 'Connection {name} changed: {fields}', params),
			}[entry.change]
		},
	},
}
</script>

<style scoped>
.view-compare {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 4);
}

.view-compare__header,
.view-compare__pickers {
	display: flex;
	flex-wrap: wrap;
	justify-content: space-between;
	align-items: flex-end;
	gap: calc(var(--default-grid-baseline) * 2);
}

.view-compare__title {
	margin: 0;
}

.view-compare__picker {
	min-width: 260px;
}

.view-compare__legend {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
}

.view-compare__swatch {
	display: inline-block;
	width: 14px;
	height: 14px;
	border-radius: 3px;
	margin-inline-start: calc(var(--default-grid-baseline) * 2);
}

.view-compare__swatch--added,
.view-compare__node--added {
	border: 2px solid var(--color-success);
}

.view-compare__swatch--removed,
.view-compare__node--removed {
	border: 2px solid var(--color-error);
}

.view-compare__swatch--changed,
.view-compare__node--changed {
	border: 2px solid var(--color-warning);
}

.view-compare__sides {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
	gap: calc(var(--default-grid-baseline) * 3);
}

.view-compare__canvas {
	min-height: 420px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.view-compare__node {
	display: flex;
	flex-direction: column;
	gap: 2px;
	height: 100%;
	padding: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 2);
	border-radius: var(--border-radius);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
}

.view-compare__node:not([class*='--']) {
	border: 1px solid var(--color-border-dark);
}

.view-compare__status {
	font-weight: bold;
}

:deep(.view-compare__edge--added path) {
	stroke: var(--color-success);
}

:deep(.view-compare__edge--removed path) {
	stroke: var(--color-error);
}

:deep(.view-compare__edge--changed path) {
	stroke: var(--color-warning);
}
</style>
