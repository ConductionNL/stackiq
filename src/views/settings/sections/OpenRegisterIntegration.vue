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
		<!-- Buttons in Title Section -->
		<template #title>
			<div class="section-title-with-buttons">
				<span>OpenRegister Integration</span>
				<div class="title-buttons">
					<NcButton
						class="title-save-button"
						type="primary"
						:disabled="loading || saving || !canSave"
						@click="saveConfiguration">
						<template #icon>
							<NcLoadingIcon v-if="saving" :size="32" />
							<Save v-else :size="20" />
						</template>
						Save Configuration
					</NcButton>
					<NcButton
						class="title-refresh-button"
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
		</template>
		<div v-if="!loading">
			<!-- Warning if OpenRegister is not installed -->
			<NcNoteCard v-if="!versionInfo.openRegisterEnabled" type="warning">
				OpenRegister is not installed or not available. Please install it to use the Software Catalog with full functionality.
			</NcNoteCard>

			<!-- Tabs for OpenRegister Configuration -->
			<div v-if="versionInfo.openRegisterEnabled" class="openregister-tabs">
				<StandardTabs
					:tabs="[
						{ key: 'general', title: 'General Configuration' },
						{ key: 'voorzieningen', title: `Voorzieningen${hasVoorzieningenConfigChanges() ? ' *' : ''}` },
						{ key: 'amef', title: `AMEF${hasAmefConfigChanges() ? ' *' : ''}` },
					]"
					:active-tab="activeTab"
					@update:active-tab="activeTab = $event">
					<!-- General Configuration Tab -->
					<div v-show="activeTab === 'general'" class="tab-panel">
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
						</div>
					</div>

					<!-- Voorzieningen Tab -->
					<div v-show="activeTab === 'voorzieningen'" class="tab-panel">
						<div class="tab-content">
							<!-- Voorzieningen Schema Configuration -->
							<div v-if="voorzieningenRegister && voorzieningenSchemas.length > 0">
								<h4>Voorzieningen Schema Configuration</h4>
								<p>Configure schemas for the Voorzieningen register</p>
								<div class="schema-configuration-grid">
									<div
										v-for="item in voorzieningenItems"
										:key="item.key"
										class="object-type-section">
										<div class="object-type-header">
											<h5>{{ item.title }}</h5>
											<span class="object-type-description">{{ item.description }}</span>
										</div>
										<NcSelect
											v-model="configuration[item.key].schema"
											:options="voorzieningenSchemaOptions"
											:input-label="item.title"
											:disabled="loading"
											@change="validateConfiguration" />
									</div>
								</div>
							</div>

							<!-- Voorzieningen Configuration Save Button -->
							<div v-if="voorzieningenRegister && voorzieningenSchemas.length > 0" class="voorzieningen-save-section">
								<div class="save-button-container">
									<NcButton
										type="primary"
										:disabled="loading || saving || !hasVoorzieningenConfigChanges()"
										@click="saveConfiguration">
										<template #icon>
											<NcLoadingIcon v-if="saving" :size="16" />
											<Save v-else :size="16" />
										</template>
										{{ saving ? 'Saving...' : 'Save Voorzieningen Configuration' }}
									</NcButton>
									<p class="save-help-text">
										Save your Voorzieningen schema configuration to enable organization and contact management.
									</p>
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
					</div>

					<!-- AMEF Tab -->
					<div v-show="activeTab === 'amef'" class="tab-panel">
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

							<!-- AMEF Configuration Save Button -->
							<div v-if="amefRegister && amefSchemas.length > 0" class="amef-save-section">
								<div class="save-button-container">
									<NcButton
										type="primary"
										:disabled="loading || saving || !hasAmefConfigChanges()"
										@click="saveConfiguration">
										<template #icon>
											<NcLoadingIcon v-if="saving" :size="16" />
											<Save v-else :size="16" />
										</template>
										{{ saving ? 'Saving...' : 'Save AMEF Configuration' }}
									</NcButton>
									<p class="save-help-text">
										Save your AMEF schema configuration to enable ArchiMate import/export functionality.
									</p>
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
					</div>
				</StandardTabs>
			</div>
		</div>

		<!-- Loading State -->
		<NcLoadingIcon v-else
			class="loading-icon"
			:size="32"
			appearance="dark" />
		<p v-if="loading" class="loading-text">
			Loading OpenRegister configuration...
		</p>
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

