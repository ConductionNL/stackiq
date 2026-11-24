<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<template>
	<CnWidgetWrapper
		v-if="shown"
		:title="t('stackiq', 'Participants')"
		titleIconPosition="left"
		:showRefresh="false"
		:showRequestFeature="false">
		<template #title-icon>
			<CnIcon name="AccountGroup" :size="20" />
		</template>
		<div class="organisation-participants" data-testid="organisation-participants">
			<NcNoteCard v-if="error" type="error">
				{{ t('stackiq', 'The participants could not be loaded.') }}
			</NcNoteCard>
			<p v-else-if="participants.length === 0" class="organisation-participants__empty">
				{{ t('stackiq', 'This cooperation lists no participants yet') }}
			</p>
			<ul v-else class="organisation-participants__list">
				<li v-for="participant in participants" :key="participant.id" data-testid="organisation-participant">
					<router-link :to="{ name: 'OrganisatieDetail', params: { id: participant.id } }">
						{{ participant.name || names[participant.id] || participant.id }}
					</router-link>
				</li>
			</ul>
		</div>
	</CnWidgetWrapper>
</template>

<script>
import { CnIcon, CnWidgetWrapper } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { NcNoteCard } from '@nextcloud/vue'
import { objectStore } from '../../store/store.js'
import { ensureObjectTypeOrSlug } from '../../utils/objectTypes.js'
import { participantsOf } from '../../utils/sharedUsage.js'

/**
 * The Participants list on a cooperation's page: each participant
 * organisation, linked to its own page. Not shown for an organisation that is
 * not a cooperation.
 *
 * @spec openspec/changes/archive/2026-11-24-shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-cooperation-shows-its-participants-req-dlg-012
 */
export default {
	name: 'OrganisationParticipantsList',

	components: {
		CnIcon,
		CnWidgetWrapper,
		NcNoteCard,
	},

	props: {
		/** The organisation id. */
		objectId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			organisation: null,
			error: false,
			names: {},
		}
	},

	computed: {
		/**
		 * @return {boolean} Whether to show the list: a cooperation, or a load that failed.
		 * @spec openspec/changes/archive/2026-11-24-shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-cooperation-shows-its-participants-req-dlg-012
		 */
		shown() {
			return this.error || ['Collaboration', 'Samenwerking'].includes(this.organisation?.type)
		},

		/**
		 * @return {Array<{id: string, name: string}>} The participant organisations.
		 * @spec openspec/changes/archive/2026-11-24-shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-cooperation-shows-its-participants-req-dlg-012
		 */
		participants() {
			return participantsOf(this.organisation)
		},
	},

	watch: {
		/**
		 * Reload when the page moves to another organisation.
		 *
		 * @return {void}
		 * @spec openspec/changes/archive/2026-11-24-shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-cooperation-shows-its-participants-req-dlg-012
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
		 * Load the organisation and the names of the participants it holds as uuids.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-11-24-shared-usage-visible-to-participants/specs/deelnames-gebruik/spec.md#requirement-a-cooperation-shows-its-participants-req-dlg-012
		 */
		async load() {
			this.error = false
			this.names = {}
			ensureObjectTypeOrSlug('organization')
			try {
				this.organisation = await objectStore.fetchObject('organization', this.objectId)
			} catch {
				this.organisation = null
				this.error = true
				return
			}
			const names = {}
			await Promise.all(this.participants.filter((p) => p.name === '').map(async ({ id }) => {
				try {
					const participant = await objectStore.fetchObject('organization', id)
					if (typeof participant?.name === 'string') {
						names[id] = participant.name
					}
				} catch {
					// Not readable: the uuid stays.
				}
			}))
			this.names = names
		},
	},
}
</script>

<style scoped>
.organisation-participants__list {
	margin: 0;
	padding-inline-start: calc(4 * var(--default-grid-baseline, 4px));
}

.organisation-participants__empty {
	color: var(--color-text-maxcontrast);
}
</style>
