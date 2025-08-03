<template>
	<div>
		<NcSettingsSection
			name="Software Catalog Configuration"
			description="Configure OpenRegister schema mappings for Software Catalog objects"
			doc-url="https://docs.opencatalogi.nl" />

		<NcSettingsSection
			name="Version Information"
			description="Current application and configuration versions">
			<div v-if="!loadingVersionInfo" class="version-info">
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
				</div>

				<!-- Consolidated Auto-Configuration Section -->
				<div class="consolidated-config">
					<div class="config-actions">
						<NcButton
							:type="versionInfo.needsUpdate ? 'primary' : 'secondary'"
							:disabled="autoConfiguring"
							@click="consolidatedAutoConfigure()">
							<template #icon>
								<NcLoadingIcon v-if="autoConfiguring" :size="20" />
								<Cog v-else :size="20" />
							</template>
							{{ versionInfo.needsUpdate ? 'Auto Configure' : 'Reload Configuration' }}
						</NcButton>
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
			</div>

			<!-- Loading State -->
			<NcLoadingIcon v-else
				class="loading-icon"
				:size="64"
				appearance="dark" />
		</NcSettingsSection>

		<NcSettingsSection
			name="OpenRegister Integration"
			description="Configure which schemas to use for organizations, contacts, and users">
			<div v-if="!loading">
				<!-- Warning if OpenRegister is not installed -->
				<NcNoteCard v-if="!settings.openRegisters" type="warning">
					OpenRegister is not installed or not available. Please install it to use the Software Catalog with full functionality.
				</NcNoteCard>

				<!-- Note: Initialization moved to single consolidated button above -->

				<!-- Register Selection Section -->
				<NcSettingsSection
					v-if="settings.openRegisters"
					name="Register Selection"
					description="Select the registers to use for your Software Catalog data">
					<div v-if="!loading" class="register-selection-content">
						<div class="register-selection-grid">
							<div class="register-selection-item">
								<h4>Voorzieningen Register</h4>
								<p>Register for organizations, contacts, and users</p>
								<NcSelect
									v-model="voorzieningenRegister"
									:options="registerOptions"
									input-label="Select Voorzieningen Register"
									:disabled="loading"
									@change="handleVoorzieningenRegisterChange" />
							</div>

							<div class="register-selection-item">
								<h4>AMEF Register</h4>
								<p>Register for ArchiMate elements, relationships, and views</p>
								<NcSelect
									v-model="amefRegister"
									:options="registerOptions"
									input-label="Select AMEF Register"
									:disabled="loading"
									@change="handleAmefRegisterChange" />
							</div>
						</div>
					</div>

					<!-- Loading State -->
					<NcLoadingIcon v-else
						class="loading-icon"
						:size="64"
						appearance="dark" />
				</NcSettingsSection>

				<!-- Voorzieningen Schema Configuration -->
				<NcSettingsSection
					v-if="settings.openRegisters && voorzieningenRegister && voorzieningenSchemas.length > 0"
					name="Voorzieningen Schema Configuration"
					description="Configure schemas for the Voorzieningen register">
					<div v-if="!loading" class="schema-configuration-content">
						<div class="schema-configuration-grid">
							<div class="object-type-section">
								<div class="object-type-header">
									<h5>Organisatie Schema</h5>
									<span class="object-type-description">Schema for organizations</span>
								</div>
								<NcSelect
									v-model="configuration.voorzieningen_organisatie.schema"
									:options="voorzieningenSchemaOptions"
									input-label="Organisatie Schema"
									:disabled="loading"
									@change="validateConfiguration" />
							</div>

							<div class="object-type-section">
								<div class="object-type-header">
									<h5>Contactpersoon Schema</h5>
									<span class="object-type-description">Schema for contact persons</span>
								</div>
								<NcSelect
									v-model="configuration.voorzieningen_contactpersoon.schema"
									:options="voorzieningenSchemaOptions"
									input-label="Contactpersoon Schema"
									:disabled="loading"
									@change="validateConfiguration" />
							</div>

							<div class="object-type-section">
								<div class="object-type-header">
									<h5>Gebruiker Schema</h5>
									<span class="object-type-description">Schema for users</span>
								</div>
								<NcSelect
									v-model="configuration.voorzieningen_gebruiker.schema"
									:options="voorzieningenSchemaOptions"
									input-label="Gebruiker Schema"
									:disabled="loading"
									@change="validateConfiguration" />
							</div>

							<div class="object-type-section">
								<div class="object-type-header">
									<h5>Contactgegevens Schema</h5>
									<span class="object-type-description">Schema for contact details</span>
								</div>
								<NcSelect
									v-model="configuration.voorzieningen_contactgegevens.schema"
									:options="voorzieningenSchemaOptions"
									input-label="Contactgegevens Schema"
									:disabled="loading"
									@change="validateConfiguration" />
							</div>
						</div>
					</div>

					<!-- Loading State -->
					<NcLoadingIcon v-else
						class="loading-icon"
						:size="64"
						appearance="dark" />
				</NcSettingsSection>

				<!-- Voorzieningen Empty State -->
				<NcSettingsSection
					v-if="settings.openRegisters && voorzieningenRegister && voorzieningenSchemas.length === 0"
					name="Voorzieningen Schema Configuration"
					description="Configure schemas for the Voorzieningen register">
					<NcNoteCard type="warning">
						The selected Voorzieningen register has no schemas. Please create schemas in this register.
					</NcNoteCard>
				</NcSettingsSection>

				<!-- AMEF Schema Configuration -->
				<NcSettingsSection
					v-if="settings.openRegisters && amefRegister && amefSchemas.length > 0"
					name="AMEF Schema Configuration"
					description="Configure schemas for the AMEF register">
					<div v-if="!loading" class="schema-configuration-content">
						<div class="schema-configuration-grid">
							<div class="object-type-section">
								<div class="object-type-header">
									<h5>Organizations Schema</h5>
									<span class="object-type-description">Schema for organizations in AMEF</span>
								</div>
								<NcSelect
									v-model="configuration.amef_organization.schema"
									:options="amefSchemaOptions"
									input-label="Organizations Schema"
									:disabled="loading"
									@change="validateConfiguration" />
							</div>

							<div class="object-type-section">
								<div class="object-type-header">
									<h5>Elements Schema</h5>
									<span class="object-type-description">Schema for ArchiMate elements</span>
								</div>
								<NcSelect
									v-model="configuration.amef_elements.schema"
									:options="amefSchemaOptions"
									input-label="Elements Schema"
									:disabled="loading"
									@change="validateConfiguration" />
							</div>

							<div class="object-type-section">
								<div class="object-type-header">
									<h5>Relationships Schema</h5>
									<span class="object-type-description">Schema for ArchiMate relationships</span>
								</div>
								<NcSelect
									v-model="configuration.amef_relationships.schema"
									:options="amefSchemaOptions"
									input-label="Relationships Schema"
									:disabled="loading"
									@change="validateConfiguration" />
							</div>

							<div class="object-type-section">
								<div class="object-type-header">
									<h5>Views Schema</h5>
									<span class="object-type-description">Schema for ArchiMate views</span>
								</div>
								<NcSelect
									v-model="configuration.amef_views.schema"
									:options="amefSchemaOptions"
									input-label="Views Schema"
									:disabled="loading"
									@change="validateConfiguration" />
							</div>
						</div>
					</div>

					<!-- Loading State -->
					<NcLoadingIcon v-else
						class="loading-icon"
						:size="64"
						appearance="dark" />
				</NcSettingsSection>

				<!-- AMEF Empty State -->
				<NcSettingsSection
					v-if="settings.openRegisters && amefRegister && amefSchemas.length === 0"
					name="AMEF Schema Configuration"
					description="Configure schemas for the AMEF register">
					<NcNoteCard type="warning">
						The selected AMEF register has no schemas. Please create schemas in this register.
					</NcNoteCard>
				</NcSettingsSection>

				<!-- Configuration Actions -->
				<NcSettingsSection
					v-if="settings.openRegisters && (voorzieningenRegister || amefRegister)"
					name="Configuration Actions"
					description="Save your register and schema configuration">
					<div class="button-container">
						<NcButton
							type="primary"
							:disabled="loading || saving || !canSave"
							@click="saveConfiguration">
							<template #icon>
								<NcLoadingIcon v-if="saving" :size="20" />
								<Save v-else :size="20" />
							</template>
							Save Configuration
						</NcButton>

						<NcButton
							type="secondary"
							:disabled="loading"
							@click="loadSettings">
							<template #icon>
								<Refresh :size="20" />
							</template>
							Refresh
						</NcButton>
					</div>
				</NcSettingsSection>
			</div>

			<!-- Loading State -->
			<NcLoadingIcon v-else
				class="loading-icon"
				:size="64"
				appearance="dark" />
		</NcSettingsSection>

		<NcSettingsSection
			name="Generic User Groups"
			description="Configure which user groups are available for assignment to users">
			<div v-if="!loading">
				<div class="generic-groups-section">
					<h3>User Group Management</h3>
					<p>Define the list of generic user groups that can be assigned to users based on their roles</p>

					<div class="groups-configuration">
						<h4>Current Generic User Groups</h4>
						<div class="group-list">
							<div v-for="(group, index) in genericUserGroups" :key="index" class="group-item">
								<NcTextField
									:value="group || ''"
									:placeholder="'Group name'"
									label="Group Name"
									@update:value="updateGroupName(index, $event)" />
								<NcButton
									type="tertiary-no-background"
									:aria-label="'Remove group'"
									@click="removeGroup(index)">
									<template #icon>
										<Close :size="16" />
									</template>
								</NcButton>
							</div>
						</div>

						<div class="group-actions">
							<NcButton
								type="secondary"
								@click="addGroup">
								<template #icon>
									<Plus :size="20" />
								</template>
								Add Group
							</NcButton>

							<NcButton
								type="primary"
								:disabled="loading || savingGroups"
								@click="saveGenericUserGroups">
								<template #icon>
									<NcLoadingIcon v-if="savingGroups" :size="20" />
									<Save v-else :size="20" />
								</template>
								Save Groups
							</NcButton>
						</div>

						<div v-if="groupValidation && groupValidation.errors.length > 0" class="validation-errors">
							<NcNoteCard type="error">
								<template #icon>
									<Alert :size="20" />
								</template>
								<strong>Validation Errors:</strong>
								<ul>
									<li v-for="error in groupValidation.errors" :key="error">
										{{ error }}
									</li>
								</ul>
							</NcNoteCard>
						</div>

						<div v-if="groupsSaveResult" class="save-results">
							<NcNoteCard v-if="groupsSaveResult.success" type="success">
								Groups saved successfully!
							</NcNoteCard>
							<NcNoteCard v-else type="error">
								{{ groupsSaveResult.error || 'Failed to save groups' }}
							</NcNoteCard>
						</div>

						<div class="groups-info">
							<h4>Group Information</h4>
							<p>These groups will be used for:</p>
							<ul>
								<li><strong>Role-based assignment:</strong> Users will be automatically assigned to groups based on their roles</li>
								<li><strong>Permission management:</strong> Groups can be used to control access to different parts of the system</li>
								<li><strong>Organization structure:</strong> Special groups like 'ambtenaar' are assigned based on organization type</li>
							</ul>

							<div class="default-groups-info">
								<h5>Recommended Groups:</h5>
								<ul>
									<li v-for="group in genericUserGroups" :key="group">
										<code>{{ group }}</code> - {{ getGroupDescription(group) }}
									</li>
								</ul>
							</div>
						</div>
					</div>

					<!-- Organization Admin Groups -->
					<div class="organization-admin-groups-section">
						<h3>Organization Admin Groups</h3>
						<p>Define groups that organization administrators (first contacts) are automatically assigned to</p>

						<div class="groups-configuration">
							<h4>Current Organization Admin Groups</h4>
							<div class="group-list">
								<div v-for="(group, index) in organizationAdminGroups" :key="index" class="group-item">
									<NcTextField
										:value="group || ''"
										:placeholder="'Group name'"
										label="Group Name"
										@update:value="updateOrganizationAdminGroupName(index, $event)" />
									<NcButton
										type="tertiary-no-background"
										:aria-label="'Remove group'"
										@click="removeOrganizationAdminGroup(index)">
										<template #icon>
											<Close :size="16" />
										</template>
									</NcButton>
								</div>
							</div>

							<div class="group-actions">
								<NcButton
									type="secondary"
									@click="addOrganizationAdminGroup">
									<template #icon>
										<Plus :size="20" />
									</template>
									Add Organization Admin Group
								</NcButton>

								<NcButton
									type="primary"
									:disabled="loading || savingOrganizationAdminGroups"
									@click="saveOrganizationAdminGroups">
									<template #icon>
										<NcLoadingIcon v-if="savingOrganizationAdminGroups" :size="20" />
										<Save v-else :size="20" />
									</template>
									Save Organization Admin Groups
								</NcButton>
							</div>

							<div v-if="organizationAdminGroupsSaveResult" class="save-results">
								<NcNoteCard :type="organizationAdminGroupsSaveResult.success ? 'success' : 'error'">
									{{ organizationAdminGroupsSaveResult.success ? 'Organization admin groups saved successfully!' : organizationAdminGroupsSaveResult.error }}
								</NcNoteCard>
							</div>

							<div class="groups-info">
								<h4>Organization Admin Group Information</h4>
								<p>These groups will be assigned to:</p>
								<ul>
									<li><strong>First contacts:</strong> The first contact person created for an organization</li>
									<li><strong>Organization administrators:</strong> Users designated as organization administrators</li>
									<li><strong>Management permissions:</strong> Users who need to manage their organization's data</li>
								</ul>
							</div>
						</div>
					</div>

					<!-- Super User Groups -->
					<div class="super-user-groups-section">
						<h3>Super User Groups</h3>
						<p>Define groups that super users (system administrators) are automatically assigned to</p>

						<div class="groups-configuration">
							<h4>Current Super User Groups</h4>
							<div class="group-list">
								<div v-for="(group, index) in superUserGroups" :key="index" class="group-item">
									<NcTextField
										:value="group || ''"
										:placeholder="'Group name'"
										label="Group Name"
										@update:value="updateSuperUserGroupName(index, $event)" />
									<NcButton
										type="tertiary-no-background"
										:aria-label="'Remove group'"
										@click="removeSuperUserGroup(index)">
										<template #icon>
											<Close :size="16" />
										</template>
									</NcButton>
								</div>
							</div>

							<div class="group-actions">
								<NcButton
									type="secondary"
									@click="addSuperUserGroup">
									<template #icon>
										<Plus :size="20" />
									</template>
									Add Super User Group
								</NcButton>

								<NcButton
									type="primary"
									:disabled="loading || savingSuperUserGroups"
									@click="saveSuperUserGroups">
									<template #icon>
										<NcLoadingIcon v-if="savingSuperUserGroups" :size="20" />
										<Save v-else :size="20" />
									</template>
									Save Super User Groups
								</NcButton>
							</div>

							<div v-if="superUserGroupsSaveResult" class="save-results">
								<NcNoteCard :type="superUserGroupsSaveResult.success ? 'success' : 'error'">
									{{ superUserGroupsSaveResult.success ? 'Super user groups saved successfully!' : superUserGroupsSaveResult.error }}
								</NcNoteCard>
							</div>

							<div class="groups-info">
								<h4>Super User Group Information</h4>
								<p>These groups will be assigned to:</p>
								<ul>
									<li><strong>System administrators:</strong> Users with full system access</li>
									<li><strong>Platform managers:</strong> Users who manage the entire platform</li>
									<li><strong>Advanced permissions:</strong> Users who need access to all system features</li>
								</ul>
							</div>
						</div>
					</div>
				</div>
			</div>
		</NcSettingsSection>

		<NcSettingsSection
			name="Organization Synchronization"
			description="Monitor and manage organization and contact person synchronization">
			<div v-if="!loading">
				<div class="sync-section">
					<h3>Synchronization Status</h3>
					<p>Monitor the status of organization and contact person synchronization</p>

					<!-- Time Window Configuration -->
					<div class="time-window-configuration">
						<h4>Incremental Sync Time Window</h4>
						<p>Configure how far back to look for updated organizations during incremental synchronization</p>

						<div class="time-window-row">
							<div class="time-window-selector">
								<NcSelect
									v-model="selectedTimeWindow"
									:options="timeWindowOptions"
									input-label="Time Window"
									:disabled="loading || loadingSyncStatus"
									@change="handleTimeWindowChange" />
							</div>

							<!-- Sync Actions in same row -->
							<div class="sync-actions">
								<NcButton
									type="secondary"
									:disabled="loading || loadingSyncStatus"
									@click="loadSyncStatus">
									<template #icon>
										<NcLoadingIcon v-if="loadingSyncStatus" :size="20" />
										<Refresh v-else :size="20" />
									</template>
									Refresh Status
								</NcButton>

								<NcButton
									type="primary"
									:disabled="loading || performingSync || !syncStatus?.configured"
									@click="performManualSync">
									<template #icon>
										<NcLoadingIcon v-if="performingSync" :size="20" />
										<Sync v-else :size="20" />
									</template>
									{{ selectedTimeWindow && selectedTimeWindow.value === 0 ? 'Full Sync Now' : 'Incremental Sync Now' }}
								</NcButton>
							</div>
						</div>

						<div class="time-window-description">
							{{ getTimeWindowDescription() }}
						</div>
					</div>

					<div class="sync-status">
						<div v-if="syncStatus" class="status-info">
							<div class="configuration-overview">
								<div class="config-item">
									<span class="config-label">Configuration:</span>
									<span v-if="syncStatus.configured" class="status-configured">✓ Configured</span>
									<span v-else class="status-missing">⚠ Not configured</span>
								</div>

								<div v-if="syncStatus.configured" class="config-details">
									<div class="config-item">
										<span class="config-label">Sync Mode:</span>
										<span class="config-value">{{ syncStatus.syncMode || 'Unknown' }}</span>
									</div>
									<div class="config-item">
										<span class="config-label">Time Window:</span>
										<span class="config-value">{{ formatTimeWindow(syncStatus.timeWindow) }}</span>
									</div>
									<div class="config-item">
										<span class="config-label">Total Organizations:</span>
										<span class="config-value">{{ formatNumber(syncStatus.totalOrganizationObjects) || 0 }}</span>
									</div>
									<div class="config-item">
										<span class="config-label">Organizations to Process:</span>
										<span class="config-value" :class="getProcessingClass(syncStatus.organizationsToProcess)">{{ syncStatus.organizationsToProcess || 0 }}</span>
									</div>
									<div class="config-item">
										<span class="config-label">Contact Persons to Process:</span>
										<span class="config-value" :class="getProcessingClass(syncStatus.contactPersonsToProcess)">{{ syncStatus.contactPersonsToProcess || 0 }}</span>
									</div>
									<div v-if="syncStatus.efficiencyImprovement" class="config-item">
										<span class="config-label">Efficiency Improvement:</span>
										<span class="config-value efficiency-highlight">{{ syncStatus.efficiencyImprovement }}</span>
									</div>
									<div class="config-item">
										<span class="config-label">Organization Entities:</span>
										<span class="config-value">{{ formatNumber(syncStatus.totalOrganizationEntities) || 0 }}</span>
									</div>
									<div class="config-item">
										<span class="config-label">Contact Schema:</span>
										<span v-if="syncStatus.contactSchemaConfigured" class="status-configured">✓ Configured</span>
										<span v-else class="status-missing">⚠ Not configured</span>
									</div>
									<div class="config-item">
										<span class="config-label">Last Sync:</span>
										<span class="config-value">{{ formatLastSyncTime(syncStatus.lastSyncTime) }}</span>
									</div>
								</div>
							</div>
							<div v-if="syncStatus.message" class="status-message">
								{{ syncStatus.message }}
							</div>
						</div>
						<div v-else class="status-loading">
							<NcLoadingIcon :size="20" />
							Loading sync status...
						</div>
					</div>

					<div v-if="syncResult" class="sync-result">
						<NcNoteCard :type="syncResult.success ? 'success' : 'error'">
							<template #icon>
								<CheckCircle v-if="syncResult.success" :size="20" />
								<Alert v-else :size="20" />
							</template>
							<div class="sync-result-content">
								<strong>{{ syncResult.message }}</strong>
								<div v-if="syncResult.success && syncResult.results" class="sync-statistics">
									<h5>Synchronization Results:</h5>
									<ul>
										<li>Organizations processed: {{ syncResult.results.organizationsProcessed }}</li>
										<li>Entities created: {{ syncResult.results.entitiesCreated }}</li>
										<li>Entities updated: {{ syncResult.results.entitiesUpdated }}</li>
										<li>Contact persons processed: {{ syncResult.results.contactPersonsProcessed }}</li>
										<li>Users created: {{ syncResult.results.usersCreated }}</li>
										<li>Users updated: {{ syncResult.results.usersUpdated }}</li>
										<li>Duration: {{ syncResult.results.duration }}</li>
									</ul>
									<div v-if="syncResult.results.errors && syncResult.results.errors.length > 0" class="sync-errors">
										<h5>Errors encountered:</h5>
										<ul>
											<li v-for="error in syncResult.results.errors" :key="error">
												{{ error }}
											</li>
										</ul>
									</div>
								</div>
							</div>
						</NcNoteCard>
					</div>

					<div class="sync-info">
						<h4>About Synchronization</h4>
						<p>The synchronization process ensures that:</p>
						<ul>
							<li><strong>Organization entities:</strong> Every organization object has a corresponding organization entity</li>
							<li><strong>User accounts:</strong> Contact persons have Nextcloud user accounts</li>
							<li><strong>Relationships:</strong> Organization entities maintain correct user lists</li>
							<li><strong>Status consistency:</strong> Organization active status reflects the 'beoordeling' field</li>
						</ul>
						<p><strong>Time-based filtering:</strong> Organizations remain in the sync queue based on their last update time in OpenRegister, not when they were last processed. An organization will naturally "age out" of the time window once it hasn't been updated for longer than the selected time period.</p>
						<p><strong>Automatic synchronization:</strong> This process runs every 5 minutes in the background using incremental sync (10-minute window by default). Use manual sync for immediate updates or troubleshooting.</p>
					</div>
				</div>
			</div>

			<!-- Loading State -->
			<NcLoadingIcon v-else
				class="loading-icon"
				:size="64"
				appearance="dark" />
		</NcSettingsSection>

		<NcSettingsSection
			name="ArchiMate Import/Export"
			description="Import ArchiMate files to create OpenRegister objects and export existing data to ArchiMate format">
			<div v-if="!loading">
				<div class="archimate-section">
					<h3>ArchiMate File Operations</h3>
					<p>Import ArchiMate (.archimate, .xml) files to automatically create objects in OpenRegister, or export existing data to ArchiMate format</p>

					<!-- Import Section -->
					<div class="import-section">
						<h4>Import ArchiMate File</h4>
						<p>Upload an ArchiMate file to import architectural elements, organizations, relationships, and views</p>

						<!-- Import Status Display -->
						<div v-if="archimateStatus.import && archimateStatus.import.status" class="import-status">
							<NcNoteCard :type="getStatusType(archimateStatus.import.status)">
								<template #icon>
									<NcLoadingIcon v-if="archimateStatus.import.status === 'running'" :size="20" />
									<CheckCircle v-else-if="archimateStatus.import.status === 'completed'" :size="20" />
									<Alert v-else-if="archimateStatus.import.status === 'failed'" :size="20" />
								</template>
								<div class="status-content">
									<strong>{{ archimateStatus.import.current_step }}</strong>
									<div v-if="archimateStatus.import.status === 'running'" class="progress-bar">
										<div class="progress-fill" :style="{ width: archimateStatus.import.progress + '%' }" />
										<span class="progress-text">{{ archimateStatus.import.progress }}%</span>
									</div>
									<div v-if="archimateStatus.import.statistics" class="status-statistics">
										<p v-if="archimateStatus.import.statistics.elements_processed > 0">
											Elements: {{ archimateStatus.import.statistics.elements_processed }}
										</p>
										<p v-if="archimateStatus.import.statistics.relationships_processed > 0">
											Relationships: {{ archimateStatus.import.statistics.relationships_processed }}
										</p>
										<p v-if="archimateStatus.import.statistics.organizations_processed > 0">
											Organizations: {{ archimateStatus.import.statistics.organizations_processed }}
										</p>
										<p v-if="archimateStatus.import.statistics.objects_created > 0">
											Created: {{ archimateStatus.import.statistics.objects_created }}
										</p>
										<p v-if="archimateStatus.import.statistics.objects_updated > 0">
											Updated: {{ archimateStatus.import.statistics.objects_updated }}
										</p>
									</div>
									<div v-if="archimateStatus.import.status === 'completed'" class="status-actions">
										<NcButton type="secondary" @click="clearImportStatus">
											Clear Status
										</NcButton>
									</div>
									<div v-if="archimateStatus.import.status === 'failed'" class="status-error">
										<p><strong>Error:</strong> {{ archimateStatus.import.error }}</p>
										<NcButton type="secondary" @click="clearImportStatus">
											Clear Status
										</NcButton>
									</div>
								</div>
							</NcNoteCard>
						</div>

						<div class="import-form">
							<div class="file-upload">
								<input
									ref="archiMateFileInput"
									type="file"
									accept=".archimate,.xml"
									style="display: none"
									@change="handleFileSelection">

								<NcButton
									type="secondary"
									:disabled="importing || !settings.openRegisters || isImportRunning"
									@click="$refs.archiMateFileInput.click()">
									<template #icon>
										<Upload :size="20" />
									</template>
									Select ArchiMate File
								</NcButton>

								<span v-if="selectedFile" class="selected-file">
									{{ selectedFile.name }} ({{ formatFileSize(selectedFile.size) }})
								</span>
							</div>

							<div v-if="selectedFile" class="import-options">
								<h5>Import Options</h5>

								<div class="option-row">
									<NcCheckboxRadioSwitch
										:checked="importOptions.updateExisting"
										@update:checked="importOptions.updateExisting = $event">
										Update Existing Objects
									</NcCheckboxRadioSwitch>
									<span class="option-description">Update objects that already exist (based on ArchiMate ID)</span>
								</div>

								<div class="option-row">
									<NcCheckboxRadioSwitch
										:checked="importOptions.deleteOrphaned"
										@update:checked="importOptions.deleteOrphaned = $event">
										Delete Orphaned Objects
									</NcCheckboxRadioSwitch>
									<span class="option-description">Delete objects that are no longer present in the imported file (orphaned objects)</span>
								</div>
							</div>

							<div v-if="selectedFile" class="import-actions">
								<NcButton
									type="primary"
									:disabled="importing || !settings.openRegisters || isImportRunning"
									@click="importArchiMateFile">
									<template #icon>
										<NcLoadingIcon v-if="importing" :size="20" />
										<CloudUpload v-else :size="20" />
									</template>
									{{ importing ? 'Starting Import...' : 'Import ArchiMate File' }}
								</NcButton>

								<NcButton
									type="tertiary"
									:disabled="importing || isImportRunning"
									@click="clearFileSelection">
									<template #icon>
										<Close :size="20" />
									</template>
									Clear
								</NcButton>
							</div>

							<div v-if="importResult" class="import-result">
								<NcNoteCard :type="importResult.success ? 'success' : 'error'">
									<template #icon>
										<CheckCircle v-if="importResult.success" :size="20" />
										<Alert v-else :size="20" />
									</template>
									<div class="result-content">
										<strong>{{ importResult.message }}</strong>
										<div v-if="importResult.success" class="import-statistics">
											<h5>Import Results:</h5>

											<!-- File Information -->
											<div v-if="importResult.file_info" class="file-info">
												<h6>File Information:</h6>
												<ul>
													<li><strong>File Name:</strong> {{ importResult.file_info.name }}</li>
													<li><strong>File Size:</strong> {{ (importResult.file_info.size / 1024 / 1024).toFixed(2) }} MB</li>
													<li><strong>File Type:</strong> {{ importResult.file_info.mime_type }}</li>
												</ul>
											</div>

											<!-- Performance Metrics -->
											<div v-if="importResult.performance_metrics" class="performance-metrics">
												<h6>Performance Metrics:</h6>
												<ul>
													<li><strong>Processing Method:</strong> {{ importResult.performance_metrics.processing_method }}</li>
													<li><strong>Batch Size:</strong> {{ importResult.performance_metrics.batch_size_used }}</li>
													<li v-if="importResult.performance_metrics.items_per_second > 0">
														<strong>Items/Second:</strong> {{ importResult.performance_metrics.items_per_second.toFixed(2) }}
													</li>
												</ul>
											</div>

											<!-- Processing Times -->
											<div v-if="importResult.processing_times" class="processing-times">
												<h6>Processing Times:</h6>
												<ul>
													<li><strong>Total Time:</strong> {{ importResult.processing_times.total_time_seconds.toFixed(2) }}s</li>
													<li><strong>Validation:</strong> {{ importResult.processing_times.validation_time_seconds.toFixed(3) }}s</li>
													<li><strong>Parsing:</strong> {{ importResult.processing_times.parse_time_seconds.toFixed(3) }}s</li>
													<li><strong>Conversion:</strong> {{ importResult.processing_times.convert_time_seconds.toFixed(2) }}s</li>
												</ul>
											</div>

											<!-- Summary Statistics -->
											<div v-if="importResult.summary" class="summary-stats">
												<h6>Summary:</h6>
												<ul>
													<li><strong>Objects Created:</strong> {{ importResult.summary.total_objects_created || 0 }}</li>
													<li><strong>Objects Updated:</strong> {{ importResult.summary.total_objects_updated || 0 }}</li>
													<li v-if="importResult.summary.total_objects_deleted > 0">
														<strong>Objects Deleted:</strong> {{ importResult.summary.total_objects_deleted }}
													</li>
													<li v-if="importResult.summary.total_errors > 0">
														<strong>Total Errors:</strong> {{ importResult.summary.total_errors }}
													</li>
												</ul>
											</div>

											<!-- Detailed Schema Statistics -->
											<div v-if="importResult.statistics" class="schema-statistics">
												<h6>Per Schema Breakdown:</h6>
												<div class="schema-grid">
													<div v-for="(stats, schema) in importResult.statistics" :key="schema" class="schema-card">
														<h6>{{ schema.charAt(0).toUpperCase() + schema.slice(1) }}</h6>
														<ul>
															<li v-if="stats.found > 0">
																🔍 Found: {{ stats.found }}
															</li>
															<li v-if="stats.created > 0">
																✅ Created: {{ stats.created }}
															</li>
															<li v-if="stats.updated > 0">
																🔄 Updated: {{ stats.updated }}
															</li>
															<li v-if="stats.skipped > 0">
																⏭️ Skipped: {{ stats.skipped }}
															</li>
															<li v-if="stats.deleted > 0">
																🗑️ Deleted: {{ stats.deleted }}
															</li>
															<li v-if="stats.errors && stats.errors.length > 0">
																❌ Errors: {{ stats.errors.length }}
															</li>
															<li v-if="stats.processing_time > 0">
																⏱️ Time: {{ stats.processing_time.toFixed(3) }}s
															</li>
															<li v-if="stats.created === 0 && stats.updated === 0 && stats.deleted === 0 && stats.skipped === 0 && (!stats.errors || stats.errors.length === 0)">
																ℹ️ No changes
															</li>
														</ul>
													</div>
												</div>
											</div>
										</div>
									</div>
								</NcNoteCard>
							</div>
						</div>
					</div>

					<!-- Export Section -->
					<div class="export-section">
						<h4>Export to ArchiMate</h4>
						<p>Export OpenRegister objects to ArchiMate format for use in modeling tools</p>

						<!-- Export Status Display -->
						<div v-if="archimateStatus.export && archimateStatus.export.status" class="export-status">
							<NcNoteCard :type="getStatusType(archimateStatus.export.status)">
								<template #icon>
									<NcLoadingIcon v-if="archimateStatus.export.status === 'running'" :size="20" />
									<CheckCircle v-else-if="archimateStatus.export.status === 'completed'" :size="20" />
									<Alert v-else-if="archimateStatus.export.status === 'failed'" :size="20" />
								</template>
								<div class="status-content">
									<strong>{{ archimateStatus.export.current_step }}</strong>
									<div v-if="archimateStatus.export.status === 'running'" class="progress-bar">
										<div class="progress-fill" :style="{ width: archimateStatus.export.progress + '%' }" />
										<span class="progress-text">{{ archimateStatus.export.progress }}%</span>
									</div>
									<div v-if="archimateStatus.export.statistics" class="status-statistics">
										<p v-if="archimateStatus.export.statistics.objects_found > 0">
											Objects Found: {{ archimateStatus.export.statistics.objects_found }}
										</p>
										<p v-if="archimateStatus.export.statistics.objects_exported > 0">
											Objects Exported: {{ archimateStatus.export.statistics.objects_exported }}
										</p>
										<p v-if="archimateStatus.export.statistics.xml_size_bytes > 0">
											XML Size: {{ (archimateStatus.export.statistics.xml_size_bytes / 1024).toFixed(2) }} KB
										</p>
									</div>
									<div v-if="archimateStatus.export.status === 'completed'" class="status-actions">
										<NcButton type="secondary" @click="clearExportStatus">
											Clear Status
										</NcButton>
									</div>
									<div v-if="archimateStatus.export.status === 'failed'" class="status-error">
										<p><strong>Error:</strong> {{ archimateStatus.export.error }}</p>
										<NcButton type="secondary" @click="clearExportStatus">
											Clear Status
										</NcButton>
									</div>
								</div>
							</NcNoteCard>
						</div>

						<div class="export-form">
							<div class="export-options">
								<h5>Export Options</h5>
								<div class="option-row">
									<label class="option-label">Format:</label>
									<NcSelect
										v-model="exportOptions.format"
										:options="[
											{ label: 'XML', value: 'xml' },
											{ label: 'JSON', value: 'json' }
										]"
										input-label="Export Format"
										placeholder="Select format" />
								</div>
								<div class="option-row">
									<NcCheckboxRadioSwitch
										:checked="exportOptions.organizationSpecific"
										@update:checked="exportOptions.organizationSpecific = $event">
										Organization Specific
									</NcCheckboxRadioSwitch>
								</div>
								<div v-if="exportOptions.organizationSpecific" class="option-row">
									<label class="option-label">Organization ID:</label>
									<NcTextField
										:value="exportOptions.organizationId || ''"
										placeholder="Enter organization ID"
										@update:value="exportOptions.organizationId = $event" />
								</div>
								<div v-if="!exportOptions.organizationSpecific" class="option-row">
									<label class="option-label">Organization Filter:</label>
									<NcTextField
										:value="exportOptions.organizationFilter || ''"
										placeholder="Filter by organization name (optional)"
										@update:value="exportOptions.organizationFilter = $event" />
								</div>
								<div class="option-row">
									<label class="option-label">Schemas to Export:</label>
									<NcSelect
										v-model="exportOptions.selectedSchemas"
										:options="availableSchemas"
										input-label="Schemas to Export"
										multiple
										placeholder="Select schemas to export" />
								</div>
								<div class="option-row">
									<NcCheckboxRadioSwitch
										:checked="exportOptions.includeRelationships"
										@update:checked="exportOptions.includeRelationships = $event">
										Include Relationships
									</NcCheckboxRadioSwitch>
								</div>
								<div class="option-row">
									<NcCheckboxRadioSwitch
										:checked="exportOptions.includeViews"
										@update:checked="exportOptions.includeViews = $event">
										Include Views
									</NcCheckboxRadioSwitch>
								</div>
							</div>

							<div class="export-actions">
								<NcButton
									type="primary"
									:disabled="exporting || !settings.openRegisters || isExportRunning"
									@click="exportToArchiMate">
									<template #icon>
										<NcLoadingIcon v-if="exporting" :size="20" />
										<Download v-else :size="20" />
									</template>
									{{ exporting ? 'Starting Export...' : 'Export to ArchiMate' }}
								</NcButton>
							</div>

							<div v-if="exportResult" class="export-result">
								<NcNoteCard :type="exportResult.success ? 'success' : 'error'">
									<template #icon>
										<CheckCircle v-if="exportResult.success" :size="20" />
										<Alert v-else :size="20" />
									</template>
									<div class="result-content">
										<strong>{{ exportResult.message }}</strong>
										<div v-if="exportResult.success" class="export-details">
											<p><strong>File:</strong> {{ exportResult.file_name }}</p>
											<p><strong>Objects exported:</strong> {{ exportResult.statistics?.objects_exported || 0 }}</p>
											<NcButton
												type="secondary"
												@click="downloadArchiMateFile(exportResult.file_name)">
												<template #icon>
													<Download :size="20" />
												</template>
												Download File
											</NcButton>
										</div>
									</div>
								</NcNoteCard>
							</div>
						</div>
					</div>

					<!-- Test Section -->
					<div class="test-section">
						<h4>Testing ArchiMate Import/Export</h4>
						<p>Upload an ArchiMate file, then export it back to compare and verify the round-trip process</p>
						<div class="test-actions">
							<NcButton
								type="secondary"
								:disabled="!importResult?.success || exporting"
								@click="testRoundTrip">
								<template #icon>
									<NcLoadingIcon v-if="testingRoundTrip" :size="20" />
									<Sync v-else :size="20" />
								</template>
								{{ testingRoundTrip ? 'Testing...' : 'Test Round-Trip' }}
							</NcButton>
						</div>

						<div v-if="roundTripResult" class="test-result">
							<NcNoteCard :type="roundTripResult.success ? 'success' : 'error'">
								<strong>{{ roundTripResult.message }}</strong>
								<div v-if="roundTripResult.success && roundTripResult.comparison" class="comparison-details">
									<p><strong>Comparison Results:</strong></p>
									<ul>
										<li>Elements matched: {{ roundTripResult.comparison.elements_matched }}</li>
										<li>Organizations matched: {{ roundTripResult.comparison.organizations_matched }}</li>
										<li>Differences found: {{ roundTripResult.comparison.differences }}</li>
									</ul>
								</div>
							</NcNoteCard>
						</div>
					</div>
				</div>
			</div>

			<!-- Loading State -->
			<NcLoadingIcon v-else
				class="loading-icon"
				:size="64"
				appearance="dark" />
		</NcSettingsSection>

		<NcSettingsSection
			name="Email Configuration"
			description="Configure email settings for notifications and templates">
			<div v-if="!loading">
				<div class="email-settings-section">
					<h3>Email Settings</h3>
					<p>Configure email notifications for organization and user events</p>

					<div class="email-configuration">
						<!-- Email Enable/Disable -->
						<div class="setting-row">
							<label class="setting-label">
								<NcCheckboxRadioSwitch
									:checked="emailSettings.enabled"
									@update:checked="updateEmailSetting('enabled', $event)">
									Enable Email Notifications
								</NcCheckboxRadioSwitch>
							</label>
							<span class="setting-description">Enable or disable all email notifications</span>
						</div>

						<!-- Sender Configuration -->
						<div v-if="emailSettings.enabled" class="sender-configuration">
							<h4>Sender Information</h4>
							<div class="setting-row">
								<label class="setting-label">Sender Name:</label>
								<NcTextField
									:value="emailSettings.senderName || ''"
									placeholder="Software Catalogus"
									label="Sender Name"
									@update:value="updateEmailSetting('senderName', $event)" />
								<span class="setting-description">Name that appears as the sender of emails</span>
							</div>

							<div class="setting-row">
								<label class="setting-label">Sender Email:</label>
								<NcTextField
									:value="emailSettings.senderEmail || ''"
									placeholder="noreply@softwarecatalogus.nl"
									type="email"
									label="Sender Email"
									@update:value="updateEmailSetting('senderEmail', $event)" />
								<span class="setting-description">Email address that appears as the sender</span>
							</div>
						</div>

						<!-- Transport Configuration -->
						<div v-if="emailSettings.enabled" class="transport-configuration">
							<h4>Mail Transport Configuration</h4>
							<div class="setting-row">
								<label class="setting-label">Transport Type:</label>
								<NcSelect
									:value="emailSettings.transportType"
									:options="transportOptions"
									input-label="Transport Type"
									placeholder="Select transport type"
									@input="updateEmailSetting('transportType', $event)" />
								<span class="setting-description">Choose the email transport provider</span>
							</div>

							<!-- SMTP Configuration -->
							<div v-if="emailSettings.transportType === 'smtp'" class="smtp-configuration">
								<h5>SMTP Configuration</h5>
								<div class="setting-row">
									<label class="setting-label">SMTP Host:</label>
									<NcTextField
										:value="emailSettings.smtpHost || ''"
										placeholder="smtp.gmail.com"
										label="SMTP Host"
										@update:value="updateEmailSetting('smtpHost', $event)" />
								</div>
								<div class="setting-row">
									<label class="setting-label">SMTP Port:</label>
									<NcTextField
										:value="emailSettings.smtpPort || ''"
										placeholder="587"
										type="number"
										label="SMTP Port"
										@update:value="updateEmailSetting('smtpPort', parseInt($event))" />
								</div>
								<div class="setting-row">
									<label class="setting-label">Encryption:</label>
									<NcSelect
										:value="emailSettings.smtpEncryption"
										:options="encryptionOptions"
										input-label="Encryption"
										placeholder="Select encryption"
										@input="updateEmailSetting('smtpEncryption', $event)" />
								</div>
								<div class="setting-row">
									<label class="setting-label">Username:</label>
									<NcTextField
										:value="emailSettings.smtpUsername || ''"
										placeholder="your-email@gmail.com"
										label="SMTP Username"
										@update:value="updateEmailSetting('smtpUsername', $event)" />
								</div>
								<div class="setting-row">
									<label class="setting-label">Password:</label>
									<NcPasswordField
										:value="emailSettings.smtpPassword || ''"
										placeholder="your-password"
										label="SMTP Password"
										@update:value="updateEmailSetting('smtpPassword', $event)" />
								</div>
							</div>

							<!-- SendGrid Configuration -->
							<div v-if="emailSettings.transportType === 'sendgrid'" class="sendgrid-configuration">
								<h5>SendGrid Configuration</h5>
								<div class="setting-row">
									<label class="setting-label">API Key:</label>
									<NcPasswordField
										:value="emailSettings.sendgridApiKey || ''"
										placeholder="SG.xxxxx"
										label="SendGrid API Key"
										@update:value="updateEmailSetting('sendgridApiKey', $event)" />
								</div>
							</div>

							<!-- Mailgun Configuration -->
							<div v-if="emailSettings.transportType === 'mailgun'" class="mailgun-configuration">
								<h5>Mailgun Configuration</h5>
								<div class="setting-row">
									<label class="setting-label">API Key:</label>
									<NcPasswordField
										:value="emailSettings.mailgunApiKey || ''"
										placeholder="key-xxxxx"
										label="Mailgun API Key"
										@update:value="updateEmailSetting('mailgunApiKey', $event)" />
								</div>
								<div class="setting-row">
									<label class="setting-label">Domain:</label>
									<NcTextField
										:value="emailSettings.mailgunDomain || ''"
										placeholder="mg.yourdomain.com"
										label="Mailgun Domain"
										@update:value="updateEmailSetting('mailgunDomain', $event)" />
								</div>
							</div>

							<!-- Postmark Configuration -->
							<div v-if="emailSettings.transportType === 'postmark'" class="postmark-configuration">
								<h5>Postmark Configuration</h5>
								<div class="setting-row">
									<label class="setting-label">API Key:</label>
									<NcPasswordField
										:value="emailSettings.postmarkApiKey || ''"
										placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
										label="Postmark API Key"
										@update:value="updateEmailSetting('postmarkApiKey', $event)" />
								</div>
							</div>

							<!-- Amazon SES Configuration -->
							<div v-if="emailSettings.transportType === 'ses'" class="ses-configuration">
								<h5>Amazon SES Configuration</h5>
								<div class="setting-row">
									<label class="setting-label">Access Key:</label>
									<NcPasswordField
										:value="emailSettings.sesAccessKey || ''"
										placeholder="AKIAIOSFODNN7EXAMPLE"
										label="SES Access Key"
										@update:value="updateEmailSetting('sesAccessKey', $event)" />
								</div>
								<div class="setting-row">
									<label class="setting-label">Secret Key:</label>
									<NcPasswordField
										:value="emailSettings.sesSecretKey || ''"
										placeholder="wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY"
										label="SES Secret Key"
										@update:value="updateEmailSetting('sesSecretKey', $event)" />
								</div>
								<div class="setting-row">
									<label class="setting-label">Region:</label>
									<NcSelect
										:value="emailSettings.sesRegion"
										:options="sesRegionOptions"
										input-label="Region"
										placeholder="Select region"
										@input="updateEmailSetting('sesRegion', $event)" />
								</div>
							</div>

							<!-- Mailjet Configuration -->
							<div v-if="emailSettings.transportType === 'mailjet'" class="mailjet-configuration">
								<h5>Mailjet Configuration</h5>
								<div class="setting-row">
									<label class="setting-label">API Key:</label>
									<NcPasswordField
										:value="emailSettings.mailjetApiKey || ''"
										placeholder="xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
										label="Mailjet API Key"
										@update:value="updateEmailSetting('mailjetApiKey', $event)" />
								</div>
								<div class="setting-row">
									<label class="setting-label">Secret Key:</label>
									<NcPasswordField
										:value="emailSettings.mailjetSecretKey || ''"
										placeholder="xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
										label="Mailjet Secret Key"
										@update:value="updateEmailSetting('mailjetSecretKey', $event)" />
								</div>
							</div>
						</div>

						<!-- Test Configuration -->
						<div v-if="emailSettings.enabled" class="test-configuration">
							<h4>Testing Configuration</h4>
							<div class="setting-row">
								<label class="setting-label">Test Receiver Override:</label>
								<NcTextField
									:value="emailSettings.testReceiverOverride || ''"
									placeholder="test@example.com (optional)"
									type="email"
									label="Test Receiver Override"
									@update:value="updateEmailSetting('testReceiverOverride', $event)" />
								<span class="setting-description">If set, all emails will be sent to this address instead of the actual recipients (for testing)</span>
							</div>
						</div>

						<!-- Email Types Configuration -->
						<div v-if="emailSettings.enabled" class="email-types-configuration">
							<h4>Email Types</h4>
							<div class="setting-row">
								<label class="setting-label">
									<NcCheckboxRadioSwitch
										:checked="emailSettings.organizationRegistrationEnabled"
										@update:checked="updateEmailSetting('organizationRegistrationEnabled', $event)">
										Organization Registration Emails
									</NcCheckboxRadioSwitch>
								</label>
								<span class="setting-description">Send welcome emails when organizations are first registered</span>
							</div>

							<div class="setting-row">
								<label class="setting-label">
									<NcCheckboxRadioSwitch
										:checked="emailSettings.organizationActivationEnabled"
										@update:checked="updateEmailSetting('organizationActivationEnabled', $event)">
										Organization Activation Emails
									</NcCheckboxRadioSwitch>
								</label>
								<span class="setting-description">Send emails when organizations are activated (status set to "Actief")</span>
							</div>

							<div class="setting-row">
								<label class="setting-label">
									<NcCheckboxRadioSwitch
										:checked="emailSettings.userCreationEnabled"
										@update:checked="updateEmailSetting('userCreationEnabled', $event)">
										User Creation Emails
									</NcCheckboxRadioSwitch>
								</label>
								<span class="setting-description">Send welcome emails when user accounts are created</span>
							</div>

							<div class="setting-row">
								<label class="setting-label">
									<NcCheckboxRadioSwitch
										:checked="emailSettings.userPasswordEnabled"
										@update:checked="updateEmailSetting('userPasswordEnabled', $event)">
										User Password Emails
									</NcCheckboxRadioSwitch>
								</label>
								<span class="setting-description">Send separate emails with auto-generated passwords when user accounts are created</span>
							</div>
						</div>

						<!-- Email Testing -->
						<div v-if="emailSettings.enabled" class="email-testing">
							<h4>Email Testing</h4>
							<div class="test-email-row">
								<NcTextField
									:value="testEmailAddress || ''"
									placeholder="test@example.com"
									type="email"
									label="Test Email Address"
									@update:value="testEmailAddress = $event" />
								<NcButton
									type="secondary"
									:disabled="!testEmailAddress || testingEmail"
									@click="sendTestEmail">
									<template #icon>
										<NcLoadingIcon v-if="testingEmail" :size="20" />
										<Email v-else :size="20" />
									</template>
									Send Test Email
								</NcButton>
							</div>

							<div v-if="emailTestResult" class="test-result">
								<NcNoteCard :type="emailTestResult.success ? 'success' : 'error'">
									{{ emailTestResult.message }}
								</NcNoteCard>
							</div>
						</div>

						<!-- Save Email Settings -->
						<div class="email-save-buttons">
							<NcButton
								type="primary"
								:disabled="loading || savingEmailSettings"
								@click="saveEmailSettings">
								<template #icon>
									<NcLoadingIcon v-if="savingEmailSettings" :size="20" />
									<Save v-else :size="20" />
								</template>
								Save Email Settings
							</NcButton>
						</div>

						<div v-if="emailSaveResult" class="save-results">
							<NcNoteCard :type="emailSaveResult.success ? 'success' : 'error'">
								{{ emailSaveResult.message || 'Email settings saved successfully!' }}
							</NcNoteCard>
						</div>
					</div>
				</div>

				<!-- Email Templates Section -->
				<div class="email-templates-section">
					<h3>Email Templates</h3>
					<p>Customize email templates using Twig syntax</p>

					<div class="template-tabs">
						<div class="tab-buttons">
							<NcButton
								v-for="template in availableTemplates"
								:key="template.key"
								:type="activeTemplate === template.key ? 'primary' : 'secondary'"
								@click="activeTemplate = template.key">
								{{ template.name }}
							</NcButton>
						</div>

						<div v-if="activeTemplate" class="template-editor">
							<div class="template-info">
								<h4>{{ getActiveTemplateName() }}</h4>
								<p>{{ getActiveTemplateDescription() }}</p>
								<div class="available-variables">
									<h5>Available Variables:</h5>
									<div class="variables-list">
										<span
											v-for="(description, variable) in getActiveTemplateVariables()"
											:key="variable"
											class="variable-tag"
											@click="insertVariable(variable)">
											{{ formatTemplateVariable(variable) }}
										</span>
									</div>
								</div>
							</div>

							<NcTextArea
								:value="getActiveTemplateContent()"
								:placeholder="'Enter your template content here...'"
								label="Template Content"
								rows="15"
								@update:value="updateTemplateContent($event)" />

							<div class="template-actions">
								<NcButton
									type="secondary"
									@click="resetTemplate">
									Reset to Default
								</NcButton>
								<NcButton
									type="primary"
									:disabled="loading || savingTemplate"
									@click="saveTemplate">
									<template #icon>
										<NcLoadingIcon v-if="savingTemplate" :size="20" />
										<Save v-else :size="20" />
									</template>
									Save Template
								</NcButton>
							</div>

							<div v-if="templateSaveResult" class="save-results">
								<NcNoteCard :type="templateSaveResult.success ? 'success' : 'error'">
									{{ templateSaveResult.message || 'Template saved successfully!' }}
								</NcNoteCard>
							</div>
						</div>
					</div>
				</div>
			</div>
		</NcSettingsSection>
	</div>
