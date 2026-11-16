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
					<NcActions
						:menuName="t('stackiq', 'Export')"
						:forceName="true"
						data-testid="view-export-menu">
						<template v-for="action in exportActions" :key="action.id">
							<NcActionLink
								v-if="action.id === 'svg'"
								:href="imageUrl"
								:download="imageFileName"
								data-testid="view-export-svg">
								{{ action.label }}
							</NcActionLink>
							<NcActionButton
								v-else
								:disabled="action.disabled || exporting"
								:description="action.reason"
								:data-testid="`view-export-${action.id}`"
								@click="exportDocument(action.id)">
								{{ action.label }}
							</NcActionButton>
						</template>
					</NcActions>
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
				:nodes="canvas.nodes"
				:edges="canvas.edges"
				:readOnly="!editing"
				:showMiniMap="false"
				@nodesChange="onNodesChange"
				@edgesChange="onEdgesChange"
				@nodeRemove="onNodeRemove"
				@connect="onConnect">
				<template #node="{ node }">
					<div class="view-editor__node" :class="`view-editor__node--${layerOf(node.data.type)}`">
						<strong>{{ node.data.name || t('stackiq', 'Unnamed element') }}</strong>
						<span>{{ node.data.type }}</span>
						<span v-if="!node.data.elementRef" class="view-editor__node-new">{{ t('stackiq', 'New, created on save') }}</span>
					</div>
				</template>
			</CnGraphCanvas>

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
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { NcActionButton, NcActionLink, NcActions, NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import { computed, reactive, ref } from 'vue'
import { createViewDocument, documentExportAvailable, documentSavedMessage, filesLink, openInFilesLabel, viewExportActions, viewImageUrl } from '../../services/viewExport.js'
import { createArchitectureViewStore, ELEMENT_TYPES, isImported, RELATION_TYPES, uuidOf } from '../../store/modules/architectureView.js'
import { applyEdgeChanges, applyNodeChanges, connect, layerOf, placeElement, removeNode } from '../../utils/viewEditorCanvas.js'
import { toCanvas } from '../../utils/viewGraph.js'

/**
 * Draws and edits one architecture view on the shared graph canvas.
 *
 * An imported GEMMA view opens read-only and offers Copy to edit; a drawn view
 * takes OpenRegister's object lock before it can be edited, and shows who holds
 * the lock otherwise. Saving writes new elements, then the relations behind new
 * connections, then the view, in the shape the GEMMA import stores.
 *
 * @spec openspec/changes/architecture-views-editor/specs/architecture-views-editor/spec.md#requirement-req-ave-001-an-application-owner-shall-draw-and-save-an-architecture-view
 */
export default {
	name: 'ArchitectureViewEditor',

	components: {
		CnGraphCanvas,
		NcActionButton,
		NcActionLink,
		NcActions,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
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
			exporting: false,
			documentExport: documentExportAvailable(),
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
		 * @return {Array<{id: string, label: string, disabled: boolean, reason: string}>} The Export menu's actions.
		 * @spec openspec/changes/architecture-views-to-office-documents/specs/architecture-view-office-export/spec.md#requirement-req-avo-003-the-document-actions-shall-say-why-they-are-unavailable-when-filinq-cannot-take-the-request
		 */
		exportActions() {
			return viewExportActions(this.documentExport)
		},

		/** @return {string} The SVG image route of this view. */
		imageUrl() {
			return viewImageUrl(uuidOf(this.view) || this.viewId)
		},

		/** @return {string} The suggested file name of the SVG download. */
		imageFileName() {
			return `${(this.view?.name || 'view').replace(/[\\/:*?"<>|]+/g, '-')}.svg`
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
		 * Ask filinq for a Word document or a PowerPoint slide of this view and
		 * say where it was filed. A 503 means filinq went away: the document
		 * actions are disabled from then on.
		 *
		 * @param {string} action `word` or `powerpoint`.
		 * @return {Promise<void>}
		 * @spec openspec/changes/architecture-views-to-office-documents/specs/architecture-view-office-export/spec.md#requirement-req-avo-002-stackiq-shall-ask-filinq-for-a-word-document-or-a-powerpoint-slide-that-holds-the-view
		 */
		async exportDocument(action) {
			this.exporting = true
			try {
				const result = await createViewDocument(uuidOf(this.view) || this.viewId, action)
				if (result.ok) {
					showSuccess(`${documentSavedMessage(result.name)} · ${openInFilesLabel()}`, {
						onClick: () => window.open(filesLink(result.fileId), '_blank', 'noopener'),
					})
					return
				}
				if (result.unavailable) {
					this.documentExport = false
				}
				showError(result.error)
			} finally {
				this.exporting = false
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

.view-editor__node-new {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
