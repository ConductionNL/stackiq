<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
 ChangeEntryTypeDialog.vue (landscape-change-entry-type)

 Shows what a type change does before it happens: the fields that carry over,
 the filled fields that are dropped (with their values) and the entries that
 still point at this one and so block the change. Confirm stays disabled while
 there are blockers, and, for a change to a service, until a service type is
 picked. The entry keeps its uuid; OpenRegister moves it.

 @spec openspec/changes/landscape-change-entry-type/specs/entry-type-change/spec.md#requirement-req-etc-002-the-user-sees-what-carries-over-before-confirming
-->
<template>
	<NcDialog
		v-if="show"
		:name="t('stackiq', 'Change type')"
		size="normal"
		:noClose="busy"
		@closing="$emit('close')">
		<div class="change-entry-type">
			<NcSelect
				:modelValue="target"
				:options="targetOptions"
				:inputLabel="t('stackiq', 'New type')"
				:clearable="false"
				label="label"
				:reduce="(option) => option.value"
				@update:modelValue="select" />

			<NcLoadingIcon v-if="loading" :size="32" />
			<template v-else-if="preview">
				<NcNoteCard v-if="preview.blockers.length > 0" type="warning">
					{{ t('stackiq', 'Other entries still point at this one, so its type cannot change.') }}
				</NcNoteCard>

				<section v-if="preview.blockers.length > 0">
					<h3>{{ t('stackiq', 'Blocking the change') }}</h3>
					<ul>
						<li v-for="blocker in preview.blockers" :key="blocker.schema + '.' + blocker.field">
							{{ t('stackiq', '{count} × {schema} ({field})', { count: blocker.count, schema: blocker.schema, field: blocker.field }) }}
						</li>
					</ul>
				</section>

				<section>
					<h3>{{ t('stackiq', 'Dropped') }}</h3>
					<p v-if="dropped.length === 0" class="change-entry-type__muted">
						{{ t('stackiq', 'No filled field is dropped.') }}
					</p>
					<ul v-else>
						<li v-for="row in dropped" :key="row.field">
							<strong>{{ row.field }}</strong>: {{ row.value }}
						</li>
					</ul>
				</section>

				<section>
					<h3>{{ t('stackiq', 'Carried over') }}</h3>
					<p class="change-entry-type__muted">
						{{ preview.carried.join(', ') }}
					</p>
					<p class="change-entry-type__muted">
						{{ t('stackiq', 'The entry keeps its identity: its history, files and notes stay with it.') }}
					</p>
				</section>

				<NcSelect
					v-if="target === 'service'"
					v-model="serviceTypes"
					:options="preview.serviceTypes || []"
					:multiple="true"
					:inputLabel="t('stackiq', 'Service type')" />
			</template>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
		</div>

		<template #actions>
			<NcButton :disabled="busy" @click="$emit('close')">
				{{ t('stackiq', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="!canConfirm" @click="confirm">
				<template #icon>
					<NcLoadingIcon v-if="busy" :size="20" />
				</template>
				{{ t('stackiq', 'Change type') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard, NcSelect } from '@nextcloud/vue'
import { changeType, droppedRows, previewTypeChange, targetsOf } from '../components/entries/entryTypes.js'

export default {
	name: 'ChangeEntryTypeDialog',

	components: { NcButton, NcDialog, NcLoadingIcon, NcNoteCard, NcSelect },

	props: {
		show: { type: Boolean, default: false },
		uuid: { type: String, required: true },
		currentType: { type: String, default: 'application' },
	},

	emits: ['close', 'changed'],

	data() {
		return {
			target: null,
			preview: null,
			serviceTypes: [],
			loading: false,
			busy: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The types this entry may change into, as options.
		 *
		 * @spec openspec/changes/landscape-change-entry-type/specs/entry-type-change/spec.md#requirement-req-etc-002-the-user-sees-what-carries-over-before-confirming
		 * @return {Array<object>} The options.
		 */
		targetOptions() {
			const labels = {
				application: t('stackiq', 'Application'),
				service: t('stackiq', 'Service'),
				systemSoftware: t('stackiq', 'System software'),
			}
			return targetsOf(this.currentType).map((value) => ({ value, label: labels[value] }))
		},

		/**
		 * The filled fields the change drops, as rows with their values.
		 *
		 * @spec openspec/changes/landscape-change-entry-type/specs/entry-type-change/spec.md#requirement-req-etc-002-the-user-sees-what-carries-over-before-confirming
		 * @return {Array<object>} The rows.
		 */
		dropped() {
			return droppedRows(this.preview?.dropped)
		},

		/**
		 * Whether the change may be confirmed: a preview without blockers, and a service type for a service.
		 *
		 * @spec openspec/changes/landscape-change-entry-type/specs/entry-type-change/spec.md#requirement-req-etc-002-the-user-sees-what-carries-over-before-confirming
		 * @return {boolean} True when Confirm is enabled.
		 */
		canConfirm() {
			if (this.busy || this.loading || !this.preview || this.preview.blockers.length > 0) {
				return false
			}
			return this.target !== 'service' || this.serviceTypes.length > 0
		},
	},

	watch: {
		show: {
			immediate: true,
			/**
			 * On opening, select the first target the entry may change into.
			 *
			 * @spec openspec/changes/landscape-change-entry-type/specs/entry-type-change/spec.md#requirement-req-etc-002-the-user-sees-what-carries-over-before-confirming
			 * @param {boolean} open Whether the dialog is open.
			 */
			handler(open) {
				if (open) {
					this.select(this.targetOptions[0]?.value || null)
				}
			},
		},
	},

	methods: {
		t,

		/**
		 * Pick a target type and load its preview.
		 *
		 * @spec openspec/changes/landscape-change-entry-type/specs/entry-type-change/spec.md#requirement-req-etc-002-the-user-sees-what-carries-over-before-confirming
		 * @param {string|null} value The target type.
		 * @return {void}
		 */
		select(value) {
			this.target = value
			this.serviceTypes = []
			if (value) {
				this.load()
			}
		},

		/**
		 * Load the preview for the chosen type.
		 *
		 * @spec openspec/changes/landscape-change-entry-type/specs/entry-type-change/spec.md#requirement-req-etc-002-the-user-sees-what-carries-over-before-confirming
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''
			this.preview = null
			try {
				const preview = await previewTypeChange(this.uuid, this.target)
				this.preview = { carried: [], dropped: {}, blockers: [], serviceTypes: [], ...preview }
			} catch (e) {
				this.error = e?.response?.data?.message || t('stackiq', 'The change could not be previewed.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Change the type and tell the parent.
		 *
		 * @spec openspec/changes/landscape-change-entry-type/specs/entry-type-change/spec.md#requirement-req-etc-001-an-entry-changes-type-and-keeps-its-identity
		 * @return {Promise<void>}
		 */
		async confirm() {
			this.busy = true
			this.error = ''
			try {
				const outcome = await changeType(this.uuid, this.target, this.serviceTypes)
				this.$emit('changed', outcome)
			} catch (e) {
				this.error = e?.response?.data?.message || t('stackiq', 'The type could not be changed.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.change-entry-type {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.change-entry-type h3 {
	margin: 0;
	font-size: var(--default-font-size);
	font-weight: bold;
}

.change-entry-type ul {
	margin: 0;
	padding-inline-start: calc(var(--default-grid-baseline) * 5);
}

.change-entry-type__muted {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