</template>

<script>
import { defineComponent } from 'vue'
import {
	NcSettingsSection,
	NcNoteCard,
	NcSelect,
	NcButton,
	NcLoadingIcon,
	NcTextField,
	NcPasswordField,
	NcCheckboxRadioSwitch,
	NcTextArea,
} from '@nextcloud/vue'
import Save from 'vue-material-design-icons/ContentSave.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import Alert from 'vue-material-design-icons/Alert.vue'
import Close from 'vue-material-design-icons/Close.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Email from 'vue-material-design-icons/Email.vue'
import Sync from 'vue-material-design-icons/Sync.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import Upload from 'vue-material-design-icons/Upload.vue'
import Download from 'vue-material-design-icons/Download.vue'
import CloudUpload from 'vue-material-design-icons/CloudUpload.vue'

/**
 * Software Catalog Settings component
 *
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @version  1.0.0
 */
export default defineComponent({
	name: 'SoftwareCatalogSettings',
	components: {
		NcSettingsSection,
		NcNoteCard,
		NcSelect,
		NcButton,
		NcLoadingIcon,
		NcTextField,
		NcPasswordField,
		NcCheckboxRadioSwitch,
		NcTextArea,
		Save,
		Refresh,
		Cog,
		Alert,
		Close,
		Plus,
		Email,
		Sync,
		CheckCircle,
		Upload,
		Download,
		CloudUpload,
	},

	/**
	 * Component data
	 *
	 * @return {object} Component data
	 */
	data() {
		return {
			loading: true,
			saving: false,
			savingSchemas: false,
			schemaSaveResult: null,
			initializing: false,
			autoConfiguring: false,
			initializationResults: null,
			loadingVersionInfo: true,
			importing: false,
			settings: {
				objectTypes: [],
				openRegisters: false,
				availableRegisters: [],
				configuration: {},
			},
			selectedRegister: null, // Keeping for backward compatibility
			voorzieningenRegister: null,
			amefRegister: null,
			voorzieningenSchemas: [],
			amefSchemas: [],
			configuration: {},
			schemaOptions: [],
			debugInfo: null,
			genericUserGroups: [],
			groupValidation: null,
			groupsSaveResult: null,
			savingGroups: false,
			// Organization admin groups
			organizationAdminGroups: [],
			organizationAdminGroupsSaveResult: null,
			savingOrganizationAdminGroups: false,
			// Super user groups
			superUserGroups: [],
			superUserGroupsSaveResult: null,
			savingSuperUserGroups: false,
			// Email-related data
			emailSettings: {
				enabled: false,
				senderEmail: '',
				senderName: '',
				testReceiverOverride: '',
				organizationRegistrationEnabled: true,
				organizationActivationEnabled: true,
				userCreationEnabled: true,
				userPasswordEnabled: true,
				// Transport configuration
				transportType: 'smtp',
				// SMTP configuration
				smtpHost: 'localhost',
				smtpPort: 587,
				smtpEncryption: 'tls',
				smtpUsername: '',
				smtpPassword: '',
				// SendGrid configuration
				sendgridApiKey: '',
				// Mailgun configuration
				mailgunApiKey: '',
				mailgunDomain: '',
				// Postmark configuration
				postmarkApiKey: '',
				// Amazon SES configuration
				sesAccessKey: '',
				sesSecretKey: '',
				sesRegion: 'us-east-1',
				// Mailjet configuration
				mailjetApiKey: '',
				mailjetSecretKey: '',
			},
			savingEmailSettings: false,
			emailSaveResult: null,
			testEmailAddress: '',
			testingEmail: false,
			emailTestResult: null,
			// Template-related data
			availableTemplates: [
				{ key: 'organization_registration', name: 'Organization Registration' },
				{ key: 'organization_activation', name: 'Organization Activation' },
				{ key: 'user_creation', name: 'User Creation' },
				{ key: 'user_password', name: 'User Password' },
			],
			activeTemplate: 'organization_registration',
			templates: {},
			savingTemplate: false,
			templateSaveResult: null,
			// Sync-related data
			syncStatus: null,
			loadingSyncStatus: false,
			performingSync: false,
			syncResult: null,
			selectedTimeWindow: { value: 10, label: '10 minutes' },
			// Version-related data
			versionInfo: {
				appName: '',
				appVersion: '',
				configuredVersion: null,
				versionsMatch: false,
				needsUpdate: false,
			},
			importResult: null,
			consolidatedResult: null,
			resettingAutoConfig: false,
			resetAutoConfigResult: null,
			// ArchiMate-related data
			selectedFile: null,
			exporting: false,
			testingRoundTrip: false,
			exportResult: null,
			importOptions: {
				updateExisting: true,
				deleteOrphaned: false,
			},
			exportOptions: {
				format: 'xml',
				organizationSpecific: false,
				organizationId: '',
				organizationFilter: '',
				selectedSchemas: [],
				includeRelationships: true,
				includeViews: false,
			},
			roundTripResult: null,
			// AMEF Register Configuration data (now handled through unified configuration)
			autoConfiguringAmef: false,
			amefConfigResults: null,
			archimateStatus: {
				import: {
					status: null,
					current_step: null,
					progress: null,
					statistics: null,
				},
				export: {
					status: null,
					current_step: null,
					progress: null,
					statistics: null,
				},
			},
			isImportRunning: false,
			isExportRunning: false,
			statusPollingInterval: null,
		}
	},

	computed: {
		/**
		 * Generates options for register selection dropdown
		 *
		 * @return {Array<object>} Array of register options with label and value
		 */
		registerOptions() {
			// Filter out duplicates by ID to prevent Vue key errors
			const uniqueRegisters = this.settings.availableRegisters.filter((register, index, arr) =>
				arr.findIndex(r => r.id === register.id) === index,
			)
			const options = uniqueRegisters.map(register => ({
				label: register.title,
				value: register.id.toString(),
			}))

			// Additional check for duplicate values in the final options
			const uniqueOptions = options.filter((option, index, arr) =>
				arr.findIndex(o => o.value === option.value) === index,
			)

			return uniqueOptions
		},

		/**
		 * Determines if any selected register has schemas
		 *
		 * @return {boolean} True if any selected register has schemas
		 */
		hasSchemas() {
			// Check if Voorzieningen register has schemas
			if (this.voorzieningenRegister) {
				const voorzieningenRegisterData = this.settings.availableRegisters.find(
					r => r.id.toString() === this.voorzieningenRegister.value,
				)
				if (voorzieningenRegisterData && Array.isArray(voorzieningenRegisterData.schemas) && voorzieningenRegisterData.schemas.length > 0) {
					return true
				}
			}

			// Check if AMEF register has schemas
			if (this.amefRegister) {
				const amefRegisterData = this.settings.availableRegisters.find(
					r => r.id.toString() === this.amefRegister.value,
				)
				if (amefRegisterData && Array.isArray(amefRegisterData.schemas) && amefRegisterData.schemas.length > 0) {
					return true
				}
			}

			return false
		},

		/**
		 * Returns all available schema options (without filtering used ones for software catalog)
		 *
		 * @return {Array<object>} Array of available schema options
		 */
		availableSchemaOptions() {
			return this.schemaOptions
		},

		/**
		 * Returns schema options for Voorzieningen register
		 *
		 * @return {Array<object>} Array of schema options for Voorzieningen register
		 */
		voorzieningenSchemaOptions() {
			// Filter out duplicates by ID to prevent Vue key errors
			const uniqueSchemas = this.voorzieningenSchemas.filter((schema, index, arr) =>
				arr.findIndex(s => s.id === schema.id) === index,
			)
			const options = uniqueSchemas.map(schema => ({
				label: schema.title,
				value: schema.id.toString(),
			}))

			// Additional check for duplicate values in the final options
			return options.filter((option, index, arr) =>
				arr.findIndex(o => o.value === option.value) === index,
			)
		},

		/**
		 * Returns schema options for AMEF register
		 *
		 * @return {Array<object>} Array of schema options for AMEF register
		 */
		amefSchemaOptions() {
			// Filter out duplicates by ID to prevent Vue key errors
			const uniqueSchemas = this.amefSchemas.filter((schema, index, arr) =>
				arr.findIndex(s => s.id === schema.id) === index,
			)
			const options = uniqueSchemas.map(schema => ({
				label: schema.title,
				value: schema.id.toString(),
			}))

			// Additional check for duplicate values in the final options
			return options.filter((option, index, arr) =>
				arr.findIndex(o => o.value === option.value) === index,
			)
		},

		/**
		 * Determines if configuration can be saved
		 *
		 * @return {boolean} True if configuration is valid and can be saved
		 */
		canSave() {
			// Check if at least one register is selected
			if (!this.voorzieningenRegister && !this.amefRegister) return false

			// Check if at least one schema is configured for selected registers
			let hasValidConfiguration = false

			// Check AMEF configuration if AMEF register is selected
			if (this.amefRegister) {
				if (this.configuration.amef_organization?.schema) {
					hasValidConfiguration = true
				}
			}

			// Check Voorzieningen configuration if Voorzieningen register is selected
			if (this.voorzieningenRegister) {
				if (this.configuration.voorzieningen_gebruiker?.schema
					|| this.configuration.voorzieningen_organisatie?.schema
					|| this.configuration.voorzieningen_contactpersoon?.schema) {
					hasValidConfiguration = true
				}
			}

			return hasValidConfiguration
		},

		/**
		 * Determines if there are schema values to save specifically for Voorzieningen register
		 *
		 * @return {boolean} True if schema values can be saved
		 */
		hasSchemaValuesToSave() {
			if (!this.voorzieningenRegister) {
				return false
			}

			// Check if at least organisatie or contactpersoon schema is configured
			return this.configuration.voorzieningen_organisatie?.schema
				|| this.configuration.voorzieningen_contactpersoon?.schema
		},

		/**
		 * Available AMEF schemas for export - dynamically built from configuration
		 *
		 * @return {Array<object>} Array of schema options
		 */
		availableSchemas() {
			// Try to get schemas from AMEF register configuration
			const configuredSchemas = []

			// Check consolidated configuration first
			if (this.settings.consolidatedConfig?.amef) {
				const amefConfig = this.settings.consolidatedConfig.amef

				if (amefConfig.elements_schema) {
					configuredSchemas.push({
						label: `Elements (Schema ${amefConfig.elements_schema})`,
						value: parseInt(amefConfig.elements_schema),
					})
				}
				if (amefConfig.organizations_schema) {
					configuredSchemas.push({
						label: `Organizations (Schema ${amefConfig.organizations_schema})`,
						value: parseInt(amefConfig.organizations_schema),
					})
				}
				if (amefConfig.relationships_schema) {
					configuredSchemas.push({
						label: `Relationships (Schema ${amefConfig.relationships_schema})`,
						value: parseInt(amefConfig.relationships_schema),
					})
				}
				if (amefConfig.views_schema) {
					configuredSchemas.push({
						label: `Views (Schema ${amefConfig.views_schema})`,
						value: parseInt(amefConfig.views_schema),
					})
				}
			}

			// Fallback to legacy configuration format
			if (configuredSchemas.length === 0 && this.configuration) {
				if (this.configuration.amef_elements?.schema) {
					configuredSchemas.push({
						label: `Elements (Schema ${this.configuration.amef_elements.schema.value})`,
						value: this.configuration.amef_elements.schema.value,
					})
				}
				if (this.configuration.amef_organization?.schema) {
					configuredSchemas.push({
						label: `Organizations (Schema ${this.configuration.amef_organization.schema.value})`,
						value: this.configuration.amef_organization.schema.value,
					})
				}
				if (this.configuration.amef_relationships?.schema) {
					configuredSchemas.push({
						label: `Relationships (Schema ${this.configuration.amef_relationships.schema.value})`,
						value: this.configuration.amef_relationships.schema.value,
					})
				}
				if (this.configuration.amef_views?.schema) {
					configuredSchemas.push({
						label: `Views (Schema ${this.configuration.amef_views.schema.value})`,
						value: this.configuration.amef_views.schema.value,
					})
				}
			}

			// If still no schemas found, try to get from selected AMEF register schemas
			if (configuredSchemas.length === 0 && this.amefRegister) {
				const amefRegisterData = this.settings.availableRegisters.find(
					r => r.id.toString() === this.amefRegister.value,
				)
				if (amefRegisterData?.schemas) {
					return amefRegisterData.schemas.map(schema => ({
						label: `${schema.title} (Schema ${schema.id})`,
						value: schema.id,
					}))
				}
			}

			// Final fallback: return empty array if no configuration available
			// This prevents hardcoded IDs and forces proper configuration
			return configuredSchemas
		},

		/**
		 * Transport type options for email configuration
		 *
		 * @return {Array<object>} Array of transport options
		 */
		transportOptions() {
			return [
				{ value: 'smtp', label: 'SMTP Server' },
				{ value: 'sendmail', label: 'Sendmail' },
				{ value: 'native', label: 'Native PHP Mail' },
				{ value: 'null', label: 'Null (No Emails)' },
				{ value: 'sendgrid', label: 'SendGrid' },
				{ value: 'mailgun', label: 'Mailgun' },
				{ value: 'postmark', label: 'Postmark' },
				{ value: 'ses', label: 'Amazon SES' },
				{ value: 'mailjet', label: 'Mailjet' },
			]
		},

		/**
		 * SMTP encryption options
		 *
		 * @return {Array<object>} Array of encryption options
		 */
		encryptionOptions() {
			return [
				{ value: 'tls', label: 'TLS' },
				{ value: 'ssl', label: 'SSL' },
				{ value: 'none', label: 'None' },
			]
		},

		/**
		 * Format options for ArchiMate export
		 *
		 * @return {Array<object>} Array of format options
		 */
		formatOptions() {
			return [
				{ value: 'xml', label: 'XML (.xml)' },
				{ value: 'json', label: 'JSON (.json)' },
			]
		},

		/**
		 * Amazon SES region options
		 *
		 * @return {Array<object>} Array of SES region options
		 */
		sesRegionOptions() {
			return [
				{ value: 'us-east-1', label: 'US East (N. Virginia)' },
				{ value: 'us-east-2', label: 'US East (Ohio)' },
				{ value: 'us-west-1', label: 'US West (N. California)' },
				{ value: 'us-west-2', label: 'US West (Oregon)' },
				{ value: 'eu-west-1', label: 'Europe (Ireland)' },
				{ value: 'eu-west-2', label: 'Europe (London)' },
				{ value: 'eu-west-3', label: 'Europe (Paris)' },
				{ value: 'eu-central-1', label: 'Europe (Frankfurt)' },
				{ value: 'ap-southeast-1', label: 'Asia Pacific (Singapore)' },
				{ value: 'ap-southeast-2', label: 'Asia Pacific (Sydney)' },
				{ value: 'ap-northeast-1', label: 'Asia Pacific (Tokyo)' },
				{ value: 'ap-northeast-2', label: 'Asia Pacific (Seoul)' },
				{ value: 'ap-south-1', label: 'Asia Pacific (Mumbai)' },
				{ value: 'ca-central-1', label: 'Canada (Central)' },
				{ value: 'sa-east-1', label: 'South America (São Paulo)' },
			]
		},

		/**
		 * Time window options for incremental synchronization
		 *
		 * @return {Array<object>} Array of time window options
		 */
		timeWindowOptions() {
			return [
				{ value: 5, label: '5 minutes' },
				{ value: 10, label: '10 minutes' },
				{ value: 30, label: '30 minutes' },
				{ value: 60, label: '1 hour' },
				{ value: 720, label: '12 hours' },
				{ value: 1440, label: '1 day' },
				{ value: 10080, label: '1 week' },
				{ value: 43200, label: '1 month' },
				{ value: 525600, label: '1 year' },
				{ value: 0, label: 'All time (full sync)' },
			]
		},
	},

	watch: {
		/**
		 * Watch for changes to the selected register
		 *
		 * @param {object} newRegister - The newly selected register
		 * @param {object} oldRegister - The previously selected register
		 */
		// Removed selectedRegister watcher - now using separate voorzieningenRegister and amefRegister
		// The register changes are handled by handleVoorzieningenRegisterChange and handleAmefRegisterChange methods
	},

	/**
	 * Lifecycle hook that loads settings when component is created
	 */
	async created() {
		await Promise.all([
			this.loadSettings(),
			this.loadSyncStatus(),
			this.loadVersionInfo(),
			this.fetchArchiMateStatus(),
		])

		// Initialize export options with default values
		this.initializeExportOptions()
	},

	beforeDestroy() {
		// Clean up progress tracking
		this.stopProgressTracking()
		// Clean up status polling
		this.stopStatusPolling()
	},

	methods: {
		/**
		 * Handles Voorzieningen register selection change
		 *
		 * @param {object} register Selected register option
		 */
		handleVoorzieningenRegisterChange(register) {
			if (register) {
				const selectedRegister = this.settings.availableRegisters.find(
					r => r.id.toString() === register.value,
				)
				this.voorzieningenSchemas = selectedRegister?.schemas || []
			} else {
				this.voorzieningenSchemas = []
			}
		},

		/**
		 * Handles AMEF register selection change
		 *
		 * @param {object} register Selected register option
		 */
		handleAmefRegisterChange(register) {
			if (register) {
				const selectedRegister = this.settings.availableRegisters.find(
					r => r.id.toString() === register.value,
				)
				this.amefSchemas = selectedRegister?.schemas || []
			} else {
				this.amefSchemas = []
			}
		},

		/**
		 * Populates register selections from existing configuration (new JSON format only)
		 */
		populateRegisterSelections() {
			// Try to determine which registers are being used based on configuration
			const config = this.settings.configuration || {}

			// Check for Voorzieningen register usage (new JSON format)
			if (config.voorzieningen && typeof config.voorzieningen === 'string') {
				try {
					const voorzieningenConfig = JSON.parse(config.voorzieningen)
					const voorzieningenRegisterId = voorzieningenConfig.register?.toString()

					if (voorzieningenRegisterId) {
						const voorzieningenRegister = this.settings.availableRegisters.find(
							r => r.id.toString() === voorzieningenRegisterId,
						)
						if (voorzieningenRegister) {
							this.voorzieningenRegister = {
								label: voorzieningenRegister.title,
								value: voorzieningenRegister.id.toString(),
							}
							this.voorzieningenSchemas = voorzieningenRegister.schemas || []
						}
					}
				} catch (e) {
					// Invalid JSON format, skip
				}
			}

			// Check for AMEF register usage (new JSON format)
			if (config.amef && typeof config.amef === 'string') {
				try {
					const amefConfig = JSON.parse(config.amef)
					const amefRegisterId = amefConfig.register?.toString()

					if (amefRegisterId) {
						const amefRegister = this.settings.availableRegisters.find(
							r => r.id.toString() === amefRegisterId,
						)
						if (amefRegister) {
							this.amefRegister = {
								label: amefRegister.title,
								value: amefRegister.id.toString(),
							}
							this.amefSchemas = amefRegister.schemas || []
						}
					}
				} catch (e) {
					// Invalid JSON format, skip
				}
			}

			// If no specific registers found, try to auto-detect based on schema configuration
			if (!this.voorzieningenRegister && !this.amefRegister) {
				this.autoDetectRegistersFromSchemas()
			}
		},

		/**
		 * Auto-detects register usage from schema configuration (new JSON format only)
		 */
		autoDetectRegistersFromSchemas() {
			const config = this.settings.configuration || {}

			// Check for Voorzieningen-specific schemas in JSON format
			if (config.voorzieningen && typeof config.voorzieningen === 'string') {
				try {
					const voorzieningenConfig = JSON.parse(config.voorzieningen)
					const organisatieSchema = voorzieningenConfig.organisatie_schema
					const contactpersoonSchema = voorzieningenConfig.contactpersoon_schema

					if (organisatieSchema || contactpersoonSchema) {
						// Find a register that contains these schemas
						for (const register of this.settings.availableRegisters) {
							const schemas = register.schemas || []
							const hasVoorzieningenSchemas = schemas.some(schema =>
								schema.id.toString() === organisatieSchema?.toString()
								|| schema.id.toString() === contactpersoonSchema?.toString(),
							)
							if (hasVoorzieningenSchemas) {
								this.voorzieningenRegister = {
									label: register.title,
									value: register.id.toString(),
								}
								this.voorzieningenSchemas = register.schemas || []
								break
							}
						}
					}
				} catch (e) {
					// Invalid JSON format, skip
				}
			}

			// Check for AMEF-specific schemas in JSON format
			if (config.amef && typeof config.amef === 'string') {
				try {
					const amefConfig = JSON.parse(config.amef)
					const elementsSchema = amefConfig.elements_schema
					const organizationSchema = amefConfig.organization_schema

					if (elementsSchema || organizationSchema) {
						// Find a register that contains these schemas
						for (const register of this.settings.availableRegisters) {
							const schemas = register.schemas || []
							const hasAmefSchemas = schemas.some(schema =>
								schema.id.toString() === elementsSchema?.toString()
								|| schema.id.toString() === organizationSchema?.toString(),
							)
							if (hasAmefSchemas) {
								this.amefRegister = {
									label: register.title,
									value: register.id.toString(),
								}
								this.amefSchemas = register.schemas || []
								break
							}
						}
					}
				} catch (e) {
					// Invalid JSON format, skip
				}
			}
		},

		/**
		 * Loads version information from the backend
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		async loadVersionInfo() {
			try {
				const response = await fetch('/index.php/apps/softwarecatalog/api/settings/version')
				if (!response.ok) {
					throw new Error(`HTTP ${response.status}: ${response.statusText}`)
				}
				const data = await response.json()

				if (data.error) {
					console.error('Failed to load version info:', data.error)
				} else if (data.message === '') {
					console.error('Empty response from version API - likely uncaught exception')
					// Set some default version info so the UI can still function
					this.versionInfo = {
						appName: 'SoftwareCatalog',
						appVersion: 'Unknown',
						configuredVersion: null,
						versionsMatch: false,
						needsUpdate: true,
					}
				} else {
					this.versionInfo = data
				}

				this.loadingVersionInfo = false
			} catch (error) {
				console.error('Failed to load version info:', error)
				// Set default values so the UI can still function
				this.versionInfo = {
					appName: 'SoftwareCatalog',
					appVersion: 'Unknown',
					configuredVersion: null,
					versionsMatch: false,
					needsUpdate: true,
				}
				this.loadingVersionInfo = false
			}
		},

		/**
		 * Manually trigger configuration import
		 *
		 * @param {boolean} force Whether to force the import
		 * @async
		 * @return {Promise<void>}
		 */
		async manualImport(force = false) {
			this.importing = true
			this.importResult = null

			try {
				const response = await fetch('/index.php/apps/softwarecatalog/api/settings/import', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({ force }),
				})

				const result = await response.json()
				this.importResult = result

				// If successful, update version info and reload settings
				if (result.success) {
					await Promise.all([
						this.loadVersionInfo(),
						this.loadSettings(),
					])
					// If auto-configuration was successful, show additional success info
					if (result.autoConfigResult && Object.keys(result.autoConfigResult).length > 0) {
						// console.log('Auto-configuration completed:', result.autoConfigResult)
						// Show a more detailed success message
						this.importResult = {
							...result,
							message: result.message + ' Auto-configured voorzieningen register with organisatie and contactpersoon schemas.',
						}
					}
				}
			} catch (error) {
				console.error('Failed to perform manual import:', error)
				this.importResult = {
					success: false,
					message: 'Import failed: ' + error.message,
				}
			} finally {
				this.importing = false
			}
		},

		/**
		 * Consolidated auto-configuration that handles everything
		 *
		 * This method calls the new consolidated endpoint that handles:
		 * - Configuration file loading
		 * - Voorzieningen register setup
		 * - AMEF register setup
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		async consolidatedAutoConfigure() {
			this.autoConfiguring = true
			this.consolidatedResult = null
			this.importResult = null // Clear old import results

			try {
				const response = await fetch('/index.php/apps/softwarecatalog/api/settings/consolidated-auto-configure', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
				})

				const result = await response.json()
				this.consolidatedResult = result

				// If successful, update version info and reload settings
				if (result.success || result.steps) {
					await Promise.all([
						this.loadVersionInfo(),
						this.loadSettings(),
					])

					// Show success message even if there were some warnings
					if (result.success) {
						// Auto-configuration completed successfully
					} else if (result.steps) {
						// Auto-configuration completed with some issues
					}
				}
			} catch (error) {
				console.error('Failed to perform consolidated auto-configuration:', error)
				this.consolidatedResult = {
					success: false,
					message: 'Auto-configuration failed: ' + error.message,
					errors: [error.message],
				}
			} finally {
				this.autoConfiguring = false
			}
		},

		/**
		 * Loads settings from the backend API
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		async loadSettings() {
			this.loading = true
			try {
				const response = await fetch('/index.php/apps/softwarecatalog/api/settings')

				// Check if response is ok first
				if (!response.ok) {
					throw new Error(`HTTP ${response.status}: ${response.statusText}`)
				}

				const data = await response.json()

				// Handle different error response formats
				if (data.error) {
					console.error('Settings API error:', data.error)
					if (data.type === 'runtime_error') {
						console.error('Runtime error - likely OpenRegister dependency issue')
					}
					// Still continue to use defaults if possible
					this.settings = {
						objectTypes: ['organization', 'contact'],
						openRegisters: false,
						availableRegisters: [],
						configuration: {},
					}
				} else if (data.message === '') {
					// Handle the empty message case
					console.error('Empty response from settings API - likely uncaught exception')
					this.settings = {
						objectTypes: ['organization', 'contact'],
						openRegisters: false,
						availableRegisters: [],
						configuration: {},
					}
				} else {
					this.settings = data
				}

				// Load user groups from the unified response
				if (data.userGroups) {
					this.genericUserGroups = data.userGroups.generic || ['Aanbod-beheerder', 'Gebruik-beheerder', 'Gebruik-raadpleger', 'Functioneel-beheerder', 'VNG-raadpleger', 'Organisatie-beheerder', 'Bezoeker']
					this.organizationAdminGroups = data.userGroups.organizationAdmin || ['organisaties-beheerder']
					this.superUserGroups = data.userGroups.superUser || ['admin', 'software-catalog-admins']
				} else {
					// Fallback to defaults
					this.genericUserGroups = ['Aanbod-beheerder', 'Gebruik-beheerder', 'Gebruik-raadpleger', 'Functioneel-beheerder', 'VNG-raadpleger', 'Organisatie-beheerder', 'Bezoeker']
					this.organizationAdminGroups = ['organisaties-beheerder']
					this.superUserGroups = ['admin', 'software-catalog-admins']
				}

				// Load email settings from the unified response
				if (data.emailSettings) {
					this.emailSettings = {
						enabled: data.emailSettings.enabled ?? false,
						senderEmail: data.emailSettings.senderEmail ?? '',
						senderName: data.emailSettings.senderName ?? '',
						testReceiverOverride: data.emailSettings.testReceiverOverride ?? '',
						organizationRegistrationEnabled: data.emailSettings.organizationRegistrationEnabled ?? true,
						organizationActivationEnabled: data.emailSettings.organizationActivationEnabled ?? true,
						userCreationEnabled: data.emailSettings.userCreationEnabled ?? true,
						userPasswordEnabled: data.emailSettings.userPasswordEnabled ?? true,
						// Transport configuration
						transportType: data.emailSettings.transportType ?? 'smtp',
						// SMTP configuration
						smtpHost: data.emailSettings.smtpHost ?? 'localhost',
						smtpPort: data.emailSettings.smtpPort ?? 587,
						smtpEncryption: data.emailSettings.smtpEncryption ?? 'tls',
						smtpUsername: data.emailSettings.smtpUsername ?? '',
						smtpPassword: data.emailSettings.smtpPassword ?? '',
						// SendGrid configuration
						sendgridApiKey: data.emailSettings.sendgridApiKey ?? '',
						// Mailgun configuration
						mailgunApiKey: data.emailSettings.mailgunApiKey ?? '',
						mailgunDomain: data.emailSettings.mailgunDomain ?? '',
						// Postmark configuration
						postmarkApiKey: data.emailSettings.postmarkApiKey ?? '',
						// Amazon SES configuration
						sesAccessKey: data.emailSettings.sesAccessKey ?? '',
						sesSecretKey: data.emailSettings.sesSecretKey ?? '',
						sesRegion: data.emailSettings.sesRegion ?? 'us-east-1',
						// Mailjet configuration
						mailjetApiKey: data.emailSettings.mailjetApiKey ?? '',
						mailjetSecretKey: data.emailSettings.mailjetSecretKey ?? '',
					}
				}

				this.initializeConfiguration()
				this.populateRegisterSelections()

				// Load debug information
				await this.loadDebugInfo()
			} catch (error) {
				console.error('Failed to load settings:', error)
				// Set defaults to allow the component to function
				this.settings = {
					objectTypes: ['organization', 'contact'],
					openRegisters: false,
					availableRegisters: [],
					configuration: {},
				}
			} finally {
				this.loading = false
			}
		},

		/**
		 * Loads all user groups from the unified settings API
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		async loadGenericUserGroups() {
			// This method is now handled by loadSettings() which loads all data at once
			// Keep this method for backward compatibility but make it a no-op
		},

		/**
		 * Loads debug information from the backend API
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		async loadDebugInfo() {
			try {
				const response = await fetch('/index.php/apps/softwarecatalog/api/settings/debug')
				if (!response.ok) {
					throw new Error(`HTTP ${response.status}: ${response.statusText}`)
				}
				const data = await response.json()

				if (data.error) {
					this.debugInfo = { error: data.error }
				} else if (data.message === '') {
					// Handle empty message response which indicates uncaught exception
					this.debugInfo = {
						error: 'Empty response from debug API - likely uncaught exception in backend',
						suggestion: 'Check server logs or try running manual import/initialization',
					}
				} else {
					this.debugInfo = data
				}
			} catch (error) {
				this.debugInfo = {
					error: 'Failed to load debug information: ' + error.message,
					suggestion: 'Check if the SoftwareCatalog app is properly installed and OpenRegister is available',
				}
			}
		},

		/**
		 * Initializes the configuration object based on existing settings
		 */
		initializeConfiguration() {
			// Initialize register-specific configuration
			this.configuration = {
				// AMEF register configuration
				amef_elements: {
					schema: null,
				},
				amef_organization: {
					schema: null,
				},
				amef_relationships: {
					schema: null,
				},
				amef_views: {
					schema: null,
				},
				// Voorzieningen register configuration
				voorzieningen_gebruiker: {
					schema: null,
				},
				voorzieningen_organisatie: {
					schema: null,
				},
				voorzieningen_contactpersoon: {
					schema: null,
				},
			}

			// Create empty configuration for each generic object type (backward compatibility)
			this.settings.objectTypes.forEach(type => {
				this.configuration = {
					...this.configuration,
					[type]: {
						schema: null,
					},
				}
			})

			// Handle existing configuration for register-specific schemas
			const configKeys = [
				'amef_elements',
				'amef_organization',
				'amef_relationships',
				'amef_views',
				'voorzieningen_gebruiker',
				'voorzieningen_organisatie',
				'voorzieningen_contactpersoon',
			]

			configKeys.forEach(configKey => {
				const registerId = this.settings.configuration[`${configKey}_register`] || ''
				const schemaId = this.settings.configuration[`${configKey}_schema`] || ''

				// If we have existing configuration, use it to set the selected register
				if (registerId && !this.selectedRegister) {
					const register = this.settings.availableRegisters.find(r => r.id.toString() === registerId)
					if (register) {
						this.selectedRegister = {
							label: register.title,
							value: register.id.toString(),
						}
						this.updateSchemaOptions(register.id.toString())
					}
				}

				// If we have a schema configured, set it
				if (schemaId && this.selectedRegister) {
					const register = this.settings.availableRegisters.find(
						r => r.id.toString() === this.selectedRegister.value,
					)
					if (register && Array.isArray(register.schemas)) {
						const schema = register.schemas.find(s => s.id.toString() === schemaId)
						if (schema) {
							this.configuration = {
								...this.configuration,
								[configKey]: {
									...this.configuration[configKey],
									schema: {
										label: schema.title,
										value: schema.id.toString(),
									},
								},
							}
						}
					}
				}
			})

			// Handle backward compatibility for generic object types
			this.settings.objectTypes.forEach(type => {
				const registerId = this.settings.configuration[`${type}_register`] || ''
				const schemaId = this.settings.configuration[`${type}_schema`] || ''

				// If we have existing configuration, use it to set the selected register
				if (registerId && !this.selectedRegister) {
					const register = this.settings.availableRegisters.find(r => r.id.toString() === registerId)
					if (register) {
						this.selectedRegister = {
							label: register.title,
							value: register.id.toString(),
						}
						this.updateSchemaOptions(register.id.toString())
					}
				}

				// If we have a schema configured, set it
				if (schemaId && this.selectedRegister) {
					const register = this.settings.availableRegisters.find(
						r => r.id.toString() === this.selectedRegister.value,
					)
					if (register && Array.isArray(register.schemas)) {
						const schema = register.schemas.find(s => s.id.toString() === schemaId)
						if (schema) {
							this.configuration = {
								...this.configuration,
								[type]: {
									...this.configuration[type],
									schema: {
										label: schema.title,
										value: schema.id.toString(),
									},
								},
							}
						}
					}
				}
			})
		},

		/**
		 * Automatically selects a suitable register
		 */
		autoSelectRegister() {
			if (this.settings.availableRegisters.length > 0 && !this.selectedRegister) {
				// Select the first available register
				const firstRegister = this.settings.availableRegisters[0]
				this.selectedRegister = {
					label: firstRegister.title,
					value: firstRegister.id.toString(),
				}
				this.updateSchemaOptions(firstRegister.id.toString())

				// Try to auto-select matching schemas
				if (Array.isArray(firstRegister.schemas)) {
					this.autoSelectMatchingSchemas(firstRegister)
				}
			}
		},

		/**
		 * Auto-selects schemas that match object type names
		 *
		 * @param {object} register - The selected register object
		 */
		autoSelectMatchingSchemas(register) {
			if (!register || !Array.isArray(register.schemas)) {
				return
			}

			// Handle register-specific auto-selection
			if (this.isRegisterType('amef')) {
				// For AMEF register, look for organization schema
				const orgSchema = register.schemas.find(
					schema => schema.title.toLowerCase().includes('organization'),
				)
				if (orgSchema) {
					this.configuration = {
						...this.configuration,
						amef_organization: {
							...this.configuration.amef_organization,
							schema: {
								label: orgSchema.title,
								value: orgSchema.id.toString(),
							},
						},
					}
				}
			} else if (this.isRegisterType('voorzieningen')) {
				// For Voorzieningen register, look for gebruiker and organisatie schemas
				const gebruikerSchema = register.schemas.find(
					schema => schema.title.toLowerCase().includes('gebruiker'),
				)
				if (gebruikerSchema) {
					this.configuration = {
						...this.configuration,
						voorzieningen_gebruiker: {
							...this.configuration.voorzieningen_gebruiker,
							schema: {
								label: gebruikerSchema.title,
								value: gebruikerSchema.id.toString(),
							},
						},
					}
				}

				const organisatieSchema = register.schemas.find(
					schema => schema.title.toLowerCase().includes('organisatie'),
				)
				if (organisatieSchema) {
					this.configuration = {
						...this.configuration,
						voorzieningen_organisatie: {
							...this.configuration.voorzieningen_organisatie,
							schema: {
								label: organisatieSchema.title,
								value: organisatieSchema.id.toString(),
							},
						},
					}
				}

				const contactpersoonSchema = register.schemas.find(
					schema => schema.title.toLowerCase().includes('contactpersoon'),
				)
				if (contactpersoonSchema) {
					this.configuration = {
						...this.configuration,
						voorzieningen_contactpersoon: {
							...this.configuration.voorzieningen_contactpersoon,
							schema: {
								label: contactpersoonSchema.title,
								value: contactpersoonSchema.id.toString(),
							},
						},
					}
				}
			} else {
				// Generic auto-selection for backward compatibility
				this.settings.objectTypes.forEach(type => {
					// Look for a schema with the same name as the object type
					const matchingSchema = register.schemas.find(
						schema => schema.title.toLowerCase().includes(type.toLowerCase()),
					)

					if (matchingSchema) {
						this.configuration = {
							...this.configuration,
							[type]: {
								...this.configuration[type],
								schema: {
									label: matchingSchema.title,
									value: matchingSchema.id.toString(),
								},
							},
						}
					}
				})
			}
		},

		/**
		 * Updates schema options based on the selected register
		 *
		 * @param {string} registerId - The ID of the selected register
		 */
		updateSchemaOptions(registerId) {
			const register = this.settings.availableRegisters.find(r => r.id.toString() === registerId)

			if (register && Array.isArray(register.schemas)) {
				this.schemaOptions = register.schemas.map(schema => ({
					label: schema.title,
					value: schema.id.toString(),
				}))
			} else {
				this.schemaOptions = []
			}
		},

		/**
		 * Formats an object type string to title case
		 *
		 * @param {string} objectType - The object type to format
		 * @return {string} The formatted title
		 */
		formatTitle(objectType) {
			return objectType.charAt(0).toUpperCase() + objectType.slice(1)
		},

		/**
		 * Gets description for an object type
		 *
		 * @param {string} objectType - The object type
		 * @return {string} The description
		 */
		getObjectTypeDescription(objectType) {
			const descriptions = {
				organization: 'Organizations that register in the software catalog',
				contact: 'Contact persons associated with organizations',
				gebruiker: 'Users who can access the software catalog system',
			}
			return descriptions[objectType] || ''
		},

		/**
		 * Handles register change event
		 */
		handleRegisterChange() {
			if (this.selectedRegister) {
				this.updateSchemaOptions(this.selectedRegister.value)

				// Clear ALL schema selections - both register-specific and generic
				const allConfigKeys = [
					'amef_organization',
					'voorzieningen_gebruiker',
					'voorzieningen_organisatie',
					'voorzieningen_contactpersoon',
					...this.settings.objectTypes,
				]

				allConfigKeys.forEach(configKey => {
					if (this.configuration[configKey]) {
						this.configuration = {
							...this.configuration,
							[configKey]: {
								...this.configuration[configKey],
								schema: null,
							},
						}
					}
				})

				// Auto-select matching schemas for the new register
				const register = this.settings.availableRegisters.find(
					r => r.id.toString() === this.selectedRegister.value,
				)

				if (register && Array.isArray(register.schemas)) {
					this.autoSelectMatchingSchemas(register)
				}
			}
		},

		/**
		 * Validates the current configuration
		 */
		validateConfiguration() {
			// This method can be expanded to add validation logic
		},

		/**
		 * Saves the configuration
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		async saveConfiguration() {
			if (!this.canSave) return

			this.saving = true
			try {
				const configToSave = {}

				// Save register-specific configuration
				const registerSpecificKeys = [
					'amef_elements',
					'amef_organization',
					'amef_relationships',
					'amef_views',
					'voorzieningen_gebruiker',
					'voorzieningen_organisatie',
					'voorzieningen_contactpersoon',
				]

				registerSpecificKeys.forEach(configKey => {
					const config = this.configuration[configKey]
					if (config) {
						// Always use openregister as source
						configToSave[`${configKey}_source`] = 'openregister'

						// Set the register ID
						configToSave[`${configKey}_register`] = this.selectedRegister.value

						// Set the schema ID if selected
						configToSave[`${configKey}_schema`] = config.schema ? config.schema.value : ''
					}
				})

				// Save generic object types configuration (backward compatibility)
				Object.entries(this.configuration).forEach(([type, config]) => {
					// Skip register-specific configs as they're handled above
					if (registerSpecificKeys.includes(type)) {
						return
					}

					// Only process generic object types
					if (this.settings.objectTypes.includes(type)) {
						// Always use openregister as source
						configToSave[`${type}_source`] = 'openregister'

						// Set the register ID for all object types
						configToSave[`${type}_register`] = this.selectedRegister.value

						// Set the schema ID if selected
						configToSave[`${type}_schema`] = config.schema ? config.schema.value : ''
					}
				})

				// Send configuration to backend
				const response = await fetch('/index.php/apps/softwarecatalog/api/settings', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify(configToSave),
				})

				const result = await response.json()
				if (result.error) {
					// Configuration save failed, but we'll continue silently
				}
			} catch (error) {
			} finally {
				this.saving = false
			}
		},

		/**
		 * Saves only the schema values for Voorzieningen register
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		async saveSchemaValues() {
			if (!this.hasSchemaValuesToSave) return

			this.savingSchemas = true
			this.schemaSaveResult = null

			try {
				const configToSave = {}

				// Only save voorzieningen schema configuration
				if (this.configuration.voorzieningen_organisatie?.schema) {
					configToSave.voorzieningen_organisatie_source = 'openregister'
					configToSave.voorzieningen_organisatie_register = this.selectedRegister.value
					configToSave.voorzieningen_organisatie_schema = this.configuration.voorzieningen_organisatie.schema.value
				}

				if (this.configuration.voorzieningen_contactpersoon?.schema) {
					configToSave.voorzieningen_contactpersoon_source = 'openregister'
					configToSave.voorzieningen_contactpersoon_register = this.selectedRegister.value
					configToSave.voorzieningen_contactpersoon_schema = this.configuration.voorzieningen_contactpersoon.schema.value
				}

				// Send configuration to backend
				const response = await fetch('/index.php/apps/softwarecatalog/api/settings', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify(configToSave),
				})

				const result = await response.json()
				if (result.error) {
					this.schemaSaveResult = {
						success: false,
						message: 'Failed to save schema values: ' + result.error,
					}
				} else {
					this.schemaSaveResult = {
						success: true,
						message: 'Schema values saved successfully! Organisatie and Contactpersoon schemas are now configured.',
					}
					// Reload settings to reflect changes
					await this.loadSettings()
				}
			} catch (error) {
				this.schemaSaveResult = {
					success: false,
					message: 'Failed to save schema values: ' + error.message,
				}
			} finally {
				this.savingSchemas = false
			}
		},

		/**
		 * Initializes the settings
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		async initializeSettings() {
			this.initializing = true
			this.initializationResults = null

			try {
				const response = await fetch('/index.php/apps/softwarecatalog/api/settings/initialize', {
					method: 'POST',
				})
				const data = await response.json()

				if (data.error) {
					this.initializationResults = { errors: [data.error] }
				} else {
					this.initializationResults = data
					// Reload settings to reflect any changes
					await this.loadSettings()
				}
			} catch (error) {
				this.initializationResults = { errors: ['Failed to initialize: ' + error.message] }
			} finally {
				this.initializing = false
			}
		},

		/**
		 * Auto-configures the settings
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		async autoConfigureSettings() {
			this.autoConfiguring = true
			this.initializationResults = null

			try {
				const response = await fetch('/index.php/apps/softwarecatalog/api/settings/auto-configure', {
					method: 'POST',
				})
				const data = await response.json()

				if (data.error) {
					this.initializationResults = { errors: [data.error] }
				} else {
					this.initializationResults = data
					// Reload settings to reflect any changes
					await this.loadSettings()
				}
			} catch (error) {
				this.initializationResults = { errors: ['Failed to auto-configure: ' + error.message] }
			} finally {
				this.autoConfiguring = false
			}
		},

		/**
		 * Checks if a register is of a specific type
		 *
		 * @param {string} type - The type of the register
		 * @return {boolean} True if the register is of the specified type
		 */
		isRegisterType(type) {
			if (!this.selectedRegister) {
				return false
			}

			const register = this.settings.availableRegisters.find(r => r.id.toString() === this.selectedRegister.value)
			if (!register) {
				return false
			}

			// Check register name or slug to determine type
			const registerTitle = register.title ? register.title.toLowerCase() : ''
			const registerSlug = register.slug ? register.slug.toLowerCase() : ''
			const typeCheck = type.toLowerCase()

			// For exact matches or contains check
			const result = registerTitle === typeCheck
				|| registerSlug === typeCheck
				|| registerTitle.includes(typeCheck)
				|| registerSlug.includes(typeCheck)

			return result
		},

		/**
		 * Checks if the selected register is a specific register (amef or voorzieningen)
		 *
		 * @return {boolean} True if the register is a specific register type
		 */
		isSpecificRegister() {
			return this.isRegisterType('amef') || this.isRegisterType('voorzieningen')
		},

		updateGroupName(index, value) {
			this.genericUserGroups[index] = value
		},

		removeGroup(index) {
			this.genericUserGroups.splice(index, 1)
		},

		addGroup() {
			this.genericUserGroups.push('')
		},

		async saveGenericUserGroups() {
			this.savingGroups = true
			this.groupValidation = null
			this.groupsSaveResult = null

			try {
				const response = await fetch('/index.php/apps/softwarecatalog/api/settings', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({
						userGroups: {
							generic: this.genericUserGroups,
						},
					}),
				})

				const result = await response.json()
				if (result.error) {
					this.groupValidation = { errors: [result.error] }
				} else {
					this.groupsSaveResult = { success: true }
					// Reload settings to reflect any changes
					await this.loadSettings()
				}
			} catch (error) {
				this.groupValidation = { errors: ['Failed to save groups: ' + error.message] }
			} finally {
				this.savingGroups = false
			}
		},

		// Organization Admin Groups methods
		updateOrganizationAdminGroupName(index, value) {
			this.organizationAdminGroups[index] = value
		},

		removeOrganizationAdminGroup(index) {
			this.organizationAdminGroups.splice(index, 1)
		},

		addOrganizationAdminGroup() {
			this.organizationAdminGroups.push('')
		},

		async saveOrganizationAdminGroups() {
			this.savingOrganizationAdminGroups = true
			this.organizationAdminGroupsSaveResult = null

			try {
				const response = await fetch('/index.php/apps/softwarecatalog/api/settings', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({
						userGroups: {
							organizationAdmin: this.organizationAdminGroups,
						},
					}),
				})

				const result = await response.json()
				if (result.error) {
					this.organizationAdminGroupsSaveResult = { success: false, error: result.error }
				} else {
					this.organizationAdminGroupsSaveResult = { success: true }
					// Reload settings to reflect any changes
					await this.loadSettings()
				}
			} catch (error) {
				this.organizationAdminGroupsSaveResult = { success: false, error: 'Failed to save organization admin groups: ' + error.message }
			} finally {
				this.savingOrganizationAdminGroups = false
			}
		},

		// Super User Groups methods
		updateSuperUserGroupName(index, value) {
			this.superUserGroups[index] = value
		},

		removeSuperUserGroup(index) {
			this.superUserGroups.splice(index, 1)
		},

		addSuperUserGroup() {
			this.superUserGroups.push('')
		},

		async saveSuperUserGroups() {
			this.savingSuperUserGroups = true
			this.superUserGroupsSaveResult = null

			try {
				const response = await fetch('/index.php/apps/softwarecatalog/api/settings', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({
						userGroups: {
							superUser: this.superUserGroups,
						},
					}),
				})

				const result = await response.json()
				if (result.error) {
					this.superUserGroupsSaveResult = { success: false, error: result.error }
				} else {
					this.superUserGroupsSaveResult = { success: true }
					// Reload settings to reflect any changes
					await this.loadSettings()
				}
			} catch (error) {
				this.superUserGroupsSaveResult = { success: false, error: 'Failed to save super user groups: ' + error.message }
			} finally {
				this.savingSuperUserGroups = false
			}
		},

		// Group description helper
		getGroupDescription(group) {
			const descriptions = {
				'Aanbod-beheerder': 'Manages software offerings and catalog content',
				'Gebruik-beheerder': 'Manages software usage and procurement',
				'Gebruik-raadpleger': 'Views software usage and procurement data',
				'Functioneel-beheerder': 'Manages functional aspects of the system',
				'VNG-raadpleger': 'Views VNG-related information',
				'Organisatie-beheerder': 'Manages organization-specific settings and users',
				Bezoeker: 'Basic visitor access to the catalog',
				beheerder: 'System administrators and managers',
				inkoper: 'Procurement specialists',
				ambtenaar: 'Civil servants (auto-assigned for gemeente organizations)',
				'software-catalog-users': 'All software catalog users',
			}
			return descriptions[group] || 'User group for role-based access control'
		},

		updateEmailSetting(key, value) {
			// Handle case where NcSelect returns an option object instead of just the value
			if (value && typeof value === 'object' && value.value !== undefined) {
				this.emailSettings[key] = value.value
			} else {
				this.emailSettings[key] = value
			}
		},

		async sendTestEmail() {
			this.testingEmail = true
			this.emailTestResult = null

			try {
				const response = await fetch('/index.php/apps/softwarecatalog/api/email/test', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({
						email: this.testEmailAddress,
						emailSettings: this.emailSettings,
					}),
				})

				const result = await response.json()

				// Check for success field first, then error field for backward compatibility
				if (result.success === false || result.error) {
					this.emailTestResult = {
						success: false,
						message: result.message || result.error || 'Failed to send test email',
					}
				} else if (result.success === true) {
					this.emailTestResult = {
						success: true,
						message: result.message || 'Test email sent successfully!',
					}
				} else {
					// Fallback for legacy responses
					this.emailTestResult = {
						success: true,
						message: 'Test email sent successfully!',
					}
				}
			} catch (error) {
				this.emailTestResult = { success: false, message: 'Failed to send test email: ' + error.message }
			} finally {
				this.testingEmail = false
			}
		},

		async saveEmailSettings() {
			this.savingEmailSettings = true
			this.emailSaveResult = null

			try {
				const response = await fetch('/index.php/apps/softwarecatalog/api/settings', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({
						emailSettings: this.emailSettings,
					}),
				})

				const result = await response.json()
				if (result.error) {
					this.emailSaveResult = { success: false, message: result.error }
				} else {
					this.emailSaveResult = { success: true, message: 'Email settings saved successfully!' }
				}
			} catch (error) {
				this.emailSaveResult = { success: false, message: 'Failed to save email settings: ' + error.message }
			} finally {
				this.savingEmailSettings = false
			}
		},

		/**
		 * Gets the name of the currently active template
		 *
		 * @return {string} The template name
		 */
		getActiveTemplateName() {
			const template = this.availableTemplates.find(t => t.key === this.activeTemplate)
			return template ? template.name : 'Unknown Template'
		},

		/**
		 * Gets the description of the currently active template
		 *
		 * @return {string} The template description
		 */
		getActiveTemplateDescription() {
			const descriptions = {
				organization_registration: 'Email sent when a new organization registers in the system.',
				organization_activation: 'Email sent when an organization is activated (set to "Actief").',
				user_creation: 'Email sent when a new user account is created for a contact person.',
				user_password: 'Email sent with auto-generated passwords when user accounts are created',
			}
			return descriptions[this.activeTemplate] || 'No description available.'
		},

		/**
		 * Gets the content of the currently active template
		 *
		 * @return {string} The template content
		 */
		getActiveTemplateContent() {
			return this.templates[this.activeTemplate] || ''
		},

		getActiveTemplateVariables() {
			// Define available variables for each template type
			const variables = {
				organization_registration: {
					'organization.name': 'Organization name',
					'organization.type': 'Organization type',
					'organization.website': 'Organization website',
					'organization.beoordeling': 'Organization status',
				},
				organization_activation: {
					'organization.name': 'Organization name',
					'organization.type': 'Organization type',
					'organization.website': 'Organization website',
				},
				user_creation: {
					'user.username': 'User username',
					'user.email': 'User email address',
					'user.displayName': 'User display name',
					'organization.name': 'Organization name',
					'user.roles': 'User roles (array)',
				},
				user_password: {
					'user.username': 'User username',
					'user.email': 'User email address',
					'user.displayName': 'User display name',
					'user.password': 'Auto-generated password',
					'organization.name': 'Organization name',
					'user.roles': 'User roles (array)',
				},
			}
			return variables[this.activeTemplate] || {}
		},

		insertVariable(variable) {
			// This would typically insert the variable into a text editor
			// For now, we'll just add it to the template content
			const currentContent = this.getActiveTemplateContent()
			const newContent = currentContent + ` {{ ${variable} }}`
			this.updateTemplateContent(newContent)
		},

		updateTemplateContent(value) {
			// Update the template content in our data
			this.$set(this.templates, this.activeTemplate, value)
		},

		/**
		 * Resets the current template to its default content
		 */
		async resetTemplate() {
			try {
				// This would fetch the default template from the server
				// For now, we'll set some default content
				const defaultTemplates = {
					organization_registration: '<h1>Welcome {{ organization.name }}!</h1><p>Your organization has been registered.</p>',
					organization_activation: '<h1>{{ organization.name }} Activated</h1><p>Your organization is now active!</p>',
					user_creation: '<h1>Welcome {{ user.displayName }}!</h1><p>Your account has been created.</p>',
					user_password: '<h1>Welcome {{ user.displayName }}!</h1><p>Your password has been generated.</p>',
				}

				this.$set(this.templates, this.activeTemplate, defaultTemplates[this.activeTemplate] || '')
			} catch (error) {
				// Error handling for template reset
				this.templateSaveResult = {
					success: false,
					message: 'Failed to reset template: ' + error.message,
				}
			}
		},

		/**
		 * Saves the current template
		 */
		async saveTemplate() {
			this.savingTemplate = true
			this.templateSaveResult = null

			try {
				// This would save the template to the server
				// For now, we'll just simulate success
				await new Promise(resolve => setTimeout(resolve, 1000))

				this.templateSaveResult = {
					success: true,
					message: 'Template saved successfully!',
				}
			} catch (error) {
				this.templateSaveResult = {
					success: false,
					message: 'Failed to save template: ' + error.message,
				}
			} finally {
				this.savingTemplate = false
			}
		},

		/**
		 * Formats a template variable for display in the UI
		 *
		 * @param {string} variable The variable name
		 * @return {string} The formatted variable string
		 */
		formatTemplateVariable(variable) {
			return `{{ ${variable} }}`
		},

		/**
		 * Loads sync status from the backend
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		async loadSyncStatus() {
			this.loadingSyncStatus = true
			try {
				const minutesBack = this.selectedTimeWindow ? this.selectedTimeWindow.value : 10
				const response = await fetch(`/index.php/apps/softwarecatalog/api/settings/sync-status?minutesBack=${minutesBack}`)
				const data = await response.json()

				if (data.error) {
					this.syncStatus = {
						configured: false,
						message: data.error,
					}
				} else {
					this.syncStatus = data
				}
			} catch (error) {
				this.syncStatus = {
					configured: false,
					message: 'Failed to load sync status: ' + error.message,
				}
			} finally {
				this.loadingSyncStatus = false
			}
		},

		/**
		 * Performs manual synchronization
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		async performManualSync() {
			this.performingSync = true
			this.syncResult = null

			try {
				const minutesBack = this.selectedTimeWindow ? this.selectedTimeWindow.value : 10
				const response = await fetch(`/index.php/apps/softwarecatalog/api/settings/sync?minutesBack=${minutesBack}`, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
				})

				const data = await response.json()

				if (data.success) {
					this.syncResult = {
						success: true,
						message: data.message || 'Synchronization completed successfully!',
						results: data,
					}
					// Refresh sync status after successful sync
					await this.loadSyncStatus()
				} else {
					this.syncResult = {
						success: false,
						message: data.message || 'Synchronization failed',
					}
				}
			} catch (error) {
				this.syncResult = {
					success: false,
					message: 'Failed to perform synchronization: ' + error.message,
				}
			} finally {
				this.performingSync = false
			}
		},

		/**
		 * Handles time window selection change
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		async handleTimeWindowChange() {
			// Reload sync status with new time window
			await this.loadSyncStatus()
		},

		/**
		 * Formats time window value for display
		 *
		 * @param {number} minutes - Time window in minutes
		 * @return {string} Formatted time window string
		 */
		formatTimeWindow(minutes) {
			if (minutes === 0) {
				return 'All time (full sync)'
			} else if (minutes < 60) {
				return `${minutes} minutes`
			} else if (minutes < 1440) {
				const hours = Math.floor(minutes / 60)
				return hours === 1 ? '1 hour' : `${hours} hours`
			} else if (minutes < 10080) {
				const days = Math.floor(minutes / 1440)
				return days === 1 ? '1 day' : `${days} days`
			} else if (minutes < 43200) {
				const weeks = Math.floor(minutes / 10080)
				return weeks === 1 ? '1 week' : `${weeks} weeks`
			} else if (minutes < 525600) {
				const months = Math.floor(minutes / 43200)
				return months === 1 ? '1 month' : `${months} months`
			} else {
				const years = Math.floor(minutes / 525600)
				return years === 1 ? '1 year' : `${years} years`
			}
		},

		/**
		 * Gets description for the current time window selection
		 *
		 * @return {string} Time window description
		 */
		getTimeWindowDescription() {
			if (!this.selectedTimeWindow) {
				return 'Select a time window to see predictions'
			}

			const minutes = this.selectedTimeWindow.value
			if (minutes === 0) {
				return 'Full synchronization - processes all organizations regardless of when they were last updated'
			} else {
				return `Incremental synchronization - only processes organizations updated in the last ${this.formatTimeWindow(minutes)}`
			}
		},

		/**
		 * Gets the efficiency class based on the efficiency improvement percentage
		 *
		 * @param {number} efficiency - Efficiency improvement percentage
		 * @return {string} Efficiency class
		 */
		getEfficiencyClass(efficiency) {
			if (efficiency > 0) {
				return 'positive'
			} else if (efficiency < 0) {
				return 'negative'
			} else {
				return 'neutral'
			}
		},

		/**
		 * Formats a number with commas
		 *
		 * @param {number} number - The number to format
		 * @return {string} Formatted number
		 */
		formatNumber(number) {
			return number.toLocaleString()
		},

		/**
		 * Formats the last sync time
		 *
		 * @param {string} lastSyncTime - Last sync time in ISO format
		 * @return {string} Formatted last sync time
		 */
		formatLastSyncTime(lastSyncTime) {
			if (!lastSyncTime || lastSyncTime === 'Never') return 'Never'
			try {
				const date = new Date(lastSyncTime)
				return date.toLocaleString()
			} catch (error) {
				return lastSyncTime
			}
		},

		/**
		 * Gets the message class based on the sync status
		 *
		 * @return {string} Message class
		 */
		getMessageClass() {
			if (this.syncStatus.message) {
				return 'error'
			} else if (this.syncStatus.configured) {
				return 'success'
			} else {
				return 'warning'
			}
		},

		/**
		 * Gets the message icon based on the sync status
		 *
		 * @return {string} Message icon
		 */
		getMessageIcon() {
			if (this.syncStatus.message) {
				return '❌'
			} else if (this.syncStatus.configured) {
				return '✅'
			} else {
				return '⚠️'
			}
		},

		/**
		 * Gets the estimated duration based on the sync status
		 *
		 * @return {string} Estimated duration
		 */
		getEstimatedDuration() {
			if (!this.syncStatus.organizationsToProcess || !this.syncStatus.contactPersonsToProcess) return 'N/A'
			const organizationsPerMinute = 1 / 60
			const contactPersonsPerMinute = 1 / 60
			const totalMinutes = (this.syncStatus.organizationsToProcess / organizationsPerMinute) + (this.syncStatus.contactPersonsToProcess / contactPersonsPerMinute)
			return this.formatTimeWindow(totalMinutes)
		},

		getProcessingClass(value) {
			if (value > 100) return 'high-load'
			if (value > 10 && value <= 100) return 'medium-load'
			if (value <= 10) return 'low-load'
			return ''
		},

		async resetAutoConfiguration(resetConfiguration = false) {
			this.resettingAutoConfig = true
			this.resetAutoConfigResult = null

			try {
				const response = await fetch('/index.php/apps/softwarecatalog/api/settings/reset-auto-config', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({ resetConfiguration }),
				})

				const result = await response.json()
				this.resetAutoConfigResult = result

				if (result.success) {
					// Reload settings and version info to reflect any changes
					await Promise.all([
						this.loadSettings(),
						this.loadVersionInfo(),
					])
				}
			} catch (error) {
				this.resetAutoConfigResult = { success: false, message: 'Failed to reset auto-configuration: ' + error.message }
			} finally {
				this.resettingAutoConfig = false
			}
		},

		/**
		 * Format estimated time of arrival
		 *
		 * @param {number} timestamp Unix timestamp
		 * @return {string} Formatted ETA string
		 */
		formatETA(timestamp) {
			const now = Date.now() / 1000
			const secondsRemaining = timestamp - now
			if (secondsRemaining <= 0) {
				return 'Complete'
			}

			if (secondsRemaining < 60) {
				return `${Math.round(secondsRemaining)}s`
			} else if (secondsRemaining < 3600) {
				return `${Math.round(secondsRemaining / 60)}m`
			} else {
				return `${Math.round(secondsRemaining / 3600)}h`
			}
		},

		/**
		 * Initialize export options with default values
		 */
		initializeExportOptions() {
			// Set default selected schemas to all available schemas
			this.exportOptions.selectedSchemas = this.availableSchemas.map(schema => schema.value)
		},

		// ArchiMate-related methods

		/**
		 * Import ArchiMate file
		 *
		 * @return {Promise<void>}
		 */
		async importArchiMateFile() {
			if (!this.selectedFile) {
				console.error('No file selected for import')
				return
			}

			this.importing = true
			this.importResult = null

			try {
				const formData = new FormData()
				formData.append('archiMateFile', this.selectedFile)
				formData.append('updateExisting', this.importOptions.updateExisting)
				formData.append('deleteOrphaned', this.importOptions.deleteOrphaned)
				formData.append('preserveIds', 'true')

				const response = await fetch('/index.php/apps/softwarecatalog/api/archimate/import', {
					method: 'POST',
					headers: {
						'X-Requested-With': 'XMLHttpRequest',
					},
					body: formData,
				})

				const result = await response.json()

				if (result.success) {
					this.importResult = result
					// Start polling for status updates
					this.startStatusPolling()
				} else {
					// Handle import failure
				}

			} catch (error) {
				console.error('Failed to import ArchiMate file:', error)
			} finally {
				this.importing = false
			}
		},

		/**
		 * Export to ArchiMate format
		 *
		 * @return {Promise<void>}
		 */
		async exportToArchiMate() {
			this.exporting = true
			this.exportResult = null

			try {
				const response = await fetch('/index.php/apps/softwarecatalog/api/archimate/export', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-Requested-With': 'XMLHttpRequest',
					},
					body: JSON.stringify(this.exportOptions),
				})

				const result = await response.json()

				if (result.success) {
					this.exportResult = result
					// Start polling for status updates
					this.startStatusPolling()
				} else {
					// Handle export failure
				}

			} catch (error) {
				console.error('Failed to export to ArchiMate:', error)
			} finally {
				this.exporting = false
			}
		},

		/**
		 * Download ArchiMate file
		 *
		 * @param {string} fileName File name to download
		 * @return {Promise<void>}
		 */
		async downloadArchiMateFile(fileName) {
			try {
				const response = await fetch(`/index.php/apps/softwarecatalog/api/archimate/download/${fileName}`)
				if (response.ok) {
					const blob = await response.blob()
					const url = window.URL.createObjectURL(blob)
					const a = document.createElement('a')
					a.href = url
					a.download = fileName
					document.body.appendChild(a)
					a.click()
					document.body.removeChild(a)
					window.URL.revokeObjectURL(url)
				} else {
					console.error('Failed to download file:', response.statusText)
				}
			} catch (error) {
				console.error('Failed to download ArchiMate file:', error)
			}
		},

		/**
		 * Test round-trip functionality
		 *
		 * @return {Promise<void>}
		 */
		async testRoundTrip() {
			this.testingRoundTrip = true
			this.roundTripResult = null

			try {
				// First import the selected file
				await this.importArchiMateFile()
				// Wait for import to complete
				// Then export back to ArchiMate
				await this.exportToArchiMate()
				this.roundTripResult = {
					success: true,
					message: 'Round-trip test completed',
					import_stats: this.importResult?.statistics || {},
					export_stats: this.exportResult?.statistics || {},
				}

			} catch (error) {
				console.error('Round-trip test failed:', error)
				this.roundTripResult = {
					success: false,
					message: 'Round-trip test failed: ' + error.message,
				}
			} finally {
				this.testingRoundTrip = false
			}
		},

		/**
		 * Handle file selection for ArchiMate import
		 *
		 * @param {Event} event File input change event
		 */
		handleFileSelection(event) {
			const files = event.target.files
			if (files && files.length > 0) {
				this.selectedFile = files[0]
				this.importResult = null
			}
		},

		/**
		 * Clear selected file
		 */
		clearFileSelection() {
			this.selectedFile = null
			this.importResult = null
			if (this.$refs.archiMateFileInput) {
				this.$refs.archiMateFileInput.value = ''
			}
		},

		/**
		 * Format file size for display
		 *
		 * @param {number} bytes File size in bytes
		 * @return {string} Formatted file size
		 */
		formatFileSize(bytes) {
			if (bytes === 0) return '0 Bytes'
			const k = 1024
			const sizes = ['Bytes', 'KB', 'MB', 'GB']
			const i = Math.floor(Math.log(bytes) / Math.log(k))
			return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i]
		},

		clearImportStatus() {
			this.archimateStatus.import = {
				status: null,
				current_step: null,
				progress: null,
				statistics: null,
			}
			this.isImportRunning = false
		},

		clearExportStatus() {
			this.archimateStatus.export = {
				status: null,
				current_step: null,
				progress: null,
				statistics: null,
			}
			this.isExportRunning = false
		},

		async fetchArchiMateStatus() {
			try {
				const response = await fetch('/index.php/apps/softwarecatalog/api/archimate/status')
				const data = await response.json()

				if (data.success && data.status) {
					this.archimateStatus = data.status

					// Update running states
					this.isImportRunning = data.status.import?.status === 'running'
					this.isExportRunning = data.status.export?.status === 'running'

					// Stop polling if no operations are running
					if (!this.isImportRunning && !this.isExportRunning) {
						this.stopStatusPolling()
					}
				}
			} catch (error) {
				console.error('Failed to fetch ArchiMate status:', error)
			}
		},

		startStatusPolling() {
			if (this.statusPollingInterval) {
				clearInterval(this.statusPollingInterval)
			}
			this.statusPollingInterval = setInterval(() => {
				this.fetchArchiMateStatus()
			}, 1000) // Poll every second
		},

		stopStatusPolling() {
			if (this.statusPollingInterval) {
				clearInterval(this.statusPollingInterval)
				this.statusPollingInterval = null
			}
		},

		getStatusType(status) {
			switch (status) {
			case 'running':
				return 'warning'
			case 'completed':
				return 'success'
			case 'failed':
				return 'error'
			default:
				return 'info'
			}
		},

		getStatusIcon(status) {
			switch (status) {
			case 'running':
				return '⏳'
			case 'completed':
				return '✅'
			case 'failed':
				return '❌'
			default:
				return 'ℹ️'
			}
		},

		getStatusText(status) {
			switch (status) {
			case 'running':
				return 'Import in progress'
			case 'completed':
				return 'Import completed'
			case 'failed':
				return 'Import failed'
			default:
				return 'No status available'
			}
		},

		getStatusStatistics(status) {
			switch (status) {
			case 'running':
				return `Elements: ${this.archimateStatus.import.statistics?.elements_processed || 0}, Relationships: ${this.archimateStatus.import.statistics?.relationships_processed || 0}, Organizations: ${this.archimateStatus.import.statistics?.organizations_processed || 0}, Created: ${this.archimateStatus.import.statistics?.objects_created || 0}, Updated: ${this.archimateStatus.import.statistics?.objects_updated || 0}`
			case 'completed':
				return `Elements: ${this.archimateStatus.import.statistics?.elements_processed || 0}, Relationships: ${this.archimateStatus.import.statistics?.relationships_processed || 0}, Organizations: ${this.archimateStatus.import.statistics?.organizations_processed || 0}, Created: ${this.archimateStatus.import.statistics?.objects_created || 0}, Updated: ${this.archimateStatus.import.statistics?.objects_updated || 0}`
			case 'failed':
				return `Error: ${this.archimateStatus.import.error}`
			default:
				return 'No statistics available'
			}
		},

		getStatusActions(status) {
			switch (status) {
			case 'running':
				return [
					{
						label: 'Cancel Import',
						icon: '⛔',
						action: this.clearImportStatus,
					},
				]
			case 'completed':
				return [
					{
						label: 'Clear Status',
						icon: '🗑️',
						action: this.clearImportStatus,
					},
				]
			case 'failed':
				return [
					{
						label: 'Clear Status',
						icon: '🗑️',
						action: this.clearImportStatus,
					},
				]
			default:
				return []
			}
		},

	},
})
</script>

