<template>
	<NcModal v-if="show" 
		:name="modalTitle"
		size="normal"
		@close="closeModal">
		<div class="organisation-modal">
			<form @submit.prevent="saveOrganisation">
				<div class="form-grid">
					<div class="form-row">
						<NcTextField
							:value="formData.naam"
							:label="t('softwarecatalog', 'Name')"
							:placeholder="t('softwarecatalog', 'Organisation name')"
							required
							@update:value="formData.naam = $event" />
					</div>

					<div class="form-row">
						<NcTextField
							:value="formData.website"
							:label="t('softwarecatalog', 'Website')"
							:placeholder="t('softwarecatalog', 'https://example.com')"
							@update:value="formData.website = $event" />
					</div>

					<div class="form-row">
						<NcSelect
							:value="selectedType"
							:options="organisationTypes"
							:label="t('softwarecatalog', 'Type')"
							:placeholder="t('softwarecatalog', 'Select organisation type')"
							@update:value="handleTypeChange" />
					</div>

					<div class="form-row">
						<NcTextField
							:value="formData.beschrijvingKort"
							:label="t('softwarecatalog', 'Short Description')"
							:placeholder="t('softwarecatalog', 'Brief description of the organisation')"
							@update:value="formData.beschrijvingKort = $event" />
					</div>

					<div class="form-row">
						<NcTextField
							:value="formData['e-mailadres']"
							:label="t('softwarecatalog', 'Email')"
							:placeholder="t('softwarecatalog', 'contact@example.com')"
							type="email"
							@update:value="formData['e-mailadres'] = $event" />
					</div>

					<div class="form-row">
						<NcTextField
							:value="formData.telefoonnummer"
							:label="t('softwarecatalog', 'Phone')"
							:placeholder="t('softwarecatalog', '+31 20 123 4567')"
							@update:value="formData.telefoonnummer = $event" />
					</div>

					<div class="form-row">
						<NcTextField
							:value="formData.oin"
							:label="t('softwarecatalog', 'OIN')"
							:placeholder="t('softwarecatalog', 'Organisation Identification Number')"
							@update:value="formData.oin = $event" />
					</div>

					<div class="form-row">
						<NcTextField
							:value="formData.cbs"
							:label="t('softwarecatalog', 'CBS')"
							:placeholder="t('softwarecatalog', 'CBS number')"
							@update:value="formData.cbs = $event" />
					</div>

					<div class="form-row">
						<NcTextField
							:value="formData.links"
							:label="t('softwarecatalog', 'Links')"
							:placeholder="t('softwarecatalog', 'Additional links')"
							@update:value="formData.links = $event" />
					</div>

				</div>

				<!-- Success Message -->
				<div v-if="success" class="success-message">
					<CheckCircle :size="24" class="success-icon" />
					<p>{{ successMessage }}</p>
					<p class="auto-close-message">{{ t('softwarecatalog', 'This dialog will close automatically in 3 seconds...') }}</p>
				</div>

				<div class="form-actions">
					<NcButton type="secondary" @click="closeModal">
						{{ t('softwarecatalog', 'Cancel') }}
					</NcButton>
					<NcButton v-if="!success"
						type="primary" 
						:disabled="loading || !isFormValid"
						native-type="submit">
						<template #icon>
							<NcLoadingIcon v-if="loading" :size="20" />
						</template>
						{{ isEditMode ? t('softwarecatalog', 'Update Organisation') : t('softwarecatalog', 'Create Organisation') }}
					</NcButton>
				</div>
			</form>
		</div>
	</NcModal>
</template>

