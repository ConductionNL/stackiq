<!--
SaveFacetViewModal.vue

Collects a name for the currently active GEMMA facet selection (and
free-text search, if any) on the module/dienst index pages, then emits
`save` so the caller persists it via the existing OpenRegister Views API
(no new ViewController/ViewService endpoint — see facets.js store).

@spec openspec/changes/gemma-faceted-search/tasks.md#task-13
@spec openspec/specs/gemma-faceted-search/spec.md#requirement-a-facet-selection-can-be-saved-as-a-view
-->

<template>
	<NcDialog
		v-if="show"
		:name="t('softwarecatalog', 'Save as view')"
		size="small"
		@closing="closeModal">
		<div class="save-facet-view-modal">
			<p class="save-facet-view-modal__description">
				{{
					t(
						'softwarecatalog',
						'Save the current filter selection so it can be reopened later.',
					)
				}}
			</p>

			<form class="save-facet-view-modal__form" @submit.prevent="save">
				<NcTextField
					v-model="name"
					:label="t('softwarecatalog', 'View name')"
					:placeholder="
						t('softwarecatalog', 'e.g. Zaakregistratie modules')
					"
					required />

				<div class="save-facet-view-modal__actions">
					<NcButton variant="secondary" @click="closeModal">
						{{ t('softwarecatalog', 'Cancel') }}
					</NcButton>
					<NcButton
						variant="primary"
						:disabled="saving || name.trim() === ''"
						type="submit">
						<template #icon>
							<NcLoadingIcon v-if="saving" :size="20" />
						</template>
						{{ t('softwarecatalog', 'Save view') }}
					</NcButton>
				</div>
			</form>
		</div>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcTextField } from '@nextcloud/vue'

export default {
	name: 'SaveFacetViewModal',

	components: {
		NcDialog,
		NcButton,
		NcTextField,
		NcLoadingIcon,
	},

	props: {
		/** Whether the modal is visible. */
		show: {
			type: Boolean,
			default: false,
		},

		/** Whether a save request is in flight — disables the submit button. */
		saving: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close', 'save'],

	data() {
		return {
			name: '',
		}
	},

	watch: {
		/**
		 * Clear the name field each time the modal is (re)opened, so a
		 * previous attempt's text never leaks into the next save.
		 *
		 * @param {boolean} value Whether the modal became visible.
		 * @return {void}
		 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-a-facet-selection-can-be-saved-as-a-view
		 */
		show(value) {
			if (value === true) {
				this.name = ''
			}
		},
	},

	methods: {
		/**
		 * Dismiss the modal without saving.
		 *
		 * @return {void}
		 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-a-facet-selection-can-be-saved-as-a-view
		 */
		closeModal() {
			this.$emit('close')
		},

		/**
		 * Emit the trimmed view name, refusing an empty one.
		 *
		 * @return {void}
		 * @spec openspec/specs/gemma-faceted-search/spec.md#requirement-a-facet-selection-can-be-saved-as-a-view
		 */
		save() {
			const trimmed = this.name.trim()
			if (trimmed === '') {
				return
			}

			this.$emit('save', trimmed)
		},
	},
}
</script>

<style scoped>
.save-facet-view-modal {
	padding: calc(var(--default-grid-baseline, 8px) * 2);
}

.save-facet-view-modal__description {
	color: var(--color-text-maxcontrast);
	margin-bottom: calc(var(--default-grid-baseline, 8px) * 2);
}

.save-facet-view-modal__form {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 8px) * 2);
}

.save-facet-view-modal__actions {
	display: flex;
	justify-content: flex-end;
	gap: var(--default-grid-baseline, 8px);
}
</style>
