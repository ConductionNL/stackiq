<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<template>
	<div class="sync-status">
		<h2 class="sync-status__title">
			{{ t('stackiq', 'Synchronisation') }}
		</h2>
		<p class="sync-status__intro">
			{{ t('stackiq', 'The organisation sync keeps organisations, contact persons and their accounts in step with the catalogue.') }}
		</p>

		<NcLoadingIcon
			v-if="loading"
			:size="32"
			:name="t('stackiq', 'Loading the synchronisation status')" />

		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<template v-else>
			<CnWidgetWrapper
				:title="t('stackiq', 'Schedule')"
				titleIconPosition="left"
				:showRefresh="false"
				:showRequestFeature="false">
				<dl class="sync-status__facts">
					<dt>{{ t('stackiq', 'Runs') }}</dt>
					<dd data-fact="interval">
						{{ intervalLabel }}
					</dd>
					<dt>{{ t('stackiq', 'Scheduled sync') }}</dt>
					<dd data-fact="enabled">
						{{ status.enabled ? t('stackiq', 'On') : t('stackiq', 'Off') }}
					</dd>
				</dl>
			</CnWidgetWrapper>

			<CnWidgetWrapper
				v-if="current"
				:title="t('stackiq', 'Running now')"
				titleIconPosition="left"
				:showRefresh="false"
				:showRequestFeature="false">
				<div class="sync-status__running" data-section="running">
					<CnProgressBar
						:showPercentage="true"
						:items="[{ key: 'sync', label: phaseLabel(current.phase), percentage: percentage, variant: 'primary' }]" />
					<p v-if="current.total_items" class="sync-status__items">
						{{ t('stackiq', '{processed} of {total} items', { processed: current.processed_items || 0, total: current.total_items }) }}
					</p>
				</div>
			</CnWidgetWrapper>

			<CnWidgetWrapper
				:title="t('stackiq', 'Last run')"
				titleIconPosition="left"
				:showRefresh="false"
				:showRequestFeature="false">
				<p v-if="!lastRun" class="sync-status__none" data-section="no-last-run">
					{{ t('stackiq', 'The sync has not run yet.') }}
				</p>
				<dl v-else class="sync-status__facts" data-section="last-run">
					<dt>{{ t('stackiq', 'Finished') }}</dt>
					<dd data-fact="finishedAt">
						{{ lastRun.finishedAt || '—' }}
					</dd>
					<template v-if="lastRun.startedAt">
						<dt>{{ t('stackiq', 'Started') }}</dt>
						<dd data-fact="startedAt">
							{{ lastRun.startedAt }}
						</dd>
					</template>
					<template v-if="lastRun.trigger">
						<dt>{{ t('stackiq', 'Started by') }}</dt>
						<dd data-fact="trigger">
							{{ triggerLabel(lastRun.trigger) }}
						</dd>
					</template>
					<template v-for="count in counts" :key="count.key">
						<dt>{{ count.label }}</dt>
						<dd :data-fact="count.key">
							{{ count.value }}
						</dd>
					</template>
					<template v-if="lastRun.errorCount !== undefined">
						<dt>{{ t('stackiq', 'Errors') }}</dt>
						<dd data-fact="errorCount" :class="{ 'sync-status__errors': lastRun.errorCount > 0 }">
							{{ lastRun.errorCount }}
						</dd>
					</template>
				</dl>
			</CnWidgetWrapper>
		</template>
	</div>
</template>

<script>
import { CnProgressBar, CnWidgetWrapper } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'

/**
 * How often the page asks again while a sync is running, in milliseconds.
 */
export const POLL_INTERVAL_MS = 3000

/**
 * @class SyncStatusView
 * @module Views/Sync
 * @copyright 2026 Conduction B.V.
 * @license EUPL-1.2
 *
 * The organisation sync for Nextcloud admins and functional administrators:
 * its interval and whether it is on, its last run, and while a run is going a
 * progress bar with its phase. It reads GET /api/sync/status, asks again every
 * three seconds while a run is going and stops when the run ends.
 *
 * @spec openspec/specs/sync-status-and-progress/spec.md#requirement-req-ssp-004-the-synchronisation-page-shall-show-the-schedule-the-last-run-and-a-running-sync-to-nextcloud-admins-and-functional-administrators
 */
