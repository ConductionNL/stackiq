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
		:name="t('softwarecatalog', 'Email configuration')"
		:description="t('softwarecatalog', 'Configure email settings for notifications and user management')"
		:loading="loading"
		:show-save-button="true"
		:can-save="canSave"
		:saving="saving"
		:save-button-text="t('softwarecatalog', 'Save email settings')"
		:has-info-content="true"
		@save="saveEmailSettings">
		<StandardTabs
			:tabs="[
				{ key: 'settings', title: t('softwarecatalog', 'Settings') },
				{ key: 'email-types', title: t('softwarecatalog', 'Email types') },
				{ key: 'testing', title: t('softwarecatalog', 'Testing') },
				{ key: 'templates', title: t('softwarecatalog', 'Templates') }
			]"
			:active-tab="activeTab"
			@update:active-tab="activeTab = $event">
			<div v-show="activeTab === 'settings'" class="tab-panel">
				<div class="email-settings-section">
					<h3>{{ t('softwarecatalog', 'Email settings') }}</h3>
					<p>{{ t('softwarecatalog', 'Configure email notifications for organization and user events') }}</p>

					<!-- Enable Email Notifications -->
					<div class="setting-group">
						<NcCheckboxRadioSwitch
							:checked.sync="emailSettings.enabled"
							type="switch">
							{{ t('softwarecatalog', 'Enable email notifications') }}
						</NcCheckboxRadioSwitch>
						<p class="setting-description">
							{{ t('softwarecatalog', 'Enable or disable all email notifications from the system') }}
						</p>
					</div>

					<!-- Sender Information -->
					<div class="setting-group">
						<h4>{{ t('softwarecatalog', 'Sender information') }}</h4>
						<NcTextField
							:label="t('softwarecatalog', 'Sender email')"
							placeholder="noreply@example.com"
							:disabled="!emailSettings.enabled"
							:value="(emailSettings.senderEmail || '').toString()"
							@update:value="emailSettings.senderEmail = $event" />
						<NcTextField
							:label="t('softwarecatalog', 'Sender name')"
							placeholder="Software Catalog"
							:disabled="!emailSettings.enabled"
							:value="(emailSettings.senderName || '').toString()"
							@update:value="emailSettings.senderName = $event" />
					</div>

					<!-- Test Receiver Override -->
					<div class="setting-group">
						<h4>{{ t('softwarecatalog', 'Test configuration') }}</h4>
						<NcTextField
							:label="t('softwarecatalog', 'Test receiver override')"
							placeholder="test@example.com"
							:disabled="!emailSettings.enabled"
							:value="(emailSettings.testReceiverOverride || '').toString()"
							@update:value="emailSettings.testReceiverOverride = $event" />
						<p class="setting-description">
							{{ t('softwarecatalog', 'If set, all emails will be sent to this address instead of the intended recipients (useful for testing)') }}
						</p>
					</div>

					<!-- Transport Configuration -->
					<div class="setting-group">
						<h4>{{ t('softwarecatalog', 'Email transport') }}</h4>
						<NcSelect
							:value.sync="emailSettings.transportType"
							:options="[
								{ label: 'SMTP', value: 'smtp' },
								{ label: 'Mailjet', value: 'mailjet' },
								{ label: 'SendGrid', value: 'sendgrid' },
								{ label: t('softwarecatalog', 'Local mail'), value: 'mail' }
							]"
							:input-label="t('softwarecatalog', 'Transport type')"
							:disabled="!emailSettings.enabled" />
					</div>

					<!-- SMTP Configuration -->
					<div v-if="emailSettings.transportType === 'smtp'" class="setting-group smtp-config">
						<h4>{{ t('softwarecatalog', 'SMTP Configuration') }}</h4>
						<div class="smtp-fields">
							<NcTextField
								:label="t('softwarecatalog', 'SMTP Host')"
								placeholder="smtp.gmail.com"
								:disabled="!emailSettings.enabled"
								:value="(emailSettings.smtpHost || '').toString()"
								@update:value="emailSettings.smtpHost = $event" />
							<NcTextField
								:label="t('softwarecatalog', 'SMTP Port')"
								placeholder="587"
								type="text"
								:disabled="!emailSettings.enabled"
								:value="(emailSettings.smtpPort == null ? '' : String(emailSettings.smtpPort))"
								@update:value="emailSettings.smtpPort = $event" />
							<NcSelect
								:value.sync="emailSettings.smtpEncryption"
								:options="[
									{ label: t('softwarecatalog', 'None'), value: 'none' },
									{ label: 'TLS', value: 'tls' },
									{ label: 'SSL', value: 'ssl' }
								]"
								:input-label="t('softwarecatalog', 'Encryption')"
								:disabled="!emailSettings.enabled" />
							<NcTextField
								:label="t('softwarecatalog', 'SMTP Username')"
								placeholder="your-email@gmail.com"
								:disabled="!emailSettings.enabled"
								:value="(emailSettings.smtpUsername || '').toString()"
								@update:value="emailSettings.smtpUsername = $event" />
							<NcPasswordField
								:label="t('softwarecatalog', 'SMTP Password')"
								:placeholder="t('softwarecatalog', 'Your app password')"
								:disabled="!emailSettings.enabled"
								:value="(emailSettings.smtpPassword || '').toString()"
								@update:value="emailSettings.smtpPassword = $event" />
						</div>
					</div>

					<!-- Mailjet Configuration -->
					<div v-else-if="emailSettings.transportType === 'mailjet'" class="setting-group">
						<h4>{{ t('softwarecatalog', 'Mailjet configuration') }}</h4>
						<NcTextField
							:label="t('softwarecatalog', 'Mailjet API Key')"
							:disabled="!emailSettings.enabled"
							:value="(emailSettings.mailjetApiKey || '').toString()"
							@update:value="emailSettings.mailjetApiKey = $event" />
						<NcPasswordField
							:label="t('softwarecatalog', 'Mailjet API Secret')"
							:disabled="!emailSettings.enabled"
							:value="(emailSettings.mailjetApiSecret || '').toString()"
							@update:value="emailSettings.mailjetApiSecret = $event" />
					</div>
				</div>
			</div>

			<div v-show="activeTab === 'email-types'" class="tab-panel">
				<div class="email-types-section">
					<h3>{{ t('softwarecatalog', 'Email types') }}</h3>
					<p>{{ t('softwarecatalog', 'Configure which types of emails are automatically sent') }}</p>

					<div class="email-type-group">
						<NcCheckboxRadioSwitch
							:checked.sync="emailSettings.organizationRegistrationEnabled"
							type="switch"
							:disabled="!emailSettings.enabled">
							{{ t('softwarecatalog', 'Organization registration') }}
						</NcCheckboxRadioSwitch>
						<p class="setting-description">
							{{ t('softwarecatalog', 'Send emails when new organizations are registered') }}
						</p>
					</div>

					<div class="email-type-group">
						<NcCheckboxRadioSwitch
							:checked.sync="emailSettings.organizationActivationEnabled"
							type="switch"
							:disabled="!emailSettings.enabled">
							{{ t('softwarecatalog', 'Organization activation') }}
						</NcCheckboxRadioSwitch>
						<p class="setting-description">
							{{ t('softwarecatalog', 'Send emails when organizations are activated') }}
						</p>
					</div>

					<div class="email-type-group">
						<NcCheckboxRadioSwitch
							:checked.sync="emailSettings.userCreationEnabled"
							type="switch"
							:disabled="!emailSettings.enabled">
							{{ t('softwarecatalog', 'User creation') }}
						</NcCheckboxRadioSwitch>
						<p class="setting-description">
							{{ t('softwarecatalog', 'Send emails when new user accounts are created') }}
						</p>
					</div>

					<div class="email-type-group">
						<NcCheckboxRadioSwitch
							:checked.sync="emailSettings.userPasswordEnabled"
							type="switch"
							:disabled="!emailSettings.enabled">
							{{ t('softwarecatalog', 'Password reset') }}
						</NcCheckboxRadioSwitch>
						<p class="setting-description">
							{{ t('softwarecatalog', 'Send emails for password reset requests') }}
						</p>
					</div>
				</div>
			</div>

			<div v-show="activeTab === 'testing'" class="tab-panel">
				<div class="email-testing-section">
					<h3>{{ t('softwarecatalog', 'Email testing') }}</h3>
					<p>{{ t('softwarecatalog', 'Test your email configuration to ensure emails are delivered correctly') }}</p>

					<!-- Connection Test -->
					<div class="test-group">
						<h4>{{ t('softwarecatalog', 'Connection test') }}</h4>
						<p>{{ t('softwarecatalog', 'Test the connection to your email provider') }}</p>
						<NcButton
							type="secondary"
							:disabled="!emailSettings.enabled || testingConnection"
							@click="testEmailConnection">
							<template #icon>
								<NcLoadingIcon v-if="testingConnection" :size="20" />
								<Email v-else :size="20" />
							</template>
							{{ testingConnection ? t('softwarecatalog', 'Testing...') : t('softwarecatalog', 'Test connection') }}
						</NcButton>

						<div v-if="connectionTestResult" class="test-result">
							<NcNoteCard :type="connectionTestResult.success ? 'success' : 'error'">
								{{ connectionTestResult.message }}
							</NcNoteCard>
						</div>
					</div>

					<!-- Send Test Email -->
					<div class="test-group">
						<h4>{{ t('softwarecatalog', 'Send test email') }}</h4>
						<p>{{ t('softwarecatalog', 'Send a test email to verify delivery') }}</p>
						<NcTextField
							:value.sync="testEmailAddress"
							:label="t('softwarecatalog', 'Test email address')"
							placeholder="test@example.com" />
						<NcButton
							type="primary"
							:disabled="!emailSettings.enabled || testingEmail || !testEmailAddress"
							@click="sendTestEmail">
							<template #icon>
								<NcLoadingIcon v-if="testingEmail" :size="20" />
								<Email v-else :size="20" />
							</template>
							{{ testingEmail ? t('softwarecatalog', 'Sending...') : t('softwarecatalog', 'Send test email') }}
						</NcButton>

						<div v-if="testEmailResult" class="test-result">
							<NcNoteCard :type="testEmailResult.success ? 'success' : 'error'">
								{{ testEmailResult.message }}
							</NcNoteCard>
						</div>
					</div>
				</div>
			</div>

			<div v-show="activeTab === 'templates'" class="tab-panel">
				<div class="email-templates-section">
					<h3>{{ t('softwarecatalog', 'Email templates') }}</h3>
					<p>{{ t('softwarecatalog', 'Customize email templates for different types of notifications') }}</p>

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
									<h5>{{ t('softwarecatalog', 'Available variables:') }}</h5>
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
								:placeholder="t('softwarecatalog', 'Enter your template content here...')"
								:label="t('softwarecatalog', 'Template content')"
								rows="15"
								@update:value="updateTemplateContent($event)" />

							<div class="template-actions">
								<NcButton
									type="secondary"
									@click="resetTemplate">
									{{ t('softwarecatalog', 'Reset to Default') }}
								</NcButton>
								<NcButton
									type="primary"
									:disabled="loading || savingTemplate"
									@click="saveTemplate">
									<template #icon>
										<NcLoadingIcon v-if="savingTemplate" :size="20" />
										<Save v-else :size="20" />
									</template>
									{{ t('softwarecatalog', 'Save template') }}
								</NcButton>
							</div>

							<div v-if="templateSaveResult" class="save-results">
								<NcNoteCard :type="templateSaveResult.success ? 'success' : 'error'">
									{{ templateSaveResult.message || t('softwarecatalog', 'Template saved successfully!') }}
								</NcNoteCard>
							</div>
						</div>
					</div>
				</div>
			</div>
		</StandardTabs>

		<!-- Info Content Slot -->
		<template #info-content>
			<div class="email-config-info">
				<h3>About Email Configuration</h3>
				<p>Configure email notifications and templates for various system events including user registration, organization activation, and password resets.</p>

				<h4>Email Settings</h4>
				<p>Configure the basic email transport and sender information:</p>
				<ul>
					<li><strong>Transport Type</strong> - Choose between SMTP, Mailjet, or other email services</li>
					<li><strong>Sender Information</strong> - Set the 'From' name and email address</li>
					<li><strong>SMTP Configuration</strong> - Host, port, encryption, and authentication details</li>
					<li><strong>Test Override</strong> - Redirect all emails to a test address during development</li>
				</ul>

				<h4>Email Types</h4>
				<p>Control which types of emails are sent automatically:</p>
				<ul>
					<li><strong>Organization Registration</strong> - Sent when a new organization is registered</li>
					<li><strong>Organization Activation</strong> - Sent when an organization is activated</li>
					<li><strong>User Creation</strong> - Sent when a new user account is created</li>
					<li><strong>Password Reset</strong> - Sent when users request password resets</li>
				</ul>

				<h4>Email Templates</h4>
				<p>Customize the content and appearance of system emails:</p>
				<ul>
					<li><strong>Template Variables</strong> - Use placeholders like {{ organization.name }} and {{ user.email }}</li>
					<li><strong>HTML Support</strong> - Include formatting, links, and basic styling</li>
					<li><strong>Personalization</strong> - Templates are automatically populated with relevant data</li>
					<li><strong>Multi-language</strong> - Support for different languages and locales</li>
				</ul>

				<h4>Testing</h4>
				<p>Validate your email configuration:</p>
				<ul>
					<li><strong>Connection Test</strong> - Verify SMTP settings and connectivity</li>
					<li><strong>Template Preview</strong> - See how emails will look with sample data</li>
					<li><strong>Test Emails</strong> - Send actual test emails to verify delivery</li>
					<li><strong>Override Testing</strong> - Use test receiver override during development</li>
				</ul>

				<h4>Gmail Configuration</h4>
				<p>For Gmail/Google Workspace accounts:</p>
				<ul>
					<li>Use smtp.gmail.com as the host</li>
					<li>Set port to 587 with TLS encryption</li>
					<li>Enable 2-factor authentication on your Google account</li>
					<li>Generate an App Password for the SMTP username/password</li>
					<li>Never use your regular Google password for SMTP</li>
				</ul>
			</div>
		</template>
	</AlwaysVisibleSection>
