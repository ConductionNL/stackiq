<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -
  - Start round: the dialog the Confirmation rounds page's header action opens
  - (src/services/attestation.js). The information manager picks the
  - organisation, whether its applications in use or its products are asked
  - about, and the deadline. The server decides who may start a round (an
  - administrator, or a maintainer of the organisation) and answers anyone
  - else with a refusal, which the dialog shows. After the start the dialog
  - lists the entries no request could be sent for, so an owner can be
  - assigned first.
  -
  - @spec openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-001-an-information-manager-starts-a-confirmation-round-for-a-scope
  -->

<template>
	<NcDialog
		:name="t('stackiq', 'Start confirmation round')"
		size="normal"
		@closing="close">
		<div class="start-round">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<template v-if="result">
				<NcNoteCard type="success">
					{{
						t('stackiq', 'Requests sent: {count}', {
							count: result.requestCount,
						})
					}}
				</NcNoteCard>
				<section
					v-if="unassigned.length > 0"
					class="start-round__unassigned">
					<h3>{{ t('stackiq', 'Entries without an owner') }}</h3>
					<p>
						{{
							t(
								'stackiq',
								'No request could be sent for these entries. Assign an owner and start a new round for them.',
							)
						}}
					</p>
					<ul>
						<li v-for="entry in unassigned" :key="entry.entryId">
							{{ entry.entryName || entry.entryId }}
						</li>
					</ul>
				</section>
			</template>

			<template v-else>
				<NcTextField
					v-model="form.name"
					:label="t('stackiq', 'Name')"
					:placeholder="
						t('stackiq', 'For example the quarter the round covers')
					"
					:disabled="busy" />
				<NcSelect
					v-model="form.organisation"
					:inputLabel="t('stackiq', 'Organisation')"
					:options="organisationChoices"
					:reduce="(option) => option.value"
					:loading="loadingOrganisations"
					:disabled="busy" />
				<NcSelect
					v-model="form.scopeSchema"
					:inputLabel="t('stackiq', 'Entries to confirm')"
					:options="scopeChoices"
					:reduce="(option) => option.value"
					:disabled="busy" />
				<NcTextField
					v-model="form.deadline"
					type="date"
					:label="t('stackiq', 'Deadline')"
					:disabled="busy" />
			</template>
		</div>

		<template #actions>
			<NcButton @click="close">
				{{ result ? t('stackiq', 'Close') : t('stackiq', 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="!result"
				variant="primary"
				:disabled="!canStart"
				@click="start">
				<template #icon>
					<NcLoadingIcon v-if="busy" :size="20" />
				</template>
				{{ t('stackiq', 'Start round') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import { getActiveOrganisationUuid } from '../composables/orClient.js'
import { organisationOptions, roundBody } from '../services/attestation.js'
import { navigationStore } from '../store/store.js'

export default {
	name: 'StartAttestationRoundDialog',

	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	emits: ['close'],

	/**
	 * @spec exclude Pinia store wiring in setup() — bootstrap plumbing
	 */
	setup() {
		return { navigationStore }
	},

	data() {
		return {
			organisations: [],
			loadingOrganisations: false,
			form: {
				name: '',
				organisation: getActiveOrganisationUuid(),
				scopeSchema: 'usage',
				deadline: '',
			},

			busy: false,
			error: '',
			result: null,
		}
	},

	computed: {
		/**
		 * The organisations the round can be for.
		 *
		 * @return {Array<{value: string, label: string}>} The options.
		 * @spec openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-001-an-information-manager-starts-a-confirmation-round-for-a-scope
		 */
		organisationChoices() {
			return organisationOptions(this.organisations)
		},

		/**
		 * The entry types a round asks about.
		 *
		 * @return {Array<{value: string, label: string}>} The options.
		 * @spec openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-001-an-information-manager-starts-a-confirmation-round-for-a-scope
		 */
		scopeChoices() {
			return [
				{ value: 'usage', label: t('stackiq', 'Applications in use') },
				{ value: 'module', label: t('stackiq', 'Products') },
			]
		},

		/**
		 * The request body, or null while a field is missing.
		 *
		 * @return {object|null} The body.
		 * @spec openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-001-an-information-manager-starts-a-confirmation-round-for-a-scope
		 */
		body() {
			return roundBody(this.form)
		},

		/**
		 * Whether the round can be started.
		 *
		 * @return {boolean} Whether it can.
		 * @spec openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-001-an-information-manager-starts-a-confirmation-round-for-a-scope
		 */
		canStart() {
			return !this.busy && this.body !== null
		},

		/**
		 * The entries no request could be sent for.
		 *
		 * @return {Array<{entryId: string, entryName: string}>} The entries.
		 * @spec openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-001-an-information-manager-starts-a-confirmation-round-for-a-scope
		 */
		unassigned() {
			return Array.isArray(this.result?.unassigned)
				? this.result.unassigned
				: []
		},
	},

	/**
	 * @spec openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-001-an-information-manager-starts-a-confirmation-round-for-a-scope
	 */
	mounted() {
		this.loadOrganisations()
	},

	methods: {
		t,

		/**
		 * Load the organisations the user belongs to.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-001-an-information-manager-starts-a-confirmation-round-for-a-scope
		 */
		async loadOrganisations() {
			this.loadingOrganisations = true
			try {
				const { data } = await axios.get(
					generateUrl('/apps/openregister/api/organisations'),
				)
				this.organisations = Array.isArray(data?.results) ? data.results : []
			} catch {
				this.error = t('stackiq', 'The organisations could not be loaded')
			} finally {
				this.loadingOrganisations = false
			}
		},

		/**
		 * Start the round and show what it sent.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-001-an-information-manager-starts-a-confirmation-round-for-a-scope
		 */
		async start() {
			if (!this.canStart) {
				return
			}
			this.busy = true
			this.error = ''
			try {
				const { data } = await axios.post(
					generateUrl('/apps/stackiq/api/attestation-rounds'),
					this.body,
				)
				this.result = data || { requestCount: 0, unassigned: [] }
				emit('cn:page:refresh', {})
			} catch (error) {
				this.error =
					error?.response?.data?.message
					|| t('stackiq', 'The round could not be started')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Close the dialog.
		 *
		 * @return {void}
		 * @spec openspec/changes/landscape-owner-attestation/specs/owner-attestation/spec.md#requirement-req-oat-001-an-information-manager-starts-a-confirmation-round-for-a-scope
		 */
		close() {
			this.navigationStore.setDialog(null)
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.start-round {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 0;
}

.start-round__unassigned ul {
	margin: 4px 0 0;
	padding-inline-start: 20px;
}
</style>
