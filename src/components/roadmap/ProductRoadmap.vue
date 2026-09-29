<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<template>
	<CnWidgetWrapper
		:title="t('stackiq', 'Roadmap')"
		titleIconPosition="left"
		:showRefresh="false"
		:showRequestFeature="false">
		<template #title-icon>
			<CnIcon name="MapMarkerPath" :size="20" />
		</template>
		<div class="product-roadmap" data-testid="product-roadmap">
			<NcLoadingIcon
				v-if="loading"
				:size="32"
				:name="t('stackiq', 'Loading the roadmap')" />
			<NcNoteCard v-else-if="error" type="error">
				{{ t('stackiq', 'The roadmap could not be loaded.') }}
			</NcNoteCard>
			<template v-else>
				<p
					v-if="statement"
					class="product-roadmap__statement"
					data-testid="product-roadmap-statement">
					{{ statement }}
				</p>
				<p v-else class="product-roadmap__empty">
					{{
						t(
							'stackiq',
							'The supplier has not published a roadmap for this application',
						)
					}}
				</p>
				<CnTimelineView
					:events="events"
					sort="desc"
					:groupBy="groupByMonth"
					:kindClassMap="{ planned: 'planned', released: 'released' }"
					:emptyLabel="t('stackiq', 'No versions with a date yet')" />
			</template>
		</div>
	</CnWidgetWrapper>
</template>

<script>
import { CnIcon, CnTimelineView, CnWidgetWrapper } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { objectStore } from '../../store/store.js'
import { roadmapEvents } from '../../utils/maintenance.js'

export default {
	name: 'ProductRoadmap',

	components: {
		CnIcon,
		CnTimelineView,
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
			statement: '',
			events: [],
		}
	},

	watch: {
		/**
		 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-004-a-product-page-shows-the-supplier-s-roadmap
		 */
		objectId() {
			this.load()
		},
	},

	/**
	 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-004-a-product-page-shows-the-supplier-s-roadmap
	 */
	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Group timeline events by month.
		 *
		 * @param {object} event A timeline event.
		 * @return {{key: string, label: string}} The month group.
		 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-004-a-product-page-shows-the-supplier-s-roadmap
		 */
		groupByMonth(event) {
			const date = new Date(event.start)
			return {
				key: `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`,
				label: date.toLocaleDateString([], {
					year: 'numeric',
					month: 'long',
				}),
			}
		},

		/**
		 * Load the roadmap statement and the product's versions.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-004-a-product-page-shows-the-supplier-s-roadmap
		 */
		async load() {
			this.loading = true
			this.error = false
			try {
				this.ensureType('module')
				this.ensureType('moduleVersion')
				const module = await objectStore.fetchObject('module', this.objectId)
				this.statement = module?.roadmapStatement || ''
				const versions = await objectStore.fetchCollectionForOptions(
					'moduleVersion',
					{
						module: this.objectId,
						_limit: 200,
					},
				)
				this.events = roadmapEvents(versions)
			} catch {
				this.error = true
				this.events = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Register an object type with the store when it is not known yet.
		 *
		 * @param {string} type The schema slug.
		 * @return {void}
		 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-004-a-product-page-shows-the-supplier-s-roadmap
		 */
		ensureType(type) {
			if (
				typeof objectStore.registerObjectType !== 'function'
				|| objectStore.objectTypeRegistry?.[type]
			) {
				return
			}
			const config = objectStore.getSchemaConfig?.(type)
			if (config?.register && config?.schema) {
				objectStore.registerObjectType(type, config.schema, config.register)
			}
		},
	},
}
</script>

<style scoped>
.product-roadmap__statement {
	white-space: pre-line;
	margin-block-end: calc(var(--default-grid-baseline) * 4);
}

.product-roadmap__empty {
	color: var(--color-text-maxcontrast);
}

.product-roadmap :deep(.cn-timeline-view__event--planned) {
	border-inline-start: 3px solid var(--color-primary-element);
}
</style>
