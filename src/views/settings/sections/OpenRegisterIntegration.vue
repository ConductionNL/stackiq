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
	<NcSettingsSection
		name="OpenRegister Integration"
		description="Configure which schemas to use for organizations, contacts, and users">
		<div v-if="!loading">
			<!-- Warning if OpenRegister is not installed -->
			<NcNoteCard v-if="!versionInfo.openRegisterEnabled" type="warning">
				OpenRegister is not installed or not available. Please install it to use the Software Catalog with full functionality.
			</NcNoteCard>

			<!-- Tabs for OpenRegister Configuration -->
			<div v-if="versionInfo.openRegisterEnabled" class="openregister-tabs">
				<BTabs content-class="mt-3" justified>
					<!-- General Configuration Tab -->
					<BTab title="General Configuration" active>
						<div class="tab-content">
							<h4>Register Selection</h4>
							<p>Select the registers to use for your Software Catalog data</p>
							<div class="register-selection-grid">
								<div class="register-selection-item">
									<h5>Voorzieningen Register</h5>
									<p>Register for organizations, contacts, and users</p>
									<NcSelect
										v-model="voorzieningenRegister"
										:options="registerOptions"
										input-label="Select Voorzieningen Register"
										:disabled="loading"
										@change="handleVoorzieningenRegisterChange" />
								</div>

								<div class="register-selection-item">
									<h5>AMEF Register</h5>
									<p>Register for ArchiMate elements, relationships, and views</p>
									<NcSelect
										v-model="amefRegister"
										:options="registerOptions"
										input-label="Select AMEF Register"
										:disabled="loading"
										@change="handleAmefRegisterChange" />
								</div>
							</div>

							<!-- Configuration Actions -->
							<div v-if="voorzieningenRegister || amefRegister" class="button-container">
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
									@click="refreshSettings">
									<template #icon>
										<Refresh :size="20" />
									</template>
									Refresh
								</NcButton>
							</div>
						</div>
					</BTab>

					<!-- Voorzieningen Tab -->
					<BTab title="Voorzieningen">
						<div class="tab-content">
							<!-- Voorzieningen Schema Configuration -->
							<div v-if="voorzieningenRegister && voorzieningenSchemas.length > 0">
								<h4>Voorzieningen Schema Configuration</h4>
								<p>Configure schemas for the Voorzieningen register</p>
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
								</div>
							</div>

							<!-- Voorzieningen Empty State -->
							<div v-else-if="voorzieningenRegister && voorzieningenSchemas.length === 0">
								<NcNoteCard type="warning">
									The selected Voorzieningen register has no schemas. Please create schemas in this register.
								</NcNoteCard>
							</div>

							<!-- No Register Selected -->
							<div v-else>
								<NcNoteCard type="info">
									Please select a Voorzieningen register in the General Configuration tab first.
								</NcNoteCard>
							</div>
						</div>
					</BTab>

					<!-- AMEF Tab -->
					<BTab title="AMEF">
						<div class="tab-content">
							<!-- AMEF Schema Configuration -->
							<div v-if="amefRegister && amefSchemas.length > 0">
								<h4>AMEF Schema Configuration</h4>
								<p>Configure schemas for the AMEF register</p>
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

									<div class="object-type-section">
										<div class="object-type-header">
											<h5>Models Schema</h5>
											<span class="object-type-description">Schema for ArchiMate models</span>
										</div>
										<NcSelect
											v-model="configuration.amef_models.schema"
											:options="amefSchemaOptions"
											input-label="Models Schema"
											:disabled="loading"
											@change="validateConfiguration" />
									</div>

									<div class="object-type-section">
										<div class="object-type-header">
											<h5>Properties Schema</h5>
											<span class="object-type-description">Schema for ArchiMate property definitions</span>
										</div>
										<NcSelect
											v-model="configuration.amef_properties.schema"
											:options="amefSchemaOptions"
											input-label="Properties Schema"
											:disabled="loading"
											@change="validateConfiguration" />
									</div>

									<div class="object-type-section">
										<div class="object-type-header">
											<h5>Property Definitions Schema</h5>
											<span class="object-type-description">Schema for ArchiMate property definition objects</span>
										</div>
										<NcSelect
											v-model="configuration.amef_property_definitions.schema"
											:options="amefSchemaOptions"
											input-label="Property Definitions Schema"
											:disabled="loading"
											@change="validateConfiguration" />
									</div>
								</div>
							</div>

							<!-- AMEF Empty State -->
							<div v-else-if="amefRegister && amefSchemas.length === 0">
								<NcNoteCard type="warning">
									The selected AMEF register has no schemas. Please create schemas in this register.
								</NcNoteCard>
							</div>

							<!-- No Register Selected -->
							<div v-else>
								<NcNoteCard type="info">
									Please select an AMEF register in the General Configuration tab first.
								</NcNoteCard>
							</div>
						</div>
					</BTab>
				</BTabs>
			</div>
		</div>

		<!-- Loading State -->
		<NcLoadingIcon v-else
			class="loading-icon"
			:size="64"
			appearance="dark" />
	</NcSettingsSection>
