<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<div class="cmdb-import-mapping" data-testid="cmdb-import-mapping">
		<NcButton
			variant="tertiary"
			:aria-expanded="expanded ? 'true' : 'false'"
			aria-controls="cmdb-import-mapping-content"
			data-testid="cmdb-import-mapping-toggle"
			@click="expanded = !expanded">
			<template #icon>
				<ChevronUp v-if="expanded" :size="20" />
				<ChevronDown v-else :size="20" />
			</template>
			{{ t('stackiq', 'Mapping (read-only)') }}
		</NcButton>

		<div
			v-show="expanded"
			id="cmdb-import-mapping-content"
			class="cmdb-import-mapping__content">
			<p class="cmdb-import__help">
				{{
					t(
						'stackiq',
						'The mapping the next import runs: the sheets it reads and, per pack, which column of the export goes to which field. It is read from the files under lib/Settings/cmdb-import of the app on the server; a file changed there is overwritten by the next app update.',
					)
				}}
			</p>

			<div
				v-if="loading"
				class="cmdb-import-mapping__loading"
				data-testid="cmdb-import-mapping-loading">
				<NcLoadingIcon :size="20" />
				<span>{{ t('stackiq', 'Loading the mapping…') }}</span>
			</div>

			<!-- The error, never as HTML; the reason names a file and a validator rule -->
			<NcNoteCard
				v-else-if="errorView"
				type="error"
				:heading="t('stackiq', 'The mapping could not be loaded.')"
				data-testid="cmdb-import-mapping-error">
				<p v-if="errorView.reason">
					{{ errorView.reason }}
				</p>
				<p v-else-if="errorView.message">
					{{ errorView.message }}
				</p>
				<p class="cmdb-import__code">
					{{
						t(
							'stackiq',
							'Error code: {code}',
							{ code: errorView.code },
							asText,
						)
					}}
				</p>
			</NcNoteCard>

			<template v-else-if="mapping">
				<dl
					class="cmdb-import-mapping__profile"
					data-testid="cmdb-import-mapping-profile">
					<div v-for="fact in profileFacts" :key="fact.key">
						<dt>{{ fact.label }}</dt>
						<dd>{{ fact.value }}</dd>
					</div>
				</dl>

				<section
					v-for="pack in mapping.packs"
					:key="pack.target"
					class="cmdb-import-mapping__pack">
					<h4 class="cmdb-import__heading">
						{{ packTargetLabel(pack.target) }}
					</h4>
					<p class="cmdb-import__help">
						{{
							t(
								'stackiq',
								'{file}: {name}, version {version}',
								{
									file: pack.file,
									name: pack.name,
									version: pack.version,
								},
								asText,
							)
						}}
					</p>
					<p v-if="pack.description" class="cmdb-import__help">
						{{ pack.description }}
					</p>
					<CnDataTable
						:rows="mappingRows(pack)"
						:columns="columns"
						rowKey="key"
						:emptyText="t('stackiq', 'This pack maps no columns')"
						:data-testid="'cmdb-import-mapping-' + pack.target">
						<template #column-source="{ row }">
							<span data-testid="cmdb-import-mapping-source">{{
								row.source
							}}</span>
						</template>
						<template #column-target="{ row }">
							<code>{{ row.target }}</code>
						</template>
						<template #column-required="{ row }">
							{{
								row.required
									? t('stackiq', 'Yes')
									: t('stackiq', 'No')
							}}
						</template>
						<template #column-details="{ row }">
							<ul
								v-if="row.details.length > 0"
								class="cmdb-import-mapping__details">
								<li
									v-for="(line, index) in row.details"
									:key="index">
									{{ line }}
								</li>
							</ul>
							<span v-else>—</span>
						</template>
					</CnDataTable>
				</section>
			</template>
		</div>
	</div>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import {
	AS_TEXT,
	loadCmdbMapping,
	mappingRows,
	normaliseError,
	packTargetLabel,
} from '../../../utils/cmdbImport.js'

/**
 * The read-only "Mapping (read-only)" block of the "CMDB import" section.
 *
 * Reads the mapping the import uses once, when the section is created, and
 * hands it to the section (`loaded`) so the help text can name the sheets
 * from it. Every value is rendered as text; the error's reason names a file
 * and a validator rule, never a cell value. Nothing here edits a mapping.
 *
 * @spec openspec/changes/cmdb-import-mapping-view/specs/cmdb-export-import/spec.md#requirement-the-admin-settings-shall-show-the-mapping-the-import-uses-req-cmdb-020
 */
