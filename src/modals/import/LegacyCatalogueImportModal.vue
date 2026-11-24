<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<!--
LegacyCatalogueImportModal.vue

Brings over what an organisation registered in the previous Softwarecatalogus,
in three steps: the file, the preview (per row whether supplier, application
and version are matched or created, with a checkbox to leave a row out) and
the result (created, updated and skipped, with the reason per skipped row).
Nothing is written before the user presses Import.

@spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-4
-->

<template>
	<NcDialog
		v-if="show"
		:name="title"
		size="large"
		@closing="close">
		<div class="legacy-import">
			<p v-if="error" class="legacy-import__error" role="alert">
				{{ error }}
			</p>

			<form v-if="step === 'file'" class="legacy-import__file" @submit.prevent="preview">
				<p>
					{{ t('stackiq', 'Choose the export of your organisation from the previous Softwarecatalogus (.xlsx). Nothing is saved before you confirm.') }}
				</p>
				<input
					type="file"
					accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
					:aria-label="title"
					@change="pick">
				<div class="legacy-import__actions">
					<NcButton variant="secondary" @click="close">
						{{ t('stackiq', 'Cancel') }}
					</NcButton>
					<NcButton variant="primary" type="submit" :disabled="file === null || busy">
						<template #icon>
							<NcLoadingIcon v-if="busy" :size="20" />
						</template>
						{{ t('stackiq', 'Preview') }}
					</NcButton>
				</div>
			</form>

			<div v-else-if="step === 'preview'" class="legacy-import__preview" data-testid="legacy-import-preview">
				<table>
					<thead>
						<tr>
							<th scope="col">{{ t('stackiq', 'Bring over') }}</th>
							<th scope="col">{{ t('stackiq', 'Supplier') }}</th>
							<th scope="col">{{ t('stackiq', 'Application') }}</th>
							<th scope="col">{{ t('stackiq', 'Version') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in rows" :key="row.sheet + row.row" :class="{ 'legacy-import__row--skipped': row.skipped }">
							<td>
								<NcCheckboxRadioSwitch
									v-if="!row.skipped && row.legacyId !== ''"
									v-model="selected"
									:value="row.legacyId"
									:aria-label="row.legacyId" />
								<span v-else>{{ row.errors.join(' ') }}</span>
							</td>
							<td>{{ row.supplier.name }} <em>{{ decisionLabel(row.supplier.decision) }}</em></td>
							<td>{{ row.product.name }} <em>{{ decisionLabel(row.product.decision) }}</em></td>
							<td>{{ row.version.number }} <em>{{ decisionLabel(row.version.decision) }}</em></td>
						</tr>
					</tbody>
				</table>
				<div class="legacy-import__actions">
					<NcButton variant="secondary" @click="close">
						{{ t('stackiq', 'Cancel') }}
					</NcButton>
					<NcButton variant="primary" :disabled="selected.length === 0 || busy" @click="apply">
						<template #icon>
							<NcLoadingIcon v-if="busy" :size="20" />
						</template>
						{{ t('stackiq', 'Import') }}
					</NcButton>
				</div>
			</div>

			<div v-else class="legacy-import__result" data-testid="legacy-import-result">
				<ul class="legacy-import__counts">
					<li v-for="count in counts" :key="count.key">
						{{ count.text }}
					</li>
				</ul>
				<ul v-if="result.skippedRows.length > 0" class="legacy-import__skipped">
					<li v-for="skipped in result.skippedRows" :key="skipped.row">
						{{ skipped.legacyId || skipped.row }}: {{ skipReasonLabel(skipped.reason) }}
					</li>
				</ul>
				<div class="legacy-import__actions">
					<NcButton variant="primary" @click="close">
						{{ t('stackiq', 'Close') }}
					</NcButton>
				</div>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcCheckboxRadioSwitch, NcDialog, NcLoadingIcon } from '@nextcloud/vue'
import {
	decisionLabel,
	leftOutIds,
	legacyImportError,
	legacyImportForm,
	legacyImportTitle,
	legacyImportUrls,
	resultCounts,
	selectableIds,
	skipReasonLabel,
} from '../../utils/legacyImport.js'

export default {
	name: 'LegacyCatalogueImportModal',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcLoadingIcon,
	},

	props: {
		/** Whether the dialog is open. */
		show: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close', 'imported'],

	data() {
		return {
			step: 'file',
			file: null,
			rows: [],
			selected: [],
			result: null,
			busy: false,
			error: '',
		}
	},

	computed: {
		title: () => legacyImportTitle(),

		/**
		 * The counts the result step shows.
		 *
		 * @return {Array<{key: string, text: string}>}
		 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-4
		 */
		counts() {
			return this.result ? resultCounts(this.result) : []
		},
	},

	methods: {
		t,
		decisionLabel,
		skipReasonLabel,

		/**
		 * Keep the chosen file.
		 *
		 * @param {Event} event The change event of the file input.
		 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-4
		 */
		pick(event) {
			this.file = event.target.files?.[0] ?? null
			this.error = ''
		},

		/**
		 * Ask the server for the preview; every row it may bring over starts checked.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-4
		 */
		async preview() {
			await this.request(async () => {
				const { data } = await axios.post(legacyImportUrls().preview, legacyImportForm(this.file))
				this.rows = data.rows ?? []
				this.selected = selectableIds(this.rows)
				this.step = 'preview'
			})
		},

		/**
		 * Bring the checked rows over and show the result.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-4
		 */
		async apply() {
			await this.request(async () => {
				const form = legacyImportForm(this.file, leftOutIds(this.rows, this.selected))
				const { data } = await axios.post(legacyImportUrls().apply, form)
				this.result = { skippedRows: [], ...data }
				this.step = 'result'
				this.$emit('imported', this.result)
			})
		},

		/**
		 * Run a request with the busy flag, showing its error in the dialog.
		 *
		 * @param {() => Promise<void>} send The request.
		 * @return {Promise<void>}
		 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-4
		 */
		async request(send) {
			this.busy = true
			this.error = ''
			try {
				await send()
			} catch (error) {
				this.error = legacyImportError(error)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Reset and close.
		 *
		 * @spec openspec/changes/landscape-migrate-from-previous-catalogue/tasks.md#task-4
		 */
		close() {
			Object.assign(this.$data, this.$options.data.call(this))
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.legacy-import {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
	padding-block-end: calc(var(--default-grid-baseline) * 3);
}

.legacy-import__error {
	color: var(--color-error-text);
}

.legacy-import__preview table {
	width: 100%;
	border-collapse: collapse;
}

.legacy-import__preview th,
.legacy-import__preview td {
	padding: var(--default-grid-baseline);
	text-align: start;
	border-block-end: 1px solid var(--color-border);
}

.legacy-import__preview em {
	color: var(--color-text-maxcontrast);
}

.legacy-import__row--skipped {
	color: var(--color-text-maxcontrast);
}

.legacy-import__actions {
	display: flex;
	justify-content: flex-end;
	gap: var(--default-grid-baseline);
}
</style>