</template>

<script>
/**
 * OpenRegister Integration Settings Component
 *
 * This component handles the configuration of OpenRegister schemas for
 * organizations, contacts, and users. It provides a tabbed interface
 * for managing Voorzieningen and AMEF register configurations.
 *
 * @author Ruben Linde <info@conduction.nl>
 * @copyright 2023 Conduction B.V.
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 */

import { settingsStore } from '../../../store/store.js'

// Nextcloud Vue components
import NcSettingsSection from '@nextcloud/vue/dist/Components/NcSettingsSection.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'

// Bootstrap Vue components
import { BTabs, BTab } from 'bootstrap-vue'

// Icons
import Save from 'vue-material-design-icons/ContentSave.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'

export default {
	name: 'OpenRegisterIntegration',

	components: {
		NcSettingsSection,
		NcSelect,
		NcButton,
		NcNoteCard,
		NcLoadingIcon,
		BTabs,
		BTab,
		Save,
		Refresh,
	},

	/**
	 * Component setup function for Composition API
	 * Provides access to the settings store
	 *
	 * @return {object} Setup object with store reference
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
			saving: false,
		}
	},

	computed: {
		// Store-connected computed properties
		loading() { return this.store.loading },
		versionInfo() { return this.store.versionInfo },
		configuration() { return this.store.configuration },
		registerOptions() { return this.store.registerOptions },
		voorzieningenSchemaOptions() { return this.store.voorzieningenSchemaOptions },
		amefSchemaOptions() { return this.store.amefSchemaOptions },
		voorzieningenSchemas() { return this.store.voorzieningenSchemas },
		amefSchemas() { return this.store.amefSchemas },

		// Two-way computed properties for register selections
		voorzieningenRegister: {
			get() { return this.store.voorzieningenRegister },
			set(value) { this.store.voorzieningenRegister = value },
		},
		amefRegister: {
			get() { return this.store.amefRegister },
			set(value) { this.store.amefRegister = value },
		},

		/**
		 * Determines if configuration can be saved
		 *
		 * @return {boolean} True if configuration is valid and can be saved
		 */
		canSave() {
			return this.voorzieningenRegister || this.amefRegister
		},
	},

	methods: {
		/**
		 * Handle Voorzieningen register change
		 * Updates schemas when register selection changes
		 *
		 * @param {object} register Selected register object
		 * @return {void}
		 */
		handleVoorzieningenRegisterChange(register) {
			this.store.handleVoorzieningenRegisterChange(register)
		},

		/**
		 * Handle AMEF register change
		 * Updates schemas when register selection changes
		 *
		 * @param {object} register Selected register object
		 * @return {void}
		 */
		handleAmefRegisterChange(register) {
			this.store.handleAmefRegisterChange(register)
		},

		/**
		 * Validate configuration
		 * Triggers validation in the store
		 *
		 * @return {void}
		 */
		validateConfiguration() {
			this.store.validateConfiguration()
		},

		/**
		 * Save configuration
		 * Saves the current configuration to the backend
		 *
		 * @return {Promise<void>}
		 */
		async saveConfiguration() {
			this.saving = true
			try {
				await this.store.saveConfiguration()
			} finally {
				this.saving = false
			}
		},

		/**
		 * Refresh settings
		 * Reloads settings from the backend
		 *
		 * @return {Promise<void>}
		 */
		async refreshSettings() {
			await this.store.loadSettings()
		},
	},
}
</script>

<style scoped>
.openregister-tabs {
	margin-top: 20px;
}

.tab-content {
	padding: 20px 0;
}

.register-selection-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
	gap: 20px;
	margin: 20px 0;
}

.register-selection-item {
	padding: 20px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background-color: var(--color-background-hover);
}

.register-selection-item h5 {
	margin: 0 0 8px 0;
	font-weight: 600;
	color: var(--color-main-text);
}

.register-selection-item p {
	margin: 0 0 16px 0;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}

.schema-configuration-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
	gap: 16px;
	margin-top: 20px;
}

.object-type-section {
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
}

.object-type-header {
	margin-bottom: 12px;
}

.object-type-header h5 {
	margin: 0 0 4px 0;
	font-weight: 600;
	color: var(--color-main-text);
}

.object-type-description {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.button-container {
	display: flex;
	gap: 12px;
	margin-top: 20px;
	padding-top: 20px;
	border-top: 1px solid var(--color-border);
}

.loading-icon {
	display: flex;
	justify-content: center;
	margin: 40px 0;
}
</style>
