<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<template>
	<CnWidgetWrapper
		:title="t('stackiq', 'Contracts')"
		titleIconPosition="left"
		:showRefresh="false"
		:showRequestFeature="false">
		<template #title-icon>
			<CnIcon name="FileSign" :size="20" />
		</template>
		<div class="application-contracts-panel">
			<NcLoadingIcon
				v-if="loading"
				:size="32"
				:name="t('stackiq', 'Loading contracts')" />
			<NcNoteCard v-else-if="error" type="error">
				{{ t('stackiq', 'The contracts could not be loaded.') }}
			</NcNoteCard>
			<p
				v-else-if="contracts.length === 0"
				class="application-contracts-panel__empty">
				{{ t('stackiq', 'No contracts found for this application') }}
			</p>
			<table v-else class="application-contracts-panel__table">
				<thead>
					<tr>
						<th scope="col">{{ t('stackiq', 'Contract number') }}</th>
						<th scope="col">{{ t('stackiq', 'Contract type') }}</th>
						<th scope="col">{{ t('stackiq', 'End date') }}</th>
						<th scope="col">{{ t('stackiq', 'Status') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="contract in contracts" :key="contractId(contract)">
						<td>
							<router-link
								:to="{
									name: 'ContractDetail',
									params: { id: String(contractId(contract)) },
								}">
								{{
									contract.contractNumber
									|| t('stackiq', 'Contract')
								}}
							</router-link>
						</td>
						<td>{{ contract.contractType || '' }}</td>
						<td>{{ contract.endDate || '' }}</td>
						<td>{{ contract.status || '' }}</td>
					</tr>
				</tbody>
			</table>
		</div>
	</CnWidgetWrapper>
</template>

<script>
import { CnIcon, CnWidgetWrapper } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { objectStore } from '../../store/store.js'
import { loadApplicationContracts } from '../../utils/applicationContracts.js'

/**
 * The contracts behind one application, on its page: contracts on a usage of
 * the application and on a service that offers it. Each row opens the contract.
 *
 * @spec openspec/changes/landscape-application-page/specs/application-page/spec.md#requirement-req-apg-003-the-application-page-lists-the-contracts-behind-the-application
 */
export default {
	name: 'ApplicationContractsPanel',

	components: {
		CnIcon,
		CnWidgetWrapper,
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		/** The application (module) id. */
		objectId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			loading: true,
			error: false,
			contracts: [],
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
		 * The id of a contract row.
		 *
		 * @param {object} contract The contract
		 * @return {string} The id
		 */
		contractId(contract) {
			return contract?.id ?? contract?.['@self']?.id ?? contract?.uuid
		},

		/**
		 * Load the contracts through the object store, without touching the store's lists.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/landscape-application-page/specs/application-page/spec.md#requirement-req-apg-003-the-application-page-lists-the-contracts-behind-the-application
		 */
		async load() {
			this.loading = true
			this.error = false
			try {
				this.contracts = await loadApplicationContracts(
					this.objectId,
					async (type, params) => {
						this.ensureType(type)
						return objectStore.fetchCollectionForOptions(type, params)
					},
				)
			} catch {
				this.error = true
				this.contracts = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Register an object type with the store when it is not known yet.
		 *
		 * @param {string} type The schema slug
		 * @return {void}
		 */
		ensureType(type) {
			if (
				typeof objectStore.registerObjectType !== 'function'
				|| objectStore.objectTypeRegistry?.[type]
			) {
				return
			}
			const config = objectStore.getSchemaConfig?.(type)
			if (config?.register && config?.schema) {
				objectStore.registerObjectType(type, config.schema, config.register)
			}
		},
	},
}
</script>

<style scoped>
.application-contracts-panel__table {
	width: 100%;
	border-collapse: collapse;
}

.application-contracts-panel__table th,
.application-contracts-panel__table td {
	padding: calc(var(--default-grid-baseline) * 2);
	border-bottom: 1px solid var(--color-border);
	text-align: start;
}

.application-contracts-panel__empty {
	color: var(--color-text-maxcontrast);
}
</style>