<style scoped>
.initialization-section {
	margin-bottom: 2rem;
	padding: 1rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
}

.initialization-results {
	margin-top: 1rem;
}

.register-selection {
	margin-bottom: 2rem;
	max-width: 400px;
}

.register-selection-content {
	margin-top: 1rem;
}

.schema-configuration-content {
	margin-top: 1rem;
}

.register-selection-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 2rem;
	margin-top: 1rem;
}

@media (max-width: 768px) {
	.register-selection-grid {
		grid-template-columns: 1fr;
	}
}

.register-selection-item {
	padding: 1.5rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background-color: var(--color-background-hover);
}

.register-selection-item h5 {
	margin: 0 0 0.5rem 0;
	color: var(--color-main-text);
	font-size: 1rem;
	font-weight: 600;
}

.register-selection-item > p {
	margin: 0 0 1rem 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
}

.schema-configuration-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 1.5rem;
}

@media (max-width: 768px) {
	.schema-configuration-grid {
		grid-template-columns: 1fr;
	}
}

.schema-configuration {
	margin-top: 2rem;
}

.object-type-section {
	margin-bottom: 1.5rem;
	display: flex;
	align-items: flex-start;
	gap: 1rem;
}

.object-type-header {
	min-width: 200px;
	display: flex;
	flex-direction: column;
}