export default {
	name: 'CmdbImportMapping',

	components: {
		CnDataTable,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		ChevronDown,
		ChevronUp,
	},

	emits: ['loaded'],

	data() {
		return {
			expanded: false,
			loading: false,
			mapping: null,
			error: null,
			asText: AS_TEXT,
		}
	},

	computed: {
		/**
		 * The profile's settings an admin reads before the packs: the sheets
		 * and the key, name, required and date columns.
		 *
		 * @return {Array<{key: string, label: string, value: string}>} One line per setting
		 * @spec openspec/changes/cmdb-import-mapping-view/specs/cmdb-export-import/spec.md#requirement-the-admin-settings-shall-show-the-mapping-the-import-uses-req-cmdb-020
		 */
		profileFacts() {
			const profile = this.mapping?.profile || {}
			const list = (values) =>
				(Array.isArray(values) ? values : []).map(String).join(', ') || '—'
			const sheets = (Array.isArray(profile.sheets) ? profile.sheets : [])
				.map((sheet) => String(sheet?.name ?? sheet ?? ''))
				.filter((name) => name !== '')
			return [
				{
					key: 'sheets',
					label: t('stackiq', 'Sheets read'),
					value: list(sheets),
				},
				{
					key: 'keyColumn',
					label: t('stackiq', 'Match column'),
					value: String(profile.keyColumn ?? '—'),
				},
				{
					key: 'nameColumn',
					label: t('stackiq', 'Name column'),
					value: String(profile.nameColumn ?? '—'),
				},
				{
					key: 'requiredColumns',
					label: t('stackiq', 'Required columns'),
					value: list(profile.requiredColumns),
				},
				{
					key: 'dateColumns',
					label: t('stackiq', 'Date columns'),
					value: list(profile.dateColumns),
				},
			]
		},

		/**
		 * The words for the current error, if any.
		 *
		 * @return {{code: string, reason: string, message: string}|null} The code, the loader's reason and the server's message
		 * @spec openspec/changes/cmdb-import-mapping-view/specs/cmdb-export-import/spec.md#requirement-the-admin-settings-shall-show-the-mapping-the-import-uses-req-cmdb-020
		 */
		errorView() {
			if (!this.error) {
				return null
			}
			return {
				code: this.error.error,
				reason: String(this.error.details?.reason ?? ''),
				message: this.error.message || '',
			}
		},

		/**
		 * The columns of every pack's table.
		 *
		 * @return {Array<object>} The columns
		 * @spec openspec/changes/cmdb-import-mapping-view/specs/cmdb-export-import/spec.md#requirement-the-admin-settings-shall-show-the-mapping-the-import-uses-req-cmdb-020
		 */
		columns() {
			return [
				{ key: 'source', label: t('stackiq', 'Column in the export') },
				{ key: 'target', label: t('stackiq', 'Field') },
				{ key: 'required', label: t('stackiq', 'Required') },
				{ key: 'transform', label: t('stackiq', 'Transformation') },
				{ key: 'details', label: t('stackiq', 'Details') },
			]
		},
	},

	/**
	 * Read the mapping as soon as the section exists: the help text needs the
	 * sheet names before the block is opened.
	 *
	 * @spec openspec/changes/cmdb-import-mapping-view/specs/cmdb-export-import/spec.md#requirement-the-admin-settings-shall-show-the-mapping-the-import-uses-req-cmdb-020
	 */
	created() {
		this.loadMapping()
	},

	methods: {
		t,
		mappingRows,
		packTargetLabel,

		/**
		 * Read the mapping from the server and tell the section about it.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/cmdb-import-mapping-view/specs/cmdb-export-import/spec.md#requirement-the-admin-settings-shall-show-the-mapping-the-import-uses-req-cmdb-020
		 */
		async loadMapping() {
			this.loading = true
			this.error = null
			try {
				this.mapping = await loadCmdbMapping({ http: axios })
				this.$emit('loaded', this.mapping)
			} catch (error) {
				this.mapping = null
				this.error = normaliseError(error)
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.cmdb-import-mapping__content {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
	margin-top: 0.5rem;
}

.cmdb-import__help {
	margin: 0.25rem 0 0;
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
}

.cmdb-import__heading {
	margin: 1rem 0 0.5rem;
	font-weight: 600;
}

.cmdb-import__code {
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
}

.cmdb-import-mapping__loading {
	display: flex;
	align-items: center;
	gap: 0.5rem;
}

.cmdb-import-mapping__profile {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
	gap: 0.5rem 1rem;
	margin: 0;
}

.cmdb-import-mapping__profile dt {
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
}

.cmdb-import-mapping__profile dd {
	margin: 0;
	font-weight: 500;
	overflow-wrap: anywhere;
}

.cmdb-import-mapping__details {
	margin: 0;
	padding-inline-start: 1.25rem;
	list-style: disc;
}
</style>
