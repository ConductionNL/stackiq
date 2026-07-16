<!--
AddContactpersoonModal.vue
Modal component for adding new contactpersoon to an organisation

@category Components
@package softwarecatalog
@author Ruben Linde
@copyright 2024
@license AGPL-3.0-or-later
@version 1.0.0
@link https://github.com/opencatalogi/softwarecatalog
-->

<template>
	<NcDialog v-if="show"
		:name="t('softwarecatalog', 'Add Contactpersoon')"
		size="small"
		@closing="closeModal">
		<div class="add-contactpersoon-modal">
			<p class="modal-description">
				{{ t('softwarecatalog', 'Add a new contactpersoon to organisation: {name}', { name: organisation?.naam || 'Unknown' }) }}
			</p>

			<form class="contactpersoon-form" @submit.prevent="saveContactpersoon">
				<div class="form-row">
					<NcTextField
						:value="formData.voornaam"
						:label="t('softwarecatalog', 'First Name')"
						:placeholder="t('softwarecatalog', 'Enter first name')"
						class="compact-field"
						required
						@update:value="formData.voornaam = $event" />
				</div>

				<div class="form-row">
					<NcTextField
						:value="formData.achternaam"
						:label="t('softwarecatalog', 'Last Name')"
						:placeholder="t('softwarecatalog', 'Enter last name')"
						class="compact-field"
						required
						@update:value="formData.achternaam = $event" />
				</div>

				<div class="form-row">
					<NcTextField
						:value="formData['e-mailadres']"
						type="email"
						:label="t('softwarecatalog', 'Email Address')"
						:placeholder="t('softwarecatalog', 'Enter email address')"
						class="compact-field"
						required
						@update:value="formData['e-mailadres'] = $event" />
				</div>

				<div class="dialog-actions">
					<NcButton type="secondary" @click="closeModal">
						{{ t('softwarecatalog', 'Cancel') }}
					</NcButton>
					<NcButton type="primary"
						:disabled="loading || !isFormValid"
						native-type="submit">
						<template #icon>
							<NcLoadingIcon v-if="loading" :size="20" />
						</template>
						{{ t('softwarecatalog', 'Add Contactpersoon') }}
					</NcButton>
				</div>
			</form>
		</div>
	</NcDialog>
</template>

<script>
import {
	NcDialog,
	NcButton,
	NcTextField,
	NcLoadingIcon,
} from '@nextcloud/vue'

import { showSuccess, showError } from '@nextcloud/dialogs'
import { objectStore, navigationStore } from '../store/store.js'

export default {
	name: 'AddContactpersoonModal',

	components: {
		NcDialog,
		NcButton,
		NcTextField,
		NcLoadingIcon,
	},

	props: {
		show: {
			type: Boolean,
			default: false,
		},
		organisation: {
			type: Object,
			default: () => ({}),
		},
	},

	emits: ['close', 'contactpersoon-added'],

	data() {
		return {
			loading: false,
			formData: {
				voornaam: '',
				achternaam: '',
				'e-mailadres': '',
			},
		}
	},

	computed: {
		/**
		 * @spec openspec/specs/fe-organizations/spec.md
		 */
		isFormValid() {
			return this.formData.voornaam.trim()
				   && this.formData.achternaam.trim()
				   && this.formData['e-mailadres'].trim()
				   && this.isValidEmail(this.formData['e-mailadres'])
		},
	},

	methods: {
		/**
		 * @spec openspec/specs/fe-organizations/spec.md
		 */
		closeModal() {
			this.resetForm()
			this.$emit('close')
		},

		/**
		 * @spec openspec/specs/fe-organizations/spec.md
		 */
		resetForm() {
			this.formData = {
				voornaam: '',
				achternaam: '',
				'e-mailadres': '',
			}
			this.loading = false
		},

		/**
		 * @spec openspec/specs/fe-organizations/spec.md
		 */
		isValidEmail(email) {
			const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
			return emailRegex.test(email)
		},

		/**
		 * @spec openspec/specs/fe-organizations/spec.md
		 */
		async saveContactpersoon() {
			if (!this.isFormValid) {
				showError(this.t('softwarecatalog', 'Please fill in all required fields with valid data'))
				return
			}

			this.loading = true

			try {
				// Get schema configuration for contactpersoon
				const contactpersoonConfig = objectStore.getSchemaConfig('contactpersoon')

				// Create new contactpersoon object with proper structure
				const newContactpersoonObject = {
					...this.formData,
					naam: `${this.formData.voornaam} ${this.formData.achternaam}`.trim(),
					organisatie: this.organisation.id || this.organisation.uuid,
					'@self': {
						created: new Date().toISOString(),
						updated: new Date().toISOString(),
						// Set the organisation metadata to link the contact person to the organization
						organisation: this.organisation.id || this.organisation.uuid,
					},
				}

				// Save the new contactpersoon object.
				const result = await objectStore.saveObject(newContactpersoonObject, {
					register: contactpersoonConfig.register,
					schema: contactpersoonConfig.schema,
				})

				showSuccess(this.t('softwarecatalog', 'Contactpersoon added successfully'))

				// Emit event to parent component.
				this.$emit('contactpersoon-added', result.data)

				// Close modal.
				this.closeModal()

				// Signal that a contactpersoon was added so parent can refresh with current filters.
				navigationStore.setTransferData({
					action: 'contactpersoonAdded',
				})

			} catch (error) {
				console.error('Error adding contactpersoon:', error)
				showError(this.t('softwarecatalog', 'Failed to add contactpersoon: {error}', { error: error.message }))
			} finally {
				this.loading = false
			}
		},

		/**
		 * @spec openspec/specs/fe-organizations/spec.md
		 */
		generateUuid() {
			return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
				const r = Math.random() * 16 | 0
				const v = c === 'x' ? r : (r & 0x3 | 0x8)
				return v.toString(16)
			})
		},
	},
}
</script>

<style scoped>
.add-contactpersoon-modal {
	padding: 16px;
	width: 100%;
	max-width: 400px;
	box-sizing: border-box;
}

.modal-description {
	margin: 0 0 16px 0;
	font-size: 14px;
	color: var(--color-text-lighter);
	line-height: 1.4;
}

.contactpersoon-form {
	width: 100%;
}

.form-row {
	margin-bottom: 16px;
	width: 100%;
}

.form-row:last-of-type {
	margin-bottom: 20px;
}

.dialog-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 20px;
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
}

/* Make NcTextField more compact and properly sized */
.compact-field {
	width: 100%;
}

.compact-field :deep(.input-field) {
	width: 100%;
	margin-bottom: 0;
}

.compact-field :deep(.input-field__main-wrapper) {
	min-height: 44px;
	width: 100%;
}

.compact-field :deep(.input-field__input) {
	padding: 10px 12px;
	font-size: 14px;
	width: 100%;
	box-sizing: border-box;
}

.compact-field :deep(.input-field__label) {
	font-size: 13px;
	margin-bottom: 6px;
	font-weight: 500;
}

/* Ensure proper dialog sizing */
:deep(.modal-container) {
	max-width: 420px;
}

:deep(.modal-wrapper) {
	padding: 0;
}
</style>
