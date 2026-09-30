<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<template>
	<CnWidgetWrapper
		:title="t('stackiq', 'Risk signals')"
		titleIconPosition="left"
		:showRefresh="false"
		:showRequestFeature="false">
		<template #title-icon>
			<CnIcon name="ShieldAlertOutline" :size="20" />
		</template>
		<div class="usage-risk-signals" data-testid="usage-risk-signals">
			<NcLoadingIcon
				v-if="loading"
				:size="32"
				:name="t('stackiq', 'Loading the risk signals')" />
			<NcNoteCard v-else-if="error" type="error">
				{{ t('stackiq', 'The risk signals could not be loaded.') }}
			</NcNoteCard>
			<dl v-else class="usage-risk-signals__list">
				<dt>{{ t('stackiq', 'Risk score') }}</dt>
				<dd data-testid="usage-risk-score">
					{{ riskScore === null ? t('stackiq', 'Not scored') : riskScore + ' / 5' }}
				</dd>
				<dt>{{ t('stackiq', 'End of support') }}</dt>
				<dd data-testid="usage-risk-eol">
					<span
						v-if="signals.endOfSupportPassed"
						class="usage-risk-signals__warning">
						{{ t('stackiq', 'Passed on {date}', { date: signals.endOfSupportDate }) }}
					</span>
					<span v-else-if="signals.endOfSupportDate">
						{{ t('stackiq', 'Supported until {date}', { date: signals.endOfSupportDate }) }}
					</span>
					<span v-else>{{ t('stackiq', 'No end of support date known') }}</span>
					<span v-if="signals.withdrawn" class="usage-risk-signals__warning">
						{{ t('stackiq', 'This version was withdrawn') }}
					</span>
				</dd>
				<dt>{{ t('stackiq', 'Known vulnerabilities') }}</dt>
				<dd data-testid="usage-risk-vulnerabilities">
					<span
						:class="{
							'usage-risk-signals__warning': signals.vulnerabilityCount > 0,
						}">
						{{
							n(
								'stackiq',
								'%n vulnerability linked to this application',
								'%n vulnerabilities linked to this application',
								signals.vulnerabilityCount,
							)
						}}
					</span>
				</dd>
			</dl>
		</div>
	</CnWidgetWrapper>
</template>

<script>
import { CnIcon, CnWidgetWrapper } from '@conduction/nextcloud-vue'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { objectStore } from '../../store/store.js'
import { refId } from '../../utils/maintenance.js'
import { riskSignals } from '../../utils/valueAssessment.js'

export default {
	name: 'UsageRiskSignals',

	components: {
		CnIcon,
		CnWidgetWrapper,
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		objectId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			loading: true,
			error: false,
			riskScore: null,
			signals: riskSignals(null, [], ''),
		}
	},

	watch: {
		/**
		 * @spec openspec/specs/application-value-assessment/spec.md#requirement-req-ava-002-the-usage-page-shows-the-risk-signals-next-to-the-risk-score
		 */
		objectId() {
			this.load()
		},
	},

	/**
	 * @spec openspec/specs/application-value-assessment/spec.md#requirement-req-ava-002-the-usage-page-shows-the-risk-signals-next-to-the-risk-score
	 */
	mounted() {
		this.load()
	},

	methods: {
		t,
		n,

		/**
		 * Load the usage, the version it runs and the vulnerabilities of its application.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/application-value-assessment/spec.md#requirement-req-ava-002-the-usage-page-shows-the-risk-signals-next-to-the-risk-score
		 */
		async load() {
			this.loading = true
			this.error = false
			try {
				this.ensureType('usage')
				this.ensureType('moduleVersion')
				this.ensureType('vulnerability')
				const usage = await objectStore.fetchObject('usage', this.objectId)
				const data = usage?.object || usage || {}
				this.riskScore = Number.isInteger(data.riskScore) ? data.riskScore : null
				const moduleId = refId(data.module)
				const versionId = refId(data.moduleVersion)
				const version = versionId
					? await objectStore.fetchObject('moduleVersion', versionId)
					: null
				const vulnerabilities = moduleId
					? await objectStore.fetchCollectionForOptions('vulnerability', {
						modules: moduleId,
						_limit: 200,
					})
					: []
				this.signals = riskSignals(version, vulnerabilities, moduleId)
			} catch {
				this.error = true
			} finally {
				this.loading = false
			}
		},

		/**
		 * Register an object type with the store when it is not known yet.
		 *
		 * @param {string} type The schema slug.
		 * @return {void}
		 * @spec openspec/specs/application-value-assessment/spec.md#requirement-req-ava-002-the-usage-page-shows-the-risk-signals-next-to-the-risk-score
		 */
		ensureType(type) {
			if (
				typeof objectStore.registerObjectType !== 'function'
				|| objectStore.objectTypeRegistry?.[type]
			) {
				return
			}
			let config = null
			try {
				config = objectStore.getSchemaConfig?.(type)
			} catch {
				config = null
			}
			if (config?.register && config?.schema) {
				objectStore.registerObjectType(type, config.schema, config.register)
				return
			}
			// OpenRegister accepts the schema slug where it takes an id, so a type
			// the settings do not map still resolves in the voorzieningen register.
			const voorzieningen =
				objectStore.settings?.voorzieningen
				|| objectStore.settings?.voorzieningenConfig
				|| {}
			if (voorzieningen.register) {
				objectStore.registerObjectType(type, type, voorzieningen.register)
			}
		},
	},
}
</script>

<style scoped>
.usage-risk-signals__list {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 4);
	margin: 0;
}

.usage-risk-signals__list dt {
	font-weight: bold;
}

.usage-risk-signals__list dd {
	margin: 0;
	display: flex;
	flex-direction: column;
}

.usage-risk-signals__warning {
	color: var(--color-error-text);
}
</style>
