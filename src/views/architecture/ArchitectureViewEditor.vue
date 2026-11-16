<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<template>
	<div class="view-editor" data-testid="architecture-view-editor">
		<NcLoadingIcon v-if="loading" :size="32" :name="t('stackiq', 'Loading the view')" />
		<NcEmptyContent
			v-else-if="loadError"
			:name="t('stackiq', 'The view could not be loaded.')"
			:description="loadError" />

		<form v-else-if="isNew" class="view-editor__new" @submit.prevent="create">
			<h2>{{ t('stackiq', 'New view') }}</h2>
			<NcTextField
				:modelValue="newName"
				:label="t('stackiq', 'Name')"
				@update:modelValue="newName = $event" />
			<NcButton type="submit" variant="primary" :disabled="!newName.trim() || saving">
				{{ t('stackiq', 'Create view') }}
			</NcButton>
		</form>

		<template v-else>
			<header class="view-editor__header">
				<div>
					<h2 class="view-editor__title">
						{{ view.name }}
					</h2>
					<p class="view-editor__meta">
						{{ originLabel }} · {{ statusLabel(view.status) }}
						<template v-if="view.basedOn">
							· {{ t('stackiq', 'Based on another view') }}
						</template>
					</p>
				</div>
				<div class="view-editor__actions">
					<NcButton v-if="imported" variant="primary" :disabled="saving" @click="copyToEdit">
						{{ t('stackiq', 'Copy to edit') }}
					</NcButton>
					<template v-else-if="editing">
						<NcButton variant="primary" :disabled="saving || !dirty" @click="save">
							{{ t('stackiq', 'Save') }}
						</NcButton>
					</template>
					<NcButton v-if="!imported" :to="{ name: 'ViewCompare', params: { id: viewId } }">
						{{ t('stackiq', 'Compare versions') }}
					</NcButton>
				</div>
			</header>

			<NcNoteCard v-if="imported" type="info">
				{{ t('stackiq', 'This view comes from the GEMMA import and is read-only, because the next import would overwrite any change. Copy it to edit your own version.') }}
			</NcNoteCard>
			<NcNoteCard v-else-if="lockedByOther" type="warning" data-testid="view-editor-lock-notice">
				{{ t('stackiq', '{user} is editing this view. It is read-only until they are done.', { user: lockHolder }) }}
			</NcNoteCard>
			<NcNoteCard v-if="message" :type="message.type">
				{{ message.text }}
			</NcNoteCard>

			<fieldset v-if="imported" class="view-editor__usage" data-testid="view-editor-usage-control">
				<legend class="view-editor__usage-title">
					{{ t('stackiq', 'Show applications') }}
				</legend>
				<NcCheckboxRadioSwitch
					type="switch"
					:modelValue="showOwn"
					:disabled="usageLoading"
					@update:modelValue="toggleUsage('own', $event)">
					{{ t('stackiq', 'Our applications') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					type="switch"
					:modelValue="showShared"
					:disabled="usageLoading"
					@update:modelValue="toggleUsage('shared', $event)">
					{{ t('stackiq', 'Shared with partners') }}
				</NcCheckboxRadioSwitch>
			</fieldset>

			<section v-if="editing" class="view-editor__palette" :aria-label="t('stackiq', 'Add to the view')">
				<NcSelect
					v-model="existingElement"
					class="view-editor__field"
					:inputLabel="t('stackiq', 'Place an existing element')"
					:options="elementOptions"
					label="label"
					:loading="searching"
					:filterable="false"
					@search="searchElements" />
				<NcButton :disabled="!existingElement" @click="placeExisting">
					{{ t('stackiq', 'Place') }}
				</NcButton>
				<NcSelect
					v-model="newElementType"
					class="view-editor__field"
					:inputLabel="t('stackiq', 'New element type')"
					:options="elementTypeOptions"
					label="label"
					:clearable="false" />
				<NcTextField
					class="view-editor__field"
					:modelValue="newElementName"
					:label="t('stackiq', 'New element name')"
					@update:modelValue="newElementName = $event" />
				<NcButton :disabled="!newElementName.trim()" @click="placeNew">
					{{ t('stackiq', 'Add element') }}
				</NcButton>
				<NcSelect
					v-model="relationType"
					class="view-editor__field"
					:inputLabel="t('stackiq', 'Relation type for new connections')"
					:options="relationTypes"
					:clearable="false" />
			</section>

			<CnGraphCanvas
				class="view-editor__canvas"
				:nodes="displayNodes"
				:edges="canvas.edges"
				:readOnly="!editing"
				:showMiniMap="false"
				@nodesChange="onNodesChange"
				@edgesChange="onEdgesChange"
				@nodeRemove="onNodeRemove"
				@connect="onConnect"
				@nodeSelect="onNodeSelect">
				<template #node="{ node }">
					<div
						v-if="node.data.usageId || node.data.more"
						class="view-editor__usage-node"
						:class="{ 'view-editor__usage-node--shared': node.data.shared, 'view-editor__usage-node--more': node.data.more }"
						:aria-label="node.data.ariaLabel"
						:title="node.data.ariaLabel">
						<span class="view-editor__usage-name">{{ node.data.label }}</span>
						<span v-if="node.data.badge" class="view-editor__usage-badge">{{ node.data.badge }}</span>
					</div>
					<div v-else class="view-editor__node" :class="`view-editor__node--${layerOf(node.data.type)}`">
						<strong>{{ node.data.name || t('stackiq', 'Unnamed element') }}</strong>
						<span>{{ node.data.type }}</span>
						<span v-if="!node.data.elementRef" class="view-editor__node-new">{{ t('stackiq', 'New, created on save') }}</span>
					</div>
				</template>
			</CnGraphCanvas>

			<UsageOverlayLegend v-if="imported && (showOwn || showShared)" :own="showOwn" :shared="showShared" />

			<section v-if="!imported && lockedByMe" class="view-editor__version">
				<NcTextField
					class="view-editor__field"
					:modelValue="versionLabel"
					:label="t('stackiq', 'Version label')"
					@update:modelValue="versionLabel = $event" />
				<NcButton :disabled="saving || dirty || !versionLabel.trim()" @click="saveVersion">
					{{ t('stackiq', 'Save version') }}
				</NcButton>
				<p v-if="dirty" class="view-editor__hint">
					{{ t('stackiq', 'Save the view first, so the version holds what you see.') }}
				</p>
			</section>
		</template>
	</div>
</template>

<script>
import { CnGraphCanvas, LockConflictError, useObjectLock } from '@conduction/nextcloud-vue'
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcCheckboxRadioSwitch, NcEmptyContent, NcLoadingIcon, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import { computed, reactive, ref } from 'vue'
import UsageOverlayLegend from '../../components/architecture/UsageOverlayLegend.vue'
import { createArchitectureViewStore, ELEMENT_TYPES, isImported, RELATION_TYPES, uuidOf } from '../../store/modules/architectureView.js'
import { useViewStore } from '../../store/modules/view.js'
import { usageOverlayNodes } from '../../utils/usageOverlay.js'
import { applyEdgeChanges, applyNodeChanges, connect, layerOf, placeElement, removeNode } from '../../utils/viewEditorCanvas.js'
import { toCanvas } from '../../utils/viewGraph.js'

/**
 * Draws and edits one architecture view on the shared graph canvas.
 *
 * An imported GEMMA view opens read-only and offers Copy to edit; a drawn view
 * takes OpenRegister's object lock before it can be edited, and shows who holds
 * the lock otherwise. An imported view can show the organisation's own
 * applications and those shared by partners inside its components
 * (architecture-shared-usage-on-views). Saving writes new elements, then the relations behind new
 * connections, then the view, in the shape the GEMMA import stores.
 *
 * @spec openspec/changes/architecture-views-editor/specs/architecture-views-editor/spec.md#requirement-req-ave-001-an-application-owner-shall-draw-and-save-an-architecture-view
 */
export default {
	name: 'ArchitectureViewEditor',

	components: {
		CnGraphCanvas,
		NcButton,
		NcCheckboxRadioSwitch,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
		UsageOverlayLegend,
	},

	props: {
		/** The AMEF register (resolved from `@resolve:amef_register`). */
		register: {
			type: [String, Number],
			required: true,
		},
	},

	setup(props) {
		const viewId = ref('')
		const lockStore = reactive({
			objects: { view: {} },
			fetchObject: async () => {},
		})
		const lock = useObjectLock(lockStore, computed(() => props.register), 'view', viewId, { schemaSlug: 'view' })
		return { viewId, lockStore, lock }
	},

	data() {
		return {
			loading: true,
			loadError: '',
			saving: false,
			message: null,
			view: null,
			canvas: { nodes: [], edges: [] },
			dirty: false,
			newName: '',
			existingElement: null,
			elementOptions: [],
			searching: false,
			newElementType: null,
			newElementName: '',
			relationType: 'Association',
			versionLabel: '',
			lockConflict: null,
			showOwn: false,
			showShared: false,
			usageLoading: false,
			usageByNode: {},
		}
	},

	computed: {
		/** @return {object} The store over the AMEF register. */
		api() {
			return createArchitectureViewStore({ http: axios, register: this.register, user: getCurrentUser()?.uid ?? '' })
		},

		/** @return {boolean} Whether the route asks for a new view. */
		isNew() {
			return this.$route?.params?.id === 'new'
		},

		/** @return {boolean} Whether the view is GEMMA content. */
		imported() {
			return isImported(this.view)
		},

		/** @return {boolean} Whether this user holds the lock. */
		lockedByMe() {
			return this.lock.lockedByMe.value === true
		},

		/** @return {boolean} Whether a colleague holds the lock. */
		lockedByOther() {
			return this.lockConflict !== null || (this.lock.locked.value === true && !this.lockedByMe)
		},

		/** @return {string} Who holds the lock. */
		lockHolder() {
			return this.lockConflict || this.lock.lockedBy.value || t('stackiq', 'A colleague')
		},

		/** @return {boolean} Whether the canvas takes edits. */
		editing() {
			return !this.imported && this.lockedByMe
		},

		/** @return {string} The origin, in words. */
		originLabel() {
			return this.imported ? t('stackiq', 'Imported') : t('stackiq', 'Drawn')
		},

		/** @return {Array<{id: string, label: string}>} The palette's element types. */
		elementTypeOptions() {
			return ELEMENT_TYPES.map(({ type }) => ({ id: type, label: type }))
		},

		/**
		 * The canvas nodes, with the applications that fill each component
		 * nested inside it when a switch is on. Only an imported view shows them.
		 *
		 * @return {Array<object>} The nodes CnGraphCanvas draws.
		 * @spec openspec/changes/architecture-shared-usage-on-views/specs/shared-usage-on-views/spec.md#requirement-req-suv-001-the-gemma-view-page-must-let-a-user-show-own-and-shared-applications-on-the-view
		 */
		displayNodes() {
			if (!this.imported || (!this.showOwn && !this.showShared)) {
				return this.canvas.nodes
			}
			const show = { own: this.showOwn, shared: this.showShared }
			const overlay = this.canvas.nodes.flatMap((node) => {
				const usage = this.usageByNode[node.id]
				if (!usage) return []
				return usageOverlayNodes({ id: node.id, ...usage }, show, { width: node.data?.width ?? 0, height: node.data?.height ?? 0, t })
			})
			return [...this.canvas.nodes, ...overlay]
		},

		/** @return {string[]} The relation types. */
		relationTypes() {
			return [...RELATION_TYPES]
		},
	},

	watch: {
		'$route.params.id': function() {
			this.load()
		},
	},

	mounted() {
		this.newElementType = this.elementTypeOptions.find((option) => option.id === 'ApplicationComponent')
		this.load()
	},

	beforeUnmount() {
		if (this.lockedByMe) {
			this.lock.release().catch(() => {})
		}
	},

	methods: {
		t,
		layerOf,

		/**
		 * Load the view, then take the lock when it is a drawn view.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.loadError = ''
			this.message = null
			this.lockConflict = null
			this.showOwn = false
			this.showShared = false
			this.usageByNode = {}
			try {
				if (this.isNew) {
					this.view = null
					return
				}
				this.viewId = String(this.$route.params.id)
				const view = await this.api.loadView(this.viewId)
				this.show(view)
				if (!isImported(view)) {
					await this.takeLock()
				}
			} catch (error) {
				this.loadError = error?.response?.status === 404
					? t('stackiq', 'This view does not exist, or you may not read it.')
					: t('stackiq', 'Try again in a moment.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Put a view on the canvas.
		 *
		 * @param {object} view The view.
		 * @return {void}
		 */
		show(view) {
			this.view = view
			this.lockStore.objects.view[uuidOf(view)] = view
			this.lockStore.fetchObject = async () => {
				const fresh = await this.api.loadView(uuidOf(view))
				this.lockStore.objects.view[uuidOf(view)] = fresh
			}
			const xml = view.xml ?? {}
			this.canvas = toCanvas(xml.viewNodes ?? view.nodes ?? [], xml.viewRelationships ?? view.connections ?? [])
			this.dirty = false
		},

		/**
		 * Take OpenRegister's object lock; a colleague's lock leaves the view read-only.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/architecture-views-editor/specs/architecture-views-editor/spec.md#requirement-req-ave-006-drawn-views-shall-stay-inside-the-organisation-that-drew-them
		 */
		async takeLock() {
			try {
				await this.lock.acquire()
			} catch (error) {
				if (error instanceof LockConflictError || error?.name === 'LockConflictError') {
					this.lockConflict = error.lockedBy || t('stackiq', 'A colleague')
					return
				}
				this.message = { type: 'warning', text: t('stackiq', 'The view could not be locked for editing, so it opens read-only.') }
			}
		},

		/**
		 * Create a new drawn view and open it.
		 *
		 * @return {Promise<void>}
		 */
		async create() {
			this.saving = true
			try {
				const view = await this.api.createView({ name: this.newName.trim() })
				this.$router.push({ name: 'ViewEditor', params: { id: uuidOf(view) } })
			} catch {
				this.message = { type: 'error', text: t('stackiq', 'The view could not be created.') }
			} finally {
				this.saving = false
			}
		},

		/**
		 * Copy to edit: a drawn copy that opens in edit mode.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/architecture-views-editor/specs/architecture-views-editor/spec.md#requirement-req-ave-002-imported-gemma-views-shall-open-read-only-and-shall-be-copied-before-editing
		 */
		async copyToEdit() {
			this.saving = true
			try {
				const copy = await this.api.copyToEdit(this.view, t('stackiq', '{name} (copy)', { name: this.view.name ?? '' }))
				this.$router.push({ name: 'ViewEditor', params: { id: uuidOf(copy) } })
			} catch {
				this.message = { type: 'error', text: t('stackiq', 'The copy could not be made.') }
			} finally {
				this.saving = false
			}
		},

		/**
		 * Save the canvas. A failure keeps the canvas as it is, with what was
		 * already written remembered for the next try.
		 *
		 * @return {Promise<void>}
		 */
		async save() {
			this.saving = true
			this.message = null
			try {
				const saved = await this.api.saveView(this.view, this.canvas)
				this.view = saved
				this.lockStore.objects.view[uuidOf(saved)] = { ...saved, '@self': { ...(saved['@self'] ?? {}), locked: this.lockStore.objects.view[uuidOf(saved)]?.['@self']?.locked } }
				this.dirty = false
				this.message = { type: 'success', text: t('stackiq', 'The view is saved.') }
			} catch {
				this.message = { type: 'error', text: t('stackiq', 'The view could not be saved. Your drawing is kept; save again to retry.') }
			} finally {
				this.saving = false
			}
		},

		/**
		 * Save version: a numbered snapshot of the saved view.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/architecture-views-editor/specs/architecture-views-editor/spec.md#requirement-req-ave-005-an-owner-shall-save-named-versions-and-compare-two-of-them
		 */
		async saveVersion() {
			this.saving = true
			try {
				const version = await this.api.saveVersion(this.view, this.versionLabel.trim())
				this.versionLabel = ''
				this.message = { type: 'success', text: t('stackiq', 'Version {number} is saved.', { number: version.versionNumber }) }
			} catch {
				this.message = { type: 'error', text: t('stackiq', 'The version could not be saved.') }
			} finally {
				this.saving = false
			}
		},

		/**
		 * Search existing elements to place.
		 *
		 * @param {string} term The search text.
		 * @return {Promise<void>}
		 */
		async searchElements(term) {
			if (!term || term.length < 2) {
				this.elementOptions = []
				return
			}
			this.searching = true
			try {
				const found = await this.api.searchElements(term)
				this.elementOptions = found
					.filter((element) => element.identifier)
					.map((element) => ({ id: element.identifier, label: `${element.name ?? element.identifier} (${element.type ?? ''})`, name: element.name ?? '', type: element.type ?? '' }))
			} catch {
				this.elementOptions = []
			} finally {
				this.searching = false
			}
		},

		/** @return {void} */
		placeExisting() {
			const element = this.existingElement
			this.canvas = { ...this.canvas, nodes: placeElement(this.canvas.nodes, { name: element.name, type: element.type, elementRef: element.id }) }
			this.existingElement = null
			this.dirty = true
		},

		/** @return {void} */
		placeNew() {
			this.canvas = { ...this.canvas, nodes: placeElement(this.canvas.nodes, { name: this.newElementName.trim(), type: this.newElementType.id, elementRef: null }) }
			this.newElementName = ''
			this.dirty = true
		},

		/**
		 * @param {Array<object>} changes Vue Flow's node changes.
		 * @return {void}
		 */
		onNodesChange(changes) {
			if (!this.editing) return
			const next = applyNodeChanges(this.canvas, changes)
			if (next.changed) {
				this.canvas = { nodes: next.nodes, edges: next.edges }
				this.dirty = true
			}
		},

		/**
		 * @param {Array<object>} changes Vue Flow's edge changes.
		 * @return {void}
		 */
		onEdgesChange(changes) {
			if (!this.editing) return
			const edges = applyEdgeChanges(this.canvas.edges, changes)
			if (edges !== this.canvas.edges) {
				this.canvas = { ...this.canvas, edges }
				this.dirty = true
			}
		},

		/**
		 * @param {string} id The node to remove.
		 * @return {void}
		 */
		onNodeRemove(id) {
			if (!this.editing) return
			this.canvas = removeNode(this.canvas, id)
			this.dirty = true
		},

		/**
		 * @param {{source: string, target: string}} connection The new connection.
		 * @return {void}
		 */
		onConnect(connection) {
			if (!this.editing) return
			const edges = connect(this.canvas.edges, connection, this.relationType)
			if (edges !== this.canvas.edges) {
				this.canvas = { ...this.canvas, edges }
				this.dirty = true
			}
		},

		/**
		 * Switch own or shared applications on or off, and reload the view's
		 * usage with exactly the switches that are on.
		 *
		 * @param {'own'|'shared'} kind Which switch.
		 * @param {boolean} value Its new state.
		 * @return {Promise<void>}
		 * @spec openspec/changes/architecture-shared-usage-on-views/specs/shared-usage-on-views/spec.md#requirement-req-suv-001-the-gemma-view-page-must-let-a-user-show-own-and-shared-applications-on-the-view
		 */
		async toggleUsage(kind, value) {
			if (kind === 'own') {
				this.showOwn = value === true
			} else {
				this.showShared = value === true
			}
			if (!this.showOwn && !this.showShared) {
				this.usageByNode = {}
				return
			}
			this.usageLoading = true
			try {
				const store = useViewStore()
				store.setIncludeGebruik(this.showOwn)
				store.setIncludeDeelnamesGebruik(this.showShared)
				await store.fetchView(this.viewId)
				if (store.error) {
					this.message = { type: 'error', text: t('stackiq', 'The applications on this view could not be loaded.') }
					return
				}
				this.usageByNode = this.usageIndex(store.activeView)
			} finally {
				this.usageLoading = false
			}
		},

		/**
		 * The enriched view's usage per view node id.
		 *
		 * @param {object|null} view The enriched view.
		 * @return {{[nodeId: string]: {usage: Array, deelnamesGebruik: Array}}} The usage by node.
		 */
		usageIndex(view) {
			const index = {}
			for (const node of view?.viewNodes ?? []) {
				const usage = Array.isArray(node.usage) ? node.usage : []
				const deelnamesGebruik = Array.isArray(node.deelnamesGebruik) ? node.deelnamesGebruik : []
				if (usage.length > 0 || deelnamesGebruik.length > 0) {
					index[String(node.viewNodeId ?? node.identifier)] = { usage, deelnamesGebruik }
				}
			}
			return index
		},

		/**
		 * A click on an application inside a component opens its usage.
		 *
		 * @param {{node: object}} event The canvas's node event.
		 * @return {void}
		 */
		onNodeSelect(event) {
			const usageId = event?.node?.data?.usageId
			if (usageId) {
				this.$router.push({ name: 'GebruikDetail', params: { id: usageId } })
			}
		},

		/**
		 * @param {string} status The status value.
		 * @return {string}
		 */
		statusLabel(status) {
			return {
				draft: t('stackiq', 'Draft'),
				'in review': t('stackiq', 'In review'),
				published: t('stackiq', 'Published'),
				retired: t('stackiq', 'Retired'),
			}[status] || t('stackiq', 'No status')
		},
	},
}
</script>

<style scoped>
.view-editor {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 4);
	height: 100%;
}

.view-editor__header {
	display: flex;
	flex-wrap: wrap;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 2);
}

