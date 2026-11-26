<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<template>
	<CnWidgetWrapper
		:title="t('stackiq', 'Supplier')"
		titleIconPosition="left"
		:showRefresh="false"
		:showRequestFeature="false">
		<template #title-icon>
			<CnIcon name="OfficeBuildingOutline" :size="20" />
		</template>
		<section class="contract-supplier-panel" data-testid="contract-supplier-panel">
			<NcLoadingIcon
				v-if="loading"
				:size="32"
				:name="t('stackiq', 'Loading the supplier')" />
			<NcNoteCard v-else-if="error" type="error">
				{{ t('stackiq', 'The supplier could not be loaded.') }}
			</NcNoteCard>
			<p
				v-else-if="!supplier"
				class="contract-supplier-panel__empty"
				data-testid="contract-supplier-empty">
				{{ emptyLabel }}
			</p>
			<dl v-else class="contract-supplier-panel__fields">
				<div>
					<dt>{{ t('stackiq', 'Supplier') }}</dt>
					<dd data-testid="contract-supplier-name">
						<router-link :to="supplierRoute">
							{{ supplier.name }}
						</router-link>
					</dd>
				</div>
				<div v-if="service && service.name">
					<dt>{{ t('stackiq', 'Through the service') }}</dt>
					<dd>{{ service.name }}</dd>
				</div>
			</dl>
		</section>
	</CnWidgetWrapper>
</template>

<script>
/**
 * The supplier of a contract (REQ-CLR-014): a contract names the service it
 * is for, and that service names its provider, so the panel reads the three
 * records in turn and links the provider to its organisation page.
 */
import { CnIcon, CnWidgetWrapper } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'

/**
 * The object in an OpenRegister object reply: the body itself when it
 * carries `@self`, else its `object`, else the body, else an empty object.
 *
 * @param {object|null} data The reply body.
 * @return {object} The object.
 */
function objectOf(data) {
	return data && data['@self'] !== undefined ? data : data?.object || data || {}
}

/**
 * The id a reference property holds: a uuid string, or the `id` (or
 * `@self.id`) of an expanded object. Empty when the property is unset.
 *
 * @param {string|object|null|undefined} value The property value.
 * @return {string} The referenced id, or ''.
 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/vendor-product-master-data/spec.md#requirement-a-contract-shows-its-supplier-through-the-service-it-is-for-req-clr-014
 */
export function referenceId(value) {
	if (typeof value === 'string') {
		return value.trim()
	}
	if (value && typeof value === 'object') {
		const id = value.id ?? value['@self']?.id ?? value.uuid
		return id === undefined || id === null ? '' : String(id).trim()
	}
	return ''
}

export default {
	name: 'ContractSupplierPanel',
	components: {
		CnIcon,
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

		serviceSchema: {
			type: String,
			default: 'catalogService',
		},

		organisationSchema: {
			type: String,
			default: 'organization',
		},
	},

	data() {
		return {
			loading: true,
			error: false,
			service: null,
			supplier: null,
			hasService: false,
		}
	},

	computed: {
		/**
		 * The supplier's organisation page.
		 *
		 * @return {object} The route.
		 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/vendor-product-master-data/spec.md#requirement-a-contract-shows-its-supplier-through-the-service-it-is-for-req-clr-014
		 */
		supplierRoute() {
			return { name: 'OrganisatieDetail', params: { id: String(this.supplier?.id ?? '') } }
		},

		/**
		 * Why no supplier shows: the contract names no service, or its service no provider.
		 *
		 * @return {string} The translated line.
		 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/vendor-product-master-data/spec.md#requirement-a-contract-shows-its-supplier-through-the-service-it-is-for-req-clr-014
		 */
		emptyLabel() {
			return this.hasService
				? t('stackiq', 'The service of this contract names no supplier.')
				: t('stackiq', 'This contract names no service, so its supplier is not known.')
		},
	},

	async mounted() {
		await this.load()
	},

	methods: {
		t,

		/**
		 * Read the contract, then its service, then the service's provider.
		 *
		 * @return {Promise<void>} Resolves once the supplier is read or known to be absent.
		 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/vendor-product-master-data/spec.md#requirement-a-contract-shows-its-supplier-through-the-service-it-is-for-req-clr-014
		 */
		async load() {
			this.loading = true
			this.error = false
			this.service = null
			this.supplier = null
			this.hasService = false
			try {
				if (!this.objectId) {
					return
				}
				const contract = await this.fetchObject(this.schema, String(this.objectId))
				const serviceId = referenceId(contract.service)
				if (serviceId === '') {
					return
				}
				this.hasService = true
				const service = await this.fetchObject(this.serviceSchema, serviceId)
				this.service = { id: serviceId, name: service.name || '' }
				const providerId = referenceId(service.provider)
				if (providerId === '') {
					return
				}
				const provider = await this.fetchObject(this.organisationSchema, providerId)
				this.supplier = { id: providerId, name: provider.name || provider.title || providerId }
			} catch {
				this.error = true
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch one object of this register, unwrapped from its envelope.
		 *
		 * @param {string} schema The schema slug.
		 * @param {string} id The object id.
		 * @return {Promise<object>} The object.
		 * @spec openspec/changes/capability-rows-the-code-does-not-yet-meet/specs/vendor-product-master-data/spec.md#requirement-a-contract-shows-its-supplier-through-the-service-it-is-for-req-clr-014
		 */
		async fetchObject(schema, id) {
			const url = generateUrl(
				'/apps/openregister/api/objects/{register}/{schema}/{id}',
				{ register: this.register, schema, id },
			)
			const { data } = await axios.get(url)
			return objectOf(data)
		},
	},
}
</script>

<style scoped>
.contract-supplier-panel__fields {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 8px;
	margin: 0;
}

.contract-supplier-panel__fields dt,
.contract-supplier-panel__empty {
	color: var(--color-text-maxcontrast);
}

.contract-supplier-panel__fields dd {
	margin: 0;
}
</style>
