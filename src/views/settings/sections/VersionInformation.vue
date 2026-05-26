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
	<AlwaysVisibleSection
		name="Version Information"
		description="Current application and configuration versions"
		:loading="loadingVersionInfo"
		:show-refresh-button="false"
		loading-text="Loading version information..."
		:has-info-content="true">
		<template #header-actions>
			<NcButton
				v-if="versionInfo.autoConfigCompleted === false"
				type="secondary"
				:disabled="autoConfiguring"
				@click="consolidatedAutoConfigure">
				Auto Configure
			</NcButton>
			<NcButton
				class="ml-8"
				type="error"
				:disabled="autoConfiguring"
				@click="handleForceUpdate">
				Force Update
			</NcButton>
			<NcButton
				v-if="versionInfo.autoConfigCompleted === true"
				class="ml-8"
				type="tertiary"
				:disabled="autoConfiguring"
				@click="handleResetAutoConfig">
				Reset Auto-Config
			</NcButton>
		</template>

		<div class="version-info">
			<div class="version-details">
				<div class="version-item">
					<strong>Application:</strong> {{ versionInfo.appName }} v{{ versionInfo.appVersion }}
				</div>
				<div class="version-item">
					<strong>Configured Version:</strong>
					<span v-if="versionInfo.configuredVersion">{{ versionInfo.configuredVersion }}</span>
					<span v-else class="no-version">Not configured</span>
				</div>
				<div class="version-item">
					<strong>Status:</strong>
					<span v-if="versionInfo.versionsMatch" class="status-ok">✓ Up to date</span>
					<span v-else-if="versionInfo.needsUpdate" class="status-warning">⚠ Update needed</span>
					<span v-else class="status-error">✗ Version mismatch</span>
				</div>
				<div class="version-item">
					<strong>Open Register:</strong>
					<span v-if="versionInfo.openRegisterEnabled" class="status-ok">✓ Installed and active</span>
					<span v-else-if="versionInfo.openRegisterInstalled" class="status-warning">⚠ Installed but not enabled</span>
					<span v-else class="status-error">✗ Not installed</span>
				</div>
			</div>

			<!-- Configuration Results -->
			<div v-if="consolidatedResult" class="config-result">
				<NcNoteCard
					v-if="consolidatedResult.success"
					type="success">
					{{ consolidatedResult.message }}

					<!-- Configuration Steps Details -->
					<div v-if="consolidatedResult.steps" class="config-steps">
						<h4>Configuration Steps:</h4>
						<ul>
							<li v-if="consolidatedResult.steps.configurationLoad?.success">
								✅ Configuration Loading: {{ consolidatedResult.steps.configurationLoad.message }}
							</li>
							<li v-if="consolidatedResult.steps.voorzieningenConfiguration?.success">
								🇳🇱 Voorzieningen: {{ consolidatedResult.steps.voorzieningenConfiguration.message }}
							</li>
							<li v-if="consolidatedResult.steps.voorzieningenConfiguration?.configured?.register">
								📋 Voorzieningen Register: {{ consolidatedResult.steps.voorzieningenConfiguration.configured.register }}
							</li>
							<li v-if="consolidatedResult.steps.voorzieningenConfiguration?.configured?.organisatieSchema">
								📊 Organisatie Schema: {{ consolidatedResult.steps.voorzieningenConfiguration.configured.organisatieSchema }}
							</li>
							<li v-if="consolidatedResult.steps.amefConfiguration?.success">
								🏗️ AMEF: {{ consolidatedResult.steps.amefConfiguration.message }}
							</li>
							<li v-if="consolidatedResult.steps.amefConfiguration?.configured?.registerId">
								📋 AMEF Register: {{ consolidatedResult.steps.amefConfiguration.configured.registerId }}
							</li>
							<li v-if="consolidatedResult.steps.groupsConfiguration?.success">
								👥 User Groups: {{ consolidatedResult.steps.groupsConfiguration.message }}
							</li>
							<li v-if="consolidatedResult.steps.groupsConfiguration?.created?.length > 0">
								➕ Created Groups: {{ consolidatedResult.steps.groupsConfiguration.created.join(', ') }}
							</li>
							<li v-if="consolidatedResult.steps.groupsConfiguration?.existing?.length > 0">
								✓ Existing Groups: {{ consolidatedResult.steps.groupsConfiguration.existing.length }} groups already exist
							</li>
						</ul>
					</div>
				</NcNoteCard>
				<NcNoteCard
					v-else
					type="error">
					{{ consolidatedResult.message }}

					<!-- Show errors if any -->
					<div v-if="consolidatedResult.errors && consolidatedResult.errors.length > 0" class="config-errors">
						<h4>Errors:</h4>
						<ul>
							<li v-for="error in consolidatedResult.errors" :key="error">
								{{ error }}
							</li>
						</ul>
					</div>
				</NcNoteCard>
			</div>

			<!-- Reset Auto-Config Results -->
			<div v-if="resetAutoConfigResult" class="reset-result">
				<NcNoteCard
					v-if="resetAutoConfigResult.success"
					type="success">
					{{ resetAutoConfigResult.message }}
				</NcNoteCard>
				<NcNoteCard
					v-else
					type="error">
					{{ resetAutoConfigResult.message }}
				</NcNoteCard>
			</div>
		</div>

		<!-- Info Content Slot -->
		<template #info>
			<div class="version-info-help">
				<h3>About Version Information</h3>
				<p>This section displays version information for the Software Catalog application and its configuration status.</p>

				<h4>Application Version</h4>
				<p>Shows the currently installed version of the Software Catalog app. This should match the version in your app store or deployment.</p>

				<h4>Configured Version</h4>
				<p>Indicates which version of the configuration schema is currently active. This helps track when configuration updates are needed.</p>

				<h4>Status Indicators</h4>
				<ul>
					<li><strong>✓ Up to date</strong> - Configuration matches the current application version</li>
					<li><strong>⚠ Update needed</strong> - Configuration needs to be updated for optimal functionality</li>
					<li><strong>✗ Version mismatch</strong> - Significant version differences detected</li>
				</ul>

				<h4>OpenRegister Status</h4>
				<ul>
					<li><strong>✓ Installed and active</strong> - OpenRegister app is properly installed and enabled</li>
					<li><strong>⚠ Installed but not enabled</strong> - App is installed but needs to be activated</li>
					<li><strong>✗ Not installed</strong> - OpenRegister app is missing and needs to be installed</li>
				</ul>

				<h4>Actions</h4>
				<p>Three maintenance actions are available here. They map to backend operations in <code>SettingsService.php</code>:</p>
				<ul>
					<li>
						<strong>Auto Configure</strong> — Calls the consolidated auto-config routine (<code>performConsolidatedAutoConfiguration</code>). It:
						<ul>
							<li>Loads/Imports the bundled register configuration when needed</li>
							<li>Configures the Voorzieningen register (register + organisatie/contactpersoon schemas)</li>
							<li>Configures AMEF (VNG-GEMMA register and required schemas)</li>
							<li>Creates/configures required user groups</li>
						</ul>
						Use this after install or when configuration is incomplete.
					</li>
					<li>
						<strong>Force Update</strong> — Triggers a full forced import and version sync (<code>forceUpdate</code>):
						<ul>
							<li>Resets the auto-config completed flag</li>
							<li>Forces re-import of the bundled configuration (same as <code>manualImport(true)</code>)</li>
							<li>Runs post-import auto-configuration</li>
							<li>Refreshes version info and configuration status</li>
						</ul>
						Use this if config drift occurred or you want to fully re-apply the shipped configuration.
					</li>
					<li>
						<strong>Reset Auto‑Config</strong> — Only clears the <code>auto_config_completed</code> flag and can optionally clear schema/register keys (<code>resetAutoConfiguration</code>). The UI triggers a safe reset (flag only). After resetting, you can run Auto Configure again.
					</li>
				</ul>

				<h4>What Auto Configure sets up</h4>
				<ul>
					<li>Register mappings for Voorzieningen and AMEF</li>
					<li>Schema configurations for organizations and contacts</li>
					<li>User group creation and assignment</li>
					<li>Default email settings</li>
				</ul>
				<p>Use Auto Configure when setting up the application for the first time or after major updates.</p>
			</div>
		</template>
	</AlwaysVisibleSection>
