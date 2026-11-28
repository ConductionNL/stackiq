<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<template>
	<CnWidgetWrapper
		:title="t('stackiq', 'Participants')"
		titleIconPosition="left"
		:showRefresh="false"
		:showRequestFeature="false">
		<template #title-icon>
			<CnIcon name="AccountGroup" :size="20" />
		</template>
		<div class="usage-participants" data-testid="usage-participants">
			<NcLoadingIcon
				v-if="loading"
				:size="32"
				:name="t('stackiq', 'Loading the participants')" />
			<NcNoteCard v-else-if="error" type="error">
				{{ t('stackiq', 'The participants could not be loaded.') }}
			</NcNoteCard>
			<p v-else-if="participants.length === 0" class="usage-participants__empty">
				{{ t('stackiq', 'This application in use is not shared with other organisations') }}
			</p>
			<template v-else>
				<p class="usage-participants__owner" data-testid="usage-participants-owner">
					{{ n('stackiq', 'Run by {owner} for {count} organisation', 'Run by {owner} for {count} organisations', count, { owner: ownerLabel, count: count }) }}
				</p>
				<NcNoteCard
					v-if="readOnly"
					type="info"
					data-testid="usage-participants-read-only">
					{{ t('stackiq', 'Your organisation takes part in this application in use. Only the owner can change it.') }}
				</NcNoteCard>
				<table class="usage-participants__table">
					<thead>
						<tr>
							<th scope="col">{{ t('stackiq', 'Organisation') }}</th>
							<th scope="col">{{ t('stackiq', 'Joined') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="participant in participants" :key="participant.id" data-testid="usage-participant">
							<td>
								<router-link :to="{ name: 'OrganisatieDetail', params: { id: participant.id } }">
									{{ nameOf(participant) }}
								</router-link>
							</td>
							<td>{{ participant.since ? formatDate(participant.since) : t('stackiq', 'Not recorded') }}</td>
						</tr>
					</tbody>
				</table>
			</template>
		</div>
	</CnWidgetWrapper>
</template>

<script>
import { CnIcon, CnWidgetWrapper } from '@conduction/nextcloud-vue'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { objectStore } from '../../store/store.js'
import { ensureObjectTypeOrSlug } from '../../utils/objectTypes.js'
import { participantsOf, relationId, relationName, sharedSummary } from '../../utils/sharedUsage.js'

/**
 * The Participants card of an application in use: the organisation that runs
 * it for the cooperation, and each participant with the date it joined. A
 * participant reads it; when the record says the reader may not update it, the
 * card says so (the page itself withdraws edit through `permissions`).
 *
 * @spec openspec/changes/archive/2026-11-24-shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-participant-reads-the-usages-shared-with-it-and-their-connections-req-dlg-010
 */
export default {
	name: 'UsageParticipantsCard',

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
			usage: null,
			names: {},
		}
	},

	computed: {
		/**
		 * @return {Array<{id: string, name: string, since: string}>} The participants.
		 * @spec openspec/changes/archive/2026-11-24-shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-participant-reads-the-usages-shared-with-it-and-their-connections-req-dlg-010
		 */
		participants() {
			return participantsOf(this.usage)
		},

		/**
		 * @return {number} How many organisations share the usage.
		 * @spec openspec/changes/archive/2026-11-24-shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-participant-reads-the-usages-shared-with-it-and-their-connections-req-dlg-010
		 */
		count() {
			return sharedSummary(this.usage)?.count ?? 0
		},

		/**
		 * @return {string} The owner's name, else its uuid.
		 * @spec openspec/changes/archive/2026-11-24-shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-participant-reads-the-usages-shared-with-it-and-their-connections-req-dlg-010
		 */
		ownerLabel() {
			const owner = relationId(this.usage?.consumer)
			return relationName(this.usage?.consumer) || this.names[owner] || owner || t('stackiq', 'an unknown organisation')
		},

		/**
		 * @return {boolean} Whether the record says the reader may not update it.
		 * @spec openspec/changes/archive/2026-11-24-shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-participant-reads-the-usages-shared-with-it-and-their-connections-req-dlg-010
		 */
		readOnly() {
			return this.usage?.['@self']?.can?.update === false
		},
	},

	watch: {
		/**
		 * Reload when the page moves to another application in use.
		 *
		 * @return {void}
		 * @spec openspec/changes/archive/2026-11-24-shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-participant-reads-the-usages-shared-with-it-and-their-connections-req-dlg-010
		 */
		objectId() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		n,
		t,

		/**
		 * Load the usage, then the names of the owner and participants it holds only as uuids.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-11-24-shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-participant-reads-the-usages-shared-with-it-and-their-connections-req-dlg-010
		 */
		async load() {
			this.loading = true
			this.error = false
			this.names = {}
			try {
				ensureObjectTypeOrSlug('usage')
				this.usage = await objectStore.fetchObject('usage', this.objectId)
			} catch {
				this.error = true
				this.usage = null
				this.loading = false
				return
			}
			this.loading = false
			await this.loadNames()
		},

		/**
		 * Fetch the organisation names the record does not carry; one that fails keeps its uuid.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-11-24-shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-participant-reads-the-usages-shared-with-it-and-their-connections-req-dlg-010
		 */
		async loadNames() {
			const wanted = this.participants.filter((p) => p.name === '').map((p) => p.id)
			const owner = relationId(this.usage?.consumer)
			if (owner !== '' && relationName(this.usage?.consumer) === '') {
				wanted.push(owner)
			}
			if (wanted.length === 0) {
				return
			}
			ensureObjectTypeOrSlug('organization')
			const names = {}
			await Promise.all([...new Set(wanted)].map(async (id) => {
				try {
					const organisation = await objectStore.fetchObject('organization', id)
					if (typeof organisation?.name === 'string') {
						names[id] = organisation.name
					}
				} catch {
					// Not readable: the uuid stays.
				}
			}))
			this.names = names
		},

		/**
		 * @param {{id: string, name: string}} participant The participant.
		 * @return {string} Its name, else its uuid.
		 * @spec openspec/changes/archive/2026-11-24-shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-participant-reads-the-usages-shared-with-it-and-their-connections-req-dlg-010
		 */
		nameOf(participant) {
			return participant.name || this.names[participant.id] || participant.id
		},

		/**
		 * @param {string} value An ISO date.
		 * @return {string} The date in the reader's locale, or the value when it does not parse.
		 * @spec openspec/changes/archive/2026-11-24-shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-participant-reads-the-usages-shared-with-it-and-their-connections-req-dlg-010
		 */
		formatDate(value) {
			const date = new Date(value)
			return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString()
		},
	},
}
</script>

<style scoped>
.usage-participants__table {
	width: 100%;
	border-collapse: collapse;
}

.usage-participants__table th,
.usage-participants__table td {
	padding: var(--default-grid-baseline, 4px) calc(2 * var(--default-grid-baseline, 4px));
	text-align: start;
	border-bottom: 1px solid var(--color-border);
}

.usage-participants__empty {
	color: var(--color-text-maxcontrast);
}
</style>