</template>

<script>
/**
 * Email Configuration Component
 *
 * This component handles email settings configuration including
 * transport settings, email types, testing, and template management.
 *
 * @author Ruben Linde <info@conduction.nl>
 * @copyright 2023 Conduction B.V.
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 */

import { settingsStore } from '../../../store/store.js'
import { showError, showSuccess } from '@nextcloud/dialogs'

// Components
import AlwaysVisibleSection from '../../../components/AlwaysVisibleSection.vue'

// Nextcloud Vue components
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'
import NcPasswordField from '@nextcloud/vue/dist/Components/NcPasswordField.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'
import NcTextArea from '@nextcloud/vue/dist/Components/NcTextArea.js'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'

// Icons
import Save from 'vue-material-design-icons/ContentSave.vue'
import Email from 'vue-material-design-icons/Email.vue'

// Bootstrap Vue components for tabs
import StandardTabs from '../../../components/StandardTabs.vue'

export default {
	name: 'EmailConfiguration',

	components: {
		AlwaysVisibleSection,
		NcButton,
		NcTextField,
		NcPasswordField,
		NcSelect,
		NcCheckboxRadioSwitch,
		NcTextArea,
		NcNoteCard,
		NcLoadingIcon,
		Save,
		Email,
		StandardTabs,
	},

	setup() {
		return {
			store: settingsStore,
		}
	},

	data() {
		return {
			activeTab: 'settings', // Default active tab
			saving: false,
			testingConnection: false,
			testingEmail: false,
			savingTemplate: false,
			testEmailAddress: '',
			connectionTestResult: null,
			testEmailResult: null,
			templateSaveResult: null,
			activeTemplate: 'organization_registration',
			availableTemplates: [
				{
					key: 'organization_registration',
					name: 'Organization Registration',
					description: 'Email sent when a new organization is registered',
					variables: {
						'organization.name': 'Name of the organization',
						'organization.email': 'Organization contact email',
						'organization.website': 'Organization website',
						'user.name': 'Name of the registering user',
						'user.email': 'Email of the registering user',
						activation_url: 'URL to activate the organization',
					},
				},
				{
					key: 'organization_activation',
					name: 'Organization Activation',
					description: 'Email sent when an organization is activated',
					variables: {
						'organization.name': 'Name of the organization',
						'organization.email': 'Organization contact email',
						'user.name': 'Name of the contact person',
						'user.email': 'Email of the contact person',
						login_url: 'URL to log into the system',
					},
				},
				{
					key: 'user_creation',
					name: 'User Creation',
					description: 'Email sent when a new user account is created',
					variables: {
						'user.name': 'Name of the new user',
						'user.email': 'Email of the new user',
						'user.username': 'Username for the new account',
						'organization.name': 'Name of the user\'s organization',
						login_url: 'URL to log into the system',
						password_reset_url: 'URL to set initial password',
					},
				},
				{
					key: 'password_reset',
					name: 'Password Reset',
					description: 'Email sent for password reset requests',
					variables: {
						'user.name': 'Name of the user',
						'user.email': 'Email of the user',
						reset_url: 'URL to reset the password',
						expiry_time: 'When the reset link expires',
					},
				},
			],
			templates: {},
		}
	},

	computed: {
		loading() { return this.store.loading },
		emailSettings: {
			get() { return this.store.emailSettings },
			set(value) { this.store.emailSettings = value },
		},
		canSave() {
			// Always allow saving for email settings
			return true
		},
	},

	async created() {
		await this.loadTemplates()
	},

	methods: {
		/**
		 * Save email settings using the settings store
		 */
		async saveEmailSettings() {
			this.saving = true
			try {
				await this.store.saveEmailSettings()
			} catch (error) {
				console.error('Failed to save email settings:', error)
			} finally {
				this.saving = false
			}
		},

		/**
		 * Test email connection using the settings store
		 */
		async testEmailConnection() {
			this.testingConnection = true
			this.connectionTestResult = null

			try {
				const result = await this.store.testEmailConnection()
				this.connectionTestResult = result
			} catch (error) {
				console.error('Connection test failed:', error)
				this.connectionTestResult = {
					success: false,
					message: t('softwarecatalog', 'Connection test failed: {error}', { error: error.message }),
				}
			} finally {
				this.testingConnection = false
			}
		},

		/**
		 * Send test email using the settings store
		 */
		async sendTestEmail() {
			this.testingEmail = true
			this.testEmailResult = null

			try {
				const result = await this.store.sendTestEmail(this.testEmailAddress)
				this.testEmailResult = result
			} catch (error) {
				console.error('Test email failed:', error)
				this.testEmailResult = {
					success: false,
					message: t('softwarecatalog', 'Test email failed: {error}', { error: error.message }),
				}
			} finally {
				this.testingEmail = false
			}
		},

		/**
		 * Load email templates from settings store
		 * Templates are loaded as part of the consolidated configuration
		 */
		async loadTemplates() {
			try {
				// Templates should be loaded from the settings store's consolidated config
				// For now, we'll use empty templates until the backend provides this data
				this.templates = {}
				console.info('Email templates functionality is not yet implemented in the backend')
			} catch (error) {
				console.error('Failed to load templates:', error)
			}
		},

		/**
		 * Get active template name
		 */
		getActiveTemplateName() {
			const template = this.availableTemplates.find(t => t.key === this.activeTemplate)
			return template ? template.name : ''
		},

		/**
		 * Get active template description
		 */
		getActiveTemplateDescription() {
			const template = this.availableTemplates.find(t => t.key === this.activeTemplate)
			return template ? template.description : ''
		},

		/**
		 * Get active template variables
		 */
		getActiveTemplateVariables() {
			const template = this.availableTemplates.find(t => t.key === this.activeTemplate)
			return template ? template.variables : {}
		},

		/**
		 * Get active template content
		 */
		getActiveTemplateContent() {
			return this.templates[this.activeTemplate] || ''
		},

		/**
		 * Update template content
		 *
		 * @param {string} content Description: New template content to save for the currently active template
		 * @return {void}
		 */
		updateTemplateContent(content) {
			this.templates[this.activeTemplate] = content
		},

		/**
		 * Format template variable for display
		 *
		 * @param {string} variable Description: Variable key (e.g., 'user.email') to format as a template placeholder
		 * @return {string}
		 */
		formatTemplateVariable(variable) {
			return `{{ ${variable} }}`
		},

		/**
		 * Insert variable into the active template at the end of the content
		 *
		 * @param {string} variable Description: Variable key to insert (e.g., 'organization.name')
		 * @return {void}
		 */
		insertVariable(variable) {
			const formattedVariable = this.formatTemplateVariable(variable)
			const currentContent = this.getActiveTemplateContent()
			this.updateTemplateContent(currentContent + formattedVariable)
		},

		/**
		 * Reset template to default
		 * TODO: Implement template reset functionality when backend supports it
		 */
		async resetTemplate() {
			try {
				// Template reset functionality is not yet implemented in the backend
				console.info('Template reset functionality is not yet implemented in the backend')
				showSuccess(t('softwarecatalog', 'Template reset functionality will be available in a future update'))
			} catch (error) {
				console.error('Failed to reset template:', error)
				showError(t('softwarecatalog', 'Failed to reset template: {error}', { error: error.message }))
			}
		},

		/**
		 * Save template
		 * TODO: Implement template save functionality when backend supports it
		 */
		async saveTemplate() {
			this.savingTemplate = true
			this.templateSaveResult = null

			try {
				// Template save functionality is not yet implemented in the backend
				console.info('Template save functionality is not yet implemented in the backend')
				this.templateSaveResult = { success: true, message: t('softwarecatalog', 'Template saved locally (backend implementation pending)') }
				showSuccess(t('softwarecatalog', 'Template save functionality will be available in a future update'))
			} catch (error) {
				console.error('Failed to save template:', error)
				this.templateSaveResult = {
					success: false,
					message: t('softwarecatalog', 'Failed to save template: {error}', { error: error.message }),
				}
				showError(t('softwarecatalog', 'Failed to save template: {error}', { error: error.message }))
			} finally {
				this.savingTemplate = false
			}
		},
	},
}
</script>