.view-editor__title {
	margin: 0;
}

.view-editor__meta,
.view-editor__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.view-editor__actions,
.view-editor__palette,
.view-editor__version,
.view-editor__new {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: calc(var(--default-grid-baseline) * 2);
}

.view-editor__new {
	flex-direction: column;
	align-items: flex-start;
	max-width: 480px;
}

.view-editor__field {
	min-width: 220px;
}

.view-editor__canvas {
	flex: 1 1 auto;
	min-height: 480px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.view-editor__node {
	display: flex;
	flex-direction: column;
	gap: 2px;
	height: 100%;
	padding: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 2);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border-dark);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	box-shadow: inset 4px 0 0 0 var(--color-border-dark);
}

.view-editor__node--business {
	box-shadow: inset 4px 0 0 0 var(--color-warning);
}

.view-editor__node--application {
	box-shadow: inset 4px 0 0 0 var(--color-primary-element);
}

.view-editor__node--technology {
	box-shadow: inset 4px 0 0 0 var(--color-success);
}

.view-editor__usage {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 4);
	margin: 0;
	padding: 0;
	border: 0;
}

.view-editor__usage-title {
	font-weight: bold;
}

.view-editor__usage-node {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--default-grid-baseline);
	height: 100%;
	padding: 0 var(--default-grid-baseline);
	border: 2px solid var(--color-primary-element);
	border-radius: var(--border-radius);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.85em;
	cursor: pointer;
}

.view-editor__usage-node--shared {
	border-style: dashed;
	border-color: var(--color-text-maxcontrast);
}

.view-editor__usage-node--more {
	border-style: dotted;
	border-color: var(--color-border-dark);
	cursor: default;
}

.view-editor__usage-name {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.view-editor__usage-badge {
	flex: 0 0 auto;
	padding: 0 var(--default-grid-baseline);
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
}

.view-editor__node-new {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
