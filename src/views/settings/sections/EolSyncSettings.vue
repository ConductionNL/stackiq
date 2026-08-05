<!--
 - @copyright Copyright (c) 2026 Conduction B.V. <info@conduction.nl>
 - @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 -
 - EOL feed sync admin section: configure the register/schema names the
 - matcher reads `eolProduct`/`eolCycle` from (provisioned by the sibling
 - openconnector `endoflife-date-source` change), enable/disable the feature,
 - and trigger a manual "sync now" run. Rendered inside the admin settings
 - panel — admin-gated by the IDelegatedSettings framework and by the default
 - admin-required posture of every SettingsController method (no
 - `@NoAdminRequired`); NOT registered in the in-app router. Shows
 - "unavailable" as a status, never an error, when the feed cannot be
 - resolved (openconnector not installed, disabled, or misconfigured) — per
 - the graceful-degradation requirement, manual `datumEindeOndersteuning`
 - entry keeps working regardless of what this panel shows.
 -->

<template>
	<AlwaysVisibleSection
		:name="t('softwarecatalog', 'End-of-life feed sync')"
		:description="t('softwarecatalog', 'Match catalog products to endoflife.date product cycles ingested via OpenConnector, to keep end-of-support dates data-driven. Softwarecatalog never calls endoflife.date directly.')"
		:loading="loading"
		:loading-text="t('softwarecatalog', 'Loading EOL sync configuration…')"
		:show-save-button="true"
		:can-save="!saving"
		:saving="saving"
		:save-button-text="t('softwarecatalog', 'Save EOL sync settings')"
		:show-refresh-button="true"
		:refreshing="loading"
		:refresh-button-text="t('softwarecatalog', 'Refresh status')"
		@save="saveConfig"
		@refresh="loadAll">
		<template #header-actions>
			<NcButton
				variant="primary"
				:disabled="syncing"
				@click="triggerSync">
				<template #icon>
					<NcLoadingIcon v-if="syncing" :size="20" />
					<Sync v-else :size="20" />
				</template>
				{{ t('softwarecatalog', 'Sync now') }}
			</NcButton>
		</template>

		<!-- Status banner. -->
		<NcNoteCard v-if="status.available" type="success">
			{{ t('softwarecatalog', 'Last run: {matched} matched, {skipped} skipped, at {time}.', { matched: status.matched, skipped: status.skipped, time: formattedLastRunAt }) }}
		</NcNoteCard>
		<NcNoteCard v-else type="warning">
			{{ t('softwarecatalog', 'Feed unavailable: {reason}. Manual end-of-support entry, the EOL-approaching filter, the roadmap, and the notification rule keep working regardless.', { reason: unavailableReasonLabel }) }}
		</NcNoteCard>

		<div class="eol-sync-settings">
			<div class="setting-group">
				<NcCheckboxRadioSwitch
					v-model="config.enabled"
					type="switch">
					{{ t('softwarecatalog', 'Enable EOL feed sync') }}
				</NcCheckboxRadioSwitch>
				<p class="help-text">
					{{ t('softwarecatalog', 'When disabled, the matcher never reads or writes anything — the same as the feed being unavailable.') }}
				</p>
			</div>

			<div class="setting-group">
				<h4>{{ t('softwarecatalog', 'Source register and schemas') }}</h4>
				<p class="help-text">
					{{ t('softwarecatalog', 'Pre-filled with the names the openconnector endoflife-date-source change provisions. Change them if your instance uses different names — no code change required.') }}
				</p>
				<NcTextField
					v-model="config.register"
					:label="t('softwarecatalog', 'Register slug')"
					:disabled="!config.enabled" />
				<NcTextField
					v-model="config.productSchema"
					:label="t('softwarecatalog', 'eolProduct schema slug')"
					:disabled="!config.enabled" />
				<NcTextField
					v-model="config.cycleSchema"
					:label="t('softwarecatalog', 'eolCycle schema slug')"
					:disabled="!config.enabled" />
			</div>

			<div class="setting-group">
				<h4>{{ t('softwarecatalog', 'Schedule') }}</h4>
				<NcTextField
					v-model="intervalMinutesInput"
					type="number"
					:label="t('softwarecatalog', 'Sync interval (minutes)')"
					:disabled="!config.enabled" />
				<p class="help-text">
					{{ t('softwarecatalog', 'The scheduled background job re-runs the matcher at this interval; the minimum enforced interval is 5 minutes.') }}
				</p>
			</div>
		</div>
	</AlwaysVisibleSection>
</template>

<script>
import { defineComponent } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcNoteCard,
	NcTextField,
} from '@nextcloud/vue'
import Sync from 'vue-material-design-icons/Sync.vue'
import AlwaysVisibleSection from '../../../components/AlwaysVisibleSection.vue'
import { apiRequest } from '../../../utils/adminApi.js'

/**
 * Reasons emitted by `EolSyncService::degrade()`, mapped to a translated
 * label. Falls back to the raw reason code for a reason not in this map
 * (forward-compatible with a future degrade path).
 *
 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-the-feature-degrades-gracefully-when-the-feed-is-unavailable
 */
const REASON_LABELS = {
	disabled: () => t('softwarecatalog', 'EOL feed sync is disabled'),
	'not-yet-run': () => t('softwarecatalog', 'not yet run'),
	'openregister-not-installed': () => t('softwarecatalog', 'OpenRegister is not installed'),
	'object-service-unavailable': () => t('softwarecatalog', 'OpenRegister is not currently reachable'),
	'module-schema-not-configured': () => t('softwarecatalog', 'the module/moduleVersie schema is not configured yet'),
	'eol-register-or-schema-not-found': () => t('softwarecatalog', 'the configured register or schema could not be found — is the openconnector endoflife-date-source change installed?'),
}

