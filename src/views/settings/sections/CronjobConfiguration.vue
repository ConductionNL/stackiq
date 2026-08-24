<!--
 - @copyright Copyright (c) 2024 Conduction B.V. <info@conduction.nl>
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
	<AlwaysVisibleSection
		name="Background Jobs Configuration"
		description="Configure user and organisation context for background jobs to enable proper authorization"
		:loading="loading"
		loadingText="Loading cronjob configuration..."
		:showSaveButton="false"
		:showRefreshButton="true"
		@refresh="loadConfig">
		<div v-if="!loading" class="cronjob-configuration">
			<!-- Information Note -->
			<NcNoteCard type="info" class="info-card">
				Background jobs (cronjobs) need a user and organisation context to
				properly access data with correct permissions. Configure each job
				below to set which user and organisation it should run as.
			</NcNoteCard>

			<!-- Cronjob Cards -->
			<div class="cronjob-list">
				<div v-for="job in cronjobs" :key="job.id" class="cronjob-card">
					<div class="cronjob-header">
						<div class="cronjob-title">
							<h4>{{ job.name }}</h4>
							<NcCheckboxRadioSwitch
								:modelValue="job.enabled"
								type="switch"
								@update:modelValue="
									updateJobEnabled(job.id, $event)
								">
								{{ job.enabled ? 'Enabled' : 'Disabled' }}
							</NcCheckboxRadioSwitch>
						</div>
						<p class="cronjob-description">
							{{ job.description }}
						</p>
						<span class="cronjob-interval">
							<Clock :size="16" />
							Runs every {{ formatInterval(job.interval) }}
						</span>
					</div>

					<div class="cronjob-config">
						<div class="config-row">
							<div class="config-field">
								<label :for="'org-' + job.id"
									>Run in Organisation</label
								>
								<NcSelect
									:id="'org-' + job.id"
									v-model="job.selectedOrganisation"
									:options="organisationOptions"
									:loading="loadingOrganisations"
									:disabled="!job.enabled || savingJob === job.id"
									inputLabel="Select an organisation" />
							</div>
						</div>

						<!-- Save button, Run button and status -->
						<div class="config-actions">
							<NcButton
								variant="primary"
								:disabled="!canSaveJob(job) || savingJob === job.id"
								@click="saveJobConfig(job)">
								<template #icon>
									<NcLoadingIcon
										v-if="savingJob === job.id"
										:size="20" />
									<ContentSave v-else :size="20" />
								</template>
								{{
									savingJob === job.id
										? 'Saving...'
										: 'Save Configuration'
								}}
							</NcButton>

							<NcButton
								variant="secondary"
								:disabled="
									!job.organisationUuid || runningJob === job.id
								"
								@click="runJob(job)">
								<template #icon>
									<NcLoadingIcon
										v-if="runningJob === job.id"
										:size="20" />
									<Play v-else :size="20" />
								</template>
								{{
									runningJob === job.id ? 'Running...' : 'Run Now'
								}}
							</NcButton>

							<!-- Status indicator -->
							<div
								class="config-status"
								:class="[
									job.organisationUuid ? 'success' : 'warning',
								]">
								<template v-if="job.organisationUuid">
									<Check :size="16" class="status-icon success" />
									<span class="status-text"
										>Configured - Job will run with proper
										authorization</span
									>
								</template>
								<template v-else>
									<AlertCircle
										:size="16"
										class="status-icon warning" />
									<span class="status-text"
										>Not configured - Job may encounter RBAC
										errors</span
									>
								</template>
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- Empty state -->
			<NcEmptyContent
				v-if="cronjobs.length === 0"
				:name="t('stackiq', 'No background jobs configured')"
				:description="
					t(
						'stackiq',
						'There are no background jobs available for configuration.',
					)
				">
				<template #icon>
					<Cog :size="64" />
				</template>
			</NcEmptyContent>
		</div>
	</AlwaysVisibleSection>
</template>

<script>
/**
 * Cronjob Configuration Settings Component
 *
 * This component handles the configuration of background job user and
 * organisation context settings.
 *
 * @author Conduction B.V. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license EUPL-1.2
 * @version 1.0.0
 */

