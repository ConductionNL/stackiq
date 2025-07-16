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
						input-label="Register"
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
							<div class="status-item">
								<span class="status-label">Contactgegevens:</span>
								<span v-if="configuration.voorzieningen_contactgegevens?.schema" class="status-configured">✓ Configured</span>
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
									<li><code>beheerder</code> - System administrators and managers</li>
									<li><code>inkoper</code> - Procurement specialists</li>
									<li><code>ambtenaar</code> - Civil servants (auto-assigned for gemeente organizations)</li>
									<li><code>software-catalog-users</code> - All software catalog users</li>
								</ul>
							</div>
						</div>
					</div>
				</div>
			</div>
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
									placeholder="Select transport type"
									@update:value="updateEmailSetting('transportType', $event)">
									<template #option="{ option }">
										{{ option.label }}
									</template>
								</NcSelect>
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
										placeholder="Select encryption"
										@update:value="updateEmailSetting('smtpEncryption', $event)">
										<template #option="{ option }">
											{{ option.label }}
										</template>
									</NcSelect>
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
										placeholder="Select region"
										@update:value="updateEmailSetting('sesRegion', $event)">
										<template #option="{ option }">
											{{ option.label }}
										</template>
									</NcSelect>
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
					|| this.configuration.voorzieningen_contactgegevens?.schema
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

				this.initializeConfiguration()
				this.autoSelectRegister()

				// Load generic user groups
				await this.loadGenericUserGroups()

				// Load email settings
				await this.loadEmailSettings()

				// Load debug information
				await this.loadDebugInfo()
			} catch (error) {
			} finally {
				this.loading = false
			}
		},

		/**
		 * Loads email settings from the backend API
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		async loadEmailSettings() {
			try {
				const response = await fetch('/index.php/apps/softwarecatalog/api/email-settings')
				const data = await response.json()

				if (data.success && data.data) {
					// Update email settings with loaded data
					this.emailSettings = {
						enabled: data.data.enabled ?? false,
						senderEmail: data.data.senderEmail ?? '',
						senderName: data.data.senderName ?? '',
						testReceiverOverride: data.data.testReceiverOverride ?? '',
						organizationRegistrationEnabled: data.data.organizationRegistrationEnabled ?? true,
						organizationActivationEnabled: data.data.organizationActivationEnabled ?? true,
						userCreationEnabled: data.data.userCreationEnabled ?? true,
						userPasswordEnabled: data.data.userPasswordEnabled ?? true,
						// Transport configuration
						transportType: data.data.transportType ?? 'smtp',
						// SMTP configuration
						smtpHost: data.data.smtpHost ?? 'localhost',
						smtpPort: data.data.smtpPort ?? 587,
						smtpEncryption: data.data.smtpEncryption ?? 'tls',
						smtpUsername: data.data.smtpUsername ?? '',
						smtpPassword: data.data.smtpPassword ?? '',
						// SendGrid configuration
						sendgridApiKey: data.data.sendgridApiKey ?? '',
						// Mailgun configuration
						mailgunApiKey: data.data.mailgunApiKey ?? '',
						mailgunDomain: data.data.mailgunDomain ?? '',
						// Postmark configuration
						postmarkApiKey: data.data.postmarkApiKey ?? '',
						// Amazon SES configuration
						sesAccessKey: data.data.sesAccessKey ?? '',
						sesSecretKey: data.data.sesSecretKey ?? '',
						sesRegion: data.data.sesRegion ?? 'us-east-1',
						// Mailjet configuration
						mailjetApiKey: data.data.mailjetApiKey ?? '',
						mailjetSecretKey: data.data.mailjetSecretKey ?? '',
					}
				}
			} catch (error) {
				// Use default settings if loading fails
				this.emailSettings = {
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
				}
			}
		},

		/**
		 * Loads generic user groups from the backend API
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		async loadGenericUserGroups() {
			try {
				const response = await fetch('/index.php/apps/softwarecatalog/api/settings/generic-user-groups')
				const data = await response.json()

				if (data.error) {
					this.genericUserGroups = ['beheerder', 'inkoper', 'ambtenaar', 'software-catalog-users']
				} else {
					this.genericUserGroups = data.groups || []
				}
			} catch (error) {
				this.genericUserGroups = ['beheerder', 'inkoper', 'ambtenaar', 'software-catalog-users']
			}
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
				voorzieningen_contactgegevens: {
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
				'voorzieningen_contactpersoon',
				'voorzieningen_contactgegevens'
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

				const contactgegevensSchema = register.schemas.find(
					schema => schema.title.toLowerCase().includes('contactgegevens')
				)
				if (contactgegevensSchema) {
					this.configuration = {
						...this.configuration,
						voorzieningen_contactgegevens: {
							...this.configuration.voorzieningen_contactgegevens,
							schema: {
								label: contactgegevensSchema.title,
								value: contactgegevensSchema.id.toString(),
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
					'voorzieningen_contactgegevens',
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
					'voorzieningen_contactpersoon',
					'voorzieningen_contactgegevens'
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
				const response = await fetch('/index.php/apps/softwarecatalog/api/settings/generic-user-groups', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({ groups: this.genericUserGroups }),
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
				const response = await fetch('/index.php/apps/softwarecatalog/api/email/settings', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({ emailSettings: this.emailSettings }),
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
</style>