.object-type-description {
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
	margin-top: 0.25rem;
}

.configuration-debug {
	margin: 2rem 0;
	padding: 1rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background-color: var(--color-background-dark);
}

.configuration-debug h4 {
	display: flex;
	align-items: center;
	gap: 1rem;
	margin-bottom: 1rem;
}

.debug-info {
	background-color: var(--color-main-background);
	padding: 10px;
	border-radius: var(--border-radius);
	font-family: monospace;
	font-size: 12px;
	overflow-x: auto;
	max-height: 300px;
	overflow-y: auto;
}

.debug-info pre {
	margin: 0;
	white-space: pre-wrap;
	word-wrap: break-word;
}

.configuration-status {
	margin: 2rem 0;
	padding: 1rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
}

.status-item {
	display: flex;
	justify-content: space-between;
	margin-bottom: 0.5rem;
}

.status-label {
	font-weight: bold;
}

.status-configured {
	color: var(--color-success);
}

.status-missing {
	color: var(--color-warning);
}

.button-container {
	margin-top: 2rem;
	display: flex;
	gap: 1rem;
}

.loading-icon {
	display: flex;
	justify-content: center;
	margin: 2rem 0;
}

.register-type-section {
	margin-bottom: 2rem;
	padding: 1rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
}

.status-group {
	margin-bottom: 1rem;
}