export default {
	name: 'SyncStatusView',
	components: {
		CnProgressBar,
		CnWidgetWrapper,
		NcLoadingIcon,
		NcNoteCard,
	},

	data() {
		return {
			loading: true,
			error: '',
			status: { interval: 0, enabled: false, lastRun: null, current: null },
			timer: null,
		}
	},

	computed: {
		/**
		 * The running operation, or null.
		 *
		 * @return {object|null}
		 */
		current() {
			return this.status.current || null
		},

		/**
		 * The last run, or null when the sync never ran.
		 *
		 * @return {object|null}
		 */
		lastRun() {
			return this.status.lastRun || null
		},

		/**
		 * The running operation's percentage, kept within 0 to 100.
		 *
		 * @return {number}
		 */
		percentage() {
			const value = Number(this.current?.percentage ?? 0)
			return Math.min(100, Math.max(0, Number.isFinite(value) ? Math.round(value) : 0))
		},

		/**
		 * The interval as words.
		 *
		 * @return {string}
		 * @spec openspec/specs/sync-status-and-progress/spec.md#requirement-req-ssp-004-the-synchronisation-page-shall-show-the-schedule-the-last-run-and-a-running-sync-to-nextcloud-admins-and-functional-administrators
		 */
		intervalLabel() {
			const seconds = Number(this.status.interval) || 0
			if (seconds <= 0) {
				return t('stackiq', 'Not scheduled')
			}

			if (seconds === 3600) {
				return t('stackiq', 'Every hour')
			}

			if (seconds === 60) {
				return t('stackiq', 'Every minute')
			}

			if (seconds % 3600 === 0) {
				return t('stackiq', 'Every {hours} hours', { hours: seconds / 3600 })
			}

			if (seconds % 60 === 0) {
				return t('stackiq', 'Every {minutes} minutes', { minutes: seconds / 60 })
			}

			return t('stackiq', 'Every {seconds} seconds', { seconds })
		},

		/**
		 * The counts of the last run that it carries, labelled.
		 *
		 * @return {Array<{key: string, label: string, value: number}>}
		 */
		counts() {
			const labels = {
				organizationsProcessed: t('stackiq', 'Organisations processed'),
				entitiesCreated: t('stackiq', 'Entities created'),
				entitiesUpdated: t('stackiq', 'Entities updated'),
				contactPersonsProcessed: t('stackiq', 'Contact persons processed'),
				usersCreated: t('stackiq', 'Users created'),
			}
			const run = this.lastRun || {}
			return Object.keys(labels)
				.filter((key) => run[key] !== undefined && run[key] !== null)
				.map((key) => ({ key, label: labels[key], value: run[key] }))
		},
	},

	mounted() {
		this.load()
	},

	beforeUnmount() {
		this.stopPolling()
	},

	methods: {
		t,

		/**
		 * Read the status, and keep asking while a run is going.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/sync-status-and-progress/spec.md#requirement-req-ssp-004-the-synchronisation-page-shall-show-the-schedule-the-last-run-and-a-running-sync-to-nextcloud-admins-and-functional-administrators
		 */
		async load() {
			this.stopPolling()
			try {
				const { data } = await axios.get(generateUrl('/apps/stackiq/api/sync/status'))
				this.status = {
					interval: data?.interval ?? 0,
					enabled: data?.enabled === true,
					lastRun: data?.lastRun ?? null,
					current: data?.current ?? null,
				}
				this.error = ''
			} catch (e) {
				this.error = e?.response?.status === 403
					? t('stackiq', 'Only Nextcloud admins and functional administrators can see the synchronisation.')
					: t('stackiq', 'The synchronisation status could not be loaded.')
				this.status = { ...this.status, current: null }
			} finally {
				this.loading = false
			}

			if (this.status.current) {
				this.timer = setTimeout(() => this.load(), POLL_INTERVAL_MS)
			}
		},

		/**
		 * Stop asking again.
		 *
		 * @return {void}
		 */
		stopPolling() {
			if (this.timer !== null) {
				clearTimeout(this.timer)
				this.timer = null
			}
		},

		/**
		 * The label of a sync phase.
		 *
		 * @param {string} phase The phase key.
		 * @return {string} The translated phase.
		 */
		phaseLabel(phase) {
			const labels = {
				initializing: t('stackiq', 'Starting'),
				processing_organizations: t('stackiq', 'Processing organisations'),
				processing_contacts: t('stackiq', 'Processing contact persons'),
				processing_users: t('stackiq', 'Processing users'),
				finalizing: t('stackiq', 'Finishing'),
				completed: t('stackiq', 'Completed'),
			}
			return labels[phase] || t('stackiq', 'Running')
		},

		/**
		 * The label of what started a run.
		 *
		 * @param {string} trigger 'scheduled' or 'manual'.
		 * @return {string} The translated trigger.
		 */
		triggerLabel(trigger) {
			if (trigger === 'manual') {
				return t('stackiq', 'Started by hand')
			}

			if (trigger === 'scheduled') {
				return t('stackiq', 'Schedule')
			}

			return String(trigger)
		},
	},
}
</script>

<style scoped>
.sync-status {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 16px;
	max-width: 900px;
}

.sync-status__intro,
.sync-status__none,
.sync-status__items {
	color: var(--color-text-maxcontrast);
}

.sync-status__facts {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 16px;
	margin: 0;
}

.sync-status__facts dt {
	/* A global rule fixes dt at 130px with nowrap, so a longer label such as
	   "Organisations processed" ran into its value; the column takes the label's width. */
	width: auto;
	font-weight: bold;
	color: var(--color-main-text);
}

.sync-status__facts dd {
	margin: 0;
}

.sync-status__errors {
	color: var(--color-error-text);
	font-weight: bold;
}
</style>
