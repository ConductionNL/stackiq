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
	<AlwaysVisibleSection
		name="OpenRegister Integration"
		description="Configure which schemas to use for organizations, contacts, and users"
		:loading="loading"
		loading-text="Loading OpenRegister configuration..."
		:show-save-button="true"
		:show-refresh-button="true"
		:can-save="canSave"
		:saving="saving"
		save-button-text="Save Configuration"
		@save="saveConfiguration"
		@refresh="refreshSettings">
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
							<div class="register-selection-grid">
								<div class="register-selection-item">
									<NcSelect
										v-model="voorzieningenRegister"
										:options="registerOptions"
										input-label="Select Voorzieningen Register"
										:loading="loadingRegisters"
										:disabled="loadingRegisters"
										@update:model-value="handleVoorzieningenRegisterChange" />
								</div>

								<div class="register-selection-item">
									<NcSelect
										v-model="amefRegister"
										:options="registerOptions"
										input-label="Select AMEF Register"
										:loading="loadingRegisters"
										:disabled="loadingRegisters"
										@update:model-value="handleAmefRegisterChange" />
								</div>
							</div>
						</div>
					</div>

					<!-- Voorzieningen Tab -->
					<div v-show="activeTab === 'voorzieningen'" class="tab-panel">
						<div class="tab-content">
							<!-- Voorzieningen Schema Configuration -->
							<div v-if="voorzieningenRegister && voorzieningenSchemas.length > 0">
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
											:loading="loadingVoorzieningenSchemas"
											:disabled="loadingVoorzieningenSchemas"
											@update:model-value="validateConfiguration" />
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
					</div>

					<!-- AMEF Tab -->
					<div v-show="activeTab === 'amef'" class="tab-panel">
						<div class="tab-content">
							<!-- AMEF Schema Configuration -->
							<div v-if="amefRegister && amefSchemas.length > 0">
								<div class="schema-configuration-grid">
									<div
										v-for="item in amefItems"
										:key="item.key"
										class="object-type-section">
										<div class="object-type-header">
											<h5>{{ item.title }}</h5>
											<span class="object-type-description">{{ item.description }}</span>
										</div>
										<NcSelect
											v-model="configuration[item.key].schema"
											:options="amefSchemaOptions"
											:input-label="item.title"
											:loading="loadingAmefSchemas"
											:disabled="loadingAmefSchemas"
											@update:model-value="validateConfiguration" />
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
					</div>
				</StandardTabs>
			</div>
		</div>
	</AlwaysVisibleSection>
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
 * @license EUPL-1.2
 * @version 1.0.0
 */

import { settingsStore } from '../../../store/store.js'
import { showError, showSuccess } from '@nextcloud/dialogs'

// Nextcloud Vue components
import { NcSelect, NcNoteCard } from '@nextcloud/vue'

// Custom components
import StandardTabs from '../../../components/StandardTabs.vue'
import AlwaysVisibleSection from '../../../components/AlwaysVisibleSection.vue'