</template>

<script>
/**
 * Version Information Settings Component
 *
 * This component displays version information and provides auto-configuration
 * functionality for the Software Catalog application.
 *
 * @author Ruben Linde <info@conduction.nl>
 * @copyright 2023 Conduction B.V.
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 */

import { settingsStore } from '../../../store/store.js'
import { showSuccess, showError } from '@nextcloud/dialogs'

// Components
import AlwaysVisibleSection from '../../../components/AlwaysVisibleSection.vue'

// Nextcloud Vue components
import { NcNoteCard, NcButton } from '@nextcloud/vue'

export default {
	name: 'VersionInformation',

	components: {
		AlwaysVisibleSection,
		NcNoteCard,
		NcButton,
	},

	/**
	 * Component setup function for Composition API
	 * Provides access to the settings store
	 *
	 * @return {object} Setup object with store reference
	  * @spec openspec/changes/retrofit-2026-05-26-fe-settings-ui/tasks.md#task-9
	 */
	setup() {
		return {
			store: settingsStore,
		}
	},

	/**
	 * Component data
	 *
	 * @return {object} Component data
	 */
	data() {
		return {
			autoConfiguring: false,
			consolidatedResult: null,
			resetAutoConfigResult: null,
		}
	},

	computed: {
		// Store-connected computed properties
		/**
		 * @spec openspec/changes/retrofit-2026-05-26-fe-settings-ui/tasks.md#task-9
		 */
		loadingVersionInfo() { return this.store.loadingVersionInfo },
		/**
		 * @spec openspec/changes/retrofit-2026-05-26-fe-settings-ui/tasks.md#task-9
		 */
		versionInfo() { return this.store.versionInfo },
	},

	methods: {
		/**
		 * Perform consolidated auto-configuration using the settings store
		 *
		 * @return {Promise<void>}
		  * @spec openspec/changes/retrofit-2026-05-26-fe-settings-ui/tasks.md#task-9
		 */
		async consolidatedAutoConfigure() {
			this.autoConfiguring = true
			this.consolidatedResult = null

			try {
				const result = await this.store.consolidatedAutoConfigure()
				this.consolidatedResult = result
				if (result && result.success) {
					showSuccess('Auto configuration completed successfully')
				} else if (result && result.message) {
					showError('Auto configuration failed: ' + result.message)
				}
			} catch (error) {
				console.error('Failed to perform auto-configuration:', error)
				this.consolidatedResult = {
					success: false,
					message: 'Failed to perform auto-configuration: ' + error.message,
				}
				showError('Failed to perform auto-configuration: ' + error.message)
			} finally {
				this.autoConfiguring = false
			}
		},

		/**
		 * @spec openspec/changes/retrofit-2026-05-26-fe-settings-ui/tasks.md#task-9
		 */
		async handleResetAutoConfig() {
			this.autoConfiguring = true
			this.resetAutoConfigResult = null
			try {
				this.resetAutoConfigResult = await this.store.resetAutoConfig()
				await this.store.loadVersionInfo()
				if (this.resetAutoConfigResult && this.resetAutoConfigResult.success) {
					showSuccess(this.resetAutoConfigResult.message || 'Auto-config reset successfully')
				} else if (this.resetAutoConfigResult) {
					showError(this.resetAutoConfigResult.message || 'Failed to reset auto-config')
				}
			} finally {
				this.autoConfiguring = false
			}
		},

		/**
		 * @spec openspec/changes/retrofit-2026-05-26-fe-settings-ui/tasks.md#task-9
		 */
		async handleForceUpdate() {
			this.autoConfiguring = true
			this.consolidatedResult = null
			try {
				this.consolidatedResult = await this.store.forceUpdate()
				await this.store.loadVersionInfo()
				if (this.consolidatedResult && this.consolidatedResult.success) {
					showSuccess(this.consolidatedResult.message || 'Force update completed successfully')
				} else if (this.consolidatedResult) {
					showError(this.consolidatedResult.message || 'Force update failed')
				}
			} finally {
				this.autoConfiguring = false
			}
		},
	},
}
</script>