/**
 * EOL feed sync settings admin section.
 *
 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-eol-sync-runs-on-a-schedule-with-a-manual-trigger
 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-the-feature-degrades-gracefully-when-the-feed-is-unavailable
 */
export default defineComponent({
	name: 'EolSyncSettings',
	components: {
		AlwaysVisibleSection,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
		Sync,
	},

	/**
	 * @return {object} Component data.
	 */
	data() {
		return {
			loading: false,
			saving: false,
			syncing: false,
			config: {
				enabled: false,
				register: '',
				productSchema: '',
				cycleSchema: '',
				intervalSeconds: 86400,
			},
			status: {
				available: false,
				reason: 'not-yet-run',
				matched: 0,
				skipped: 0,
				lastRunAt: null,
			},
		}
	},

	computed: {
		/**
		 * The interval, presented/edited in whole minutes rather than raw
		 * seconds — friendlier admin input.
		 *
		 * @return {string} The interval in minutes, as a string for NcTextField.
		 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-eol-sync-runs-on-a-schedule-with-a-manual-trigger
		 */
		intervalMinutesInput: {
			get() {
				return String(Math.max(1, Math.round((this.config.intervalSeconds || 86400) / 60)))
			},
			set(value) {
				const minutes = parseInt(value, 10)
				if (Number.isFinite(minutes) && minutes > 0) {
					this.config.intervalSeconds = minutes * 60
				}
			},
		},

		/**
		 * A human-readable last-run timestamp, or a placeholder when the
		 * feature has never run.
		 *
		 * @return {string} The formatted timestamp.
		 */
		formattedLastRunAt() {
			if (!this.status.lastRunAt) {
				return t('softwarecatalog', 'never')
			}
			try {
				return new Date(this.status.lastRunAt).toLocaleString()
			} catch (e) {
				return this.status.lastRunAt
			}
		},

		/**
		 * The translated label for the current unavailability reason
		 * (design.md Decision 6 — distinguishes "not configured" from
		 * "configured but nothing matched yet").
		 *
		 * @return {string} The translated reason label.
		 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-the-feature-degrades-gracefully-when-the-feed-is-unavailable
		 */
		unavailableReasonLabel() {
			const reason = this.status.reason || 'not-yet-run'
			const labelFn = REASON_LABELS[reason]
			return labelFn ? labelFn() : reason
		},
	},

	/**
	 * Load the config and status on mount.
	 *
	 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-products-are-mapped-to-endoflife-date-via-per-module-config
	 */
	async created() {
		await this.loadAll()
	},

	methods: {
		t,

		/**
		 * Load both the configuration and the current status.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-products-are-mapped-to-endoflife-date-via-per-module-config
		 */
		async loadAll() {
			this.loading = true
			try {
				await Promise.all([this.loadConfig(), this.loadStatus()])
			} finally {
				this.loading = false
			}
		},

		/**
		 * Load the EOL sync configuration from the admin endpoint.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-products-are-mapped-to-endoflife-date-via-per-module-config
		 */
		async loadConfig() {
			try {
				const data = await apiRequest('eol-sync/config')
				if (data && data.config) {
					this.config = { ...this.config, ...data.config }
				}
			} catch (error) {
				showError(t('softwarecatalog', 'Could not load EOL sync configuration') + ': ' + error.message)
			}
		},

		/**
		 * Load the last-recorded EOL sync status.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-the-feature-degrades-gracefully-when-the-feed-is-unavailable
		 */
		async loadStatus() {
			try {
				const data = await apiRequest('eol-sync/status')
				if (data && data.status) {
					this.status = { ...this.status, ...data.status }
				}
			} catch (error) {
				showError(t('softwarecatalog', 'Could not load EOL sync status') + ': ' + error.message)
			}
		},

		/**
		 * Persist the edited configuration.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-products-are-mapped-to-endoflife-date-via-per-module-config
		 */
		async saveConfig() {
			this.saving = true
			try {
				const data = await apiRequest('eol-sync/config', { method: 'POST', body: this.config })
				if (data && data.config) {
					this.config = { ...this.config, ...data.config }
				}
				showSuccess(t('softwarecatalog', 'EOL sync settings saved'))
			} catch (error) {
				showError(t('softwarecatalog', 'Could not save EOL sync settings') + ': ' + error.message)
			} finally {
				this.saving = false
			}
		},

		/**
		 * Trigger a manual EOL sync run — invokes the exact same
		 * orchestration logic as the scheduled background job.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/eol-feed-integration/spec.md#requirement-eol-sync-runs-on-a-schedule-with-a-manual-trigger
		 */
		async triggerSync() {
			this.syncing = true
			try {
				const data = await apiRequest('eol-sync/trigger', { method: 'POST' })
				if (data && data.status) {
					this.status = { ...this.status, ...data.status }
				}
				if (this.status.available) {
					showSuccess(t('softwarecatalog', 'EOL sync completed: {matched} matched, {skipped} skipped.', { matched: this.status.matched, skipped: this.status.skipped }))
				} else {
					showError(t('softwarecatalog', 'EOL sync did not run: {reason}', { reason: this.unavailableReasonLabel }))
				}
			} catch (error) {
				showError(t('softwarecatalog', 'EOL sync failed') + ': ' + error.message)
			} finally {
				this.syncing = false
			}
		},
	},
})
</script>

<style scoped>
.help-text {
	font-size: 12px;
	color: var(--color-text-lighter);
	margin: 0 0 0.5rem;
}

.eol-sync-settings {
	max-width: 640px;
}

.setting-group {
	margin-bottom: 1.5rem;
}

.setting-group h4 {
	margin-bottom: 0.25rem;
}
</style>