<style scoped>
.email-settings-section,
.email-types-section,
.email-testing-section,
.email-templates-section {
	padding: 1rem 0;
}

.setting-group,
.email-type-group,
.test-group {
	margin-bottom: 2rem;
	padding-bottom: 1rem;
	border-bottom: 1px solid var(--color-border);
}

.setting-group:last-child,
.email-type-group:last-child,
.test-group:last-child {
	border-bottom: none;
}

.setting-group h4,
.test-group h4 {
	margin: 0 0 1rem 0;
	font-size: 1rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.setting-description {
	margin: 0.5rem 0 0 0;
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
}

.smtp-config .smtp-fields {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 1rem;
}

.smtp-config .smtp-fields > :nth-child(3) {
	grid-column: 1 / -1;
}

.smtp-config .smtp-fields > :nth-child(4),
.smtp-config .smtp-fields > :nth-child(5) {
	grid-column: 1 / -1;
}

.test-result {
	margin-top: 1rem;
}

.template-tabs {
	margin-top: 1rem;
}

.tab-buttons {
	display: flex;
	gap: 0.5rem;
	margin-bottom: 1rem;
	flex-wrap: wrap;
}

.template-editor {
	margin-top: 1rem;
}

.template-info {
	margin-bottom: 1rem;
	padding: 1rem;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
}

.template-info h4 {
	margin: 0 0 0.5rem 0;
	font-size: 1rem;
	font-weight: 600;
}

.template-info p {
	margin: 0 0 1rem 0;
	color: var(--color-text-maxcontrast);
}

.available-variables h5 {
	margin: 0 0 0.5rem 0;
	font-size: 0.875rem;
	font-weight: 600;
}

.variables-list {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem;
}

.variable-tag {
	display: inline-block;
	padding: 0.25rem 0.5rem;
	background: var(--color-primary-light);
	color: var(--color-primary-text);
	border-radius: var(--border-radius);
	font-size: 0.75rem;
	font-family: monospace;
	cursor: pointer;
	transition: background-color 0.2s;
}

.variable-tag:hover {
	background: var(--color-primary);
}

.template-actions {
	display: flex;
	gap: 1rem;
	margin: 1rem 0;
}

.save-results {
	margin-top: 1rem;
}
</style>
