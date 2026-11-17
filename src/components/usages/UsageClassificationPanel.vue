<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<template>
	<CnWidgetWrapper
		:title="t('stackiq', 'Security classification (BIO)')"
		titleIconPosition="left"
		:showRefresh="false"
		:showRequestFeature="false">
		<template #title-icon>
			<CnIcon name="ShieldLockOutline" :size="20" />
		</template>
		<div class="usage-classification" data-testid="usage-classification">
			<NcLoadingIcon v-if="loading" :size="32" :name="t('stackiq', 'Loading the classification')" />
			<NcNoteCard v-else-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<form v-else class="usage-classification__form" @submit.prevent="save">
				<div v-for="aspect in aspects" :key="aspect.key" class="usage-classification__aspect">
					<label :for="`classification-${aspect.key}`">{{ aspect.label }}</label>
					<select :id="`classification-${aspect.key}`" v-model="form[aspect.level]" :data-testid="`classification-${aspect.key}`">
						<option value="">
							{{ t('stackiq', 'Not classified') }}
						</option>
						<option v-for="level in levels" :key="level" :value="level">
							{{ level }}
						</option>
					</select>
					<NcTextField
						v-model="form[aspect.reason]"
						:label="t('stackiq', 'Main reason')"
						:data-testid="`classification-${aspect.key}-reason`" />
				</div>
				<p data-testid="classification-overall">
					<strong>{{ t('stackiq', 'BBN level') }}:</strong>
					{{ overall || t('stackiq', 'Not classified') }}
				</p>
				<p v-if="classifiedAt" class="usage-classification__stamp">
					{{ t('stackiq', 'Classified on {date} by {user}', { date: classifiedAt, user: classifiedBy || '' }) }}
				</p>
				<p v-if="suggestionNote" class="usage-classification__stamp" data-testid="classification-suggestion-note">
					{{ suggestionNote }}
				</p>
				<div class="usage-classification__actions">
					<NcButton variant="secondary" :disabled="busy" data-testid="classification-suggest" @click="suggest">
						{{ t('stackiq', 'Suggest from GEMMA') }}
					</NcButton>
					<NcButton type="submit" variant="primary" :disabled="busy || !dirty" data-testid="classification-save">
						{{ t('stackiq', 'Save') }}
					</NcButton>
				</div>
			</form>
		</div>
	</CnWidgetWrapper>
</template>

<script>
import { CnIcon, CnWidgetWrapper } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard, NcTextField } from '@nextcloud/vue'
import { objectStore } from '../../store/store.js'

/** The levels, lowest first. */
export const LEVELS = ['BBN1', 'BBN2', 'BBN3']

/** The usage properties the panel edits. */
export const FIELDS = ['availabilityLevel', 'availabilityReason', 'integrityLevel', 'integrityReason', 'confidentialityLevel', 'confidentialityReason']

/**
 * The highest of the given levels, or '' when none is set (what the server derives as bbnLevel).
 *
 * @param {Array<string>} values The levels.
 * @return {string}
 */
export function highestLevel(values) {
	return values.filter((value) => LEVELS.includes(value)).sort().pop() ?? ''
}

/**
 * UsageClassificationPanel — the BIO classification of an application in use
 * (security-baseline-classification REQ-BCL-001..003): a level and a main
 * reason per aspect, the overall BBN level the server derives, Suggest from
 * GEMMA, which fills the levels without saving, and Save.
 *
 * @spec openspec/changes/security-baseline-classification/specs/baseline-classification/spec.md#requirement-req-bcl-003-stackiq-shall-suggest-the-levels-from-gemmas-reference-components
 */
