<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -
  - PublicationAction — the publication state badge and the Publish / Withdraw
  - actions of a catalogue entry's page (REQ-ODP-011, board SkApplicatie).
  - Mounted as the detail page's `actionsComponent`: CnDetailPage hands it the
  - record and whether the page lets the user edit it.
  -
  - Publish (now or on a date) is offered while the entry is not published,
  - Withdraw while it is published or scheduled. Both call the guarded
  - publication endpoint; a refusal is shown and the state does not change.
  -->
<template>
	<div v-if="publicationType" class="publication-action" data-testid="publication-action">
		<span
			class="publication-action__badge"
			:class="`publication-action__badge--${state}`"
			data-testid="publication-badge">
			{{ badgeLabel }}
		</span>
		<NcActions
			v-if="mayPublish"
			:aria-label="t('stackiq', 'Publication')"
			:disabled="busy">
			<template #icon>
				<NcLoadingIcon v-if="busy" :size="20" />
				<Publish v-else :size="20" />
			</template>
			<NcActionButton
				v-if="state === 'unpublished'"
				data-testid="publication-publish-now"
				@click="publish(null)">
				<template #icon>
					<Publish :size="20" />
				</template>
				{{ t('stackiq', 'Publish now') }}
			</NcActionButton>
			<NcActionButton
				v-if="state === 'unpublished'"
				data-testid="publication-publish-date"
				@click="dateOpen = true">
				<template #icon>
					<CalendarClock :size="20" />
				</template>
				{{ t('stackiq', 'Publish on a date') }}
			</NcActionButton>
			<NcActionButton
				v-if="state !== 'unpublished'"
				data-testid="publication-withdraw"
				@click="withdraw">
				<template #icon>
					<PublishOff :size="20" />
				</template>
				{{ t('stackiq', 'Withdraw') }}
			</NcActionButton>
		</NcActions>
		<p
			v-if="error"
			class="publication-action__error"
			role="alert"
			data-testid="publication-error">
			{{ error }}
		</p>
		<PublishDateModal
			v-if="dateOpen"
			:open="dateOpen"
			@close="dateOpen = false"
			@submit="onDateSubmit" />
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import {
	NcActionButton,
	NcActions,
	NcLoadingIcon,
} from '@nextcloud/vue'
import CalendarClock from 'vue-material-design-icons/CalendarClock.vue'
import Publish from 'vue-material-design-icons/Publish.vue'
import PublishOff from 'vue-material-design-icons/PublishOff.vue'
import PublishDateModal from '../../modals/publication/PublishDateModal.vue'
import {
	entryId,
	isPublishableType,
	publicationState,
	publishEntry,
	refusalMessage,
	withdrawEntry,
} from '../../services/publicationActions.js'

