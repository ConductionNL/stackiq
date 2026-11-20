<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -
  - "Claimed usage of our offering" on a supplier's organisation page: the
  - usages other organisations claim of the supplier's products, each with the
  - organisation, product, version, module and date, and Accept and Decline.
  - Shown only to members of the supplier (the page's organisation is the
  - user's active one); the server refuses an answer from anyone else.
  -
  - @spec openspec/changes/supplier-accepts-claimed-usage/specs/aanbod-listings/spec.md#requirement-a-supplier-answers-the-usages-claimed-of-its-products-req-010
  -->
<template>
	<CnWidgetWrapper
		v-if="claims !== null"
		:title="t('stackiq', 'Claimed usage of our offering')"
		titleIconPosition="left"
		:showRefresh="false"
		:showRequestFeature="false"
		data-testid="claimed-usage-list">
		<template #title-icon>
			<CnIcon name="HandshakeOutline" :size="20" />
		</template>
		<p class="claimed-usage__intro">
			{{ t('stackiq', 'Organisations that say they use your product.') }}
		</p>
		<p v-if="claims.length === 0" data-testid="claimed-usage-empty">
			{{ t('stackiq', 'No claimed usage to answer.') }}
		</p>
		<table v-else class="claimed-usage__table">
			<thead>
				<tr>
					<th scope="col">
						{{ t('stackiq', 'Organisation') }}
					</th>
					<th scope="col">
						{{ t('stackiq', 'Product') }}
					</th>
					<th scope="col">
						{{ t('stackiq', 'Version') }}
					</th>
					<th scope="col">
						{{ t('stackiq', 'Claimed on') }}
					</th>
					<th scope="col">
						<span class="hidden-visually">{{ t('stackiq', 'Actions') }}</span>
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="claim in claims" :key="claim.id" :data-testid="`claim-${claim.id}`">
					<td>{{ claim.organisation }}</td>
					<td>{{ claim.module }}</td>
					<td>{{ claim.moduleVersion }}</td>
					<td>{{ claimDate(claim.claimedAt) }}</td>
					<td class="claimed-usage__actions">
						<NcButton
variant="primary"
							:disabled="busy === claim.id"
							:data-testid="`claim-accept-${claim.id}`"
							@click="answer(claim, 'accept')">
							{{ t('stackiq', 'Accept') }}
						</NcButton>
						<NcButton
variant="tertiary"
							:disabled="busy === claim.id"
							:data-testid="`claim-decline-${claim.id}`"
							@click="declining = claim">
							{{ t('stackiq', 'Decline') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<DeclineClaimDialog
v-if="declining"
			:claim="declining"
			:busy="busy === declining.id"
			@close="declining = null"
			@confirm="answer(declining, 'decline')" />
	</CnWidgetWrapper>
</template>

<script>
import { CnIcon, CnWidgetWrapper } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import DeclineClaimDialog from '../../dialogs/DeclineClaimDialog.vue'
import { answerClaim, claimDate, fetchClaims } from './claimedUsage.js'

export default {
	name: 'ClaimedUsageList',
	components: { CnIcon, CnWidgetWrapper, DeclineClaimDialog, NcButton, NcNoteCard },
	props: {
		/** The organisation whose page is open. */
		objectId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			claims: null,
			busy: '',
			declining: null,
			error: '',
		}
	},

	/**
	 * @spec openspec/changes/supplier-accepts-claimed-usage/specs/aanbod-listings/spec.md#requirement-a-supplier-answers-the-usages-claimed-of-its-products-req-010
	 */
	async mounted() {
		this.claims = await fetchClaims(this.objectId)
	},

	methods: {
		t,
		claimDate,

		/**
		 * Send the answer; an answered claim leaves the list.
		 *
		 * @param {object} claim The claim.
		 * @param {'accept'|'decline'} action The answer.
		 *
		 * @spec openspec/changes/supplier-accepts-claimed-usage/specs/aanbod-listings/spec.md#requirement-a-supplier-answers-the-usages-claimed-of-its-products-req-010
		 */
		async answer(claim, action) {
			this.busy = claim.id
			this.error = ''
			try {
				await answerClaim(claim.id, action)
				this.claims = this.claims.filter((c) => c.id !== claim.id)
				this.declining = null
			} catch {
				this.error = t('stackiq', 'The answer was not saved. Please try again.')
			} finally {
				this.busy = ''
			}
		},
	},
}
</script>

<style scoped>
.claimed-usage__intro {
	color: var(--color-text-maxcontrast);
}

.claimed-usage__table {
	width: 100%;
	border-collapse: collapse;
}

.claimed-usage__table th,
.claimed-usage__table td {
	padding: 4px 8px;
	text-align: start;
	border-bottom: 1px solid var(--color-border);
}

.claimed-usage__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
}
</style>
