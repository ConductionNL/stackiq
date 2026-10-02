<!--
 - @copyright Copyright (c) 2026 Conduction B.V. <info@conduction.nl>
 - @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 -
 - Service desk exchange admin section (sharing-itsm-exchange): choose the
 - service desk and the organisation whose applications are exchanged, then
 - set up the flows. The source and its credential live in integriq; this
 - section never sees them. Admin-gated server-side by
 - #[AuthorizedAdminSetting(StackiqAdmin::class)] on every endpoint it calls.
 -->

<template>
	<AlwaysVisibleSection
		id="section-itsm"
		:name="t('stackiq', 'Service desk exchange')"
		:description="
			t(
				'stackiq',
				'Keep applications, connections, licences and contracts in step with TOPdesk or ServiceNow. Add the service desk source in integriq first; stackiq never holds its password.',
			)
		"
		:loading="loading"
		:loadingText="t('stackiq', 'Loading the service desk exchange…')">
		<NcNoteCard v-if="status.available === false" type="warning">
			{{
				t(
					'stackiq',
					"OpenRegister's flow engine is not available, so the exchange cannot run.",
				)
			}}
		</NcNoteCard>
		<NcNoteCard v-else-if="status.enabled" type="success">
			{{
				t(
					'stackiq',
					'Set up for {desk} on {date}. The import runs every night at 02:00.',
					{
						desk: deskLabel,
						date: setUpDate,
					},
				)
			}}
		</NcNoteCard>

		<div class="itsm-form">
			<NcSelect
				v-model="desk"
				:options="status.desks || []"
				label="label"
				:inputLabel="t('stackiq', 'Service desk')"
				:placeholder="t('stackiq', 'Choose TOPdesk or ServiceNow')" />
			<NcSelect
				v-model="organisation"
				:options="organisations"
				label="name"
				:loading="loadingOrganisations"
				:inputLabel="t('stackiq', 'Your organisation')"
				:placeholder="
					t('stackiq', 'The organisation whose applications are exchanged')
				" />
			<NcNoteCard v-if="organisationLoadError" type="warning">
				{{ organisationLoadError }}
			</NcNoteCard>
			<NcTextField
				v-if="desk && desk.id === 'topdesk'"
				v-model="templateId"
				:label="t('stackiq', 'TOPdesk asset template id')"
				:helperText="
					t(
						'stackiq',
						'TOPdesk needs a template to create an asset. Copy the id of your Application template from TOPdesk.',
					)
				" />
			<p class="help-text">
				{{
					t(
						'stackiq',
						'The service desk owns the name, supplier, installed version and status of an application. Stackiq owns licences, contracts, BBN level, TIME class and publication. Each side only overwrites the fields it owns.',
					)
				}}
			</p>
			<NcButton
				variant="primary"
				:disabled="!desk || !organisation || saving"
				@click="setUp">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="20" />
					<SwapHorizontal v-else :size="20" />
				</template>
				{{
					status.enabled
						? t('stackiq', 'Set up again')
						: t('stackiq', 'Set up the exchange')
				}}
			</NcButton>
		</div>

		<NcNoteCard v-if="refusal" type="error">
			{{ refusal }}
		</NcNoteCard>
	</AlwaysVisibleSection>
</template>

<script>
import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import { defineComponent } from 'vue'
import SwapHorizontal from 'vue-material-design-icons/SwapHorizontal.vue'
import AlwaysVisibleSection from '../../../components/AlwaysVisibleSection.vue'
import { apiRequest } from '../../../utils/adminApi.js'

/**
 * Service desk exchange set-up.
 *
 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-001-an-administrator-sets-up-the-exchange-without-stackiq-holding-a-credential
 */
export default defineComponent({
	name: 'ItsmExchange',
	components: {
		AlwaysVisibleSection,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
		SwapHorizontal,
	},

	data() {
		return {
			loading: true,
			saving: false,
			loadingOrganisations: false,
			status: { desks: [] },
			organisations: [],
			organisationLoadError: '',
			desk: null,
			organisation: null,
			refusal: '',
			templateId: '',
		}
	},

	computed: {
		/**
		 * @return {string} The label of the desk that is set up.
		 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-001-an-administrator-sets-up-the-exchange-without-stackiq-holding-a-credential
		 */
		deskLabel() {
			const found = (this.status.desks || []).find(
				(desk) => desk.id === this.status.desk,
			)
			return found ? found.label : String(this.status.desk || '')
		},

		/**
		 * @return {string} The set-up date, in the reader's locale.
		 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-001-an-administrator-sets-up-the-exchange-without-stackiq-holding-a-credential
		 */
		setUpDate() {
			return this.status.setUpAt
				? new Date(this.status.setUpAt).toLocaleDateString()
				: ''
		},
	},

	async mounted() {
		await this.load()
	},

	methods: {
		t,

		/**
		 * Read the set-up and the organisations to choose from.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-001-an-administrator-sets-up-the-exchange-without-stackiq-holding-a-credential
		 */
		async load() {
			this.loading = true
			try {
				this.status = await apiRequest('itsm/config')
				await this.loadOrganisations()
				this.desk =
					(this.status.desks || []).find(
						(desk) => desk.id === this.status.desk,
					) || null
				this.organisation =
					this.organisations.find(
						(org) => org.id === this.status.organisation,
					) || null
			} catch (error) {
				this.refusal = error.message
			} finally {
				this.loading = false
			}
		},

		/**
		 * The organisations that use applications: everything but suppliers.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-001-an-administrator-sets-up-the-exchange-without-stackiq-holding-a-credential
		 */
		async loadOrganisations() {
			this.loadingOrganisations = true
			this.organisationLoadError = ''
			try {
				const configResponse = await axios.get(
					generateUrl('/apps/stackiq/api/voorzieningen/config'),
				)
				const register = configResponse?.data?.config?.register
				const schema = configResponse?.data?.config?.organisatie_schema
				if (!register || !schema) {
					this.organisationLoadError = t(
						'stackiq',
						'The organisation register is not configured, so there are no organisations to choose from.',
					)
					return
				}
				const response = await axios.get(
					generateUrl(
						'/apps/openregister/api/objects/{register}/{schema}',
						{
							register,
							schema,
						},
					),
					{ params: { _limit: 500 } },
				)
				this.organisations = (response?.data?.results || [])
					.filter((org) => org.type !== 'Supplier')
					.map((org) => ({
						id: org['@self']?.id || org.id,
						name: org.name || org['@self']?.name || org.id,
					}))
			} catch {
				this.organisationLoadError = t(
					'stackiq',
					'The organisations could not be loaded. Reload the page to try again.',
				)
			} finally {
				this.loadingOrganisations = false
			}
		},

		/**
		 * Set up the exchange; show what the server refused, word for word.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-001-an-administrator-sets-up-the-exchange-without-stackiq-holding-a-credential
		 */
		async setUp() {
			this.saving = true
			this.refusal = ''
			try {
				const result = await apiRequest('itsm/setup', {
					method: 'POST',
					body: {
						desk: this.desk.id,
						organisation: this.organisation.id,
						templateId: this.templateId,
					},
				})
				showSuccess(
					t(
						'stackiq',
						'{count} flows are set up. The first import runs tonight.',
						{ count: Object.keys(result.flows || {}).length },
					),
				)
				this.status = await apiRequest('itsm/config')
			} catch (error) {
				this.refusal = error.message
			} finally {
				this.saving = false
			}
		},
	},
})
</script>

<style scoped>
.itsm-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 560px;
}

.help-text {
	color: var(--color-text-maxcontrast);
}
</style>
