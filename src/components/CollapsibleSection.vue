<!--
 - @copyright Copyright (c) 2023 Ruben Linde <info@conduction.nl>
 - @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 -
 - Licensed under the EUPL, Version 1.2 or – as soon they will be approved by
 - the European Commission – subsequent versions of the EUPL (the "Licence");
 - You may not use this work except in compliance with the Licence.
 - You may obtain a copy of the Licence at:
 -
 - https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 -
 - Unless required by applicable law or agreed to in writing, software
 - distributed under the Licence is distributed on an "AS IS" basis,
 - WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 - See the Licence for the specific language governing permissions and
 - limitations under the Licence.
 -->

<template>
	<NcSettingsSection :name="name" :description="description" :doc-url="docUrl">
		<div class="collapsible-section">
			<!-- Section Header with Controls -->
			<div class="section-header">
				<div class="section-info">
					<h3 class="section-title">
						{{ name }}
					</h3>
					<p v-if="description" class="section-description">
						{{ description }}
					</p>
				</div>

				<div class="section-controls">
					<!-- Save Button -->
					<NcButton
						v-if="showSaveButton && !loading"
						variant="primary"
						:disabled="saving || !canSave"
						@click="handleSave">
						<template #icon>
							<NcLoadingIcon v-if="saving" :size="20" />
							<Save v-else :size="20" />
						</template>
						{{ saveButtonText }}
					</NcButton>

					<!-- Refresh Button -->
					<NcButton
						v-if="showRefreshButton && !loading"
						variant="secondary"
						:disabled="refreshing"
						@click="handleRefresh">
						<template #icon>
							<NcLoadingIcon v-if="refreshing" :size="20" />
							<Refresh v-else :size="20" />
						</template>
						{{ refreshButtonText }}
					</NcButton>

					<!-- Info Button -->
					<NcButton
						v-if="hasInfoContent"
						variant="tertiary-no-background"
						:aria-label="'Show information about ' + name"
						@click="showInfoModal = true">
						<template #icon>
							<Information :size="20" />
						</template>
					</NcButton>

					<!-- Collapse Toggle -->
					<NcButton
						variant="tertiary-no-background"
						:aria-label="isExpanded ? 'Collapse section' : 'Expand section'"
						@click="toggleExpanded">
						<template #icon>
							<ChevronUp v-if="isExpanded" :size="20" />
							<ChevronDown v-else :size="20" />
						</template>
					</NcButton>
				</div>
			</div>

			<!-- Collapsible Content -->
			<div v-if="isExpanded" class="section-content">
				<div v-if="!loading">
					<slot />
				</div>

				<!-- Loading State -->
				<NcLoadingIcon
					v-else
					class="loading-icon"
					:size="64"
					appearance="dark" />
			</div>
		</div>

		<!-- Info Modal -->
		<NcModal
			v-if="showInfoModal"
			@close="showInfoModal = false">
			<div class="info-modal">
				<div class="modal-header">
					<h2>{{ name }} - Information</h2>
				</div>
				<div class="modal-content">
					<slot name="info-content">
						<p>No additional information available.</p>
					</slot>
				</div>
				<div class="modal-footer">
					<NcButton @click="showInfoModal = false">
						Close
					</NcButton>
				</div>
			</div>
		</NcModal>
	</NcSettingsSection>
</template>

<script>
/**
 * Collapsible Section Component
 *
 * A reusable component that provides collapsible functionality for settings sections
 * with integrated save/refresh buttons and info modals.
 *
 * @author Ruben Linde <info@conduction.nl>
 * @copyright 2023 Conduction B.V.
 * @license EUPL-1.2
 * @version 1.0.0
 */

import {
	NcSettingsSection,
	NcButton,
	NcLoadingIcon,
	NcModal,
} from '@nextcloud/vue'

// Icons
import Save from 'vue-material-design-icons/ContentSave.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Information from 'vue-material-design-icons/Information.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'

