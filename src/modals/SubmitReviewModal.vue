<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -
  - Submit-a-review modal (softwarecatalog#375). Own file per ADR-012
  - (modals live in their own component). Collects a title, a 1-10 rating,
  - and a testimonial, then POSTs to /api/reviews. The author is NEVER sent
  - by this form — ReviewController/ReviewService always derive it from the
  - authenticated session server-side, so there is deliberately no "your
  - name" field here.
  -
  - @spec openspec/specs/catalog-ratings/spec.md#requirement-the-submitting-users-identity-must-be-bound-server-side-and-must-not-be-accepted-from-client-input
  -->

<template>
	<NcDialog v-if="show"
		:name="t('softwarecatalog', 'Write a review')"
		size="small"
		@closing="closeModal">
		<div class="submit-review-modal">
			<p class="modal-description">
				{{ t('softwarecatalog', 'Your review will be visible to other municipalities once an administrator approves it.') }}
			</p>

			<form class="review-form" @submit.prevent="submitReview">
				<div class="form-row">
					<NcTextField
						v-model="formData.naam"
						:label="t('softwarecatalog', 'Title')"
						:placeholder="t('softwarecatalog', 'Summarise your experience in a few words')"
						required />
				</div>

				<div class="form-row">
					<NcSelect
						v-model="selectedRating"
						:options="ratingOptions"
						:input-label="t('softwarecatalog', 'Rating (1-10)')"
						:placeholder="t('softwarecatalog', 'Select a rating')"
						label="label"
						track-by="value"
						:reduce="(option) => option.value"
						:clearable="false" />
				</div>

				<div class="form-row">
					<NcTextArea
						v-model="formData.beschrijvingLang"
						:label="t('softwarecatalog', 'Testimonial')"
						:placeholder="t('softwarecatalog', 'What was your experience with this software?')" />
				</div>

				<NcNoteCard v-if="error" type="error">
					{{ error }}
				</NcNoteCard>

				<div class="dialog-actions">
					<NcButton variant="secondary" @click="closeModal">
						{{ t('softwarecatalog', 'Cancel') }}
					</NcButton>
					<NcButton variant="primary"
						:disabled="loading || !isFormValid"
						type="submit">
						<template #icon>
							<NcLoadingIcon v-if="loading" :size="20" />
						</template>
						{{ t('softwarecatalog', 'Submit review') }}
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
	NcTextArea,
	NcSelect,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { apiRequest } from '../utils/adminApi.js'
import { ratingOptions, isReviewFormValid, buildReviewSubmission } from '../utils/reviewForm.js'

/**
 * Submit-a-review modal for a module or dienst detail page.
 *
 * @spec openspec/specs/catalog-ratings/spec.md
 */
export default {
	name: 'SubmitReviewModal',

	components: {
		NcDialog,
		NcButton,
		NcTextField,
		NcTextArea,
		NcSelect,
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		show: {
			type: Boolean,
			default: false,
		},
		/** 'module' or 'dienst' — the type of the object being reviewed. */
		subjectType: {
			type: String,
			required: true,
		},
		/** The uuid of the module/dienst being reviewed. */
		subjectId: {
			type: String,
			required: true,
		},
	},

	emits: ['close', 'review-submitted'],

	data() {
		return {
			loading: false,
			error: '',
			selectedRating: 8,
			formData: {
				naam: '',
				beschrijvingLang: '',
			},
		}
	},

	computed: {
		/**
		 * @return {Array<{label:string, value:number}>} 1-10 rating options.
		 * @spec openspec/specs/catalog-ratings/spec.md
		 */
		ratingOptions() {
			return ratingOptions()
		},

		/**
		 * @return {boolean} Whether the form is ready to submit.
		 * @spec openspec/specs/catalog-ratings/spec.md
		 */
		isFormValid() {
			return isReviewFormValid(this.formData.naam, this.selectedRating)
		},
	},

	methods: {
		t,

		/**
		 * Close the modal and reset its state.
		 *
		 * @return {void}
		 * @spec openspec/specs/catalog-ratings/spec.md
		 */
		closeModal() {
			this.resetForm()
			this.$emit('close')
		},

		/**
		 * Reset the form back to its initial state.
		 *
		 * @return {void}
		 * @spec openspec/specs/catalog-ratings/spec.md
		 */
		resetForm() {
			this.formData = { naam: '', beschrijvingLang: '' }
			this.selectedRating = 8
			this.error = ''
		},

		/**
		 * Submit the review. Note: `auteur` is intentionally never included
		 * in this payload — the server always derives it from the
		 * authenticated session and ignores any client-supplied value.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/catalog-ratings/spec.md#requirement-the-submitting-users-identity-must-be-bound-server-side-and-must-not-be-accepted-from-client-input
		 */
		async submitReview() {
			if (!this.isFormValid) {
				return
			}

			this.loading = true
			this.error = ''
			try {
				const body = buildReviewSubmission(
					this.formData.naam,
					this.selectedRating,
					this.formData.beschrijvingLang,
					this.subjectType,
					this.subjectId,
				)
				await apiRequest('reviews', { method: 'POST', body })
				showSuccess(t('softwarecatalog', 'Thank you — your review was submitted for moderation'))
				this.$emit('review-submitted')
				this.closeModal()
			} catch (submitError) {
				this.error = submitError.message
				showError(t('softwarecatalog', 'Could not submit your review') + ': ' + submitError.message)
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.submit-review-modal {
	padding: 0 20px 20px;
}

.modal-description {
	color: var(--color-text-maxcontrast);
	margin-bottom: 16px;
}

.form-row {
	margin-bottom: 16px;
}

.dialog-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 16px;
}
</style>
