<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<template>
	<CnWidgetWrapper
		:title="t('stackiq', 'Runs on')"
		titleIconPosition="left"
		:showRefresh="false"
		:showRequestFeature="false">
		<template #title-icon>
			<CnIcon name="Server" :size="20" />
		</template>
		<div class="usage-runs-on" data-testid="usage-runs-on">
			<NcLoadingIcon
				v-if="loading"
				:size="32"
				:name="t('stackiq', 'Loading the components')" />
			<NcNoteCard v-else-if="error" type="error">
				{{ t('stackiq', 'The components could not be loaded.') }}
			</NcNoteCard>
			<p
				v-else-if="components.length === 0"
				class="usage-runs-on__empty">
				{{ t('stackiq', 'No technology component recorded for this application in use') }}
			</p>
			<template v-else>
				<p class="usage-runs-on__worst" data-testid="usage-runs-on-worst">
					<span :class="stateClass(state)">{{ worstLabel(state) }}</span>
				</p>
				<table class="usage-runs-on__table">
					<thead>
						<tr>
							<th scope="col">{{ t('stackiq', 'Component') }}</th>
							<th scope="col">{{ t('stackiq', 'End of support') }}</th>
							<th scope="col">{{ t('stackiq', 'Support') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="(row, index) in components"
							:key="(row.id || row.name) + '-' + index"
							:data-state="row.state">
							<td>
								<span v-if="row.through" class="usage-runs-on__through">
									{{ t('stackiq', 'Under {name}:', { name: throughName(row) }) }}
								</span>
								<router-link
									v-if="row.id"
									:to="{ name: 'TechnologieDetail', params: { id: String(row.id) } }">
									{{ row.name || t('stackiq', 'Technology component') }}
								</router-link>
								<span v-else>{{ row.name }}</span>
							</td>
							<td>{{ row.endOfSupport || t('stackiq', 'Unknown') }}</td>
							<td>
								<span :class="stateClass(row.state)">{{ stateLabel(row.state) }}</span>
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
import { translate as t } from '@nextcloud/l10n'
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { objectStore } from '../../store/store.js'
import { ensureObjectTypeOrSlug } from '../../utils/objectTypes.js'
import { loadUsageExposure } from '../../utils/technologyLifecycle.js'

/**
 * The technology components an application in use runs on, on its page, with
 * each component's end of support and support state, and the worst state over
 * them and the components they run on, one level down. Custom because a
 * built-in object list cannot show a state derived per row (design D5).
 *
 * @spec openspec/changes/operations-technology-components/specs/technology-components/spec.md#requirement-req-tco-003-an-application-in-use-shall-record-the-components-it-runs-on-and-show-their-support-state
 */
export default {
	name: 'UsageRunsOnPanel',

	components: {
		CnIcon,
		CnWidgetWrapper,
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		/** The usage id. */
		objectId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			loading: true,
			error: false,
			state: 'unknown',
			components: [],
		}
	},

	watch: {
		/**
		 * Reload when the page moves to another application in use.
		 *
		 * @return {void}
		 * @spec openspec/changes/operations-technology-components/specs/technology-components/spec.md#requirement-req-tco-003-an-application-in-use-shall-record-the-components-it-runs-on-and-show-their-support-state
		 */
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
		 * Load the usage and the components it runs on.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/operations-technology-components/specs/technology-components/spec.md#requirement-req-tco-003-an-application-in-use-shall-record-the-components-it-runs-on-and-show-their-support-state
		 */
		async load() {
			this.loading = true
			this.error = false
			try {
				ensureObjectTypeOrSlug('usage')
				ensureObjectTypeOrSlug('technologyComponent')
				const result = await loadUsageExposure(
					this.objectId,
					(type, id) => objectStore.fetchObject(type, id),
				)
				this.state = result.state
				this.components = result.components
			} catch {
				this.error = true
				this.state = 'unknown'
				this.components = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * The name of the component a lower row runs underneath.
		 *
		 * @param {{through: string|null}} row A row.
		 * @return {string}
		 * @spec openspec/changes/operations-technology-components/specs/technology-components/spec.md#requirement-req-tco-003-an-application-in-use-shall-record-the-components-it-runs-on-and-show-their-support-state
		 */
		throughName(row) {
			const parent = this.components.find((other) => other.id === row.through && other.through === null)
			return parent?.name || row.through || ''
		},

		/**
		 * The label of one component's support state.
		 *
		 * @param {string} state The state.
		 * @return {string}
		 * @spec openspec/changes/operations-technology-components/specs/technology-components/spec.md#requirement-req-tco-003-an-application-in-use-shall-record-the-components-it-runs-on-and-show-their-support-state
		 */
		stateLabel(state) {
			return {
				supported: t('stackiq', 'Supported'),
				ending: t('stackiq', 'Support ends within 180 days'),
				ended: t('stackiq', 'Support ended'),
			}[state] ?? t('stackiq', 'Unknown')
		},

		/**
		 * The sentence for the worst state the application is exposed to.
		 *
		 * @param {string} state The worst state.
		 * @return {string}
		 * @spec openspec/changes/operations-technology-components/specs/technology-components/spec.md#requirement-req-tco-003-an-application-in-use-shall-record-the-components-it-runs-on-and-show-their-support-state
		 */
		worstLabel(state) {
			return {
				supported: t('stackiq', 'Every component it runs on is supported'),
				ending: t('stackiq', 'A component it runs on reaches its end of support within 180 days'),
				ended: t('stackiq', 'A component it runs on is past its end of support'),
			}[state] ?? t('stackiq', 'The end of support of a component it runs on is not known')
		},

		/**
		 * The class that marks a state.
		 *
		 * @param {string} state The state.
		 * @return {string}
		 * @spec openspec/changes/operations-technology-components/specs/technology-components/spec.md#requirement-req-tco-003-an-application-in-use-shall-record-the-components-it-runs-on-and-show-their-support-state
		 */
		stateClass(state) {
			return 'usage-runs-on__state usage-runs-on__state--' + (state || 'unknown')
		},
	},
}
</script>

<style scoped>
.usage-runs-on__table {
	width: 100%;
	border-collapse: collapse;
}

.usage-runs-on__table th,
.usage-runs-on__table td {
	padding: calc(var(--default-grid-baseline) * 2);
	border-bottom: 1px solid var(--color-border);
	text-align: start;
}

.usage-runs-on__through {
	margin-inline-end: calc(var(--default-grid-baseline) * 2);
	color: var(--color-text-maxcontrast);
}

.usage-runs-on__empty {
	color: var(--color-text-maxcontrast);
}

.usage-runs-on__state--ended {
	color: var(--color-error-text);
	font-weight: bold;
}

.usage-runs-on__state--ending {
	color: var(--color-warning-text);
	font-weight: bold;
}

.usage-runs-on__state--supported {
	color: var(--color-success-text);
}

.usage-runs-on__state--unknown {
	color: var(--color-text-maxcontrast);
}
</style>
