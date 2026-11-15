<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
 Custom reports (insight-exports-and-custom-reports): the caller's own
 OpenRegister export profiles over the catalogue register. A report is
 defined over one catalogue schema, run as a download from OpenRegister, and
 deleted. OpenRegister owns the profiles, the export right and the file.

 @spec openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-004-a-user-shall-define-run-and-delete-their-own-reports-over-one-catalogue-schema
-->
<template>
	<div class="custom-reports">
		<header class="custom-reports__header">
			<h2 class="custom-reports__title">
				{{ title || t('stackiq', 'Custom reports') }}
			</h2>
			<p class="custom-reports__intro">
				{{ description || t('stackiq', 'Your own reports over the catalogue: pick the fields, run them as a file.') }}
			</p>
		</header>

		<div class="custom-reports__new">
			<NcSelect
				v-model="newSchema"
				class="custom-reports__schema"
				data-testid="custom-reports-schema"
				:options="schemaOptions"
				:inputLabel="t('stackiq', 'Report over')"
				:placeholder="t('stackiq', 'Pick a catalogue list')"
				label="label"
				trackBy="slug" />
			<NcButton
				variant="primary"
				data-testid="custom-reports-new"
				:disabled="!newSchemaObject"
				@click="dialogOpen = true">
				{{ t('stackiq', 'New report') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="44" />

		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<NcEmptyContent
			v-else-if="reports.length === 0"
			:name="t('stackiq', 'No reports yet')"
			:description="t('stackiq', 'Pick a catalogue list above and define your first report.')" />

		<table v-else class="custom-reports__table" data-testid="custom-reports-list">
			<thead>
				<tr>
					<th>{{ t('stackiq', 'Name') }}</th>
					<th>{{ t('stackiq', 'Report over') }}</th>
					<th>{{ t('stackiq', 'Format') }}</th>
					<th>
						<span class="hidden-visually">{{ t('stackiq', 'Actions') }}</span>
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="report in reports" :key="report.id" data-testid="custom-report-row">
					<td>{{ report.name }}</td>
					<td>{{ schemaLabelOf(report.schemaId) }}</td>
					<td>{{ String(report.format || 'csv').toUpperCase() }}</td>
					<td class="custom-reports__actions">
						<NcButton
							variant="secondary"
							:href="runUrlOf(report)"
							download
							data-testid="custom-report-run">
							{{ t('stackiq', 'Run') }}
						</NcButton>
						<NcButton
							variant="tertiary"
							data-testid="custom-report-delete"
							:aria-label="t('stackiq', 'Delete {name}', { name: report.name })"
							@click="remove(report)">
							{{ t('stackiq', 'Delete') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<CustomReportDialog
			v-if="dialogOpen && newSchemaObject"
			:registerId="register"
			:schema="newSchemaObject"
			:schemaLabel="newSchema ? newSchema.label : ''"
			@created="onCreated"
			@close="dialogOpen = false" />
	</div>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard, NcSelect } from '@nextcloud/vue'
import CustomReportDialog from '../../modals/reports/CustomReportDialog.vue'
import { ownReports, profilesUrl, profileUrl, rowsOf, runUrl } from './customReports.js'

export default {
	name: 'CustomReportsView',

	components: { CustomReportDialog, NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard, NcSelect },

	props: {
		/** The catalogue register's id (manifest `@resolve:voorzieningen_register`). */
		register: { type: [String, Number], required: true },
		/** The catalogue schemas a report may read: `[{slug, label}]`. */
		schemas: { type: Array, default: () => [] },
		/** Page title (manifest `pages[].title`). */
		title: { type: String, default: '' },
		/** Page description. */
		description: { type: String, default: '' },
	},

	data() {
		return {
			loading: true,
			error: '',
			profiles: [],
			schemaObjects: [],
			newSchema: null,
			dialogOpen: false,
		}
	},

	computed: {
		/**
		 * @return {object[]} The caller's own catalogue reports.
		 */
		reports() {
			return ownReports(this.profiles, { userId: getCurrentUser()?.uid ?? '', registerId: this.register })
		},

		/**
		 * @return {Array<{slug: string, label: string}>} The catalogue schemas OpenRegister knows.
		 */
		schemaOptions() {
			const known = new Set(this.schemaObjects.map((schema) => schema.slug))
			return this.schemas
				.filter((schema) => known.has(schema.slug))
				.map((schema) => ({ slug: schema.slug, label: t('stackiq', schema.label) }))
		},

		/**
		 * @return {object|null} The OpenRegister schema picked for a new report.
		 */
		newSchemaObject() {
			return this.schemaObjects.find((schema) => schema.slug === this.newSchema?.slug) ?? null
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Read the caller's profiles and the catalogue register's schemas.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-004-a-user-shall-define-run-and-delete-their-own-reports-over-one-catalogue-schema
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const [profiles, schemas] = await Promise.all([
					axios.get(profilesUrl(generateUrl)),
					axios.get(generateUrl('/apps/openregister/api/schemas'), { params: { register: this.register, _limit: 200 } }),
				])
				this.profiles = rowsOf(profiles.data)
				this.schemaObjects = rowsOf(schemas.data)
			} catch {
				this.error = t('stackiq', 'The reports could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * The page's name of the schema a report reads.
		 *
		 * @param {string|number} schemaId The schema id.
		 * @return {string} Its label, or '' when it is not a catalogue schema.
		 */
		schemaLabelOf(schemaId) {
			const schema = this.schemaObjects.find((candidate) => String(candidate.id) === String(schemaId))
			const option = this.schemas.find((candidate) => candidate.slug === schema?.slug)
			return option ? t('stackiq', option.label) : (schema?.title ?? '')
		},

		/**
		 * @param {object} report The report.
		 * @return {string} The URL that runs it.
		 */
		runUrlOf(report) {
			return runUrl(generateUrl, report.id)
		},

		/**
		 * List a report the dialog just saved.
		 *
		 * @param {object} profile The stored profile.
		 * @return {void}
		 */
		onCreated(profile) {
			if (profile && profile.id !== undefined) {
				this.profiles = [...this.profiles.filter((existing) => existing.id !== profile.id), profile]
			}
		},

		/**
		 * Delete a report.
		 *
		 * @param {object} report The report.
		 * @return {Promise<void>}
		 * @spec openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-004-a-user-shall-define-run-and-delete-their-own-reports-over-one-catalogue-schema
		 */
		async remove(report) {
			this.error = ''
			try {
				await axios.delete(profileUrl(generateUrl, report.id))
				this.profiles = this.profiles.filter((profile) => profile.id !== report.id)
			} catch {
				this.error = t('stackiq', 'The report could not be deleted.')
			}
		},
	},
}
</script>

<style scoped>
.custom-reports {
	padding: calc(var(--default-grid-baseline) * 4);
}

.custom-reports__new {
	display: flex;
	align-items: flex-end;
	gap: calc(var(--default-grid-baseline) * 2);
	margin-block: calc(var(--default-grid-baseline) * 4);
}

.custom-reports__schema {
	min-width: 260px;
}

.custom-reports__table {
	width: 100%;
	border-collapse: collapse;
}

.custom-reports__table th,
.custom-reports__table td {
	padding: calc(var(--default-grid-baseline) * 2);
	border-bottom: 1px solid var(--color-border);
	text-align: start;
}

.custom-reports__actions {
	display: flex;
	gap: var(--default-grid-baseline);
	justify-content: flex-end;
}
</style>
