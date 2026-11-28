<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -
  - Webhooks admin section: the OpenRegister webhooks on the catalogue
  - register, each with its address, the catalogue events it sends and its
  - last delivery; add, edit and remove go through OpenRegister's webhooks
  - API (admin-only there). Webhooks on other registers are not listed.
  -
  - @spec openspec/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
  -->
<template>
	<AlwaysVisibleSection
		id="section-webhooks"
		:name="t('stackiq', 'Webhooks')"
		:description="t('stackiq', 'Notify another system when an application or a contract in the catalogue changes.')"
		:loading="loading"
		:loadingText="t('stackiq', 'Loading webhooks…')">
		<template #header-actions>
			<NcButton
				v-if="webhooks.length > 0"
				variant="primary"
				:disabled="!target"
				data-testid="webhook-add"
				@click="openEditor(null)">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('stackiq', 'Add webhook') }}
			</NcButton>
		</template>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<NcEmptyContent
			v-if="!error && webhooks.length === 0"
			:name="t('stackiq', 'No webhooks')"
			:description="t('stackiq', 'Add a webhook to tell another system when the catalogue changes.')">
			<template #icon>
				<Webhook :size="40" />
			</template>
			<template #action>
				<NcButton
					variant="primary"
					:disabled="!target"
					data-testid="webhook-add"
					@click="openEditor(null)">
					{{ t('stackiq', 'Add webhook') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<ul v-else-if="webhooks.length > 0" class="webhook-list">
			<li
				v-for="webhook in webhooks"
				:key="webhook.id"
				class="webhook-item"
				:data-testid="'webhook-' + webhook.id">
				<div class="webhook-info">
					<span class="webhook-url">{{ webhook.url }}</span>
					<span class="webhook-events help-text">{{ eventLabels(webhook) }}</span>
					<span class="webhook-delivery help-text">{{ deliveryText(webhook) }}</span>
				</div>
				<NcActions :aria-label="t('stackiq', 'Webhook actions')">
					<NcActionButton :data-testid="'webhook-edit-' + webhook.id" @click="openEditor(webhook)">
						<template #icon>
							<Pencil :size="20" />
						</template>
						{{ t('stackiq', 'Edit') }}
					</NcActionButton>
					<NcActionButton :data-testid="'webhook-remove-' + webhook.id" @click="remove(webhook)">
						<template #icon>
							<Delete :size="20" />
						</template>
						{{ t('stackiq', 'Remove') }}
					</NcActionButton>
				</NcActions>
			</li>
		</ul>

		<EditWebhookModal
			v-if="editing"
			:webhook="editing.webhook"
			:events="events"
			:saving="saving"
			@close="editing = null"
			@save="save" />
	</AlwaysVisibleSection>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcActionButton, NcActions, NcButton, NcEmptyContent, NcNoteCard } from '@nextcloud/vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Webhook from 'vue-material-design-icons/Webhook.vue'
import AlwaysVisibleSection from '../../../components/AlwaysVisibleSection.vue'
import EditWebhookModal from '../../../modals/webhook/EditWebhookModal.vue'
import {
	catalogueEvents,
	catalogueTarget,
	lastDelivery,
	listCatalogueWebhooks,
	removeWebhook,
	saveWebhook,
	webhookBody,
	webhookEvents,
} from '../../../utils/catalogueWebhooks.js'

export default {
	name: 'CatalogueWebhooks',
	components: {
		AlwaysVisibleSection,
		Delete,
		EditWebhookModal,
		NcActionButton,
		NcActions,
		NcButton,
		NcEmptyContent,
		NcNoteCard,
		Pencil,
		Plus,
		Webhook,
	},

	data() {
		return {
			loading: true,
			saving: false,
			error: '',
			target: null,
			webhooks: [],
			editing: null,
			events: catalogueEvents(),
		}
	},

	async mounted() {
		await this.load()
	},

	methods: {
		t,

		/**
		 * Read the catalogue register and its webhooks.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const { data } = await axios.get(generateUrl('/apps/stackiq/api/voorzieningen/config'))
				this.target = catalogueTarget(data?.config)
				if (!this.target) {
					this.error = t('stackiq', 'The catalogue register is not configured, so there are no webhooks to show.')
					return
				}
				this.webhooks = await listCatalogueWebhooks(this.target)
			} catch (error) {
				this.error = t('stackiq', 'Could not load the webhooks: {message}', { message: error.message })
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {object} webhook A catalogue webhook.
		 * @return {string} The catalogue events it sends, in words.
		 * @spec openspec/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
		 */
		eventLabels(webhook) {
			const ids = webhookEvents(webhook, this.target)
			return this.events.filter((event) => ids.includes(event.id)).map((event) => event.label).join(', ')
		},

		/**
		 * @param {object} webhook A catalogue webhook.
		 * @return {string} Its last delivery, in words.
		 * @spec openspec/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
		 */
		deliveryText(webhook) {
			const last = lastDelivery(webhook)
			if (!last) {
				return t('stackiq', 'Not delivered yet')
			}
			const at = new Date(last.at).toLocaleString()
			return last.ok
				? t('stackiq', 'Last delivered {date}', { date: at })
				: t('stackiq', 'Last delivery failed {date}', { date: at })
		},

		/**
		 * Open the editor for a webhook, or for a new one.
		 *
		 * @param {object|null} webhook The webhook, or null.
		 * @return {void}
		 * @spec openspec/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
		 */
		openEditor(webhook) {
			this.editing = {
				id: webhook ? webhook.id : null,
				webhook: webhook ? { ...webhook, eventIds: webhookEvents(webhook, this.target) } : null,
			}
		},

		/**
		 * Store what the editor emitted, then reload the list.
		 *
		 * @param {{name: string, url: string, events: string[]}} form The entered webhook.
		 * @return {Promise<void>}
		 * @spec openspec/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
		 */
		async save(form) {
			this.saving = true
			try {
				await saveWebhook(webhookBody(form, this.target), this.editing.id)
				showSuccess(t('stackiq', 'Webhook saved'))
				this.editing = null
				await this.load()
			} catch (error) {
				showError(t('stackiq', 'Could not save the webhook: {message}', { message: error.message }))
			} finally {
				this.saving = false
			}
		},

		/**
		 * Remove a webhook, then reload the list.
		 *
		 * @param {object} webhook The webhook.
		 * @return {Promise<void>}
		 * @spec openspec/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
		 */
		async remove(webhook) {
			try {
				await removeWebhook(webhook.id)
				showSuccess(t('stackiq', 'Webhook removed'))
				await this.load()
			} catch (error) {
				showError(t('stackiq', 'Could not remove the webhook: {message}', { message: error.message }))
			}
		},
	},
}
</script>

<style scoped>
.help-text {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.webhook-list {
	list-style: none;
	margin: 0;
	padding: 0;
	max-width: 640px;
}

.webhook-item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.webhook-info {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.webhook-url {
	font-weight: 600;
	word-break: break-all;
}
</style>
