<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<template>
	<div class="upcoming-maintenance" data-testid="upcoming-maintenance">
		<NcLoadingIcon
			v-if="loading"
			:size="32"
			:name="t('stackiq', 'Loading planned maintenance')" />
		<NcNoteCard v-else-if="error" type="error">
			{{ t('stackiq', 'The planned maintenance could not be loaded.') }}
		</NcNoteCard>
		<p v-else-if="windows.length === 0" class="upcoming-maintenance__empty">
			{{
				t(
					'stackiq',
					'No maintenance planned on the applications you use in the next 30 days',
				)
			}}
		</p>
		<ul v-else class="upcoming-maintenance__list">
			<li
				v-for="window in windows"
				:key="rowId(window)"
				class="upcoming-maintenance__item"
				data-testid="upcoming-maintenance-item">
				<router-link
					v-if="moduleId(window)"
					:to="{ name: 'ModuleDetail', params: { id: moduleId(window) } }"
					class="upcoming-maintenance__module">
					{{ moduleName(window) }}
				</router-link>
				<span class="upcoming-maintenance__title">{{ window.title }}</span>
				<span class="upcoming-maintenance__when">{{ period(window) }}</span>
				<span class="upcoming-maintenance__impact">{{
					impactLabel(window.impact)
				}}</span>
			</li>
		</ul>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { objectStore } from '../../store/store.js'
import { loadUpcomingMaintenance, refId } from '../../utils/maintenance.js'

export default {
	name: 'UpcomingMaintenanceWidget',

	components: {
		NcLoadingIcon,
		NcNoteCard,
	},

	data() {
		return {
			loading: true,
			error: false,
			windows: [],
		}
	},

	/**
	 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-002-organisations-that-use-a-product-see-its-planned-maintenance
	 */
	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * @param {object} row A row.
		 * @return {string|null} Its id.
		 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-002-organisations-that-use-a-product-see-its-planned-maintenance
		 */
		rowId(row) {
			return refId(row)
		},

		/**
		 * @param {object} window A maintenance window.
		 * @return {string|null} The id of its product.
		 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-002-organisations-that-use-a-product-see-its-planned-maintenance
		 */
		moduleId(window) {
			return refId(window.module)
		},

		/**
		 * @param {object} window A maintenance window.
		 * @return {string} The name of its product.
		 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-002-organisations-that-use-a-product-see-its-planned-maintenance
		 */
		moduleName(window) {
			const module = window.module
			return (
				(typeof module === 'object'
					&& (module?.name || module?.['@self']?.name))
				|| t('stackiq', 'Application')
			)
		},

		/**
		 * @param {object} window A maintenance window.
		 * @return {string} Its start and end, in the user's locale.
		 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-002-organisations-that-use-a-product-see-its-planned-maintenance
		 */
		period(window) {
			const format = (value) =>
				value
					? new Date(value).toLocaleString([], {
							dateStyle: 'medium',
							timeStyle: 'short',
						})
					: ''
			return t('stackiq', '{start} to {end}', {
				start: format(window.startsAt),
				end: format(window.endsAt),
			})
		},

		/**
		 * @param {string} impact The impact value.
		 * @return {string} Its label.
		 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-002-organisations-that-use-a-product-see-its-planned-maintenance
		 */
		impactLabel(impact) {
			return (
				{
					'no impact': t('stackiq', 'No impact'),
					degraded: t('stackiq', 'Degraded'),
					unavailable: t('stackiq', 'Unavailable'),
				}[impact] || ''
			)
		},

		/**
		 * Load the windows for the active organisation.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-002-organisations-that-use-a-product-see-its-planned-maintenance
		 */
		async load() {
			this.loading = true
			this.error = false
			try {
				const organisationId = await this.activeOrganisationId()
				this.windows = await loadUpcomingMaintenance(
					organisationId,
					async (type, params) => {
						this.ensureType(type)
						return objectStore.fetchCollectionForOptions(type, params)
					},
				)
			} catch {
				this.error = true
				this.windows = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * The active organisation, from the app's own /api/me.
		 *
		 * @return {Promise<string|null>} Its id.
		 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-002-organisations-that-use-a-product-see-its-planned-maintenance
		 */
		async activeOrganisationId() {
			const response = await fetch(generateUrl('/apps/stackiq/api/me'), {
				headers: { requesttoken: OC.requestToken },
			})
			if (!response.ok) {
				return null
			}
			const data = await response.json()
			return data?.organisations?.active?.uuid ?? null
		},

		/**
		 * Register an object type with the store when it is not known yet.
		 *
		 * @param {string} type The schema slug.
		 * @return {void}
		 * @spec openspec/specs/maintenance-and-supplier-roadmap/spec.md#requirement-req-msr-002-organisations-that-use-a-product-see-its-planned-maintenance
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
.upcoming-maintenance__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.upcoming-maintenance__item {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: calc(var(--default-grid-baseline) * 1);
	padding: calc(var(--default-grid-baseline) * 2) 0;
	border-bottom: 1px solid var(--color-border);
}

.upcoming-maintenance__module,
.upcoming-maintenance__title {
	font-weight: bold;
}

.upcoming-maintenance__when,
.upcoming-maintenance__impact,
.upcoming-maintenance__empty {
	color: var(--color-text-maxcontrast);
}
</style>
