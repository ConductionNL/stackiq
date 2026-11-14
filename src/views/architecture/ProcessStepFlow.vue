<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<template>
	<CnWidgetWrapper
		:title="t('stackiq', 'Step flow')"
		titleIconPosition="left"
		:showRefresh="false"
		:showRequestFeature="false">
		<template #title-icon>
			<CnIcon name="SitemapOutline" :size="20" />
		</template>
		<div class="process-step-flow" data-testid="process-step-flow">
			<NcLoadingIcon
				v-if="loading"
				:size="32"
				:name="t('stackiq', 'Loading the steps')" />
			<NcEmptyContent
				v-else-if="flow.nodes.length === 0"
				:name="t('stackiq', 'No steps yet')"
				:description="t('stackiq', 'Add the steps of this process to see them as a flow.')" />
			<CnGraphCanvas
				v-else
				class="process-step-flow__canvas"
				:nodes="flow.nodes"
				:edges="flow.edges"
				:readOnly="true"
				:showMiniMap="false">
				<template #node="{ node }">
					<div
						class="process-step-flow__node"
						:class="{ 'process-step-flow__node--attention': node.data.needsAttention }">
						<strong>{{ node.data.name }}</strong>
						<span>{{ stepTypeLabel(node.data.stepType) }}</span>
						<span>{{ t('stackiq', 'Risk: {level}', { level: riskLabel(node.data.riskLevel) }) }}</span>
						<span>{{ t('stackiq', 'Compliance: {check}', { check: complianceLabel(node.data.complianceCheck) }) }}</span>
						<span v-if="node.data.needsAttention" class="process-step-flow__attention">
							{{ t('stackiq', 'Needs attention') }}
						</span>
						<span v-if="node.data.applications.length" class="process-step-flow__applications">
							{{ node.data.applications.join(', ') }}
						</span>
					</div>
				</template>
			</CnGraphCanvas>
		</div>
	</CnWidgetWrapper>
</template>

<script>
import { CnGraphCanvas, CnIcon, CnWidgetWrapper } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { objectStore } from '../../store/store.js'
import { ensureObjectTypeOrSlug } from '../../utils/objectTypes.js'
import { buildStepFlow } from '../../utils/processStepFlow.js'
import { loadProcessSteps } from '../../utils/processStepUsages.js'

/**
 * The steps of one business process as a read-only flow on the shared graph
 * canvas. A step with a high risk or a failed compliance check is marked with
 * the error colour and says so in text (REQ-BPM-004).
 *
 * @spec openspec/changes/architecture-process-mapping/specs/business-process-mapping/spec.md#requirement-req-bpm-004-the-process-page-shall-show-the-steps-as-a-read-only-flow
 */
export default {
	name: 'ProcessStepFlow',

	components: {
		CnGraphCanvas,
		CnIcon,
		CnWidgetWrapper,
		NcEmptyContent,
		NcLoadingIcon,
	},

	props: {
		/** The process id. */
		objectId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			loading: true,
			flow: { nodes: [], edges: [] },
		}
	},

	watch: {
		objectId() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Load the steps and build the flow.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/architecture-process-mapping/specs/business-process-mapping/spec.md#requirement-req-bpm-004-the-process-page-shall-show-the-steps-as-a-read-only-flow
		 */
		async load() {
			this.loading = true
			try {
				ensureObjectTypeOrSlug('processStep')
				ensureObjectTypeOrSlug('usage')
				const { steps, usageNames } = await loadProcessSteps(this.objectId, {
					fetchList: (type, params) => objectStore.fetchCollectionForOptions(type, params),
					fetchObject: (type, id) => objectStore.fetchObject(type, id),
				})
				this.flow = buildStepFlow(steps, usageNames)
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {string} value - the step type.
		 * @return {string}
		 */
		stepTypeLabel(value) {
			return { task: t('stackiq', 'Task'), event: t('stackiq', 'Event'), decision: t('stackiq', 'Decision') }[value] || value
		},

		/**
		 * @param {string} value - the risk level.
		 * @return {string}
		 */
		riskLabel(value) {
			return {
				'not assessed': t('stackiq', 'Not assessed'),
				low: t('stackiq', 'Low'),
				medium: t('stackiq', 'Medium'),
				high: t('stackiq', 'High'),
			}[value] || value
		},

		/**
		 * @param {string} value - the compliance check.
		 * @return {string}
		 */
		complianceLabel(value) {
			return {
				'not checked': t('stackiq', 'Not checked'),
				compliant: t('stackiq', 'Compliant'),
				'not compliant': t('stackiq', 'Not compliant'),
				'not applicable': t('stackiq', 'Not applicable'),
			}[value] || value
		},
	},
}
</script>

<style scoped>
.process-step-flow__canvas {
	height: 360px;
}

.process-step-flow__node {
	display: flex;
	flex-direction: column;
	gap: 2px;
	padding: 6px 8px;
	border: 2px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.85rem;
	max-width: 220px;
}

.process-step-flow__node--attention {
	border-color: var(--color-error);
}

.process-step-flow__attention {
	color: var(--color-error-text, var(--color-error));
	font-weight: 600;
}

.process-step-flow__applications {
	color: var(--color-text-maxcontrast);
}
</style>