export default {
	name: 'PublicationAction',
	components: {
		CalendarClock,
		NcActionButton,
		NcActions,
		NcLoadingIcon,
		Publish,
		PublishDateModal,
		PublishOff,
	},

	// CnDetailPage passes its whole actions-slot scope; only what is
	// declared below is used, the rest must not land on the root element.
	inheritAttrs: false,
	props: {
		/** The record on the page. */
		object: {
			type: Object,
			default: null,
		},

		/** The record's schema (its `slug` is the catalogue object type). */
		schema: {
			type: Object,
			default: null,
		},

		/** CnDetailPage's object type, `<register>-<schema>`. */
		objectType: {
			type: String,
			default: '',
		},

		/** Whether the page lets the user edit the record. */
		canEditRecord: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			busy: false,
			error: '',
			dateOpen: false,
			// The dates after a publish or withdraw on this page, until the
			// page reloads the record.
			override: null,
		}
	},

	computed: {
		/**
		 * The catalogue object type the publication endpoint knows this
		 * record by, or '' when it is not publishable.
		 *
		 * @return {string}
		 * @spec openspec/changes/publish-and-lock-from-the-list/specs/open-data-publishing/spec.md#requirement-an-entrys-page-publishes-or-withdraws-that-entry-req-odp-011
		 */
		publicationType() {
			const slug = this.schema && typeof this.schema.slug === 'string' ? this.schema.slug : ''
			const fromType = String(this.objectType || '').split('-').pop()
			const type = isPublishableType(slug) ? slug : fromType
			return isPublishableType(type) && entryId(this.object) !== '' ? type : ''
		},

		/**
		 * The record's dates, with this page's own publish or withdraw applied.
		 *
		 * @return {object}
		 * @spec openspec/changes/publish-and-lock-from-the-list/specs/open-data-publishing/spec.md#requirement-an-entrys-page-publishes-or-withdraws-that-entry-req-odp-011
		 */
		dates() {
			return { ...(this.object || {}), ...(this.override || {}) }
		},

		/** @spec openspec/changes/publish-and-lock-from-the-list/specs/open-data-publishing/spec.md#requirement-an-entrys-page-publishes-or-withdraws-that-entry-req-odp-011 */
		state() {
			return publicationState(this.dates)
		},

		/** @spec openspec/changes/publish-and-lock-from-the-list/specs/open-data-publishing/spec.md#requirement-an-entrys-page-publishes-or-withdraws-that-entry-req-odp-011 */
		badgeLabel() {
			if (this.state === 'published') {
				return t('stackiq', 'Published')
			}
			if (this.state === 'scheduled') {
				return t('stackiq', 'Scheduled')
			}
			return t('stackiq', 'Not published')
		},

		/**
		 * Whether to offer Publish and Withdraw: the page lets the user edit
		 * the record and it is not a read-only copy from another instance.
		 * The endpoint's ownership guard still decides each call.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/publish-and-lock-from-the-list/specs/open-data-publishing/spec.md#requirement-an-entrys-page-publishes-or-withdraws-that-entry-req-odp-011
		 */
		mayPublish() {
			const source = this.object && this.object._source
			const peer = Boolean(source && typeof source === 'object' && String(source.instance || '').trim() !== '')
			return this.canEditRecord && !peer
		},
	},

	methods: {
		t,

		/**
		 * Publish the record now (`when` null) or at `when`.
		 *
		 * @param {?string} when An ISO-8601 moment, or null for now.
		 * @return {Promise<void>}
		 * @spec openspec/changes/publish-and-lock-from-the-list/specs/open-data-publishing/spec.md#requirement-an-entrys-page-publishes-or-withdraws-that-entry-req-odp-011
		 */
		async publish(when) {
			await this.run(async () => {
				const result = await publishEntry(this.publicationType, entryId(this.object), { when })
				this.override = {
					publicationDate: result.publicationDate || when || new Date().toISOString(),
					depublicationDate: null,
				}
				showSuccess(t('stackiq', 'Entry published'))
			})
		},

		/**
		 * Withdraw the record.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/publish-and-lock-from-the-list/specs/open-data-publishing/spec.md#requirement-an-entrys-page-publishes-or-withdraws-that-entry-req-odp-011
		 */
		async withdraw() {
			await this.run(async () => {
				await withdrawEntry(this.publicationType, entryId(this.object))
				this.override = {
					publicationDate: null,
					depublicationDate: new Date().toISOString(),
				}
				showSuccess(t('stackiq', 'Entry withdrawn'))
			})
		},

		/**
		 * Publish at the moment the date dialog returned.
		 *
		 * @param {string} when An ISO-8601 moment.
		 * @return {Promise<void>}
		 * @spec openspec/changes/publish-and-lock-from-the-list/specs/open-data-publishing/spec.md#requirement-an-entrys-page-publishes-or-withdraws-that-entry-req-odp-011
		 */
		async onDateSubmit(when) {
			this.dateOpen = false
			await this.publish(when)
		},

		/**
		 * Run one endpoint call: busy while it runs, and on a refusal the
		 * endpoint's message is shown and the state stays as it was.
		 *
		 * @param {Function} call The call.
		 * @return {Promise<void>}
		 * @spec openspec/changes/publish-and-lock-from-the-list/specs/open-data-publishing/spec.md#requirement-an-entrys-page-publishes-or-withdraws-that-entry-req-odp-011
		 */
		async run(call) {
			this.busy = true
			this.error = ''
			try {
				await call()
			} catch (e) {
				this.error = refusalMessage(e)
				showError(this.error)
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.publication-action {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.publication-action__badge {
	padding: 2px 10px;
	border-radius: var(--border-radius-pill);
	font-size: 0.9em;
	background-color: var(--color-background-dark);
	color: var(--color-main-text);
}

.publication-action__badge--published {
	background-color: var(--color-success);
	color: var(--color-primary-element-text);
}

.publication-action__badge--scheduled {
	background-color: var(--color-warning);
	color: var(--color-main-text);
}

.publication-action__error {
	flex-basis: 100%;
	margin: 0;
	color: var(--color-error-text, var(--color-error));
}
</style>
