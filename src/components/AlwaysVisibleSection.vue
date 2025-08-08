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
		<div class="always-visible-section">
			<!-- Section Header with Controls -->
			<div class="section-header">
				<div class="section-controls">
					<!-- Save Button -->
					<NcButton
						v-if="showSaveButton && !loading"
						type="primary"
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
						type="secondary"
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
						type="tertiary-no-background"
						:aria-label="'Show information about ' + name"
						@click="showInfoModal = true">
						<template #icon>
							<Information :size="20" />
						</template>
					</NcButton>
				</div>
			</div>

			<!-- Always Visible Content -->
			<div class="section-content">
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
			v-if="hasInfoContent"
			:show="showInfoModal"
			:title="name + ' Information'"
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
	},

	data() {
		return {
			showInfoModal: false,
		}
	},

	methods: {
		/**
		 * Handle save button click
		 */
		handleSave() {
			this.$emit('save')
		},

		/**
		 * Handle refresh button click
		 */
		handleRefresh() {
			this.$emit('refresh')
		},
	},
})
</script>

<style scoped>
.always-visible-section {
	width: 100%;
}

.section-header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	margin-bottom: 1rem;
	padding-bottom: 1rem;
	border-bottom: 1px solid var(--color-border);
}

.section-info {
	flex: 1;
}

.section-title {
	margin: 0 0 0.5rem 0;
	font-size: 1.25rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.section-description {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.875rem;
	line-height: 1.4;
}

.section-controls {
	display: flex;
	gap: 0.5rem;
	align-items: center;
	flex-shrink: 0;
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

.info-content {
	max-width: 600px;
	line-height: 1.6;
}

/* Responsive design */
@media (max-width: 768px) {
	.section-header {
		flex-direction: column;
		gap: 1rem;
	}

	.section-controls {
		width: 100%;
		justify-content: flex-end;
	}
}
</style>