export default {
	name: 'UsageClassificationPanel',

	components: { CnIcon, CnWidgetWrapper, NcButton, NcLoadingIcon, NcNoteCard, NcTextField },

	props: {
		/** The usage. */
		objectId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			loading: true,
			busy: false,
			error: '',
			stored: {},
			form: Object.fromEntries(FIELDS.map((field) => [field, ''])),
			classifiedAt: '',
			classifiedBy: '',
			suggestionNote: '',
		}
	},

	computed: {
		/**
		 * @return {Array<string>}
		 * @spec openspec/changes/security-baseline-classification/tasks.md#task-4
		 */
		levels() {
			return LEVELS
		},

		/**
		 * @return {Array<object>}
		 * @spec openspec/changes/security-baseline-classification/tasks.md#task-4
		 */
		aspects() {
			return [
				{ key: 'availability', label: t('stackiq', 'Availability'), level: 'availabilityLevel', reason: 'availabilityReason' },
				{ key: 'integrity', label: t('stackiq', 'Integrity'), level: 'integrityLevel', reason: 'integrityReason' },
				{ key: 'confidentiality', label: t('stackiq', 'Confidentiality'), level: 'confidentialityLevel', reason: 'confidentialityReason' },
			]
		},

		/**
		 * @return {string} The overall level as the server will derive it.
		 * @spec openspec/changes/security-baseline-classification/tasks.md#task-4
		 */
		overall() {
			return highestLevel([this.form.availabilityLevel, this.form.integrityLevel, this.form.confidentialityLevel])
		},

		/**
		 * @return {boolean} Whether the form differs from what is stored.
		 * @spec openspec/changes/security-baseline-classification/tasks.md#task-4
		 */
		dirty() {
			return FIELDS.some((field) => (this.form[field] || '') !== (this.stored[field] || ''))
		},
	},

	watch: {
		/**
		 * Reload for another usage.
		 *
		 * @spec openspec/changes/security-baseline-classification/tasks.md#task-4
		 */
		objectId() {
			this.load()
		},
	},

	/** @spec openspec/changes/security-baseline-classification/tasks.md#task-4 */
	mounted() {
		this.load()
	},

	methods: {
		n,
		t,

		/** @spec openspec/changes/security-baseline-classification/tasks.md#task-4 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const usage = (await objectStore.fetchObject('usage', this.objectId)) ?? {}
				this.stored = Object.fromEntries(FIELDS.map((field) => [field, usage[field] ?? '']))
				this.form = { ...this.stored }
				this.classifiedAt = usage.classifiedAt ?? ''
				this.classifiedBy = usage.classifiedBy ?? ''
			} catch {
				this.error = t('stackiq', 'The classification could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fill the levels from GEMMA; nothing is saved until Save.
		 *
		 * @spec openspec/changes/security-baseline-classification/tasks.md#task-4
		 */
		async suggest() {
			this.busy = true
			try {
				const { data } = await axios.get(generateUrl('/apps/stackiq/api/gebruik/{id}/classification-suggestion', { id: this.objectId }))
				for (const aspect of this.aspects) {
					const level = data?.levels?.[aspect.key]
					if (LEVELS.includes(level)) {
						this.form[aspect.level] = level
					}
				}
				this.suggestionNote = n('stackiq', 'Suggested from %n reference component with a GEMMA score', 'Suggested from %n reference components with a GEMMA score', data?.scored ?? 0)
			} catch {
				this.suggestionNote = t('stackiq', 'No suggestion could be made.')
			} finally {
				this.busy = false
			}
		},

		/** @spec openspec/changes/security-baseline-classification/tasks.md#task-4 */
		async save() {
			this.busy = true
			try {
				const changes = Object.fromEntries(FIELDS.map((field) => [field, this.form[field] || null]))
				await objectStore.patchObject('usage', this.objectId, changes)
				await this.load()
			} catch {
				this.error = t('stackiq', 'The classification could not be saved.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.usage-classification__form {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.usage-classification__aspect {
	display: grid;
	grid-template-columns: minmax(120px, 1fr) minmax(100px, 1fr) 2fr;
	align-items: end;
	gap: 8px;
}

.usage-classification__stamp {
	color: var(--color-text-maxcontrast);
}

.usage-classification__actions {
	display: flex;
	gap: 8px;
}
</style>
