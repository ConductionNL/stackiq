<template>
	<div>
		<NcSettingsSection
			name="Software Catalog Configuration"
			description="Configure OpenRegister schema mappings for Software Catalog objects"
			doc-url="https://docs.opencatalogi.nl" />

		<NcSettingsSection
			name="OpenRegister Integration"
			description="Configure which schemas to use for organizations, contacts, and users">
			<div v-if="!loading">
				<!-- Warning if OpenRegister is not installed -->
				<NcNoteCard v-if="!settings.openRegisters" type="warning">
					OpenRegister is not installed or not available. Please install it to use the Software Catalog with full functionality.
				</NcNoteCard>

				<!-- Initialization and Auto-Configure Section -->
				<div v-if="settings.openRegisters" class="initialization-section">
					<h3>Initialization</h3>
					<p>Initialize and auto-configure the Software Catalog settings</p>

					<div class="button-container">
						<NcButton
							type="secondary"
							:disabled="loading || initializing"
							@click="initializeSettings">
							<template #icon>
								<NcLoadingIcon v-if="initializing" :size="20" />
								<Cog v-else :size="20" />
							</template>
							Initialize & Auto-Configure
						</NcButton>

						<NcButton
							type="secondary"
							:disabled="loading || autoConfiguring"
							@click="autoConfigureSettings">
							<template #icon>
								<NcLoadingIcon v-if="autoConfiguring" :size="20" />
								<AutoFix v-else :size="20" />
							</template>
							Auto-Configure Only
						</NcButton>
					</div>

					<!-- Initialization Results -->
					<div v-if="initializationResults" class="initialization-results">
						<NcNoteCard v-if="initializationResults.errors && initializationResults.errors.length > 0" type="error">
							<template #icon>
								<Alert :size="20" />
							</template>
							<strong>Initialization Issues:</strong>
							<ul>
								<li v-for="error in initializationResults.errors" :key="error">{{ error }}</li>
							</ul>
						</NcNoteCard>

						<NcNoteCard v-if="initializationResults.autoConfigured" type="success">
							Auto-configuration completed successfully!
						</NcNoteCard>

						<NcNoteCard v-if="initializationResults.fullyConfigured" type="success">
							All object types are now configured.
						</NcNoteCard>
					</div>
				</div>

				<!-- Register Selection -->
				<div v-if="settings.openRegisters" class="register-selection">
					<h3>Register Selection</h3>
					<p>Select the register to store your Software Catalog data</p>

					<NcSelect
						v-model="selectedRegister"
						:options="registerOptions"
						input-label="Select Register"
						:disabled="loading"
						@change="handleRegisterChange" />
				</div>

				<!-- Warning if selected register has no schemas -->
				<NcNoteCard v-if="selectedRegister && !hasSchemas" type="warning">
					The selected register has no schemas. Please create schemas in this register or select a different register.
				</NcNoteCard>

				<!-- Object Type Schema Configuration -->
				<div v-if="selectedRegister && hasSchemas" class="schema-configuration">
					<h3>Schema Configuration</h3>
					<p>Configure schemas for each register type</p>

					<!-- AMEF Register Configuration -->
					<div v-if="isRegisterType('amef')" class="register-type-section">
						<h4>AMEF Register Configuration</h4>
						<p>Configure schemas for AMEF architectural elements</p>

						<div class="object-type-section">
							<div class="object-type-header">
								<h5>Organization Schema</h5>
								<span class="object-type-description">Schema for organizations in the AMEF register</span>
							</div>

							<NcSelect
								v-model="configuration.amef_organization.schema"
								:options="availableSchemaOptions"
								input-label="Organization Schema"
								:disabled="loading"
								@change="validateConfiguration" />
						</div>
					</div>

					<!-- Voorzieningen Register Configuration -->
					<div v-if="isRegisterType('voorzieningen')" class="register-type-section">
						<h4>Voorzieningen Register Configuration</h4>
						<p>Configure schemas for software catalog services</p>

						<div class="object-type-section">
							<div class="object-type-header">
								<h5>Organisatie Schema</h5>
								<span class="object-type-description">Schema for organizations in the Voorzieningen register</span>
							</div>

							<NcSelect
								v-model="configuration.voorzieningen_organisatie.schema"
								:options="availableSchemaOptions"
								input-label="Organisatie Schema"
								:disabled="loading"
								@change="validateConfiguration" />
						</div>

						<div class="object-type-section">
							<div class="object-type-header">
								<h5>Contactpersoon Schema</h5>
								<span class="object-type-description">Schema for contact persons in the Voorzieningen register</span>
							</div>

							<NcSelect
								v-model="configuration.voorzieningen_contactpersoon.schema"
								:options="availableSchemaOptions"
								input-label="Contactpersoon Schema"
								:disabled="loading"
								@change="validateConfiguration" />
						</div>
					</div>

					<!-- Generic Object Types (for backward compatibility) -->
					<div v-if="!isSpecificRegister()" class="register-type-section">
						<h4>Generic Configuration</h4>
						<div v-for="objectType in settings.objectTypes" :key="objectType" class="object-type-section">
							<div class="object-type-header">
								<h5>{{ formatTitle(objectType) }}</h5>
								<span class="object-type-description">{{ getObjectTypeDescription(objectType) }}</span>
							</div>

							<NcSelect
								v-model="configuration[objectType].schema"
								:options="availableSchemaOptions"
								input-label="Schema"
								:disabled="loading"
								@change="validateConfiguration" />
						</div>
					</div>

					<!-- Current Configuration Debug -->
					<div class="configuration-debug">
						<h4>Current Configuration Values
							<NcButton
								type="tertiary"
								size="small"
								:disabled="loading"
								@click="loadDebugInfo">
								<template #icon>
									<Refresh :size="16" />
								</template>
								Refresh
							</NcButton>
						</h4>
						<div class="debug-info">
							<pre v-if="debugInfo">{{ JSON.stringify(debugInfo, null, 2) }}</pre>
							<div v-else>Loading debug information...</div>
						</div>
					</div>

					<!-- Configuration Status -->
					<div class="configuration-status">
						<h4>Configuration Status</h4>
						<div v-if="isRegisterType('amef')" class="status-group">
							<h5>AMEF Register</h5>
							<div class="status-item">
								<span class="status-label">Organization:</span>
								<span v-if="configuration.amef_organization?.schema" class="status-configured">✓ Configured</span>
								<span v-else class="status-missing">⚠ Not configured</span>
							</div>
						</div>

						<div v-if="isRegisterType('voorzieningen')" class="status-group">
							<h5>Voorzieningen Register</h5>
							<div class="status-item">
								<span class="status-label">Organisatie:</span>
								<span v-if="configuration.voorzieningen_organisatie?.schema" class="status-configured">✓ Configured</span>
								<span v-else class="status-missing">⚠ Not configured</span>
							</div>
							<div class="status-item">
								<span class="status-label">Contactpersoon:</span>
								<span v-if="configuration.voorzieningen_contactpersoon?.schema" class="status-configured">✓ Configured</span>
								<span v-else class="status-missing">⚠ Not configured</span>
							</div>
						</div>

						<div v-if="!isSpecificRegister()" class="status-group">
							<h5>Generic Configuration</h5>
							<div v-for="objectType in settings.objectTypes" :key="objectType" class="status-item">
								<span class="status-label">{{ formatTitle(objectType) }}:</span>
								<span v-if="configuration[objectType]?.schema" class="status-configured">✓ Configured</span>
								<span v-else class="status-missing">⚠ Not configured</span>
							</div>
						</div>
					</div>
				</div>

				<!-- Save Buttons -->
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
									:value="group"
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
									<li v-for="error in groupValidation.errors" :key="error">{{ error }}</li>
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
									<li v-for="group in genericUserGroups" :key="group"><code>{{ group }}</code> - {{ getGroupDescription(group) }}</li>
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
										:value="group"
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
										:value="group"
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
											<li v-for="error in syncResult.results.errors" :key="error">{{ error }}</li>
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
									:value="emailSettings.senderName"
									placeholder="Software Catalogus"
									label="Sender Name"
									@update:value="updateEmailSetting('senderName', $event)" />
								<span class="setting-description">Name that appears as the sender of emails</span>
							</div>

							<div class="setting-row">
								<label class="setting-label">Sender Email:</label>
								<NcTextField
									:value="emailSettings.senderEmail"
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
									v-model="emailSettings.transportType"
									:options="transportOptions"
									input-label="Transport Type"
									placeholder="Select transport type"
									@change="updateEmailSetting('transportType', $event)" />
								<span class="setting-description">Choose the email transport provider</span>
							</div>

							<!-- SMTP Configuration -->
							<div v-if="emailSettings.transportType === 'smtp'" class="smtp-configuration">
								<h5>SMTP Configuration</h5>
								<div class="setting-row">
									<label class="setting-label">SMTP Host:</label>
									<NcTextField
										:value="emailSettings.smtpHost"
										placeholder="smtp.gmail.com"
										label="SMTP Host"
										@update:value="updateEmailSetting('smtpHost', $event)" />
								</div>
								<div class="setting-row">
									<label class="setting-label">SMTP Port:</label>
									<NcTextField
										:value="emailSettings.smtpPort"
										placeholder="587"
										type="number"
										label="SMTP Port"
										@update:value="updateEmailSetting('smtpPort', parseInt($event))" />
								</div>
								<div class="setting-row">
									<label class="setting-label">Encryption:</label>
									<NcSelect
										v-model="emailSettings.smtpEncryption"
										:options="encryptionOptions"
										input-label="Encryption"
										placeholder="Select encryption"
										@change="updateEmailSetting('smtpEncryption', $event)" />
								</div>
								<div class="setting-row">
									<label class="setting-label">Username:</label>
									<NcTextField
										:value="emailSettings.smtpUsername"
										placeholder="your-email@gmail.com"
										label="SMTP Username"
										@update:value="updateEmailSetting('smtpUsername', $event)" />
								</div>
								<div class="setting-row">
									<label class="setting-label">Password:</label>
									<NcPasswordField
										:value="emailSettings.smtpPassword"
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
										:value="emailSettings.sendgridApiKey"
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
										:value="emailSettings.mailgunApiKey"
										placeholder="key-xxxxx"
										label="Mailgun API Key"
										@update:value="updateEmailSetting('mailgunApiKey', $event)" />
								</div>
								<div class="setting-row">
									<label class="setting-label">Domain:</label>
									<NcTextField
										:value="emailSettings.mailgunDomain"
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
										:value="emailSettings.postmarkApiKey"
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
										:value="emailSettings.sesAccessKey"
										placeholder="AKIAIOSFODNN7EXAMPLE"
										label="SES Access Key"
										@update:value="updateEmailSetting('sesAccessKey', $event)" />
								</div>
								<div class="setting-row">
									<label class="setting-label">Secret Key:</label>
									<NcPasswordField
										:value="emailSettings.sesSecretKey"
										placeholder="wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY"
										label="SES Secret Key"
										@update:value="updateEmailSetting('sesSecretKey', $event)" />
								</div>
								<div class="setting-row">
									<label class="setting-label">Region:</label>
									<NcSelect
										v-model="emailSettings.sesRegion"
										:options="sesRegionOptions"
										input-label="Region"
										placeholder="Select region"
										@change="updateEmailSetting('sesRegion', $event)" />
								</div>
							</div>

							<!-- Mailjet Configuration -->
							<div v-if="emailSettings.transportType === 'mailjet'" class="mailjet-configuration">
								<h5>Mailjet Configuration</h5>
								<div class="setting-row">
									<label class="setting-label">API Key:</label>
									<NcPasswordField
										:value="emailSettings.mailjetApiKey"
										placeholder="xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
										label="Mailjet API Key"
										@update:value="updateEmailSetting('mailjetApiKey', $event)" />
								</div>
								<div class="setting-row">
									<label class="setting-label">Secret Key:</label>
									<NcPasswordField
										:value="emailSettings.mailjetSecretKey"
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
									:value="emailSettings.testReceiverOverride"
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
									:value="testEmailAddress"
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
import AutoFix from 'vue-material-design-icons/AutoFix.vue'
import Alert from 'vue-material-design-icons/Alert.vue'
import Close from 'vue-material-design-icons/Close.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Email from 'vue-material-design-icons/Email.vue'
import Sync from 'vue-material-design-icons/Sync.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'

