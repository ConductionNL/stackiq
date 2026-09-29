<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<template>
	<CnWidgetWrapper
		:title="t('stackiq', 'AI Act evidence')"
		titleIconPosition="left"
		:showRefresh="false"
		:showRequestFeature="false">
		<template #title-icon>
			<CnIcon name="ClipboardCheckOutline" :size="20" />
		</template>
		<div class="ai-act-checklist" data-testid="ai-act-checklist">
			<NcLoadingIcon
				v-if="loading"
				:size="32"
				:name="t('stackiq', 'Loading the evidence')" />
			<NcNoteCard v-else-if="error" type="error">
				{{ t('stackiq', 'The evidence could not be loaded.') }}
			</NcNoteCard>
			<template v-else>
				<NcNoteCard
					v-if="missingFria"
					type="warning"
					data-testid="ai-act-fria-warning">
					{{ t('stackiq', 'This is a high-risk AI system without a fundamental rights impact assessment.') }}
				</NcNoteCard>
				<ul class="ai-act-checklist__list">
					<li
						v-for="item in checklist"
						:key="item.tag"
						class="ai-act-checklist__item"
						:data-testid="'ai-act-evidence-' + item.tag"
						:data-present="item.present ? 'true' : 'false'">
						<CnIcon
							:name="item.present ? 'CheckCircle' : 'AlertCircle'"
							:size="20"
							:class="item.present ? 'ai-act-checklist__icon--ok' : 'ai-act-checklist__icon--missing'" />
						<span class="ai-act-checklist__tag">{{ tagLabel(item.tag) }}</span>
						<span class="ai-act-checklist__state">
							{{ item.present ? item.files.join(', ') : t('stackiq', 'Missing') }}
						</span>
					</li>
				</ul>
				<p class="ai-act-checklist__hint">
					{{ t('stackiq', 'Attach a document under Documents and give it the matching tag.') }}
				</p>
			</template>
		</div>
	</CnWidgetWrapper>
</template>

<script>
import { CnIcon, CnWidgetWrapper } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { evidenceChecklist, friaMissing } from '../../utils/aiAct.js'

export default {
	name: 'AiActChecklist',
	components: {
		CnIcon,
		CnWidgetWrapper,
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		objectId: {
			type: [String, Number],
			default: '',
		},

		register: {
			type: String,
			default: 'stackiq',
		},

		schema: {
			type: String,
			default: 'aiSystem',
		},
	},

	data() {
		return {
			loading: true,
			error: false,
			system: {},
			files: [],
		}
	},

	computed: {
		/**
		 * One entry per evidence tag, present when a file carries it.
		 *
		 * @return {Array<object>} The checklist.
		 * @spec openspec/specs/ai-system-inventory/spec.md#requirement-req-ais-003-a-high-risk-ai-system-without-a-fundamental-rights-impact-assessment-is-flagged
		 */
		checklist() {
			return evidenceChecklist(this.files)
		},

		/**
		 * Whether the system is high risk and has no FRIA reference.
		 *
		 * @return {boolean} True when the warning shows.
		 * @spec openspec/specs/ai-system-inventory/spec.md#requirement-req-ais-003-a-high-risk-ai-system-without-a-fundamental-rights-impact-assessment-is-flagged
		 */
		missingFria() {
			return friaMissing(this.system)
		},
	},

	async mounted() {
		await this.load()
	},

	methods: {
		t,

		/**
		 * The translated name of an evidence tag.
		 *
		 * @param {string} tag The tag as stored on the file.
		 * @return {string} The label.
		 * @spec openspec/specs/ai-system-inventory/spec.md#requirement-req-ais-002-an-ai-system-carries-its-ai-act-classification-and-evidence
		 */
		tagLabel(tag) {
			const labels = {
				FRIA: t('stackiq', 'Fundamental rights impact assessment (FRIA)'),
				'Technical documentation': t('stackiq', 'Technical documentation'),
				'Human oversight': t('stackiq', 'Human oversight'),
				Logging: t('stackiq', 'Logging'),
			}
			return labels[tag] ?? tag
		},

		/**
		 * Read the AI system and its files.
		 *
		 * @return {Promise<void>} Resolves once both are read.
		 * @spec openspec/specs/ai-system-inventory/spec.md#requirement-req-ais-003-a-high-risk-ai-system-without-a-fundamental-rights-impact-assessment-is-flagged
		 */
		async load() {
			this.loading = true
			this.error = false
			try {
				if (this.objectId) {
					const params = {
						register: this.register,
						schema: this.schema,
						id: String(this.objectId),
					}
					const [object, files] = await Promise.all([
						axios.get(generateUrl('/apps/openregister/api/objects/{register}/{schema}/{id}', params)),
						axios.get(generateUrl('/apps/openregister/api/objects/{register}/{schema}/{id}/files', params)),
					])
					this.system = object.data?.object || object.data || {}
					this.files = files.data?.results ?? []
				}
			} catch (e) {
				this.error = true
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.ai-act-checklist__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.ai-act-checklist__item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 0;
	border-bottom: 1px solid var(--color-border);
}

.ai-act-checklist__tag {
	flex: 1;
}

.ai-act-checklist__state {
	color: var(--color-text-maxcontrast);
}

.ai-act-checklist__icon--ok {
	color: var(--color-success-text);
}

.ai-act-checklist__icon--missing {
	color: var(--color-warning-text);
}

.ai-act-checklist__hint {
	margin-top: 8px;
	color: var(--color-text-maxcontrast);
}
</style>