import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
// Nextcloud Vue components
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import Check from 'vue-material-design-icons/Check.vue'
// Icons
import Clock from 'vue-material-design-icons/Clock.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import Play from 'vue-material-design-icons/Play.vue'
// Custom components
import AlwaysVisibleSection from '../../../components/AlwaysVisibleSection.vue'

export default {
	name: 'CronjobConfiguration',

	components: {
		NcSelect,
		NcNoteCard,
		NcEmptyContent,
		NcLoadingIcon,
		NcCheckboxRadioSwitch,
		NcButton,
		AlwaysVisibleSection,
		Clock,
		Check,
		AlertCircle,
		Cog,
		ContentSave,
		Play,
	},

	data() {
		return {
			loading: true,
			loadingOrganisations: false,
			savingJob: null,
			runningJob: null,
			cronjobs: [],
			organisations: [],
		}
	},

	computed: {
		/**
		 * Format organisations for NcSelect
		 *
		 * @return {Array} Organisation options for select
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		organisationOptions() {
			return this.organisations.map((org) => ({
				value: org.uuid,
				label: org.name,
			}))
		},
	},

	async mounted() {
		await this.loadConfig()
	},

	methods: {
		/**
		 * Load all configuration data
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		async loadConfig() {
			this.loading = true
			try {
				await Promise.all([this.loadCronjobs(), this.loadOrganisations()])
			} catch (error) {
				console.error('Failed to load cronjob configuration:', error)
				showError('Failed to load cronjob configuration')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Load cronjob configurations
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		async loadCronjobs() {
			try {
				const response = await axios.get(
					generateUrl('/apps/stackiq/api/settings/cronjobs'),
				)
				if (response.data.success) {
					// Transform to array and add selected values for dropdowns.
					// Labels will be updated when users and organisations are loaded.
					this.cronjobs = Object.values(response.data.cronjobs).map(
						(job) => ({
							...job,
							selectedOrganisation: job.organisationUuid
								? {
										value: job.organisationUuid,
										label: job.organisationUuid,
									}
								: null,
						}),
					)

					// Update labels if organisations are already loaded.
					this.updateOrganisationLabels()
				}
			} catch (error) {
				console.error('Failed to load cronjobs:', error)
				throw error
			}
		},

		/**
		 * Update organisation labels in cronjobs from loaded organisations
		 *
		 * @return {void}
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		updateOrganisationLabels() {
			if (this.organisations.length === 0) return

			this.cronjobs.forEach((job) => {
				if (job.organisationUuid) {
					const org = this.organisations.find(
						(o) => o.uuid === job.organisationUuid,
					)
					if (org) {
						job.selectedOrganisation = {
							value: org.uuid,
							label: org.name,
						}
					}
				}
			})
		},

		/**
		 * Load available organisations from OpenRegister API
		 *
		 * Since the user is logged in on the settings page, we can call
		 * the OpenRegister organisations endpoint directly.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		async loadOrganisations() {
			this.loadingOrganisations = true
			try {
				// Call OpenRegister organisations endpoint directly.
				const response = await axios.get(
					generateUrl('/apps/openregister/api/organisations'),
				)

				// The response has a 'results' array with organisations.
				if (response.data && response.data.results) {
					this.organisations = response.data.results.map((org) => ({
						uuid: org.uuid,
						name: org.name,
						description: org.description,
					}))

					// Update labels in cronjobs.
					this.updateOrganisationLabels()
				}
			} catch (error) {
				console.error('Failed to load organisations:', error)
			} finally {
				this.loadingOrganisations = false
			}
		},

		/**
		 * Check if a job can be saved (has an organisation selected)
		 *
		 * @param {object} job The job to check
		 * @return {boolean} True if the job can be saved
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		canSaveJob(job) {
			return !!job.selectedOrganisation?.value
		},

		/**
		 * Update job enabled state
		 *
		 * @param {string} jobId The job ID
		 * @param {boolean} enabled Whether the job is enabled
		 * @return {Promise<void>}
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		async updateJobEnabled(jobId, enabled) {
			const job = this.cronjobs.find((j) => j.id === jobId)
			if (job) {
				job.enabled = enabled
				await this.saveJobConfig(job)
			}
		},

		/**
		 * Save job configuration
		 *
		 * @param {object} job The job to save
		 * @return {Promise<void>}
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		async saveJobConfig(job) {
			this.savingJob = job.id
			try {
				const response = await axios.post(
					generateUrl('/apps/stackiq/api/settings/cronjobs'),
					{
						jobId: job.id,
						organisationUuid: job.selectedOrganisation?.value || null,
						enabled: job.enabled,
					},
				)

				if (response.data.success) {
					// Update local state.
					job.organisationUuid = job.selectedOrganisation?.value || null
					showSuccess('Cronjob configuration saved')
				} else {
					showError(
						response.data.message || 'Failed to save configuration',
					)
				}
			} catch (error) {
				console.error('Failed to save job config:', error)
				showError('Failed to save cronjob configuration')
			} finally {
				this.savingJob = null
			}
		},

		/**
		 * Manually run a cronjob
		 *
		 * @param {object} job The job to run
		 * @return {Promise<void>}
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		async runJob(job) {
			if (!job.organisationUuid) {
				showError('Please configure and save an organisation first')
				return
			}

			this.runningJob = job.id
			try {
				// Trigger the organization sync endpoint.
				const response = await axios.post(
					generateUrl('/apps/stackiq/api/settings/sync'),
				)

				if (response.data.success) {
					showSuccess('Background job executed successfully')
				} else {
					showError(response.data.message || 'Job execution failed')
				}
			} catch (error) {
				console.error('Failed to run job:', error)
				showError(
					'Failed to run background job: '
						+ (error.response?.data?.message || error.message),
				)
			} finally {
				this.runningJob = null
			}
		},

		/**
		 * Format interval in human readable format
		 *
		 * @param {number} seconds Interval in seconds
		 * @return {string} Formatted interval
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		formatInterval(seconds) {
			if (seconds < 60) {
				return `${seconds} seconds`
			} else if (seconds < 3600) {
				const minutes = Math.floor(seconds / 60)
				return `${minutes} minute${minutes > 1 ? 's' : ''}`
			} else {
				const hours = Math.floor(seconds / 3600)
				return `${hours} hour${hours > 1 ? 's' : ''}`
			}
		},

		/**
		 * Translation helper
		 *
		 * @param {string} app App name
		 * @param {string} text Text to translate
		 * @return {string} Translated text
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		t(app, text) {
			return text
		},
	},
}
</script>

<style scoped>
.cronjob-configuration {
	padding: 0;
}

.info-card {
	margin-bottom: 20px;
}

.cronjob-list {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.cronjob-card {
	position: relative;
	padding: 20px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background-color: var(--color-background-hover);
}

.cronjob-header {
	margin-bottom: 16px;
}

.cronjob-title {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 8px;
}

.cronjob-title h4 {
	margin: 0;
	font-weight: 600;
	color: var(--color-main-text);
}

.cronjob-description {
	margin: 0 0 8px 0;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}

.cronjob-interval {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	font-size: 12px;
	color: var(--color-text-lighter);
}

.cronjob-config {
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

.config-row {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
	gap: 16px;
	margin-bottom: 16px;
}

.config-field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.config-field label {
	font-size: 13px;
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

.config-actions {
	display: flex;
	align-items: center;
	gap: 16px;
	flex-wrap: wrap;
	margin-top: 8px;
}

.config-status {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 10px 14px;
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
	background-color: var(--color-background-hover);
}

.config-status.success {
	border-color: var(--color-success);
	background-color: var(--color-success-light, rgba(70, 186, 118, 0.1));
}

.config-status.warning {
	border-color: var(--color-warning);
	background-color: var(--color-warning-light, rgba(236, 178, 46, 0.1));
}

.status-icon {
	flex-shrink: 0;
}

.status-icon.success {
	color: var(--color-success);
}

.status-icon.warning {
	color: var(--color-warning);
}

.status-text {
	font-size: 13px;
	color: var(--color-main-text);
}
</style>
