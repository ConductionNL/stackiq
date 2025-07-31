/**
 * @file SelectedObjectsList.vue
 * @module Components
 * @author Your Name
 * @copyright 2024 Your Organization
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 */

<script setup>
import { objectStore } from '../store/store.js'
</script>

<template>
	<div class="selected-objects-container">
		<h4>{{ title }} ({{ selectedObjects.length }})</h4>

		<div v-if="selectedObjects.length" class="selected-objects-list">
			<TransitionGroup name="list" tag="div">
				<div v-for="obj in selectedObjects"
					:key="obj.id"
					class="selected-object-item"
					:class="{ 'has-error': getObjectError(obj) }">
					<div class="object-info">
						<strong>{{ getObjectName(obj) }}</strong>
						<p class="object-schema">
							{{ getObjectSchema(obj) }}
						</p>
						<p v-if="getObjectError(obj)" class="object-error">
							<AlertCircle :size="16" />
							{{ getObjectError(obj) }}
						</p>
					</div>
					<NcButton v-if="showRemove"
						type="tertiary"
						:aria-label="`Remove ${getObjectName(obj)}`"
						@click="removeObject(obj.id || obj['@self']?.id)">
						<template #icon>
							<Close :size="20" />
						</template>
					</NcButton>
				</div>
			</TransitionGroup>
		</div>

		<NcEmptyContent v-else :name="emptyTitle">
			<template #description>
				{{ emptyDescription }}
			</template>
		</NcEmptyContent>
	</div>
</template>

<script>
import {
	NcButton,
	NcEmptyContent,
} from '@nextcloud/vue'

import Close from 'vue-material-design-icons/Close.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'

export default {
	name: 'SelectedObjectsList',
	components: {
		NcButton,
		NcEmptyContent,
		Close,
		AlertCircle,
	},
	props: {
		/**
		 * Title for the selected objects section
		 */
		title: {
			type: String,
			default: 'Selected Publications',
		},
		/**
		 * Title to show when no objects are selected
		 */
		emptyTitle: {
			type: String,
			default: 'No publications selected',
		},
		/**
		 * Description to show when no objects are selected
		 */
		emptyDescription: {
			type: String,
			default: 'No publications are currently selected.',
		},
		/**
		 * Array of objects to display (optional, if not provided uses selected objects from store)
		 */
		objects: {
			type: Array,
			default: null,
		},
		/**
		 * Whether to show remove buttons
		 */
		showRemove: {
			type: Boolean,
			default: true,
		},
	},
	computed: {
		/**
		 * Get objects to display (either from props or from store)
		 * @return {Array<object>} Array of publication objects
		 */
		selectedObjects() {
			return this.objects || objectStore.selectedObjects || []
		},
	},
	methods: {
		/**
		 * Remove object from selected objects in the store
		 * @param {string} objectId - The object ID to remove
		 */
		removeObject(objectId) {
			// Always remove from store - the store is the source of truth
			const currentSelected = [...objectStore.selectedObjects]
			const index = currentSelected.findIndex(obj =>
				(obj.id || obj['@self']?.id) === objectId,
			)
			if (index > -1) {
				currentSelected.splice(index, 1)
				objectStore.setSelectedObjects(currentSelected)
			}
		},

		/**
		 * Get display name for an object
		 * @param {object} obj - The object to get name for
		 * @return {string} The display name
		 */
		getObjectName(obj) {
			return obj['@self']?.name
				|| obj.name
				|| obj.title
				|| obj['@self']?.title
				|| `Unnamed ${this.title.includes('Publication') ? 'Publication' : 'Object'}`
		},

		/**
		 * Get schema name for an object
		 * @param {object} obj - The object to get schema for
		 * @return {string} The schema name or fallback text
		 */
		getObjectSchema(obj) {
			// Try to get schema name from various possible locations
			const schema = obj['@self']?.schema || obj.schema

			if (typeof schema === 'object') {
				return schema.name || schema.title || schema.id || 'Unknown Schema'
			} else if (typeof schema === 'string') {
				return schema
			}

			return 'No Schema'
		},

		/**
		 * Get error message for an object
		 * @param {object} obj - The object to get error for
		 * @return {string|null} The error message or null if no error
		 */
		getObjectError(obj) {
			const objectId = obj.id || obj['@self']?.id
			return objectStore.getObjectError(objectId)
		},
	},
}
</script>

<style scoped>
.selected-objects-container {
	margin: 20px 0;
}

.selected-objects-list {
	max-height: 300px;
	overflow-y: auto;
	border: 1px solid var(--color-border);
	border-radius: 4px;
}

.selected-object-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 12px;
	border-bottom: 1px solid var(--color-border);
	background-color: var(--color-background-hover);
	transition: all 0.3s ease;
}

.selected-object-item:last-child {
	border-bottom: none;
}

.object-info strong {
	display: block;
	margin-bottom: 4px;
	color: var(--color-main-text);
}

.object-schema {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 0;
}

.object-error {
	color: var(--color-error);
	font-size: 0.85em;
	margin: 4px 0 0 0;
	display: flex;
	align-items: center;
	gap: 4px;
}

.selected-object-item.has-error {
	border-left: 3px solid var(--color-error);
	background-color: var(--color-background-dark);
}

/* Transition animations for list items */
.list-move,
.list-enter-active,
.list-leave-active {
	transition: all 0.3s ease;
}

.list-enter-from,
.list-leave-to {
	opacity: 0;
	transform: translateX(30px);
}

.list-leave-active {
	position: absolute;
	right: 0;
	left: 0;
}
</style>
