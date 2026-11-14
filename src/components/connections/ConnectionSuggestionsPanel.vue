<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<template>
	<CnWidgetWrapper
		:title="t('stackiq', 'Suggested connections')"
		titleIconPosition="left"
		:showRefresh="false"
		:showRequestFeature="false">
		<template #title-icon>
			<CnIcon name="LinkVariant" :size="20" />
		</template>
		<div class="connection-suggestions">
			<NcLoadingIcon
				v-if="loading"
				:size="32"
				:name="t('stackiq', 'Loading suggested connections')" />

			<template v-else>
				<NcNoteCard v-if="error" type="error">
					{{ error }}
				</NcNoteCard>

				<p v-else-if="suggestions.length === 0" class="connection-suggestions__empty">
					{{ t('stackiq', 'No suggestions. Every known connection of this application is already in your usage.') }}
				</p>

				<template v-else>
					<ul class="connection-suggestions__list">
						<li
							v-for="suggestion in suggestions"
							:key="suggestion.id"
							class="connection-suggestions__item"
							:data-suggestion="suggestion.id">
							<div class="connection-suggestions__text">
								<span class="connection-suggestions__name">{{ suggestion.name || t('stackiq', 'Unnamed connection') }}</span>
								<span class="connection-suggestions__meta">{{ describe(suggestion) }}</span>
								<span class="connection-suggestions__reason">{{ reasonLabel(suggestion) }}</span>
							</div>
							<div class="connection-suggestions__actions">
								<NcButton
									variant="primary"
									:disabled="busy"
									:aria-label="t('stackiq', 'Accept {name}', { name: suggestion.name })"
									@click="accept([suggestion.id])">
									{{ t('stackiq', 'Accept') }}
								</NcButton>
								<NcButton
									variant="tertiary"
									:disabled="busy"
									:aria-label="t('stackiq', 'Dismiss {name}', { name: suggestion.name })"
									@click="dismiss([suggestion.id])">
									{{ t('stackiq', 'Dismiss') }}
								</NcButton>
							</div>
						</li>
					</ul>
					<div v-if="suggestions.length > 1" class="connection-suggestions__all">
						<NcButton
							variant="secondary"
							:disabled="busy"
							@click="accept(suggestions.map((suggestion) => suggestion.id))">
							{{ t('stackiq', 'Accept all') }}
						</NcButton>
					</div>
				</template>
			</template>
		</div>
	</CnWidgetWrapper>
</template>

<script>
import { CnIcon, CnWidgetWrapper } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'

/**
 * @class ConnectionSuggestionsPanel
 * @module Components/Connections
 * @copyright 2026 Conduction B.V.
 * @license EUPL-1.2
 *
 * The connections stackiq suggests for one usage, on the usage detail page:
 * connections its application shares with another application in the
 * organisation's landscape, and the connections of an earlier version or a
 * replaced application carried over. Accept adds a suggestion to the usage's
 * connections, Dismiss hides it for good; the server checks that the caller's
 * organisation owns the usage on every request.
 *
 * @spec openspec/changes/connections-derived-dependencies/specs/derived-connections/spec.md
 */