export default {
	name: 'CollapsibleSection',

	components: {
		NcSettingsSection,
		NcButton,
		NcLoadingIcon,
		NcModal,
		Save,
		Refresh,
		Information,
		ChevronUp,
		ChevronDown,
	},

	props: {
		/**
		 * Section name
		 */
		name: {
			type: String,
			required: true,
		},

		/**
		 * Section description
		 */
		description: {
			type: String,
			default: '',
		},

		/**
		 * Documentation URL
		 */
		docUrl: {
			type: String,
			default: '',
		},

		/**
		 * Whether the section is loading
		 */
		loading: {
			type: Boolean,
			default: false,
		},

		/**
		 * Whether to show the save button
		 */
		showSaveButton: {
			type: Boolean,
			default: false,
		},

		/**
		 * Whether to show the refresh button
		 */
		showRefreshButton: {
			type: Boolean,
			default: false,
		},

		/**
		 * Whether saving is in progress
		 */
		saving: {
			type: Boolean,
			default: false,
		},

		/**
		 * Whether refreshing is in progress
		 */
		refreshing: {
			type: Boolean,
			default: false,
		},

		/**
		 * Whether the save button should be enabled
		 */
		canSave: {
			type: Boolean,
			default: true,
		},

		/**
		 * Save button text
		 */
		saveButtonText: {
			type: String,
			default: 'Save',
		},

		/**
		 * Refresh button text
		 */
		refreshButtonText: {
			type: String,
			default: 'Refresh',
		},

		/**
		 * Whether the section should be expanded by default
		 */
		defaultExpanded: {
			type: Boolean,
			default: false,
		},

		/**
		 * Whether the section has info content
		 */
		hasInfoContent: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['save', 'refresh'],

	data() {
		return {
			isExpanded: this.defaultExpanded,
			showInfoModal: false,
		}
	},

	methods: {
		/**
		 * Toggle section expanded state
		  * @spec openspec/specs/fe-shell-navigation/spec.md
		 */
		toggleExpanded() {
			this.isExpanded = !this.isExpanded
		},

		/**
		 * Handle save button click
		  * @spec openspec/specs/fe-shell-navigation/spec.md
		 */
		handleSave() {
			this.$emit('save')
		},

		/**
		 * Handle refresh button click
		  * @spec openspec/specs/fe-shell-navigation/spec.md
		 */
		handleRefresh() {
			this.$emit('refresh')
		},
	},
}
</script>

<style scoped>
.collapsible-section {
	margin-bottom: 20px;
}

.section-header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	padding: 16px 0;
	border-bottom: 1px solid var(--color-border);
	margin-bottom: 16px;
}

.section-info {
	flex: 1;
	margin-right: 16px;
}

.section-title {
	margin: 0 0 8px 0;
	font-size: 18px;
	font-weight: 600;
	color: var(--color-main-text);
}

.section-description {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	line-height: 1.4;
}

.section-controls {
	display: flex;
	gap: 8px;
	align-items: center;
	flex-shrink: 0;
}

.section-content {
	animation: slideDown 0.2s ease-out;
}

@keyframes slideDown {
	from {
		opacity: 0;
		transform: translateY(-10px);
	}
	to {
		opacity: 1;
		transform: translateY(0);
	}
}

.loading-icon {
	display: flex;
	justify-content: center;
	padding: 40px 0;
}

/* Info Modal Styles */
.info-modal {
	padding: 20px;
	max-width: 600px;
	max-height: 80vh;
	overflow-y: auto;
}

.modal-header {
	margin-bottom: 16px;
	padding-bottom: 16px;
	border-bottom: 1px solid var(--color-border);
}

.modal-header h2 {
	margin: 0;
	font-size: 20px;
	font-weight: 600;
	color: var(--color-main-text);
}

.modal-content {
	margin-bottom: 20px;
	line-height: 1.6;
}

.modal-content :deep(h3) {
	margin-top: 20px;
	margin-bottom: 12px;
	font-size: 16px;
	font-weight: 600;
}

.modal-content :deep(h4) {
	margin-top: 16px;
	margin-bottom: 8px;
	font-size: 14px;
	font-weight: 600;
}

.modal-content :deep(ul) {
	padding-left: 20px;
	margin-bottom: 16px;
}

.modal-content :deep(li) {
	margin-bottom: 4px;
}

.modal-content :deep(p) {
	margin-bottom: 12px;
}

.modal-content :deep(code) {
	background-color: var(--color-background-dark);
	padding: 2px 6px;
	border-radius: 4px;
	font-family: monospace;
	font-size: 13px;
}

.modal-footer {
	display: flex;
	justify-content: flex-end;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

/* WCAG 2.3.3 — the expand animation is decorative; a reduced-motion user gets
   the expanded section immediately instead of the slide. */
@media (prefers-reduced-motion: reduce) {
	.section-content {
		animation: none;
	}
}
</style>