.generic-groups-section {
	margin-bottom: 2rem;
	padding: 1rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
}

.groups-configuration {
	margin-top: 1rem;
}

.group-list {
	margin-bottom: 1rem;
}

.group-item {
	display: flex;
	align-items: center;
	margin-bottom: 0.5rem;
}

.group-actions {
	margin-bottom: 1rem;
	display: flex;
	gap: 1rem;
}

.validation-errors {
	margin-bottom: 1rem;
}

.save-results {
	margin-bottom: 1rem;
}

.schema-save-result {
	margin-top: 1rem;
}

.groups-info {
	margin-top: 1rem;
}

.default-groups-info {
	margin-top: 1rem;
}

.email-settings-section {
	margin-bottom: 2rem;
	padding: 1rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
}

.email-configuration {
	margin-top: 1rem;
}

.setting-row {
	margin-bottom: 1rem;
}

.setting-label {
	font-weight: bold;
}

.setting-description {
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
	margin-top: 0.25rem;
}

.email-testing {
	margin-top: 1rem;
}

.test-email-row {
	display: flex;
	align-items: center;
	gap: 1rem;
}

.test-result {
	margin-top: 1rem;
}

.email-save-buttons {
	margin-top: 1rem;
}

.template-tabs {
	margin-top: 1rem;
}

