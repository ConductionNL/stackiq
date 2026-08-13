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
		<!-- Header actions positioned at top-right of the section title area -->
		<div class="section-header-actions">
			<div class="header-buttons">
				<NcButton
					v-if="showSaveButton"
					variant="primary"
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
					variant="secondary"
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
					variant="tertiary-no-background"
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

		<!-- Info Modal — own file per ADR-004/ADR-012 -->
		<AlwaysVisibleSectionInfoModal
			v-if="hasInfoContent"
			:name="name"
			:show="showInfoModal"
			@close="showInfoModal = false">
			<!--
				BOTH slot names are honoured. `info-content` wins when supplied and
				`info` is the fallback, so no caller can be silently empty.

				This section only ever declared `info`, but four of its five callers
				(UserGroupsConfiguration, EmailConfiguration, ArchiMateImportExport,
				OrganizationSynchronization) pass `#info-content` — the name
				CollapsibleSection uses. They all set `:has-info-content="true"`, so
				the (i) button rendered and opened a completely EMPTY modal.
			-->
			<slot name="info-content">
				<slot name="info" />
			</slot>
		</AlwaysVisibleSectionInfoModal>
	</NcSettingsSection>
</template>

<script>
import { defineComponent } from 'vue'
import { NcSettingsSection, NcButton, NcLoadingIcon } from '@nextcloud/vue'
import Save from 'vue-material-design-icons/ContentSave.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Information from 'vue-material-design-icons/Information.vue'
import AlwaysVisibleSectionInfoModal from '../modals/AlwaysVisibleSectionInfoModal.vue'

/**
 * Always Visible Section component
 * Always shows content without toggle functionality
 *
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2
 * @version  1.0.0
 */
export default defineComponent({
	name: 'AlwaysVisibleSection',

	components: {
		NcSettingsSection,
		NcButton,
		NcLoadingIcon,
		Save,
		Refresh,
		Information,
		AlwaysVisibleSectionInfoModal,
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

/* .info-content lives with the modal in src/modals/AlwaysVisibleSectionInfoModal.vue */

/* Responsive */
@media (max-width: 768px) {
	.title-buttons {
		width: 100%;
		justify-content: flex-end;
	}
}
</style>