export default {
	name: 'ConnectionSuggestionsPanel',
	components: {
		CnIcon,
		CnWidgetWrapper,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		/**
		 * The usage uuid (passed by the detail page as `objectId`).
		 *
		 * @type {string}
		 */
		objectId: {
			type: [String, Number],
			default: '',
		},
	},

	emits: ['accepted'],

	data() {
		return {
			loading: true,
			busy: false,
			suggestions: [],
			error: '',
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
		 * The URL of a suggestion endpoint for this usage.
		 *
		 * @param {string} action '' for the listing, else 'accept' or 'dismiss'.
		 * @return {string} The URL.
		 * @spec openspec/changes/connections-derived-dependencies/specs/derived-connections/spec.md
		 */
		url(action = '') {
			const path = '/apps/stackiq/api/usages/{uuid}/connection-suggestions' + (action ? '/' + action : '')
			return generateUrl(path, { uuid: String(this.objectId) })
		},

		/**
		 * Read the suggestions for this usage.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/connections-derived-dependencies/specs/derived-connections/spec.md
		 */
		async load() {
			if (!this.objectId) {
				this.loading = false
				return
			}

			this.loading = true
			this.error = ''
			try {
				const { data } = await axios.get(this.url())
				this.suggestions = Array.isArray(data?.results) ? data.results : []
			} catch (e) {
				this.suggestions = []
				this.error = e?.response?.status === 403
					? t('stackiq', 'Only the organisation that uses this application can see its suggested connections.')
					: t('stackiq', 'The suggested connections could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Accept suggestions: they are added to the usage's connections.
		 *
		 * @param {string[]} ids The suggestion ids.
		 * @return {Promise<void>}
		 * @spec openspec/changes/connections-derived-dependencies/specs/derived-connections/spec.md
		 */
		async accept(ids) {
			const done = await this.post('accept', ids, 'accepted')
			if (done !== null) {
				showSuccess(t('stackiq', 'Connections added: {count}', { count: done.length }))
				this.$emit('accepted', done)
			}
		},

		/**
		 * Dismiss suggestions: they stay hidden for this usage.
		 *
		 * @param {string[]} ids The suggestion ids.
		 * @return {Promise<void>}
		 * @spec openspec/changes/connections-derived-dependencies/specs/derived-connections/spec.md
		 */
		async dismiss(ids) {
			await this.post('dismiss', ids, 'dismissed')
		},

		/**
		 * Post ids to an action and take the handled ones out of the panel.
		 *
		 * @param {string} action 'accept' or 'dismiss'.
		 * @param {string[]} ids The suggestion ids.
		 * @param {string} key The reply key that lists what was handled.
		 * @return {Promise<Array|null>} What the server handled, or null on failure.
		 */
		async post(action, ids, key) {
			this.busy = true
			try {
				const { data } = await axios.post(this.url(action), { ids })
				const handled = Array.isArray(data?.[key]) ? data[key] : []
				const gone = new Set(ids.map(String))
				this.suggestions = this.suggestions.filter((suggestion) => !gone.has(String(suggestion.id)))
				return handled
			} catch (e) {
				showError(e?.response?.status === 403
					? t('stackiq', 'Only the organisation that uses this application can change its connections.')
					: t('stackiq', 'The suggestion could not be saved.'))
				return null
			} finally {
				this.busy = false
			}
		},

		/**
		 * The type and direction of a suggestion, as one line.
		 *
		 * @param {object} suggestion The suggestion.
		 * @return {string} The type and direction, or ''.
		 */
		describe(suggestion) {
			return [suggestion.type, suggestion.direction]
				.filter((part) => typeof part === 'string' && part !== '')
				.join(' · ')
		},

		/**
		 * Why a suggestion is offered.
		 *
		 * @param {object} suggestion The suggestion.
		 * @return {string} The translated reason.
		 * @spec openspec/changes/connections-derived-dependencies/specs/derived-connections/spec.md
		 */
		reasonLabel(suggestion) {
			if (suggestion.reason === 'carry-over') {
				return suggestion.draft
					? t('stackiq', 'Draft from the application this one replaces')
					: t('stackiq', 'Carried over from another version')
			}

			return t('stackiq', 'Both applications are in your landscape')
		},
	},
}
</script>

<style scoped>
.connection-suggestions__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.connection-suggestions__item {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.connection-suggestions__text {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.connection-suggestions__name {
	font-weight: bold;
	color: var(--color-main-text);
}

.connection-suggestions__meta,
.connection-suggestions__reason,
.connection-suggestions__empty {
	color: var(--color-text-maxcontrast);
}

.connection-suggestions__actions,
.connection-suggestions__all {
	display: flex;
	gap: 8px;
}

.connection-suggestions__all {
	margin-top: 8px;
	justify-content: flex-end;
}
</style>