<script>
import { 
	NcModal,
	NcTextField,
	NcSelect,
	NcButton,
	NcLoadingIcon
} from '@nextcloud/vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import { objectStore } from '../store/store.js'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
	name: 'OrganisationModal',
	components: {
		NcModal,
		NcTextField,
		NcSelect,
		NcButton,
		NcLoadingIcon,
		CheckCircle,
	},
	props: {
		show: {
			type: Boolean,
			default: false,
		},
		organisation: {
			type: Object,
			default: null,
		},
		mode: {
			type: String,
			default: 'create', // 'create', 'edit', 'copy'
		},
	},
	data() {
		return {
			formData: {
				naam: '',
				website: '',
				type: '',
				beschrijvingKort: '',
				'e-mailadres': '',
				telefoonnummer: '',
				oin: '',
				cbs: '',
				links: '',
				status: 'Concept',
				deelnemers: [],
				contactpersonen: [],
			},
			selectedType: null,
			loading: false,
			success: false,
			successMessage: '',
			organisationTypes: [
				{ id: 'Gemeente', label: 'Gemeente' },
				{ id: 'Leverancier', label: 'Leverancier' },
				{ id: 'Samenwerking', label: 'Samenwerking' },
				{ id: 'Community', label: 'Community' },
			],
		}
	},
	computed: {
		isEditMode() {
			return this.mode === 'edit'
		},
		isCopyMode() {
			return this.mode === 'copy'
		},
		modalTitle() {
			if (this.isEditMode) {
				return this.t('softwarecatalog', 'Edit Organisation')
			} else if (this.isCopyMode) {
				return this.t('softwarecatalog', 'Copy Organisation')
			}
			return this.t('softwarecatalog', 'Create Organisation')
		},
		isFormValid() {
			return this.formData.naam.trim().length > 0
		},
	},
	watch: {
		organisation: {
			handler() {
				this.loadOrganisationData()
			},
			immediate: true,
		},
		show: {
			handler(newVal) {
				if (newVal) {
					this.resetForm()
					this.loadOrganisationData()
				}
			},
			immediate: true,
		},
	},
	methods: {
		resetForm() {
			this.formData = {
				naam: '',
				website: '',
				type: '',
				beschrijvingKort: '',
				'e-mailadres': '',
				telefoonnummer: '',
				oin: '',
				cbs: '',
				links: '',
				status: 'Concept',
				deelnemers: [],
				contactpersonen: [],
			}
			this.selectedType = null
			this.loading = false
			this.success = false
			this.successMessage = ''
		},
		loadOrganisationData() {
			if (!this.organisation) return

			// Load organisation data into form
			this.formData = {
				naam: this.organisation.naam || '',
				website: this.organisation.website || '',
				type: this.organisation.type || '',
				beschrijvingKort: this.organisation.beschrijvingKort || '',
				'e-mailadres': this.organisation['e-mailadres'] || '',
				telefoonnummer: this.organisation.telefoonnummer || '',
				oin: this.organisation.oin || '',
				cbs: this.organisation.cbs || '',
				links: this.organisation.links || '',
				status: this.organisation.status || 'Concept',
				deelnemers: this.organisation.deelnemers || [],
				contactpersonen: this.isCopyMode ? [] : (this.organisation.contactpersonen || []),
			}

			// Set selected type
			if (this.formData.type) {
				this.selectedType = this.organisationTypes.find(type => type.id === this.formData.type)
			}
		},
		handleTypeChange(selectedOption) {
			this.selectedType = selectedOption
			this.formData.type = selectedOption ? selectedOption.id : ''
		},
		closeModal() {
			this.$emit('close')
		},
		async saveOrganisation() {
			if (!this.isFormValid) {
				showError(this.t('softwarecatalog', 'Please fill in all required fields'))
				return
			}

			this.loading = true
			this.success = false

			try {
				// Get schema configuration for organisatie
				const schemaConfig = objectStore.getSchemaConfig('organisatie')
				let result

				if (this.isEditMode) {
					// Update existing organisation - preserve @self metadata
					const updateData = {
						...this.formData,
						'@self': this.organisation['@self'] || {}
					}
					result = await objectStore.saveObject(updateData, {
						register: schemaConfig.register,
						schema: schemaConfig.schema
					})
					this.successMessage = this.t('softwarecatalog', 'Organisation updated successfully')
				} else {
					// Create new organisation (both create and copy modes)
					result = await objectStore.saveObject(this.formData, {
						register: schemaConfig.register,
						schema: schemaConfig.schema
					})
					this.successMessage = this.t('softwarecatalog', 'Organisation created successfully')
				}

				// Show success state
				this.success = true
				
				// Refresh organisation list
				await objectStore.fetchCollection('organisatie', {
					_extend: '@self.schema,contactpersonen',
					_limit: 20,
					_page: 1
				})
				
				// Auto-close modal after 3 seconds
				setTimeout(() => {
					this.closeModal()
				}, 3000)

			} catch (error) {
				console.error('Error saving organisation:', error)
				showError(this.t('softwarecatalog', 'Failed to save organisation: {error}', { error: error.message }))
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.organisation-modal {
	padding: 20px;
	max-width: 600px;
	margin: 0 auto;
}

.form-grid {
	display: grid;
	gap: 16px;
	margin-bottom: 20px;
}

.form-row {
	display: flex;
	flex-direction: column;
}

.form-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 20px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

.form-row :deep(.input-field__main-wrapper) {
	width: 100%;
}

.form-row :deep(.select__main-wrapper) {
	width: 100%;
}

/* Success Message Styles */
.success-message {
	display: flex;
	flex-direction: column;
	align-items: center;
	padding: 20px;
	margin: 20px 0;
	background: var(--color-success-light);
	border: 1px solid var(--color-success);
	border-radius: 8px;
	text-align: center;
}

.success-icon {
	color: var(--color-success);
	margin-bottom: 8px;
}

.success-message p {
	margin: 4px 0;
	color: var(--color-text-dark);
}

.auto-close-message {
	font-size: 12px;
	color: var(--color-text-lighter);
	font-style: italic;
}
</style>
