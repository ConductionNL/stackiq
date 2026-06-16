<!--
 - @copyright Copyright (c) 2023 Ruben Linde <info@conduction.nl>
 - @license AGPL-3.0-or-later
 -
 - This program is free software: you can redistribute it and/or modify
 - it under the terms of the GNU Affero General Public License as
 - published by the Free Software Foundation, either version 3 of the
 - License, or (at your option) any later version.
 -
 - This program is distributed in the hope that it will be useful,
 - but WITHOUT ANY WARRANTY; without even the implied warranty of
 - MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 - GNU Affero General Public License for more details.
 -
 - You should have received a copy of the GNU Affero General Public License
 - along with this program. If not, see <http://www.gnu.org/licenses/>.
 -->

<template>
	<NcSettingsSection :name="name" :description="description" :doc-url="docUrl">
		<!-- Header actions positioned at top-right of the section title area -->
		<div class="section-header-actions">
			<div class="header-buttons">
				<NcButton
					v-if="showSaveButton"
					type="primary"
					:disabled="loading || saving || !canSave"
					class="title-save-button"
					@click="handleSave">
					<template #icon>
						<NcLoadingIcon v-if="saving" :size="20" />
						<Save v-else :size="20" />
					</template>
					{{ saveButtonText }}
				</NcButton>

				<NcButton
					v-if="showRefreshButton"
					type="secondary"
					:disabled="loading || refreshing"
					class="title-refresh-button"
					@click="handleRefresh">
					<template #icon>
						<NcLoadingIcon v-if="refreshing" :size="20" />
						<Refresh v-else :size="20" />
					</template>
					{{ refreshButtonText }}
				</NcButton>

				<NcButton
					v-if="hasInfoContent"
					type="tertiary-no-background"
					:aria-label="'Show information about ' + name"
					@click="showInfoModal = true">
					<template #icon>
						<Information :size="20" />
					</template>
				</NcButton>

				<!-- Optional header actions from parent -->
				<slot name="header-actions" />
			</div>
		</div>

		<div class="always-visible-section">
			<!-- Always Visible Content -->
			<div class="section-content">
				<div v-if="!loading">
					<slot />
				</div>

				<!-- Loading State -->
				<NcLoadingIcon
					v-else
					class="loading-icon"
					:size="32"
					appearance="dark" />
				<p v-if="loading" class="loading-text">
					{{ loadingText }}
				</p>
			</div>
		</div>

		<!-- Info Modal -->
		<NcModal
			v-if="hasInfoContent"
			:show="showInfoModal"
			:title="name + ' Information'"
			:name="name + ' Info'"
			@close="showInfoModal = false">
			<div class="info-content">
				<slot name="info" />
			</div>
		</NcModal>
	</NcSettingsSection>
</template>

<script>
import { defineComponent } from 'vue'
import { NcSettingsSection, NcButton, NcLoadingIcon, NcModal } from '@nextcloud/vue'
import Save from 'vue-material-design-icons/ContentSave.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Information from 'vue-material-design-icons/Information.vue'

/**
 * Always Visible Section component
 * Always shows content without toggle functionality
 *
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @version  1.0.0
 */
export default defineComponent({
	name: 'AlwaysVisibleSection',

	components: {
		NcSettingsSection,
		NcButton,
		NcLoadingIcon,
		NcModal,
		Save,
		Refresh,
		Information,
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
		 * Loading state
		 */
		loading: {
			type: Boolean,
			default: false,
		},

		/**
		 * Show save button
		 */
		showSaveButton: {
			type: Boolean,
			default: false,
		},

		/**
		 * Show refresh button
		 */
		showRefreshButton: {
			type: Boolean,
			default: false,
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
		 * Can save state
		 */
		canSave: {
			type: Boolean,
			default: true,
		},

		/**
		 * Saving state
		 */
		saving: {
			type: Boolean,
			default: false,
		},

		/**
		 * Refreshing state
		 */
		refreshing: {
			type: Boolean,
			default: false,
		},

		/**
		 * Has info content
		 */
		hasInfoContent: {
			type: Boolean,
			default: false,
		},

		/**
		 * Optional loading text shown below the spinner
		 */
		loadingText: {
			type: String,
			default: 'Loading...',
		},
	},

	data() {
		return {
			showInfoModal: false,
		}
	},

	methods: {
		/**
		 * Handle save button click
		  * @spec openspec/changes/retrofit-2026-05-26-fe-shell-navigation/tasks.md#task-4
		 */
		handleSave() {
			this.$emit('save')
		},

		/**
		 * Handle refresh button click
		  * @spec openspec/changes/retrofit-2026-05-26-fe-shell-navigation/tasks.md#task-4
		 */
		handleRefresh() {
			this.$emit('refresh')
		},
	},
})
</script>

<style scoped>
.section-title-with-buttons {
	display: flex;
	align-items: center;
	justify-content: space-between;
	width: 100%;
}

.header-buttons {
	display: flex;
	gap: 8px;
	align-items: center;
}

.title-save-button {
	margin-left: auto;
}

.title-refresh-button {
	margin-left: 8px;
}

.always-visible-section {
	width: 100%;
}

/* Place header actions container in the top-right corner of the section */
.section-header-actions {
	position: absolute;
	top: 6px; /* align with title baseline */
	right: 0;
	z-index: 1;
}

/* Ensure the parent has relative context */
.settings-section {
	position: relative;
}

.section-content {
	margin-top: 1rem;
}

.loading-icon {
	display: flex;
	justify-content: center;
	align-items: center;
	padding: 2rem;
}

.loading-text {
	text-align: center;
	margin-top: -8px;
	color: var(--color-text-lighter);
}

.info-content {
	max-width: 600px;
	line-height: 1.6;
}

/* Responsive */
@media (max-width: 768px) {
	.title-buttons { width: 100%; justify-content: flex-end; }
}
</style>
