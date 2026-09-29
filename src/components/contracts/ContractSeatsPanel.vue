<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<template>
	<CnWidgetWrapper
		:title="t('stackiq', 'Licences')"
		titleIconPosition="left"
		:showRefresh="false"
		:showRequestFeature="false">
		<template #title-icon>
			<CnIcon name="AccountMultiple" :size="20" />
		</template>
		<div class="contract-seats-panel" data-testid="contract-seats-panel">
			<NcLoadingIcon
				v-if="loading"
				:size="32"
				:name="t('stackiq', 'Loading licences')" />
			<NcNoteCard v-else-if="error" type="error">
				{{ t('stackiq', 'The licences could not be loaded.') }}
			</NcNoteCard>
			<template v-else>
				<p
					class="contract-seats-panel__state"
					data-testid="contract-seats-state">
					{{ stateLabel }}
				</p>
				<CnProgressBar v-if="showBar" :items="barItems" :showValue="false" />
				<dl class="contract-seats-panel__numbers">
					<div>
						<dt>{{ t('stackiq', 'Licence metric') }}</dt>
						<dd>{{ licenceMetricLabel(position.metric) }}</dd>
					</div>
					<div>
						<dt>{{ t('stackiq', 'Licences bought') }}</dt>
						<dd>{{ formatCount(position.bought) }}</dd>
					</div>
					<div>
						<dt>{{ t('stackiq', 'Licences in use') }}</dt>
						<dd>{{ formatCount(position.inUse) }}</dd>
					</div>
				</dl>
				<p v-if="updated" class="contract-seats-panel__updated">
					{{ t('stackiq', 'Last changed on {date}', { date: updated }) }}
				</p>
			</template>
		</div>
	</CnWidgetWrapper>
</template>

<script>
import { CnIcon, CnProgressBar, CnWidgetWrapper } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { SEAT_STATE, seatPosition } from '../../utils/licensePosture.js'
import { licenceMetricLabel, seatStateLabel } from '../../utils/seatLabels.js'

export default {
	name: 'ContractSeatsPanel',
	components: {
		CnIcon,
		CnProgressBar,
		CnWidgetWrapper,
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		objectId: {
			type: [String, Number],
			default: '',
		},

		register: {
			type: String,
			default: 'stackiq',
		},

		schema: {
			type: String,
			default: 'catalogContract',
		},
	},

	data() {
		return {
			loading: true,
			error: false,
			contract: {},
		}
	},

	computed: {
		/**
		 * The seat position of the loaded contract.
		 *
		 * @return {object} The seatPosition() result.
		 * @spec openspec/changes/contracts-licence-seats/specs/licence-seats/spec.md#requirement-req-lsc-002-the-contract-detail-page-must-show-licences-in-use-against-licences-bought
		 */
		position() {
			return seatPosition(this.contract)
		},

		/**
		 * The state sentence at the top of the panel.
		 *
		 * @return {string} The translated state.
		 * @spec openspec/changes/contracts-licence-seats/specs/licence-seats/spec.md#requirement-req-lsc-002-the-contract-detail-page-must-show-licences-in-use-against-licences-bought
		 */
		stateLabel() {
			return seatStateLabel(this.position)
		},

		/**
		 * Whether the bar is drawn: only for a counted contract with both numbers.
		 *
		 * @return {boolean} True when the bar is shown.
		 * @spec openspec/changes/contracts-licence-seats/specs/licence-seats/spec.md#requirement-req-lsc-002-the-contract-detail-page-must-show-licences-in-use-against-licences-bought
		 */
		showBar() {
			return (
				this.position.state === SEAT_STATE.WITHIN
				|| this.position.state === SEAT_STATE.OVER
			)
		},

		/**
		 * One bar: licences in use against licences bought.
		 *
		 * @return {Array<object>} The CnProgressBar items.
		 * @spec openspec/changes/contracts-licence-seats/specs/licence-seats/spec.md#requirement-req-lsc-002-the-contract-detail-page-must-show-licences-in-use-against-licences-bought
		 */
		barItems() {
			const { bought, inUse, state } = this.position
			const percentage =
				bought > 0 ? Math.min(100, (inUse / bought) * 100) : 100
			return [
				{
					key: 'seats',
					label: t('stackiq', '{inUse} of {bought} in use', {
						inUse: this.formatCount(inUse),
						bought: this.formatCount(bought),
					}),

					percentage,
					variant: state === SEAT_STATE.OVER ? 'error' : 'success',
				},
			]
		},

		/**
		 * The date the contract was last changed, in the reader's locale.
		 *
		 * @return {string} The date, or an empty string.
		 * @spec openspec/changes/contracts-licence-seats/specs/licence-seats/spec.md#requirement-req-lsc-002-the-contract-detail-page-must-show-licences-in-use-against-licences-bought
		 */
		updated() {
			const raw = this.contract?.['@self']?.updated
			if (!raw) {
				return ''
			}
			const date = new Date(raw)
			return Number.isNaN(date.getTime()) ? '' : date.toLocaleDateString()
		},
	},

	async mounted() {
		await this.load()
	},

	methods: {
		t,
		licenceMetricLabel,

		/**
		 * Read the contract object.
		 *
		 * @return {Promise<void>} Resolves once the contract is read.
		 * @spec openspec/changes/contracts-licence-seats/specs/licence-seats/spec.md#requirement-req-lsc-002-the-contract-detail-page-must-show-licences-in-use-against-licences-bought
		 */
		async load() {
			this.loading = true
			this.error = false
			try {
				if (this.objectId) {
					const url = generateUrl(
						'/apps/openregister/api/objects/{register}/{schema}/{id}',
						{
							register: this.register,
							schema: this.schema,
							id: String(this.objectId),
						},
					)
					const { data } = await axios.get(url)
					this.contract =
						data && data['@self'] !== undefined
							? data
							: data?.object || data || {}
				}
			} catch {
				this.error = true
			} finally {
				this.loading = false
			}
		},

		/**
		 * Format a count in the reader's locale.
		 *
		 * @param {number|null} value The count.
		 * @return {string} The formatted count, or an empty string.
		 * @spec openspec/changes/contracts-licence-seats/specs/licence-seats/spec.md#requirement-req-lsc-002-the-contract-detail-page-must-show-licences-in-use-against-licences-bought
		 */
		formatCount(value) {
			return value === null || value === undefined
				? ''
				: Number(value).toLocaleString()
		},
	},
}
</script>

<style scoped>
.contract-seats-panel {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.contract-seats-panel__state {
	font-weight: bold;
}

.contract-seats-panel__numbers {
	display: grid;
	grid-template-columns: repeat(3, minmax(0, 1fr));
	gap: 8px;
	margin: 0;
}

.contract-seats-panel__numbers dt {
	color: var(--color-text-maxcontrast);
}

.contract-seats-panel__numbers dd {
	margin: 0;
}

.contract-seats-panel__updated {
	color: var(--color-text-maxcontrast);
}
</style>
