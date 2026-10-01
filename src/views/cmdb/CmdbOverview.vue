<!--
 - @copyright Copyright (c) 2026 Conduction B.V. <info@conduction.nl>
 - @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 -
 - CMDB page (sharing-itsm-exchange, REQ-ITX-007): says what stackiq records
 - as a configuration management database and what it does not, links to the
 - lists that hold it, shows the service desk exchange, and takes a file
 - import from admins. The import endpoint is admin-gated server-side; the
 - isAdmin check here only hides a form a non-admin could not use.
 -->

<template>
	<div class="cmdb">
		<h2 class="cmdb-title">
			{{ t('stackiq', 'CMDB') }}
		</h2>
		<p class="cmdb-intro">
			{{
				t(
					'stackiq',
					'Stackiq is your configuration management database for applications. It records what your organisation runs, what it is made of, how it connects and what you pay for it.',
				)
			}}
		</p>

		<section class="cmdb-section" data-testid="cmdb-records">
			<h3>{{ t('stackiq', 'What stackiq records') }}</h3>
			<ul class="cmdb-links">
				<li v-for="item in records" :key="item.route">
					<router-link :to="{ name: item.route }">
						{{ item.label }}
					</router-link>
					<span class="cmdb-hint">{{ item.hint }}</span>
				</li>
			</ul>
		</section>

		<section class="cmdb-section" data-testid="cmdb-not-recorded">
			<h3>{{ t('stackiq', 'What stackiq does not record') }}</h3>
			<p>
				{{
					t(
						'stackiq',
						'Servers, laptops and network devices are not recorded here, and stackiq does not discover hardware on your network. Tickets and incidents stay in your service desk.',
					)
				}}
			</p>
		</section>

		<section class="cmdb-section" data-testid="cmdb-exchange">
			<h3>{{ t('stackiq', 'Service desk exchange') }}</h3>
			<NcLoadingIcon v-if="loading" :size="28" />
			<template v-else>
				<NcNoteCard v-if="status.enabled" type="success">
					{{
						t(
							'stackiq',
							'Stackiq and {desk} are in step. The import runs every night at 02:00, and stackiq sends its own changes straight away.',
							{
								desk: deskLabel,
							},
						)
					}}
				</NcNoteCard>
				<NcNoteCard v-else type="info">
					{{
						t(
							'stackiq',
							'No service desk is connected yet. An admin connects TOPdesk or ServiceNow in the admin settings.',
						)
					}}
				</NcNoteCard>
				<p>
					{{
						t(
							'stackiq',
							'The service desk owns the name, supplier, installed version and status of an application. Stackiq owns licences, contracts, BBN level, TIME class and publication. Each side only overwrites the fields it owns.',
						)
					}}
				</p>
				<NcButton v-if="isAdmin" :href="settingsUrl">
					{{
						status.enabled
							? t('stackiq', 'Change the exchange')
							: t('stackiq', 'Connect a service desk')
					}}
				</NcButton>
			</template>
		</section>

		<section v-if="isAdmin" class="cmdb-section" data-testid="cmdb-file-import">
			<h3>{{ t('stackiq', 'Import a file') }}</h3>
			<p>
				{{
					t(
						'stackiq',
						'No service desk API? Import a CSV or XLSX file with the columns recordId, name, supplierName, installedVersion and status. Importing the same file again updates the applications instead of adding them twice.',
					)
				}}
			</p>
			<form class="cmdb-import" @submit.prevent="importFile">
				<label for="cmdb-file">{{ t('stackiq', 'File to import') }}</label>
				<input
					id="cmdb-file"
					ref="file"
					type="file"
					accept=".csv,.xlsx"
					@change="chosen = $event.target.files.length > 0" />
				<NcButton
					type="submit"
					variant="primary"
					:disabled="!chosen || importing">
					<template #icon>
						<NcLoadingIcon v-if="importing" :size="20" />
						<Upload v-else :size="20" />
					</template>
					{{ t('stackiq', 'Import file') }}
				</NcButton>
			</form>
			<NcNoteCard v-if="importMessage" :type="importOk ? 'success' : 'error'">
				{{ importMessage }}
			</NcNoteCard>
		</section>
	</div>
