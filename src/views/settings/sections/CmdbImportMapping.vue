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
			{{ t('stackiq', 'Mapping') }}
		</NcButton>

		<div
			v-show="expanded"
			id="cmdb-import-mapping-content"
			class="cmdb-import-mapping__content">
			<p class="cmdb-import__help">
				{{
					t(
						'stackiq',
						'The mapping the next import runs: the sheets it reads and, for each kind of record, the pack that says which column of the export goes to which field.',
					)
				}}
			</p>
			<p
				v-if="mapping && mapping.openRegisterPacks === true"
				class="cmdb-import__help">
				{{
					t(
						'stackiq',
						'The packs are kept in OpenRegister, where you can view and edit them. A pack deleted there is restored from the shipped file at the next import.',
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

			<!-- The error, never as HTML; the reason names a file or an OpenRegister pack and the rule it breaks -->
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
				<NcNoteCard
					v-if="mapping.openRegisterPacks === false"
					type="info"
					data-testid="cmdb-import-mapping-no-store">
					<p>
						{{
							t(
								'stackiq',
								"OpenRegister's pack store is not available, so the import uses the mapping shipped with stackiq. Update or enable OpenRegister to view and edit the mapping there.",
							)
						}}
					</p>
				</NcNoteCard>

				<dl
					class="cmdb-import-mapping__profile"
					data-testid="cmdb-import-mapping-profile">
					<div v-for="fact in profileFacts" :key="fact.key">
						<dt>{{ fact.label }}</dt>
						<dd>{{ fact.value }}</dd>
					</div>
				</dl>

				<ul class="cmdb-import-mapping__packs">
					<li
						v-for="pack in mapping.packs"
						:key="pack.target"
						class="cmdb-import-mapping__pack"
						:data-testid="'cmdb-import-mapping-' + pack.target">
						<div class="cmdb-import-mapping__line">
							<span class="cmdb-import-mapping__target">
								{{ packTargetLabel(pack.target) }}
							</span>
							<CnStatusBadge
								:label="packSourceLabel(pack.source)"
								:variant="
									pack.source === 'openregister'
										? 'primary'
										: 'default'
								"
								size="small"
								data-testid="cmdb-import-mapping-source" />
							<span
								v-if="packVersionText(pack)"
								class="cmdb-import__help"
								data-testid="cmdb-import-mapping-version">
								{{ packVersionText(pack) }}
							</span>
							<CnStatusBadge
								v-if="pack.edited === true"
								:label="t('stackiq', 'Edited')"
								variant="info"
								size="small"
								data-testid="cmdb-import-mapping-edited" />
							<NcButton
								v-if="packEditUrl(pack)"
								variant="secondary"
								:href="packEditUrl(pack)"
								target="_blank"
								:aria-label="
									t(
										'stackiq',
										'Edit in OpenRegister: {target} (opens in a new tab)',
										{ target: packTargetLabel(pack.target) },
										asText,
									)
								"
								data-testid="cmdb-import-mapping-edit">
								<template #icon>
									<OpenInNew :size="20" />
								</template>
								{{ t('stackiq', 'Edit in OpenRegister') }}
							</NcButton>
						</div>
						<p
							v-if="pack.target === 'municipality'"
							class="cmdb-import__help"
							data-testid="cmdb-import-mapping-not-editable">
							{{
								t(
									'stackiq',
									'This pack maps the import options, not a column of the export, so it is not edited in OpenRegister.',
								)
							}}
						</p>
						<p
							v-if="pack.newerShipped === true"
							class="cmdb-import__help"
							data-testid="cmdb-import-mapping-newer">
							{{
								t(
									'stackiq',
									'Stackiq ships a newer version of this pack ({version}). It was not applied, because the pack was edited in OpenRegister. Delete the pack there to use the shipped version from the next import on; your edits are then lost.',
									{ version: String(pack.shippedVersion ?? '') },
									asText,
								)
							}}
						</p>
					</li>
				</ul>
			</template>
		</div>
	</div>
</template>

<script>
import { CnStatusBadge } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import {
	AS_TEXT,
	loadCmdbMapping,
	normaliseError,
	packEditUrl,
	packSourceLabel,
	packTargetLabel,
	packVersionText,
} from '../../../utils/cmdbImport.js'

/**
 * The "Mapping" block of the "CMDB import" section.
 *
 * Reads the mapping the import uses once, when the section is created, and
 * hands it to the section (`loaded`) so the help text can name the sheets
 * from it. Per pack it shows one line: where the import takes the pack from
 * (OpenRegister's pack store or the shipped file), its version, whether it
 * was edited or a newer version ships, and a link to OpenRegister's editor.
 * Nothing here edits a mapping; that happens in OpenRegister. Every value is
 * rendered as text; the error's reason names a file or a pack and the rule
 * it breaks, never a cell value.
 *
 * @spec openspec/changes/cmdb-import-mapping-view/specs/cmdb-export-import/spec.md#requirement-the-admin-settings-shall-show-the-mapping-the-import-uses-req-cmdb-020
 */
export default {
	name: 'CmdbImportMapping',

	components: {
		CnStatusBadge,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		ChevronDown,
		ChevronUp,
		OpenInNew,
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
		packEditUrl,
		packSourceLabel,
		packTargetLabel,
		packVersionText,

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

.cmdb-import-mapping__packs {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
	margin: 0;
	padding: 0;
	list-style: none;
}

.cmdb-import-mapping__line {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0.5rem;
}

.cmdb-import-mapping__line .cmdb-import__help {
	margin: 0;
}

.cmdb-import-mapping__target {
	font-weight: 600;
}
</style>