export default {
	name: 'OpenRegisterIntegration',

	components: {
		NcSelect,
		NcNoteCard,
		StandardTabs,
		AlwaysVisibleSection,
	},

	/**
	 * Component setup function for Composition API
	 * Provides access to the settings store
	 *
	 * @return {object} Setup object with store reference
	  * @spec openspec/specs/fe-settings-ui/spec.md
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
		/**
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		loading() {
			return this.store.loadingOpenRegisterConfig
		},
		/**
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		loadingRegisters() {
			return this.store.isLoadingRegisters
		},
		/**
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		loadingVoorzieningenSchemas() {
			return this.store.isLoadingVoorzieningenSchemas
		},
		/**
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		loadingAmefSchemas() {
			return this.store.isLoadingAmefSchemas
		},
		/**
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		versionInfo() { return this.store.versionInfo },
		/**
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		configuration() { return this.store.configuration },
		/**
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		registerOptions() { return this.store.registerOptions },
		/**
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		voorzieningenSchemaOptions() { return this.store.voorzieningenSchemaOptions },
		/**
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		amefSchemaOptions() { return this.store.amefSchemaOptions },
		/**
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		voorzieningenSchemas() { return this.store.voorzieningenSchemas },
		/**
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		amefSchemas() { return this.store.amefSchemas },

		// Dynamic list of all voorzieningen schema config entries
		/**
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		voorzieningenItems() {
			return [
				{ key: 'voorzieningen_organisatie_schema', title: 'Organisatie Schema', description: 'Schema for organizations' },
				{ key: 'voorzieningen_contactpersoon_schema', title: 'Contactpersoon Schema', description: 'Schema for contact persons' },
				{ key: 'voorzieningen_suite_schema', title: 'Suite Schema', description: 'Schema for suites' },
				{ key: 'voorzieningen_dienst_schema', title: 'Dienst Schema', description: 'Schema for services' },
				{ key: 'voorzieningen_kwetsbaarheid_schema', title: 'Kwetsbaarheid Schema', description: 'Schema for vulnerabilities' },
				{ key: 'voorzieningen_gebruik_schema', title: 'Gebruik Schema', description: 'Schema for usage' },
				{ key: 'voorzieningen_contract_schema', title: 'Contract Schema', description: 'Schema for contracts' },
				{ key: 'voorzieningen_koppeling_schema', title: 'Koppeling Schema', description: 'Schema for connections/links' },
				{ key: 'voorzieningen_beoordeeling_schema', title: 'Beoordeeling Schema', description: 'Schema for assessments' },
				{ key: 'voorzieningen_module_schema', title: 'Module Schema', description: 'Schema for modules' },
				{ key: 'voorzieningen_compliancy_schema', title: 'Compliancy Schema', description: 'Schema for compliance records' },
				{ key: 'voorzieningen_moduleVersie_schema', title: 'Module Versie Schema', description: 'Schema for module versions' },
				{ key: 'voorzieningen_sector_schema', title: 'Sector Schema', description: 'Schema for sectors' },
			]
		},

		// Dynamic list of all AMEF schema config entries
		/**
		 * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		amefItems() {
			return [
				{ key: 'amef_element_schema', title: 'Element Schema', description: 'Schema for ArchiMate elements' },
				{ key: 'amef_model_schema', title: 'Model Schema', description: 'Schema for ArchiMate models' },
				{ key: 'amef_organization_schema', title: 'Organization Schema', description: 'Schema for organizations in AMEF' },
				{ key: 'amef_property_definition_schema', title: 'Property Definition Schema', description: 'Schema for property definitions' },
				{ key: 'amef_relation_schema', title: 'Relation Schema', description: 'Schema for ArchiMate relationships' },
				{ key: 'amef_view_schema', title: 'View Schema', description: 'Schema for ArchiMate views' },
				// NOTE: 'amef_property_schema' removed - properties are never root-level AMEF objects, only nested within other elements
			]
		},

		// Two-way computed properties for register selections
		voorzieningenRegister: {
			/**
			 * @spec openspec/specs/fe-settings-ui/spec.md
			 */
			get() { return this.store.voorzieningenRegister },
			/**
			 * @spec openspec/specs/fe-settings-ui/spec.md
			 */
			set(value) { this.store.voorzieningenRegister = value },
		},
		amefRegister: {
			/**
			 * @spec openspec/specs/fe-settings-ui/spec.md
			 */
			get() { return this.store.amefRegister },
			/**
			 * @spec openspec/specs/fe-settings-ui/spec.md
			 */
			set(value) { this.store.amefRegister = value },
		},

		/**
		 * Determines if configuration can be saved
		 *
		 * @return {boolean} True if configuration is valid and can be saved
		  * @spec openspec/specs/fe-settings-ui/spec.md
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

	/**
	 * Component lifecycle - load initial data
	 * Only loads essential data needed for register/schema dropdowns
	  * @spec openspec/specs/fe-settings-ui/spec.md
	 */
	async mounted() {
		// Load only essential data for OpenRegister configuration dropdowns
		try {
			await this.store.loadOpenRegisterEssentials()
		} catch (error) {
			console.error('Failed to load OpenRegister essentials on mount:', error)
			showError('Failed to load OpenRegister configuration: ' + error.message)
		}
	},

	methods: {
		/**
		 * Handle Voorzieningen register change
		 * Updates schemas when register selection changes
		 *
		 * @param {object} register Selected register object
		 * @return {void}
		  * @spec openspec/specs/fe-settings-ui/spec.md
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
		  * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		handleAmefRegisterChange(register) {
			this.store.handleAmefRegisterChange(register)
		},

		/**
		 * Validate configuration
		 * Triggers validation in the store
		 *
		 * @return {void}
		  * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		validateConfiguration() {
			this.store.validateConfiguration()
		},

		/**
		 * Check if AMEF configuration has been modified
		 * Compares current configuration with original values
		 *
		 * @return {boolean} True if AMEF configuration has changed
		  * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		hasAmefConfigChanges() {
			if (!this.amefRegister) return false

			const amefKeys = [
				'amef_element_schema',
				'amef_model_schema',
				'amef_organization_schema',
				'amef_property_definition_schema',
				'amef_relation_schema',
				'amef_view_schema',
				// NOTE: 'amef_property_schema' removed - properties are never root-level AMEF objects
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
		  * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		hasVoorzieningenConfigChanges() {
			if (!this.voorzieningenRegister) return false

			const voorzieningenKeys = [
				'voorzieningen_organisatie_schema',
				'voorzieningen_contactpersoon_schema',
				'voorzieningen_suite_schema',
				'voorzieningen_dienst_schema',
				'voorzieningen_kwetsbaarheid_schema',
				'voorzieningen_gebruik_schema',
				'voorzieningen_contract_schema',
				'voorzieningen_koppeling_schema',
				'voorzieningen_beoordeeling_schema',
				'voorzieningen_module_schema',
				'voorzieningen_compliancy_schema',
				'voorzieningen_moduleVersie_schema',
				'voorzieningen_sector_schema',
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
		  * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		async saveConfiguration() {
			this.saving = true
			try {
				await this.store.saveConfiguration()
				showSuccess('OpenRegister configuration saved successfully')
			} catch (error) {
				console.error('Failed to save OpenRegister configuration:', error)
				showError('Failed to save OpenRegister configuration: ' + error.message)
			} finally {
				this.saving = false
			}
		},

		/**
		 * Refresh settings
		 * Reloads only essential data needed for the dropdowns
		 *
		 * @return {Promise<void>}
		  * @spec openspec/specs/fe-settings-ui/spec.md
		 */
		async refreshSettings() {
			try {
				// Reload essential OpenRegister configuration data
				await this.store.loadOpenRegisterEssentials()
				showSuccess('OpenRegister configuration refreshed successfully')
			} catch (error) {
				console.error('Failed to refresh OpenRegister configuration:', error)
				showError('Failed to refresh OpenRegister configuration: ' + error.message)
			}
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

</style>