</template>

<script>
import { getCurrentUser, getRequestToken } from '@nextcloud/auth'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { defineComponent } from 'vue'
import Upload from 'vue-material-design-icons/Upload.vue'

/**
 * The CMDB overview page.
 *
 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-007-the-cmdb-page-says-what-stackiq-is
 */
export default defineComponent({
	name: 'CmdbOverview',
	components: { NcButton, NcLoadingIcon, NcNoteCard, Upload },

	data() {
		return {
			loading: true,
			status: {},
			chosen: false,
			importing: false,
			importMessage: '',
			importOk: false,
		}
	},

	computed: {
		/**
		 * @return {Array<{route: string, label: string, hint: string}>} The lists that hold the CMDB.
		 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-007-the-cmdb-page-says-what-stackiq-is
		 */
		records() {
			return [
				{
					route: 'Gebruik',
					label: t('stackiq', 'Applications in use'),
					hint: t(
						'stackiq',
						'What your organisation runs, which version, and who owns it.',
					),
				},
				{
					route: 'Modules',
					label: t('stackiq', 'Applications and their components'),
					hint: t(
						'stackiq',
						'The products, from which supplier, and what they consist of.',
					),
				},
				{
					route: 'Koppelingen',
					label: t('stackiq', 'Connections'),
					hint: t(
						'stackiq',
						'Which application exchanges data with which.',
					),
				},
				{
					route: 'Contracten',
					label: t('stackiq', 'Licences and contracts'),
					hint: t(
						'stackiq',
						'What you bought, how many licences, until when and at what cost.',
					),
				},
			]
		},

		/**
		 * @return {boolean} Whether the reader is a Nextcloud admin.
		 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-007-the-cmdb-page-says-what-stackiq-is
		 */
		isAdmin() {
			return getCurrentUser()?.isAdmin === true
		},

		/**
		 * @return {string} The admin settings section of the exchange.
		 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-007-the-cmdb-page-says-what-stackiq-is
		 */
		settingsUrl() {
			return generateUrl('/settings/admin/stackiq') + '#section-itsm'
		},

		/**
		 * @return {string} The connected desk's name.
		 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-007-the-cmdb-page-says-what-stackiq-is
		 */
		deskLabel() {
			const found = (this.status.desks || []).find(
				(desk) => desk.id === this.status.desk,
			)
			return found ? found.label : String(this.status.desk || '')
		},
	},

	async mounted() {
		try {
			const response = await fetch(
				generateUrl('/apps/stackiq/api/itsm/status'),
				{
					headers: {
						Accept: 'application/json',
						requesttoken: getRequestToken(),
					},
				},
			)
			this.status = response.ok ? await response.json() : {}
		} finally {
			this.loading = false
		}
	},

	methods: {
		t,

		/**
		 * Send the chosen file and say what happened.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/sharing-itsm-exchange/specs/itsm-exchange/spec.md#requirement-req-itx-006-a-file-feeds-the-same-import
		 */
		async importFile() {
			const file = this.$refs.file.files[0]
			if (!file) {
				return
			}

			this.importing = true
			this.importMessage = ''
			try {
				const form = new FormData()
				form.append('file', file)
				const response = await fetch(
					generateUrl('/apps/stackiq/api/itsm/import'),
					{
						method: 'POST',
						headers: { requesttoken: getRequestToken() },
						body: form,
					},
				)
				const body = await response.json()
				this.importOk = response.ok && body.started === true
				this.importMessage = this.importOk
					? t(
							'stackiq',
							'The import of {rows} rows has started. The applications appear as the run goes.',
							{ rows: body.rows },
						)
					: body.message || t('stackiq', 'The import did not start.')
			} finally {
				this.importing = false
			}
		},
	},
})
</script>

<style scoped>
.cmdb {
	padding: 20px;
	max-width: 900px;
}

.cmdb-intro,
.cmdb-hint {
	color: var(--color-text-maxcontrast);
}

.cmdb-section {
	margin-top: 24px;
}

.cmdb-links {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.cmdb-links a {
	font-weight: bold;
	margin-right: 8px;
}

.cmdb-import {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 12px;
	margin-bottom: 12px;
}
</style>
