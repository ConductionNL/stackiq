<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<template>
	<CnWidgetWrapper
		v-if="!forbidden"
		:title="t('stackiq', 'How organisations classify this application')"
		titleIconPosition="left"
		:showRefresh="false"
		:showRequestFeature="false">
		<template #title-icon>
			<CnIcon name="ShieldLockOutline" :size="20" />
		</template>
		<div class="module-classification" data-testid="module-classification-summary">
			<NcLoadingIcon v-if="loading" :size="32" :name="t('stackiq', 'Loading the classifications')" />
			<NcNoteCard v-else-if="error" type="error">
				{{ t('stackiq', 'The classifications could not be loaded.') }}
			</NcNoteCard>
			<template v-else>
				<p data-testid="module-classification-count">
					{{ n('stackiq', '%n organisation classified this application', '%n organisations classified this application', organisations) }}
				</p>
				<p v-if="!levels" class="module-classification__note" data-testid="module-classification-withheld">
					{{ t('stackiq', 'The levels show once at least three organisations have classified it.') }}
				</p>
				<table v-else class="module-classification__table">
					<thead>
						<tr>
							<th scope="col">
								{{ t('stackiq', 'Aspect') }}
							</th>
							<th v-for="level in levelNames" :key="level" scope="col">
								{{ level }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in rows" :key="row.key" :data-testid="`module-classification-${row.key}`">
							<th scope="row">
								{{ row.label }}
							</th>
							<td v-for="level in levelNames" :key="level">
								{{ row.counts[level] ?? 0 }}
							</td>
						</tr>
					</tbody>
				</table>
			</template>
		</div>
	</CnWidgetWrapper>
</template>

<script>
import { CnIcon, CnWidgetWrapper } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'

/**
 * ModuleClassificationSummary — how organisations classify an application
 * (security-baseline-classification REQ-BCL-004): the number of organisations
 * that classified it and, from three on, per aspect and overall the number
 * of organisations per level. It names no organisation, and it hides itself
 * for a reader the server refuses (403), such as a supplier.
 *
 * @spec openspec/changes/security-baseline-classification/specs/baseline-classification/spec.md#requirement-req-bcl-004-municipalities-shall-see-how-organisations-classify-an-application-without-names-and-not-below-three
 */
export default {
	name: 'ModuleClassificationSummary',

	components: { CnIcon, CnWidgetWrapper, NcLoadingIcon, NcNoteCard },

	props: {
		/** The application. */
		objectId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			loading: true,
			error: false,
			forbidden: false,
			organisations: 0,
			levels: null,
		}
	},

	computed: {
		/**
		 * @return {Array<string>}
		 * @spec openspec/changes/security-baseline-classification/tasks.md#task-4
		 */
		levelNames() {
			return ['BBN1', 'BBN2', 'BBN3']
		},

		/**
		 * @return {Array<object>} One row per aspect and overall.
		 * @spec openspec/changes/security-baseline-classification/tasks.md#task-4
		 */
		rows() {
			const labels = {
				availability: t('stackiq', 'Availability'),
				integrity: t('stackiq', 'Integrity'),
				confidentiality: t('stackiq', 'Confidentiality'),
				overall: t('stackiq', 'BBN level'),
			}
			return Object.entries(labels).map(([key, label]) => ({ key, label, counts: this.levels?.[key] ?? {} }))
		},
	},

	watch: {
		/**
		 * Reload for another application.
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
			this.error = false
			try {
				const { data } = await axios.get(generateUrl('/apps/stackiq/api/modules/{id}/classification-summary', { id: this.objectId }))
				this.organisations = Number(data?.organisations) || 0
				this.levels = data?.levels ?? null
			} catch (error) {
				if (error?.response?.status === 403) {
					this.forbidden = true
				} else {
					this.error = true
				}
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.module-classification__note {
	color: var(--color-text-maxcontrast);
}

.module-classification__table th,
.module-classification__table td {
	padding: 4px 8px;
	text-align: start;
	border-bottom: 1px solid var(--color-border);
}
</style>