.tab-buttons {
	margin-bottom: 1rem;
}

.template-editor {
	margin-top: 1rem;
}

.template-info {
	margin-bottom: 1rem;
}

.available-variables {
	margin-top: 1rem;
}

.variables-list {
	margin-top: 0.5rem;
}

.variable-tag {
	padding: 0.25rem;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
	margin-right: 0.5rem;
	cursor: pointer;
}

.template-actions {
	margin-top: 1rem;
	display: flex;
	justify-content: space-between;
}

.organization-admin-groups-section {
	margin-bottom: 2rem;
	padding: 1rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
}

.super-user-groups-section {
	margin-bottom: 2rem;
	padding: 1rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
}

.sync-section {
	margin-bottom: 2rem;
	padding: 1rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
}

.sync-status {
	margin: 1rem 0;
	padding: 1rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background-color: var(--color-main-background);
}

.status-info {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.configuration-overview {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.config-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 0.5rem;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
}

.config-label {
	font-weight: bold;
	min-width: 200px;
	color: var(--color-main-text);
}

.config-value {
	color: var(--color-text-maxcontrast);
	text-align: right;
}

.status-configured {
	color: var(--color-success);
	font-weight: bold;
}

.status-missing {
	color: var(--color-warning);
	font-weight: bold;
}

.config-details {
	margin-top: 0.5rem;
}

.status-message {
	margin-top: 0.5rem;
	padding: 0.5rem;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
	font-style: italic;
}

.status-loading {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	color: var(--color-text-maxcontrast);
}

.sync-actions {
	margin: 1rem 0;
	display: flex;
	gap: 1rem;
}

.sync-result {
	margin: 1rem 0;
}

.sync-result-content {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.sync-statistics {
	margin-top: 1rem;
}

.sync-statistics h5 {
	margin: 0.5rem 0;
	font-weight: bold;
}

.sync-statistics ul {
	margin: 0.5rem 0;
	padding-left: 1.5rem;
}

.sync-statistics li {
	margin: 0.25rem 0;
}

.sync-errors {
	margin-top: 1rem;
	padding: 0.5rem;
	background-color: var(--color-error-hover);
	border-radius: var(--border-radius);
}

.sync-errors h5 {
	color: var(--color-error);
	margin: 0 0 0.5rem 0;
}

.sync-errors ul {
	margin: 0;
	padding-left: 1.5rem;
}

.sync-errors li {
	color: var(--color-error);
	margin: 0.25rem 0;
}

.sync-info {
	margin-top: 2rem;
	padding: 1rem;
	background-color: var(--color-background-dark);
	border-radius: var(--border-radius);
}

.sync-info h4 {
	margin: 0 0 1rem 0;
}

.sync-info p {
	margin: 0.5rem 0;
}

.sync-info ul {
	margin: 0.5rem 0;
	padding-left: 1.5rem;
}

.sync-info li {
	margin: 0.25rem 0;
}

.time-window-configuration {
	margin-bottom: 2rem;
	padding: 1rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background-color: var(--color-background-dark);
}

.time-window-configuration h4 {
	margin: 0 0 0.5rem 0;
	color: var(--color-main-text);
}

.time-window-configuration p {
	margin: 0 0 1rem 0;
	color: var(--color-text-maxcontrast);
}

.time-window-row {
	display: flex;
	align-items: flex-end;
	gap: 1rem;
	margin-bottom: 1rem;
}

.time-window-selector {
	flex: 1;
	min-width: 200px;
	max-width: 300px;
}

.sync-actions {
	display: flex;
	gap: 1rem;
}

.time-window-description {
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
	font-style: italic;
	line-height: 1.4;
}

.sync-predictions {
	margin-bottom: 1rem;
}

.prediction-grid {
	display: flex;
	flex-wrap: wrap;
	gap: 1rem;
}

.prediction-card {
	flex: 1;
	padding: 1rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
}

.prediction-label {
	font-weight: bold;
	margin-bottom: 0.5rem;
}

.prediction-value {
	font-size: 1.2em;
	font-weight: bold;
}

.prediction-value.full-sync {
	color: var(--color-success);
}

.prediction-value.incremental-sync {
	color: var(--color-warning);
}

.prediction-value.high-load {
	color: var(--color-error);
}

.prediction-value.medium-load {
	color: var(--color-warning);
}

.prediction-value.low-load {
	color: var(--color-success);
	font-weight: bold;
}

.efficiency-metrics {
	margin-top: 1rem;
	display: flex;
	flex-wrap: wrap;
	gap: 1rem;
}

.efficiency-card {
	flex: 1;
	padding: 1rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
}

.efficiency-label {
	font-weight: bold;
	margin-bottom: 0.5rem;
}

.efficiency-value {
	font-size: 1.2em;
	font-weight: bold;
}

.efficiency-value.positive {
	color: var(--color-success);
}

.efficiency-value.negative {
	color: var(--color-error);
}

.efficiency-value.neutral {
	color: var(--color-warning);
}

.processing-estimate {
	margin-top: 1rem;
}

.estimate-header {
	font-weight: bold;
	margin-bottom: 0.5rem;
}

.estimate-subtitle {
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.estimate-content {
	display: flex;
	flex-wrap: wrap;
	gap: 1rem;
}

.estimate-item {
	display: flex;
	align-items: center;
	gap: 0.5rem;
}

.estimate-icon {
	font-size: 1.2em;
}

.estimate-text {
	font-size: 1.2em;
}

.system-status-details {
	margin-top: 1rem;
}

.status-message {
	margin-top: 0.5rem;
	padding: 0.5rem;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
	font-style: italic;
}

.message-icon {
	font-size: 1.2em;
	margin-right: 0.5rem;
}

.message-text {
	font-size: 1.2em;
}

.error .message-icon {
	color: var(--color-error);
}

.warning .message-icon {
	color: var(--color-warning);
}

.success .message-icon {
	color: var(--color-success);
}

.efficiency-highlight {
	font-weight: bold;
}

.high-load {
	color: var(--color-error);
	font-weight: bold;
}

/* ArchiMate section styles */
.archimate-section {
	margin-bottom: 2rem;
	padding: 1rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
}

.import-section,
.export-section,
.test-section {
	margin-bottom: 2rem;
	padding: 1rem;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	background-color: var(--color-main-background);
}

.import-form,
.export-form {
	margin-top: 1rem;
}

.file-upload {
	display: flex;
	align-items: center;
	gap: 1rem;
	margin-bottom: 1rem;
}

.selected-file {
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.import-options,
.export-options {
	margin: 1rem 0;
	padding: 1rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
}

.option-row {
	display: flex;
	align-items: center;
	gap: 1rem;
	margin-bottom: 1rem;
}

.option-label {
	min-width: 120px;
	font-weight: bold;
}

.option-description {
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
	margin-left: 0.5rem;
}

.import-actions,
.export-actions,
.test-actions {
	display: flex;
	gap: 1rem;
	margin: 1rem 0;
}

.import-result,
.export-result,
.test-result {
	margin-top: 1rem;
}

.result-content {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.import-statistics,
.export-details,
.comparison-details {
	margin-top: 1rem;
}

.import-statistics ul,
.comparison-details ul {
	margin: 0.5rem 0;
	padding-left: 1.5rem;
}

.processing-stats {
	margin-top: 0.5rem;
	font-style: italic;
}

.medium-load {
	color: var(--color-warning);
	font-weight: bold;
}

.low-load {
	color: var(--color-success);
	font-weight: bold;
}

.sync-status-table {
	width: 100%;
	border-collapse: collapse;
	margin-top: 1rem;
}

.sync-status-table td {
	padding: 0.5rem;
	border: 1px solid var(--color-border);
	vertical-align: top;
}

.sync-status-table .status-label {
	font-weight: bold;
	background-color: var(--color-background-hover);
	min-width: 150px;
}

.sync-status-table .status-value {
	background-color: var(--color-main-background);
}

.status-table {
	margin-top: 1rem;
}

.version-info {
	max-width: 600px;
}

.version-details {
	margin-bottom: 2rem;
	padding: 1rem;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius-large);
}

.version-item {
	margin-bottom: 0.5rem;
	display: flex;
	align-items: center;
	gap: 0.5rem;
}

.version-item:last-child {
	margin-bottom: 0;
}

.no-version {
	color: var(--color-text-lighter);
	font-style: italic;
}

.status-ok {
	color: var(--color-success);
	font-weight: bold;
}

.status-warning {
	color: var(--color-warning);
	font-weight: bold;
}

.status-error {
	color: var(--color-error);
	font-weight: bold;
}

.manual-import {
	margin-top: 1.5rem;
}

.import-actions {
	display: flex;
	gap: 1rem;
	margin-bottom: 1rem;
}

.import-result {
	margin-top: 1rem;
}

.reset-result {
	margin-top: 1rem;
}

.auto-config-details {
	margin-top: 1rem;
	padding: 1rem;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.auto-config-details h4 {
	margin: 0 0 0.5rem 0;
	color: var(--color-main-text);
	font-weight: 600;
}

.auto-config-details ul {
	margin: 0;
	padding-left: 1.5rem;
}

.auto-config-details li {
	margin: 0.25rem 0;
	color: var(--color-success);
}

/* AMEF Configuration styles */
.amef-auto-config {
	margin-bottom: 2rem;
	padding: 1.5rem;
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large);
}

.amef-auto-config h3 {
	margin: 0 0 0.5rem 0;
	color: var(--color-main-text);
	font-weight: 600;
}

.amef-auto-config p {
	margin: 0 0 1rem 0;
	color: var(--color-text-maxcontrast);
}

.amef-manual-config {
	margin-bottom: 2rem;
}

.amef-manual-config h3 {
	margin: 0 0 0.5rem 0;
	color: var(--color-main-text);
	font-weight: 600;
}

.amef-manual-config p {
	margin: 0 0 1.5rem 0;
	color: var(--color-text-maxcontrast);
}

.schema-mappings {
	display: grid;
	gap: 1.5rem;
	margin-bottom: 2rem;
}

.schema-mapping {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.schema-mapping label {
	font-weight: 600;
	color: var(--color-main-text);
}

.field-description {
	margin: 0;
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.current-config {
	padding: 1.5rem;
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large);
	margin-top: 1.5rem;
}

.current-config h4 {
	margin: 0 0 1rem 0;
	color: var(--color-main-text);
	font-weight: 600;
}

.config-summary {
	display: grid;
	gap: 0.75rem;
}

.config-item {
	display: flex;
	gap: 0.5rem;
	align-items: center;
}

.config-item strong {
	min-width: 120px;
	color: var(--color-main-text);
}

.not-configured {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.config-results {
	margin-top: 1rem;
}

/* Progress tracking styles */
.import-progress {
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 1rem;
	margin-top: 1rem;
}

.progress-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 0.75rem;
}

.progress-header h5 {
	margin: 0;
	font-size: 0.875rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.progress-percentage {
	font-size: 1rem;
	font-weight: bold;
	color: var(--color-primary);
}

.progress-bar {
	background: var(--color-background-dark);
	border-radius: calc(var(--border-radius) / 2);
	height: 8px;
	margin-bottom: 0.75rem;
	overflow: hidden;
}

.progress-fill {
	background: linear-gradient(90deg, var(--color-success), var(--color-primary));
	height: 100%;
	transition: width 0.3s ease;
	border-radius: calc(var(--border-radius) / 2);
}

.progress-details {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
}

.progress-phase {
	margin-bottom: 0.25rem;
}

.progress-phase strong {
	color: var(--color-main-text);
}

.current-item {
	font-style: italic;
	margin-left: 0.5rem;
}

.progress-items,
.progress-eta {
	margin-bottom: 0.25rem;
}

.progress-errors,
.progress-warnings {
	margin-top: 0.75rem;
	padding: 0.5rem;
	border-radius: calc(var(--border-radius) / 2);
}

.progress-errors {
	background: var(--color-error-bg, #ffeaea);
	border: 1px solid var(--color-error-border, #e5484d);
	color: var(--color-error-text, #721c24);
}

.progress-warnings {
	background: var(--color-warning-bg, #fffbeb);
	border: 1px solid var(--color-warning-border, #f59e0b);
	color: var(--color-warning-text, #856404);
}

.progress-errors h6,
.progress-warnings h6 {
	margin: 0 0 0.25rem 0;
	font-size: 0.75rem;
	font-weight: 600;
}

.progress-errors ul,
.progress-warnings ul {
	margin: 0;
	padding-left: 1rem;
	font-size: 0.6875rem;
}

.progress-errors li,
.progress-warnings li {
	margin-bottom: 0.125rem;
}

/* Performance Metrics Styling */
.performance-summary {
	margin-top: 1rem;
	padding: 1rem;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
}

.performance-summary h6 {
	margin: 0 0 1rem 0;
	font-size: 0.875rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.performance-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 1rem;
}

.performance-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 0.5rem;
	background: var(--color-background);
	border-radius: calc(var(--border-radius) / 2);
	border: 1px solid var(--color-border);
}

.performance-item .label {
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

.performance-item .value {
	font-weight: 600;
	color: var(--color-primary);
}

/* Processing Times Styling */
.processing-times {
	margin-top: 1rem;
	padding: 1rem;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
}

.processing-times h6 {
	margin: 0 0 1rem 0;
	font-size: 0.875rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.timing-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
	gap: 0.75rem;
	margin-bottom: 1rem;
}

.timing-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 0.5rem;
	background: var(--color-background);
	border-radius: calc(var(--border-radius) / 2);
	border: 1px solid var(--color-border);
}

.timing-item .label {
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

.timing-item .value {
	font-weight: 600;
	color: var(--color-success);
}

/* Performance Breakdown Styling */
.performance-breakdown {
	margin-top: 1rem;
}

.performance-breakdown h6 {
	margin: 0 0 1rem 0;
	font-size: 0.875rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.breakdown-bars {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
}

.breakdown-bar {
	display: flex;
	align-items: center;
	gap: 1rem;
}

.bar-label {
	min-width: 80px;
	font-size: 0.75rem;
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

.bar-container {
	flex: 1;
	height: 20px;
	background: var(--color-background);
	border-radius: 10px;
	overflow: hidden;
	border: 1px solid var(--color-border);
}

.bar-fill {
	height: 100%;
	transition: width 0.3s ease;
	border-radius: 10px;
}

.bar-fill.validation {
	background: linear-gradient(90deg, #10b981, #059669);
}

.bar-fill.parsing {
	background: linear-gradient(90deg, #3b82f6, #2563eb);
}

.bar-fill.conversion {
	background: linear-gradient(90deg, #f59e0b, #d97706);
}

.bar-percent {
	min-width: 50px;
	font-size: 0.75rem;
	font-weight: 600;
	color: var(--color-main-text);
	text-align: right;
}

/* File Information Styling */
.file-info {
	margin-top: 1rem;
	padding: 1rem;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
}

.file-info h6 {
	margin: 0 0 1rem 0;
	font-size: 0.875rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.file-info ul {
	margin: 0;
	padding-left: 1rem;
}

.file-info li {
	margin-bottom: 0.5rem;
	font-size: 0.875rem;
}

/* Performance Metrics Styling */
.performance-metrics {
	margin-top: 1rem;
	padding: 1rem;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
}

.performance-metrics h6 {
	margin: 0 0 1rem 0;
	font-size: 0.875rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.performance-metrics ul {
	margin: 0;
	padding-left: 1rem;
}

.performance-metrics li {
	margin-bottom: 0.5rem;
	font-size: 0.875rem;
}

/* Processing Times Styling */
.processing-times {
	margin-top: 1rem;
	padding: 1rem;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
}

.processing-times h6 {
	margin: 0 0 1rem 0;
	font-size: 0.875rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.processing-times ul {
	margin: 0;
	padding-left: 1rem;
}

.processing-times li {
	margin-bottom: 0.5rem;
	font-size: 0.875rem;
}

/* Summary Statistics Styling */
.summary-stats {
	margin-top: 1rem;
	padding: 1rem;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
}

.summary-stats h6 {
	margin: 0 0 1rem 0;
	font-size: 0.875rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.summary-stats ul {
	margin: 0;
	padding-left: 1rem;
}

.summary-stats li {
	margin-bottom: 0.5rem;
	font-size: 0.875rem;
}

/* Schema Statistics Styling */
.schema-statistics {
	margin-top: 1rem;
	padding: 1rem;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
}

.schema-statistics h6 {
	margin: 0 0 1rem 0;
	font-size: 0.875rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.schema-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 1rem;
}

.schema-card {
	padding: 0.75rem;
	background: var(--color-background);
	border: 1px solid var(--color-border);
	border-radius: calc(var(--border-radius) / 2);
}

.schema-card h6 {
	display: block;
	margin: 0 0 0.5rem 0;
	font-size: 0.8125rem;
	font-weight: 600;
	color: var(--color-primary);
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.schema-card ul {
	margin: 0;
	padding: 0;
	list-style: none;
}

.schema-card li {
	margin-bottom: 0.25rem;
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
}

.schema-card li:last-child {
	margin-bottom: 0;
}

/* ArchiMate Status Styling */
.import-status,
.export-status {
	margin-bottom: 1rem;
}

.status-content {
	padding: 0.5rem 0;
}

.progress-bar {
	width: 100%;
	height: 8px;
	background-color: var(--color-background-dark);
	border-radius: 4px;
	margin: 0.5rem 0;
	position: relative;
	overflow: hidden;
}

.progress-fill {
	height: 100%;
	background-color: var(--color-primary);
	transition: width 0.3s ease;
}

.progress-text {
	position: absolute;
	top: 50%;
	left: 50%;
	transform: translate(-50%, -50%);
	font-size: 0.75rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.status-statistics {
	margin-top: 0.5rem;
	padding: 0.5rem;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
}

.status-statistics p {
	margin: 0.25rem 0;
	font-size: 0.875rem;
}

.status-actions {
	margin-top: 0.5rem;
}

.status-error {
	margin-top: 0.5rem;
	padding: 0.5rem;
	background: var(--color-error-background);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-error);
}

.status-error p {
	margin: 0;
	font-size: 0.875rem;
	color: var(--color-error);
}
</style>
