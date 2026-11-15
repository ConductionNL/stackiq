<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<template>
	<CnWidgetWrapper
		:title="t('stackiq', 'Supporting applications')"
		titleIconPosition="left"
		:showRefresh="false"
		:showRequestFeature="false">
		<template #title-icon>
			<CnIcon name="OfficeBuilding" :size="20" />
		</template>
		<div class="process-step-usages" data-testid="process-step-usages">
			<NcLoadingIcon
				v-if="loading"
				:size="32"
				:name="t('stackiq', 'Loading the applications')" />
			<template v-else>
				<ul v-if="visible.length" class="process-step-usages__list">
					<li v-for="usage in visible" :key="usage.id">
						<router-link :to="{ name: 'GebruikDetail', params: { id: usage.id } }">
							{{ usage.name }}
						</router-link>
					</li>
				</ul>
				<p v-else-if="hidden === 0" class="process-step-usages__empty">
					{{ t('stackiq', 'No application in use supports this step yet.') }}
				</p>
				<NcNoteCard v-if="hidden > 0" type="info">
					{{ n('stackiq', '%n linked application is not visible to you', '%n linked applications are not visible to you', hidden) }}
				</NcNoteCard>
			</template>
		</div>
	</CnWidgetWrapper>
</template>

<script>
import { CnIcon, CnWidgetWrapper } from '@conduction/nextcloud-vue'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { objectStore } from '../../store/store.js'
import { ensureObjectTypeOrSlug } from '../../utils/objectTypes.js'
import { loadStepUsages } from '../../utils/processStepUsages.js'

/**
 * The applications in use that support a process step, saying how many
 * linked applications the reader may not see (REQ-BPM-002).
 *
 * @spec openspec/changes/architecture-process-mapping/specs/business-process-mapping/spec.md#requirement-req-bpm-002-a-step-shall-name-the-applications-in-use-that-support-it
 */
export default {
	name: 'ProcessStepUsages',

	components: {
		CnIcon,
		CnWidgetWrapper,
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		/** The processStep id. */
		objectId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			loading: true,
			visible: [],
			hidden: 0,
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
		n,

		/**
		 * Load the step's usages.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/architecture-process-mapping/specs/business-process-mapping/spec.md#requirement-req-bpm-002-a-step-shall-name-the-applications-in-use-that-support-it
		 */
		async load() {
			this.loading = true
			try {
				ensureObjectTypeOrSlug('processStep')
				ensureObjectTypeOrSlug('usage')
				const { visible, hidden } = await loadStepUsages(this.objectId, (type, id) => objectStore.fetchObject(type, id))
				this.visible = visible
				this.hidden = hidden
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.process-step-usages__list {
	margin: 0;
	padding-inline-start: 20px;
}

.process-step-usages__empty {
	color: var(--color-text-maxcontrast);
}
</style>