<style scoped>
.version-info {
	margin-top: 16px;
}

.version-details {
	display: grid;
	gap: 12px;
	margin-bottom: 24px;
}

.version-item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
}

.version-item strong {
	min-width: 140px;
	font-weight: 600;
	color: var(--color-main-text);
}

.no-version {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.status-ok {
	color: var(--color-success);
	font-weight: 600;
}

.status-warning {
	color: var(--color-warning);
	font-weight: 600;
}

.status-error {
	color: var(--color-error);
	font-weight: 600;
}

.consolidated-config {
	margin-top: 24px;
	padding-top: 24px;
	border-top: 1px solid var(--color-border);
}

.config-actions {
	margin-bottom: 16px;
}

.config-result,
.reset-result {
	margin-top: 16px;
}

.config-steps {
	margin-top: 16px;
}

.config-steps h4 {
	margin: 0 0 12px 0;
	font-size: 14px;
	font-weight: 600;
	color: var(--color-main-text);
}

.config-steps ul {
	margin: 0;
	padding-left: 20px;
}

.config-steps li {
	margin-bottom: 8px;
	color: var(--color-main-text);
}

.config-errors {
	margin-top: 16px;
}

.config-errors h4 {
	margin: 0 0 12px 0;
	font-size: 14px;
	font-weight: 600;
	color: var(--color-error);
}

.config-errors ul {
	margin: 0;
	padding-left: 20px;
}

.config-errors li {
	margin-bottom: 8px;
	color: var(--color-error);
}

.loading-icon {
	display: flex;
	justify-content: center;
	margin: 40px 0;
}
</style>