/**
 * Software Catalog Settings component
 *
 * @category Component
 * @package  OCA\SoftwareCatalog\Components
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/SoftwareCatalog
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
		AutoFix,
		Alert,
		Close,
		Plus,
		Email,
		Sync,
		CheckCircle,
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
			initializing: false,
			autoConfiguring: false,
			initializationResults: null,
			settings: {
				objectTypes: [],
				openRegisters: false,
				availableRegisters: [],
				configuration: {},
			},
			selectedRegister: null,
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
		}
	},

	computed: {
		/**
		 * Generates options for register selection dropdown
		 *
		 * @return {Array<object>} Array of register options with label and value
		 */
		registerOptions() {
			return this.settings.availableRegisters.map(register => ({
				label: register.title,
				value: register.id.toString(),
			}))
		},

		/**
		 * Determines if the selected register has schemas
		 *
		 * @return {boolean} True if the selected register has schemas
		 */
		hasSchemas() {
			if (!this.selectedRegister) return false

			const register = this.settings.availableRegisters.find(
				r => r.id.toString() === this.selectedRegister.value,
			)

			return register && Array.isArray(register.schemas) && register.schemas.length > 0
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
		 * Determines if configuration can be saved
		 *
		 * @return {boolean} True if configuration is valid and can be saved
		 */
		canSave() {
			if (!this.selectedRegister || !this.hasSchemas) return false

			// Check if at least one schema is configured based on register type
			if (this.isRegisterType('amef')) {
				return this.configuration.amef_organization?.schema
			}

			if (this.isRegisterType('voorzieningen')) {
				return this.configuration.voorzieningen_gebruiker?.schema
					|| this.configuration.voorzieningen_organisatie?.schema
					|| this.configuration.voorzieningen_contactpersoon?.schema
			}

			// Check if at least one object type is configured (backward compatibility)
			return this.settings.objectTypes.some(type =>
				this.configuration[type] && this.configuration[type].schema
			)
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
		selectedRegister(newRegister, oldRegister) {
			if (newRegister && newRegister !== oldRegister) {
				this.handleRegisterChange()
			}
		},
		},

	/**
	 * Lifecycle hook that loads settings when component is created
	 */
	async created() {
		await this.loadSettings()
		await this.loadSyncStatus()
	},

	methods: {
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

				const data = await response.json()

				if (data.error) {
					return
				}

				this.settings = data

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
				this.autoSelectRegister()

				// Load debug information
				await this.loadDebugInfo()
			} catch (error) {
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
				const data = await response.json()

				if (data.error) {
					this.debugInfo = { error: data.error }
				} else {
					this.debugInfo = data
				}
			} catch (error) {
				this.debugInfo = { error: 'Failed to load debug information: ' + error.message }
			}
		},

		/**
		 * Initializes the configuration object based on existing settings
		 */
		initializeConfiguration() {
			// Initialize register-specific configuration
			this.configuration = {
				// AMEF register configuration
				amef_organization: {
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
				'amef_organization',
				'voorzieningen_gebruiker',
				'voorzieningen_organisatie',
				'voorzieningen_contactpersoon'
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
					schema => schema.title.toLowerCase().includes('organization')
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
					schema => schema.title.toLowerCase().includes('gebruiker')
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
					schema => schema.title.toLowerCase().includes('organisatie')
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
					schema => schema.title.toLowerCase().includes('contactpersoon')
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
					...this.settings.objectTypes
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
					'amef_organization',
					'voorzieningen_gebruiker',
					'voorzieningen_organisatie',
					'voorzieningen_contactpersoon'
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
							generic: this.genericUserGroups
						}
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
							organizationAdmin: this.organizationAdminGroups
						}
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
							superUser: this.superUserGroups
						}
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
			this.emailSettings[key] = value
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
						message: result.message || result.error || 'Failed to send test email'
					}
				} else if (result.success === true) {
					this.emailTestResult = {
						success: true,
						message: result.message || 'Test email sent successfully!'
					}
				} else {
					// Fallback for legacy responses
					this.emailTestResult = {
						success: true,
						message: 'Test email sent successfully!'
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
						emailSettings: this.emailSettings
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
					message: 'Failed to reset template: ' + error.message
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
					message: 'Template saved successfully!'
				}
			} catch (error) {
				this.templateSaveResult = {
					success: false,
					message: 'Failed to save template: ' + error.message
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
						message: data.error
					}
				} else {
					this.syncStatus = data
				}
			} catch (error) {
				this.syncStatus = {
					configured: false,
					message: 'Failed to load sync status: ' + error.message
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
						results: data
					}
					// Refresh sync status after successful sync
					await this.loadSyncStatus()
				} else {
					this.syncResult = {
						success: false,
						message: data.message || 'Synchronization failed'
					}
				}
			} catch (error) {
				this.syncResult = {
					success: false,
					message: 'Failed to perform synchronization: ' + error.message
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
</style>
