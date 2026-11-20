<!--
  - SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<script setup>
import { navigationStore, objectStore } from '../../store/store.js'
</script>

<template>
	<NcDialog
		:name="n('stackiq', 'Migrate {count} object', 'Migrate {count} objects', selectedObjects.length, { count: selectedObjects.length })"
		size="large"
		:canClose="false">
		<!-- Source and Target Information -->
		<div class="migration-overview">
			<div class="source-info">
				<h4>{{ t('stackiq', 'Source') }}</h4>
				<div class="info-card">
					<div class="card-item">
						<div class="card-label-with-icon">
							<DatabaseOutline :size="16" />
							<span class="card-label">{{ t('stackiq', 'Register:') }}</span>
						</div>
						<span class="card-value">{{
							sourceRegister?.title || sourceRegister?.id || t('stackiq', 'Unknown')
						}}</span>
					</div>
					<div class="card-item">
						<div class="card-label-with-icon">
							<FileTreeOutline :size="16" />
							<span class="card-label">{{ t('stackiq', 'Schema:') }}</span>
						</div>
						<span class="card-value">{{
							sourceSchema?.title || sourceSchema?.id || t('stackiq', 'Unknown')
						}}</span>
					</div>
				</div>
			</div>

			<div class="migration-arrow">→</div>

			<div class="source-info">
				<h4>{{ t('stackiq', 'Target') }}</h4>
				<div class="info-card">
					<div class="card-item">
						<div class="card-label-with-icon">
							<DatabaseOutline :size="16" />
							<span class="card-label">{{ t('stackiq', 'Register:') }}</span>
						</div>
						<span class="card-value">{{
							targetRegister?.title || t('stackiq', 'Not selected')
						}}</span>
					</div>
					<div class="card-item">
						<div class="card-label-with-icon">
							<FileTreeOutline :size="16" />
							<span class="card-label">{{ t('stackiq', 'Schema:') }}</span>
						</div>
						<span class="card-value">{{
							targetSchema?.title || t('stackiq', 'Not selected')
						}}</span>
					</div>
				</div>
			</div>
		</div>

		<!-- Step 1: Confirm Selection -->
		<div v-if="step === 1" class="migration-step step-1">
			<h3 class="step-title">{{ t('stackiq', 'Confirm Object Selection') }}</h3>

			<NcNoteCard type="info">
				{{ t('stackiq', 'Review the selected objects below. You can remove any objects you don\'t want to migrate by clicking the remove button.') }}
			</NcNoteCard>

			<div class="selected-objects-container">
				<h4>{{ t('stackiq', 'Selected Objects ({count})', { count: selectedObjects.length }) }}</h4>

				<div v-if="selectedObjects.length" class="selected-objects-list">
					<div
						v-for="obj in selectedObjects"
						:key="obj.id"
						class="selected-object-item">
						<div class="object-info">
							<strong>{{
								obj['@self']?.name
								|| obj.name
								|| obj.title
								|| obj['@self']?.title
								|| t('stackiq', 'Unnamed Object')
							}}</strong>
							<p class="object-id">
								{{ t('stackiq', 'ID: {id}', { id: obj.id || obj['@self']?.id }) }}
							</p>
						</div>
						<NcButton
							variant="tertiary"
							:aria-label="t('stackiq', 'Remove {name}', { name: obj['@self']?.name || obj.name || obj.title || obj['@self']?.title || obj.id }, undefined, { escape: false })"
							@click="removeObject(obj.id)">
							<template #icon>
								<Close :size="20" />
							</template>
						</NcButton>
					</div>
				</div>

				<NcEmptyContent v-else :name="t('stackiq', 'No objects selected')">
					<template #description>
						{{ t('stackiq', 'No objects are currently selected for migration.') }}
					</template>
				</NcEmptyContent>
			</div>
		</div>

		<!-- Step 2: Select Target Register and Schema -->
		<div v-if="step === 2" class="migration-step">
			<h3>{{ t('stackiq', 'Select Target Register and Schema') }}</h3>
			<p>
				{{
					n(
						'stackiq',
						'Choose the destination register and schema for the {count} selected object:',
						'Choose the destination register and schema for the {count} selected objects:',
						selectedObjects.length,
						{ count: selectedObjects.length },
					)
				}}
			</p>

			<!-- Target Register Selection -->
			<div class="selection-section">
				<h4>{{ t('stackiq', 'Target Register') }}</h4>
				<NcSelect
					v-model="targetRegister"
					:options="availableRegisters"
					label="title"
					trackBy="id"
					:inputLabel="t('stackiq', 'Target register')"
					:placeholder="t('stackiq', 'Select a register…')"
					@update:modelValue="onRegisterChange" />
			</div>

			<!-- Target Schema Selection -->
			<div v-if="targetRegister" class="selection-section">
				<h4>{{ t('stackiq', 'Target Schema') }}</h4>
				<NcSelect
					v-model="targetSchema"
					:options="availableSchemas"
					label="title"
					trackBy="id"
					:inputLabel="t('stackiq', 'Target schema')"
					:placeholder="t('stackiq', 'Select a schema…')"
					@update:modelValue="onSchemaChange" />
			</div>
		</div>

		<!-- Step 3: Property Mapping -->
		<div v-if="step === 3" class="migration-step">
			<h3>{{ t('stackiq', 'Property Mapping') }}</h3>
			<p>
				{{ t('stackiq', 'Map properties from the source schema to the target schema. Properties not mapped will be discarded.') }}
			</p>

			<NcNoteCard type="info">
				<template
					v-for="(part, index) in t('stackiq', 'Configure how properties should be mapped when migrating from source schema {source} to target schema {target}').split(/(\{source\}|\{target\})/)"
					:key="index">
					<strong v-if="part === '{source}'">{{ sourceSchema?.title }}</strong>
					<strong v-else-if="part === '{target}'">{{ targetSchema?.title }}</strong>
					<template v-else>{{ part }}</template>
				</template>
			</NcNoteCard>

			<div class="mapping-container">
				<div class="mapping-header">
					<div class="source-header">
						<h4>{{ t('stackiq', 'Source Properties') }}</h4>
						<span class="schema-name">{{ sourceSchema?.title }}</span>
					</div>
					<div class="arrow-header">→</div>
					<div class="target-header">
						<h4>{{ t('stackiq', 'Target Properties') }}</h4>
						<span class="schema-name">{{ targetSchema?.title }}</span>
					</div>
				</div>

				<div class="mapping-list">
					<div
						v-for="sourceProperty in sourceProperties"
						:key="sourceProperty.name"
						class="mapping-row">
						<div class="source-property">
							<span class="property-name">{{
								sourceProperty.name
							}}</span>
							<span class="property-type">{{
								sourceProperty.type
							}}</span>
						</div>
						<div class="mapping-arrow">→</div>
						<div class="target-property">
							<NcSelect
								v-model="uiMappings[sourceProperty.name]"
								:options="targetPropertyOptions"
								label="label"
								trackBy="value"
								:inputLabel="
									t(
										'stackiq',
										'Map {property} to target property',
										{ property: sourceProperty.name },
									)
								"
								:placeholder="t('stackiq', 'Map to target property…')"
								:clearable="true"
								@update:modelValue="
									updateMappingFromUI(sourceProperty.name)
								" />
						</div>
					</div>
				</div>
			</div>
		</div>

		<!-- Step 4: Migration Report -->
		<div v-if="step === 4" class="migration-step">
			<h3 class="report-title">{{ t('stackiq', 'Migration Report') }}</h3>

			<NcNoteCard v-if="migrationResult?.success" type="success">
				<p>{{ t('stackiq', 'Objects successfully migrated!') }}</p>
			</NcNoteCard>
			<NcNoteCard
				v-if="migrationResult && !migrationResult.success"
				type="error">
				<p>{{ t('stackiq', 'Migration failed. Please check the details below.') }}</p>
			</NcNoteCard>

			<div v-if="migrationResult" class="migration-report">
				<!-- Migration Summary -->
				<div class="report-section">
					<h4>{{ t('stackiq', 'Migration Summary') }}</h4>
					<div class="migration-info">
						<div class="migration-detail">
							<strong>{{ t('stackiq', 'Source:') }}</strong>
							<div class="migration-meta">
								<span
									>{{ sourceRegister?.title }} /
									{{ sourceSchema?.title }}</span
								>
								<span class="object-count">{{
									n('stackiq', '{count} object', '{count} objects', selectedObjects.length, { count: selectedObjects.length })
								}}</span>
							</div>
						</div>
						<div class="migration-detail">
							<strong>{{ t('stackiq', 'Target:') }}</strong>
							<div class="migration-meta">
								<span
									>{{ targetRegister?.title }} /
									{{ targetSchema?.title }}</span
								>
							</div>
						</div>
					</div>
				</div>

				<!-- Statistics -->
				<div class="report-section">
					<h4>{{ t('stackiq', 'Statistics') }}</h4>
					<ul>
						<li>
							{{
								t('stackiq', 'Objects migrated: {count}', {
									count: migrationResult.statistics?.objectsMigrated || 0,
								})
							}}
						</li>
						<li>
							{{
								t('stackiq', 'Objects failed: {count}', {
									count: migrationResult.statistics?.objectsFailed || 0,
								})
							}}
						</li>
						<li>
							{{
								t('stackiq', 'Properties mapped: {count}', {
									count: migrationResult.statistics?.propertiesMapped || 0,
								})
							}}
						</li>
						<li>
							{{
								t('stackiq', 'Properties discarded: {count}', {
									count: migrationResult.statistics?.propertiesDiscarded || 0,
								})
							}}
						</li>
					</ul>
				</div>

				<!-- Migration Details -->
				<div v-if="migrationResult.details?.length" class="report-section">
					<h4>{{ t('stackiq', 'Migration Details') }}</h4>
					<div class="migration-details">
						<div
							v-for="detail in migrationResult.details"
							:key="detail.objectId"
							class="migration-detail-item">
							<div class="detail-header">
								<strong>{{
									detail.objectTitle || detail.objectId
								}}</strong>
								<span
									class="status"
									:class="[detail.success ? 'success' : 'error']">
									{{ detail.success ? t('stackiq', 'Success') : t('stackiq', 'Failed') }}
								</span>
							</div>
							<div v-if="detail.error" class="detail-error">
								{{ detail.error }}
							</div>
						</div>
					</div>
				</div>

				<!-- Warnings -->
				<div v-if="migrationResult.warnings?.length" class="report-section">
					<h4>{{ t('stackiq', 'Warnings') }}</h4>
					<ul>
						<li
							v-for="warning in migrationResult.warnings"
							:key="warning"
							class="warning-text">
							{{ warning }}
						</li>
					</ul>
				</div>

				<!-- Errors -->
				<div v-if="migrationResult.errors?.length" class="report-section">
					<h4>{{ t('stackiq', 'Errors') }}</h4>
					<ul>
						<li
							v-for="error in migrationResult.errors"
							:key="error"
							class="error-text">
							{{ error }}
						</li>
					</ul>
				</div>
			</div>
		</div>

		<template #actions>
			<NcButton @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{ step === 4 ? t('stackiq', 'Close') : t('stackiq', 'Cancel') }}
			</NcButton>

			<NcButton
				v-if="step === 1"
				:disabled="selectedObjects.length === 0"
				variant="primary"
				@click="nextStep">
				<template #icon>
					<ArrowRight :size="20" />
				</template>
				{{ t('stackiq', 'Next') }}
			</NcButton>

			<NcButton v-if="step === 2" variant="secondary" @click="previousStep">
				<template #icon>
					<ArrowLeft :size="20" />
				</template>
				{{ t('stackiq', 'Back') }}
			</NcButton>

			<NcButton
				v-if="step === 2"
				:disabled="!targetRegister || !targetSchema"
				variant="primary"
				@click="nextStep">
				<template #icon>
					<ArrowRight :size="20" />
				</template>
				{{ t('stackiq', 'Next') }}
			</NcButton>

			<NcButton v-if="step === 3" variant="secondary" @click="previousStep">
				<template #icon>
					<ArrowLeft :size="20" />
				</template>
				{{ t('stackiq', 'Back') }}
			</NcButton>

			<NcButton
				v-if="step === 3"
				:disabled="loading || !canMigrate"
				variant="primary"
				@click="performMigration">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<DatabaseExport v-else :size="20" />
				</template>
				{{ t('stackiq', 'Migrate Objects') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { getRequestToken } from '@nextcloud/auth'
import { translate as t } from '@nextcloud/l10n'
import {
	NcButton,
	NcDialog,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import ArrowRight from 'vue-material-design-icons/ArrowRight.vue'
// Icons
import Cancel from 'vue-material-design-icons/Cancel.vue'
import Close from 'vue-material-design-icons/Close.vue'
import DatabaseExport from 'vue-material-design-icons/DatabaseExport.vue'
import DatabaseOutline from 'vue-material-design-icons/DatabaseOutline.vue'
import FileTreeOutline from 'vue-material-design-icons/FileTreeOutline.vue'

export default {
	name: 'MigrationObject',
	components: {
		NcButton,
		NcDialog,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		Cancel,
		ArrowRight,
		ArrowLeft,
		Close,
		DatabaseExport,
		DatabaseOutline,
		FileTreeOutline,
	},

	data() {
		return {
			step: 1, // 1: confirm selection, 2: select target register/schema, 3: property mapping, 4: report
			loading: false,
			selectedObjects: [],
			availableRegisters: [],
			availableSchemas: [],
			targetRegister: null,
			targetSchema: null,
			sourceProperties: [],
			targetProperties: [],
			mapping: {},
			// Go-between variable for UI binding - maps source properties to selected target options
			uiMappings: {},
			migrationResult: null,
		}
	},

	computed: {
		/**
		 * @spec openspec/specs/fe-object-modals/spec.md
		 */
		sourceRegister() {
			// Get register info from the first selected object
			if (this.selectedObjects.length === 0) return null
			const firstObject = this.selectedObjects[0]
			const register = firstObject['@self']?.register
			if (typeof register === 'object') {
				return register
			}
			// If it's just an ID, try to find it in available registers
			return (
				objectStore.availableRegisters.find((r) => r.id === register) || {
					id: register,
					title: register,
				}
			)
		},

		/**
		 * @spec openspec/specs/fe-object-modals/spec.md
		 */
		sourceSchema() {
			// Get schema info from the first selected object
			if (this.selectedObjects.length === 0) return null
			const firstObject = this.selectedObjects[0]
			const schema = firstObject['@self']?.schema
			if (typeof schema === 'object') {
				return schema
			}
			// If it's just an ID, try to find it in available schemas
			return (
				objectStore.availableSchemas.find((s) => s.id === schema) || {
					id: schema,
					title: schema,
				}
			)
		},

		/**
		 * @spec openspec/specs/fe-object-modals/spec.md
		 */
		targetPropertyOptions() {
			const options = this.targetProperties.map((prop) => ({
				label: `${prop.name} (${prop.type})`,
				value: prop.name,
			}))

			options.push({
				label: t('stackiq', '(Do not map)'),
				value: null,
			})

			return options
		},

		/**
		 * @spec openspec/specs/fe-object-modals/spec.md
		 */
		canMigrate() {
			// Check if we have target register/schema and at least one property mapping
			const hasValidMappings = Object.values(this.uiMappings).some(
				(option) => option && option.value,
			)
			return this.targetRegister && this.targetSchema && hasValidMappings
		},
	},

	mounted() {
		this.initializeMigration()
	},

	methods: {
		/**
		 * @spec openspec/specs/fe-object-modals/spec.md
		 */
		initializeMigration() {
			// Get selected objects from the store or navigation context
			this.selectedObjects = objectStore.selectedObjects || []
			if (this.selectedObjects.length === 0) {
				this.closeModal()
				return
			}
			this.loadAvailableRegisters()
		},

		/**
		 * @spec openspec/specs/fe-object-modals/spec.md
		 */
		async loadAvailableRegisters() {
			this.loading = true
			try {
				const response = await fetch(
					'/index.php/apps/openregister/api/registers',
				)
				const data = await response.json()
				this.availableRegisters = data.results || []
			} catch (error) {
				console.error('Error loading registers:', error)
				this.availableRegisters = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * @spec openspec/specs/fe-object-modals/spec.md
		 */
		async onRegisterChange() {
			if (!this.targetRegister) {
				this.availableSchemas = []
				this.targetSchema = null
				return
			}

			this.loading = true
			try {
				const response = await fetch(
					`/index.php/apps/openregister/api/schemas?register=${this.targetRegister.id}`,
				)
				const data = await response.json()
				this.availableSchemas = data.results || []
			} catch (error) {
				console.error('Error loading schemas:', error)
				this.availableSchemas = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * @spec openspec/specs/fe-object-modals/spec.md
		 */
		async onSchemaChange() {
			if (!this.targetSchema) {
				return
			}
			await this.loadSchemaProperties()
		},

		/**
		 * @param objectId
		 * @spec openspec/specs/fe-object-modals/spec.md
		 */
		removeObject(objectId) {
			this.selectedObjects = this.selectedObjects.filter(
				(obj) => obj.id !== objectId,
			)
			if (this.selectedObjects.length === 0) {
				this.closeModal()
			}
		},

		/**
		 * @spec openspec/specs/fe-object-modals/spec.md
		 */
		nextStep() {
			if (this.step === 1 && this.selectedObjects.length > 0) {
				this.step = 2
			} else if (this.step === 2 && this.targetRegister && this.targetSchema) {
				this.step = 3
			}
		},

		/**
		 * @spec openspec/specs/fe-object-modals/spec.md
		 */
		previousStep() {
			if (this.step > 1) {
				this.step--
			}
		},

		/**
		 * @spec openspec/specs/fe-object-modals/spec.md
		 */
		async loadSchemaProperties() {
			if (!this.sourceSchema || !this.targetSchema) {
				return
			}

			try {
				// Load source schema properties
				this.sourceProperties = this.extractSchemaProperties(
					this.sourceSchema,
				)

				// Load target schema properties
				const response = await fetch(
					`/index.php/apps/openregister/api/schemas/${this.targetSchema.id}`,
				)
				const targetSchemaData = await response.json()
				this.targetProperties =
					this.extractSchemaProperties(targetSchemaData)

				// Initialize property mappings
				this.initializePropertyMappings()

				// Sync UI mappings
				this.convertMappingToUI()
			} catch (error) {
				console.error('Error loading schema properties:', error)
			}
		},

		/**
		 * @param schema
		 * @spec openspec/specs/fe-object-modals/spec.md
		 */
		extractSchemaProperties(schema) {
			// Extract properties from schema definition
			const properties = []
			if (schema.properties) {
				Object.keys(schema.properties).forEach((key) => {
					properties.push({
						name: key,
						type: schema.properties[key].type || 'string',
						required: schema.required?.includes(key) || false,
					})
				})
			}
			return properties
		},

		/**
		 * @spec openspec/specs/fe-object-modals/spec.md
		 */
		initializePropertyMappings() {
			this.mapping = {}
			this.uiMappings = {}

			// Auto-map properties with same names
			this.sourceProperties.forEach((sourceProp) => {
				const matchingTarget = this.targetProperties.find(
					(targetProp) => targetProp.name === sourceProp.name,
				)
				if (matchingTarget) {
					// Simple mapping: target property as key, source property as value
					this.mapping[matchingTarget.name] = sourceProp.name

					// Set up UI mapping
					const targetOption = this.targetPropertyOptions.find(
						(option) => option.value === matchingTarget.name,
					)
					if (targetOption) {
						this.uiMappings[sourceProp.name] = targetOption
					}
				}
			})
		},

		/**
		 * @spec openspec/specs/fe-object-modals/spec.md
		 */
		async performMigration() {
			if (!this.canMigrate) {
				return
			}

			// Make sure our mapping is up to date before sending
			this.convertUIToMapping()

			this.loading = true
			try {
				const response = await fetch(
					'/index.php/apps/openregister/api/migrate',
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: getRequestToken(),
						},
						body: JSON.stringify({
							sourceRegister: this.sourceRegister.id,
							sourceSchema: this.sourceSchema.id,
							targetRegister: this.targetRegister.id,
							targetSchema: this.targetSchema.id,
							objects: this.selectedObjects.map((obj) => obj.id),
							mapping: this.mapping,
						}),
					},
				)

				if (!response.ok) {
					throw new Error(`HTTP error! status: ${response.status}`)
				}

				this.migrationResult = await response.json()
				this.step = 4

				// Refresh the object list
				objectStore.refreshObjectList()
			} catch (error) {
				console.error('Error performing migration:', error)
				this.migrationResult = {
					success: false,
					errors: [error.message || t('stackiq', 'An error occurred during migration')],
				}
				this.step = 4
			} finally {
				this.loading = false
			}
		},

		/**
		 * @spec openspec/specs/fe-object-modals/spec.md
		 */
		closeModal() {
			navigationStore.setModal(false)
		},

		/**
		 * @param sourceProperty
		 * @spec openspec/specs/fe-object-modals/spec.md
		 */
		updateMappingFromUI(sourceProperty) {
			// Convert UI mappings to our simple mapping format
			this.convertUIToMapping()
		},

		/**
		 * @spec openspec/specs/fe-object-modals/spec.md
		 */
		convertUIToMapping() {
			// Convert from UI format (source -> target option) to our format (target -> source)
			this.mapping = {}

			for (const [sourceProp, targetOption] of Object.entries(
				this.uiMappings,
			)) {
				if (targetOption && targetOption.value) {
					this.mapping[targetOption.value] = sourceProp
				}
			}
		},

		/**
		 * @spec openspec/specs/fe-object-modals/spec.md
		 */
		convertMappingToUI() {
			// Convert from our format (target -> source) to UI format (source -> target option)
			this.uiMappings = {}

			for (const [targetProp, sourceProp] of Object.entries(this.mapping)) {
				const targetOption = this.targetPropertyOptions.find(
					(option) => option.value === targetProp,
				)
				if (targetOption) {
					this.uiMappings[sourceProp] = targetOption
				}
			}
		},
	},
}
</script>

<style scoped>
.migration-step {
	padding: 0;
}

.step-1 {
	padding-top: 0 !important;
}

.step-title {
	margin-top: 0 !important;
	margin-bottom: 16px;
	color: var(--color-main-text);
}

.report-title {
	margin-top: 0 !important;
	margin-bottom: 16px;
	color: var(--color-main-text);
}

.migration-step h3 {
	margin-bottom: 16px;
	color: var(--color-main-text);
}

.selected-objects-container {
	margin: 20px 0;
}

.selected-objects-list {
	max-height: 400px;
	overflow-y: auto;
	border: 1px solid var(--color-border);
	border-radius: 4px;
}

.selected-object-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 12px;
	border-bottom: 1px solid var(--color-border);
	background-color: var(--color-background-hover);
}

.selected-object-item:last-child {
	border-bottom: none;
}

.selection-section {
	margin: 20px 0;
}

.selection-section h4 {
	margin-bottom: 8px;
	color: var(--color-main-text);
}

.mapping-container {
	margin: 20px 0;
	border: 1px solid var(--color-border);
	border-radius: 4px;
	overflow: hidden;
}

.mapping-header {
	display: grid;
	grid-template-columns: 1fr auto 1fr;
	gap: 16px;
	padding: 16px;
	background-color: var(--color-background-dark);
	align-items: center;
}

.source-header,
.target-header {
	text-align: center;
}

.source-header h4,
.target-header h4 {
	margin: 0 0 4px 0;
	color: var(--color-main-text);
}

.schema-name {
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.arrow-header {
	font-size: 1.5em;
	font-weight: bold;
	color: var(--color-main-text);
	text-align: center;
}

.mapping-list {
	max-height: 400px;
	overflow-y: auto;
}

.mapping-row {
	display: grid;
	grid-template-columns: 1fr auto 1fr;
	gap: 16px;
	padding: 12px 16px;
	border-bottom: 1px solid var(--color-border);
	align-items: center;
}

.mapping-row:last-child {
	border-bottom: none;
}

.source-property {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.property-name {
	font-weight: bold;
	color: var(--color-main-text);
}

.property-type {
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
	font-family: monospace;
}

.mapping-arrow {
	font-size: 1.2em;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.target-property {
	min-width: 200px;
}

.migration-report {
	margin-top: 16px;
}

.migration-info {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.migration-detail {
	padding: 12px;
	background-color: var(--color-background-dark);
	border-radius: 4px;
}

.migration-meta {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-top: 8px;
}

.object-count {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.migration-details {
	max-height: 300px;
	overflow-y: auto;
}

.migration-detail-item {
	padding: 12px;
	border-bottom: 1px solid var(--color-border);
	background-color: var(--color-background-dark);
	border-radius: 4px;
	margin-bottom: 8px;
}

.detail-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 8px;
}

.status {
	padding: 4px 8px;
	border-radius: 4px;
	font-size: 0.8em;
	font-weight: bold;
}

.status.success {
	background-color: var(--color-success);
	color: white;
}

.status.error {
	background-color: var(--color-error);
	color: white;
}

.detail-error {
	color: var(--color-error);
	font-size: 0.9em;
	font-style: italic;
}

.loading-container {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 16px;
	padding: 40px;
}

.object-info strong {
	display: block;
	margin-bottom: 4px;
	color: var(--color-main-text);
}

.object-id {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 0;
}

.report-section {
	margin-bottom: 20px;
	padding: 16px;
	background-color: var(--color-background-hover);
	border-radius: 4px;
}

.report-section h4 {
	margin-bottom: 12px;
	color: var(--color-main-text);
}

.report-section ul {
	margin: 0;
	padding-left: 20px;
}

.report-section li {
	margin-bottom: 4px;
}

.error-text {
	color: var(--color-error);
}

.warning-text {
	color: var(--color-warning);
}

.detail-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
	gap: 16px;
	margin: 16px 0;
}

.detail-item {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.detail-label {
	font-weight: bold;
	color: var(--color-main-text);
	font-size: 0.9em;
}

.detail-value {
	color: var(--color-text-maxcontrast);
}

.migration-overview {
	display: grid;
	grid-template-columns: 1fr auto 1fr;
	gap: 24px;
	margin: 20px 0;
	align-items: start;
}

.source-info,
.target-info {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.source-info h4,
.target-info h4 {
	margin: 0;
	color: var(--color-main-text);
	text-align: center;
	font-size: 1.1em;
}

.info-card {
	padding: 16px;
	background-color: var(--color-background-hover);
	border-radius: 8px;
	border: 1px solid var(--color-border);
}

.card-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border-dark);
}

.card-item:last-child {
	border-bottom: none;
}

.card-label-with-icon {
	display: flex;
	align-items: center;
	gap: 8px;
}

.card-label {
	font-weight: bold;
	color: var(--color-main-text);
	font-size: 0.9em;
}

.card-value {
	color: var(--color-text-maxcontrast);
	font-weight: 500;
}

.migration-overview .migration-arrow {
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 2em;
	font-weight: bold;
	color: var(--color-primary);
	margin-top: 40px;
}

/* number */
.codeMirrorContainer.light :deep(.ͼd) {
	color: #d19a66;
}

.codeMirrorContainer.dark :deep(.ͼd) {
	color: #9d6c3a;
}

/* text cursor */
.codeMirrorContainer :deep(.cm-content) * {
	cursor: text !important;
}

/* selection color */
.codeMirrorContainer.light :deep(.cm-line)::selection,
.codeMirrorContainer.light :deep(.cm-line) ::selection {
	background-color: #d7eaff !important;
	color: black;
}

.codeMirrorContainer.dark :deep(.cm-line)::selection,
.codeMirrorContainer.dark :deep(.cm-line) ::selection {
	background-color: #8fb3e6 !important;
	color: black;
}

/* string */
.codeMirrorContainer.light :deep(.cm-line .ͼe)::selection {
	color: #2d770f;
}

.codeMirrorContainer.dark :deep(.cm-line .ͼe)::selection {
	color: #104e0c;
}

/* boolean */
.codeMirrorContainer.light :deep(.cm-line .ͼc)::selection {
	color: #221199;
}

.codeMirrorContainer.dark :deep(.cm-line .ͼc)::selection {
	color: #4026af;
}

/* null */
.codeMirrorContainer.light :deep(.cm-line .ͼb)::selection {
	color: #770088;
}

.codeMirrorContainer.dark :deep(.cm-line .ͼb)::selection {
	color: #770088;
}

/* number */
.codeMirrorContainer.light :deep(.cm-line .ͼd)::selection {
	color: #8c5c2c;
}

.codeMirrorContainer.dark :deep(.cm-line .ͼd)::selection {
	color: #623907;
}
</style>
