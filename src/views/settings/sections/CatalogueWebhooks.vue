<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<!--
 Webhooks section of the exchange settings (platform-properties, REQ-PLAT-005):
 the OpenRegister webhooks on the catalogue register, with address, events and
 last delivery, and add, edit and remove. OpenRegister delivers, retries and
 signs; stackiq lists and writes only webhooks filtered to its own register.
 OpenRegister's webhooks API answers administrators only.
-->

<template>
	<AlwaysVisibleSection
		id="section-webhooks"
		:name="t('stackiq', 'Webhooks')"
		:description="
			t(
				'stackiq',
				'Notify another system when an application, contract or application in use is created, changed or deleted.',
			)
		"
		:loading="loading"
		:loadingText="t('stackiq', 'Loading the webhooks…')">
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<NcNoteCard v-else-if="!scope.register" type="warning">
			{{ t('stackiq', 'The catalogue register and its schemas are not configured yet.') }}
		</NcNoteCard>

		<NcEmptyContent
			v-if="!loading && !error && !webhooks.length"
			:name="t('stackiq', 'No webhooks')"
			:description="t('stackiq', 'No other system is notified of catalogue changes yet.')"
			data-testid="webhooks-empty">
			<template #action>
				<NcButton variant="primary" :disabled="!scope.register" @click="openModal(null)">
					{{ t('stackiq', 'Add webhook') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<template v-else-if="webhooks.length">
			<table class="webhooks-table" data-testid="webhooks-table">
				<thead>
					<tr>
						<th>{{ t('stackiq', 'Address') }}</th>
						<th>{{ t('stackiq', 'Events') }}</th>
						<th>{{ t('stackiq', 'Last delivery') }}</th>
						<th>
							<span class="hidden-visually">{{ t('stackiq', 'Actions') }}</span>
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="webhook in webhooks" :key="webhook.id" :data-testid="'webhook-' + webhook.id">
						<td>
							<strong>{{ webhook.name || webhook.url }}</strong>
							<div class="webhooks-table__url">
								{{ webhook.url }}
							</div>
							<span v-if="webhook.enabled === false" class="webhooks-table__off">{{ t('stackiq', 'Inactive') }}</span>
						</td>
						<td>{{ eventsText(webhook) }}</td>
						<td>{{ deliveryText(webhook) }}</td>
						<td>
							<NcActions :aria-label="t('stackiq', 'Actions for {name}', { name: webhook.name || webhook.url })">
								<NcActionButton :data-testid="'webhook-edit-' + webhook.id" @click="openModal(webhook)">
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
						</td>
					</tr>
				</tbody>
			</table>
			<NcButton variant="secondary" :disabled="!scope.register" @click="openModal(null)">
				{{ t('stackiq', 'Add webhook') }}
			</NcButton>
		</template>

		<EditWebhookModal
			:show="modalOpen"
			:webhook="editing"
			:scope="scope"
			:saving="saving"
			@close="modalOpen = false"
			@save="save" />
	</AlwaysVisibleSection>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcActionButton,
	NcActions,
	NcButton,
	NcEmptyContent,
	NcNoteCard,
} from '@nextcloud/vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import AlwaysVisibleSection from '../../../components/AlwaysVisibleSection.vue'
import EditWebhookModal from '../../../modals/webhook/EditWebhookModal.vue'
import { apiRequest } from '../../../utils/adminApi.js'
import {
	catalogueScope,
	isCatalogueWebhook,
	lastDelivery,
	webhookBody,
	webhookForm,
} from '../../../utils/catalogueWebhooks.js'

/**
 * OpenRegister's webhooks endpoint, or one webhook on it.
 *
 * @param {number|string} [id] The webhook id.
 * @return {string} The URL.
 */
function webhooksUrl(id) {
	return id === undefined
		? generateUrl('/apps/openregister/api/webhooks')
		: generateUrl('/apps/openregister/api/webhooks/{id}', { id })
}

/**
 * The catalogue webhooks section of the exchange settings.
 *
 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
 */
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
	},

	data() {
		return {
			loading: true,
			saving: false,
			error: '',
			scope: catalogueScope({}),
			webhooks: [],
			modalOpen: false,
			editing: null,
		}
	},

	async mounted() {
		await this.load()
	},

	methods: {
		t,

		/**
		 * Load the catalogue scope and the webhooks filtered to it.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const config = await apiRequest('voorzieningen/config')
				this.scope = catalogueScope(config?.config || {})
				const response = await axios.get(webhooksUrl())
				this.webhooks = (response.data?.results || []).filter((webhook) => isCatalogueWebhook(webhook, this.scope))
			} catch (error) {
				this.webhooks = []
				this.error = error?.response?.data?.error || error?.message || t('stackiq', 'The webhooks could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Open the dialog for a webhook, or for a new one.
		 *
		 * @param {object|null} webhook The webhook, or null.
		 * @return {void}
		 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
		 */
		openModal(webhook) {
			this.editing = webhook
			this.modalOpen = true
		},

		/**
		 * Create or update the webhook the dialog describes, then reload.
		 *
		 * @param {object} form The dialog's form.
		 * @return {Promise<void>}
		 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
		 */
		async save(form) {
			this.saving = true
			try {
				const body = webhookBody(form, this.scope, this.editing || {})
				if (this.editing) {
					await axios.put(webhooksUrl(this.editing.id), body)
				} else {
					await axios.post(webhooksUrl(), body)
				}
				showSuccess(t('stackiq', 'The webhook is saved.'))
				this.modalOpen = false
				await this.load()
			} catch (error) {
				showError(error?.response?.data?.error || t('stackiq', 'The webhook could not be saved.'))
			} finally {
				this.saving = false
			}
		},

		/**
		 * Remove a webhook, then reload.
		 *
		 * @param {object} webhook The webhook.
		 * @return {Promise<void>}
		 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
		 */
		async remove(webhook) {
			try {
				await axios.delete(webhooksUrl(webhook.id))
				showSuccess(t('stackiq', 'The webhook is removed.'))
				await this.load()
			} catch (error) {
				showError(error?.response?.data?.error || t('stackiq', 'The webhook could not be removed.'))
			}
		},

		/**
		 * The actions and entries a webhook follows, in words.
		 *
		 * @param {object} webhook The webhook.
		 * @return {string} The text.
		 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
		 */
		eventsText(webhook) {
			const form = webhookForm(webhook, this.scope)
			const actions = {
				created: t('stackiq', 'Created'),
				updated: t('stackiq', 'Changed'),
				deleted: t('stackiq', 'Deleted'),
			}
			const subjects = {
				module: t('stackiq', 'Applications'),
				contract: t('stackiq', 'Contracts'),
				usage: t('stackiq', 'Applications in use'),
			}
			return t('stackiq', '{actions}: {subjects}', {
				actions: form.actions.map((id) => actions[id]).join(', ') || '–',
				subjects: form.subjects.map((id) => subjects[id]).join(', ') || '–',
			})
		},

		/**
		 * The last delivery in words.
		 *
		 * @param {object} webhook The webhook.
		 * @return {string} The text.
		 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-another-system-is-notified-when-a-catalogue-entry-changes-req-plat-005
		 */
		deliveryText(webhook) {
			const delivery = lastDelivery(webhook)
			if (!delivery) {
				return t('stackiq', 'Not sent yet')
			}
			const date = new Date(delivery.at).toLocaleString()
			return delivery.ok
				? t('stackiq', '{date}, delivered', { date })
				: t('stackiq', '{date}, failed', { date })
		},
	},
}
</script>

<style scoped>
.webhooks-table {
	width: 100%;
	border-collapse: collapse;
	margin-bottom: 12px;
}

.webhooks-table th,
.webhooks-table td {
	padding: 8px;
	border-bottom: 1px solid var(--color-border);
	text-align: start;
	vertical-align: top;
}

.webhooks-table__url {
	color: var(--color-text-maxcontrast);
	word-break: break-all;
}

.webhooks-table__off {
	color: var(--color-warning-text);
}
</style>