// Custom components
import StandardTabs from '../../../components/StandardTabs.vue'

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
		StandardTabs,
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
			activeTab: 'general', // Default active tab
		}
	},

	computed: {
		// Store-connected computed properties
		loading() {
			return this.store.loadingOpenRegisterConfig
		},
		versionInfo() { return this.store.versionInfo },
		configuration() { return this.store.configuration },
		registerOptions() { return this.store.registerOptions },
		voorzieningenSchemaOptions() { return this.store.voorzieningenSchemaOptions },
		amefSchemaOptions() { return this.store.amefSchemaOptions },
		voorzieningenSchemas() { return this.store.voorzieningenSchemas },
		amefSchemas() { return this.store.amefSchemas },

		// Dynamic list of all voorzieningen schema config entries
		voorzieningenItems() {
			return [
				{ key: 'voorzieningen_organisatie', title: 'Organisatie Schema', description: 'Schema for organizations' },
				{ key: 'voorzieningen_contactpersoon', title: 'Contactpersoon Schema', description: 'Schema for contact persons' },
				{ key: 'voorzieningen_voorziening', title: 'Voorziening Schema', description: 'Schema for provisions' },
				{ key: 'voorzieningen_voorziening_aanbod', title: 'Voorziening Aanbod Schema', description: 'Schema for provision offers' },
				{ key: 'voorzieningen_voorziening_versie', title: 'Voorziening Versie Schema', description: 'Schema for provision versions' },
				{ key: 'voorzieningen_kwetsbaarheid', title: 'Kwetsbaarheid Schema', description: 'Schema for vulnerabilities' },
				{ key: 'voorzieningen_contract', title: 'Contract Schema', description: 'Schema for contracts' },
				{ key: 'voorzieningen_standaard', title: 'Standaard Schema', description: 'Schema for standards' },
				{ key: 'voorzieningen_review', title: 'Review Schema', description: 'Schema for reviews' },
				{ key: 'voorzieningen_koppeling', title: 'Koppeling Schema', description: 'Schema for links' },
				{ key: 'voorzieningen_beoordeeling', title: 'Beoordeeling Schema', description: 'Schema for assessments' },
				{ key: 'voorzieningen_voorziening_module', title: 'Voorziening Module Schema', description: 'Schema for provision modules' },
				{ key: 'voorzieningen_verklaring', title: 'Verklaring Schema', description: 'Schema for declarations' },
				{ key: 'voorzieningen_koppeling_gebruik', title: 'Koppeling Gebruik Schema', description: 'Schema for link usage' },
				{ key: 'voorzieningen_compliancy', title: 'Compliancy Schema', description: 'Schema for compliancy' },
				{ key: 'voorzieningen_module_gebruik', title: 'Module Gebruik Schema', description: 'Schema for module usage' },
				{ key: 'voorzieningen_module_versie', title: 'Module Versie Schema', description: 'Schema for module versions' },
				{ key: 'voorzieningen_sector', title: 'Sector Schema', description: 'Schema for sectors' },
			]
		},

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
			// Check if any register is selected
			const hasRegisters = this.voorzieningenRegister || this.amefRegister
			
			// Check if AMEF configuration has been modified
			const amefConfigModified = this.hasAmefConfigChanges()
			
			// Check if Voorzieningen configuration has been modified
			const voorzieningenConfigModified = this.hasVoorzieningenConfigChanges()
			
			return hasRegisters && (amefConfigModified || voorzieningenConfigModified)
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
		 * Check if AMEF configuration has been modified
		 * Compares current configuration with original values
		 *
		 * @return {boolean} True if AMEF configuration has changed
		 */
		hasAmefConfigChanges() {
			if (!this.amefRegister) return false

			const amefKeys = [
				'amef_elements',
				'amef_organization',
				'amef_relationships',
				'amef_views',
				'amef_models',
				'amef_properties',
				'amef_property_definitions',
			]

			return amefKeys.some(key => {
				const config = this.configuration[key]
				return config && config.schema && config.schema.value !== undefined
			})
		},

		/**
		 * Check if Voorzieningen configuration has been modified
		 * Compares current configuration with original values
		 *
		 * @return {boolean} True if Voorzieningen configuration has changed
		 */
		hasVoorzieningenConfigChanges() {
			if (!this.voorzieningenRegister) return false

			const voorzieningenKeys = [
				'voorzieningen_organisatie',
				'voorzieningen_contactpersoon',
				'voorzieningen_voorziening',
				'voorzieningen_voorziening_aanbod',
				'voorzieningen_voorziening_versie',
				'voorzieningen_kwetsbaarheid',
				'voorzieningen_contract',
				'voorzieningen_standaard',
				'voorzieningen_review',
				'voorzieningen_koppeling',
				'voorzieningen_beoordeeling',
				'voorzieningen_voorziening_module',
				'voorzieningen_verklaring',
				'voorzieningen_koppeling_gebruik',
				'voorzieningen_compliancy',
				'voorzieningen_module_gebruik',
				'voorzieningen_module_versie',
				'voorzieningen_sector',
			]

			return voorzieningenKeys.some(key => {
				const config = this.configuration[key]
				return config && config.schema && config.schema.value !== undefined
			})
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
			// Only reload the specific configurations needed for this component
			await Promise.all([
				this.store.loadAmefConfig(),
				this.store.loadVoorzieningenConfig(),
			])
		},
	},
}
</script>

<style scoped>
.openregister-tabs {
	margin-top: 20px;
}

.tab-content {
	padding: 0;
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

.amef-save-section {
	margin-top: 24px;
	padding: 20px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
}

.save-button-container {
	text-align: center;
}

.save-help-text {
	margin: 12px 0 0 0;
	font-size: 14px;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.section-title-with-buttons {
	display: flex;
	align-items: center;
	justify-content: space-between;
	width: 100%;
}

.title-buttons {
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

.loading-icon {
	display: flex;
	justify-content: center;
	margin: 40px 0;
}

.loading-text {
	text-align: center;
	color: var(--color-text-lighter);
	margin-top: 1rem;
	font-size: 14px;
}
</style>
