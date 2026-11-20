<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -
  - Assistant actions (insight-assistant-answers-and-action-trail D3): every
  - assistant tool call on catalogue records, read from OpenRegister's audit
  - trail with the action prefix `mcp.` and the tool prefix `stackiq.`,
  - filtered on tool, user and period and paged by OpenRegister. Stackiq keeps
  - no copy; who may read the trail is OpenRegister's rule.
  -->
<template>
	<div class="assistantActionsView">
		<h2 class="aa-title">
			{{ t('stackiq', 'Assistant actions') }}
		</h2>
		<p class="aa-intro">
			{{ t('stackiq', 'What assistants did on catalogue records through stackiq\'s tools, read from the OpenRegister audit trail.') }}
		</p>

		<form class="aa-filters" data-testid="aa-filters" @submit.prevent="load(true)">
			<NcTextField
				v-model="filters.tool"
				:label="t('stackiq', 'Tool')"
				data-testid="aa-filter-tool" />
			<NcTextField
				v-model="filters.user"
				:label="t('stackiq', 'User')"
				data-testid="aa-filter-user" />
			<NcTextField
				v-model="filters.from"
				type="date"
				:label="t('stackiq', 'From')"
				data-testid="aa-filter-from" />
			<NcTextField
				v-model="filters.to"
				type="date"
				:label="t('stackiq', 'To')"
				data-testid="aa-filter-to" />
			<NcButton type="submit" :disabled="loading" data-testid="aa-apply">
				{{ t('stackiq', 'Apply filters') }}
			</NcButton>
		</form>

		<div aria-live="polite">
			<NcNoteCard v-if="failure" type="error" data-testid="aa-failure">
				{{ failure }}
			</NcNoteCard>

			<p v-else-if="!loading && rows.length === 0" class="aa-muted" data-testid="aa-empty">
				{{ t('stackiq', 'No assistant actions match these filters.') }}
			</p>

			<table v-if="rows.length > 0" class="aa-table" data-testid="aa-table">
				<thead>
					<tr>
						<th scope="col">
							{{ t('stackiq', 'When') }}
						</th>
						<th scope="col">
							{{ t('stackiq', 'User') }}
						</th>
						<th scope="col">
							{{ t('stackiq', 'Tool') }}
						</th>
						<th scope="col">
							{{ t('stackiq', 'Record') }}
						</th>
						<th scope="col">
							{{ t('stackiq', 'Outcome') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in rows" :key="row.id" data-testid="aa-row">
						<td>{{ formatWhen(row.when) }}</td>
						<td>{{ row.user }}</td>
						<td><code>{{ row.tool }}</code></td>
						<td>
							<router-link v-if="locationOf(row)" :to="locationOf(row)">
								{{ row.recordId }}
							</router-link>
							<span v-else>{{ row.recordId || '—' }}</span>
						</td>
						<td>
							<span v-if="row.outcome.ok">{{ t('stackiq', 'Succeeded') }}</span>
							<span v-else class="aa-failed">
								{{ row.outcome.error ? t('stackiq', 'Failed: {error}', { error: row.outcome.error }) : t('stackiq', 'Failed') }}
							</span>
						</td>
					</tr>
				</tbody>
			</table>

			<NcLoadingIcon v-if="loading" :size="32" />

			<NcButton
				v-if="!loading && nextCursor !== null"
				class="aa-more"
				data-testid="aa-more"
				@click="load(false)">
				{{ t('stackiq', 'Load more') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard, NcTextField } from '@nextcloud/vue'
import manifest from '../manifest.json'
import {
	actionsFailure,
	actionsQuery,
	AUDIT_TRAIL_PATH,
	detailRoutes,
	readActionsPage,
	recordLocation,
} from '../utils/assistantActions.js'

export default {
	name: 'AssistantActionsView',
	components: { NcButton, NcLoadingIcon, NcNoteCard, NcTextField },
	data() {
		return {
			filters: { tool: '', user: '', from: '', to: '' },
			rows: [],
			nextCursor: null,
			loading: false,
			failure: '',
			schemaSlugs: {},
			routes: detailRoutes(manifest),
		}
	},

	mounted() {
		this.load(true)
	},

	methods: {
		t,

		/**
		 * Load the first page (fresh) or the next one.
		 *
		 * @param {boolean} fresh Whether to start over with the current filters.
		 * @return {Promise<void>}
		 */
		async load(fresh) {
			this.loading = true
			this.failure = ''
			try {
				const { data } = await axios.get(generateUrl(AUDIT_TRAIL_PATH), {
					params: actionsQuery(this.filters, fresh ? null : this.nextCursor),
				})
				const page = readActionsPage(data)
				this.rows = fresh ? page.rows : this.rows.concat(page.rows)
				this.nextCursor = page.nextCursor
				await this.resolveSchemas(page.rows)
			} catch (error) {
				if (fresh) {
					this.rows = []
				}
				this.nextCursor = null
				this.failure = actionsFailure(error, (text) => t('stackiq', text))
			} finally {
				this.loading = false
			}
		},

		/**
		 * Look up the slug of each schema the rows name that is not known yet,
		 * so a record links to its stackiq page. A schema that cannot be read
		 * leaves its records unlinked.
		 *
		 * @param {Array} rows The rows just loaded.
		 * @return {Promise<void>}
		 */
		async resolveSchemas(rows) {
			const missing = [...new Set(rows.map((row) => row.schemaId).filter((id) => id !== '' && !(id in this.schemaSlugs)))]
			const found = await Promise.all(missing.map(async (id) => {
				try {
					const { data } = await axios.get(generateUrl('/apps/openregister/api/schemas/{id}', { id }))
					return [id, String(data?.slug ?? '')]
				} catch {
					return [id, '']
				}
			}))
			this.schemaSlugs = { ...this.schemaSlugs, ...Object.fromEntries(found) }
		},

		locationOf(row) {
			return recordLocation(row, this.schemaSlugs, this.routes)
		},

		formatWhen(value) {
			const date = new Date(value)
			return Number.isNaN(date.getTime()) ? value : date.toLocaleString()
		},
	},
}
</script>

<style scoped>
.assistantActionsView {
	padding: calc(var(--default-grid-baseline) * 4);
}

.aa-intro,
.aa-muted {
	color: var(--color-text-maxcontrast);
}

.aa-filters {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: calc(var(--default-grid-baseline) * 2);
	margin-block: calc(var(--default-grid-baseline) * 4);
}

.aa-filters > * {
	flex: 1 1 180px;
	max-width: 260px;
}

.aa-table {
	width: 100%;
	border-collapse: collapse;
}

.aa-table th,
.aa-table td {
	padding: calc(var(--default-grid-baseline) * 2);
	border-bottom: 1px solid var(--color-border);
	text-align: start;
}

.aa-table a {
	color: var(--color-primary-element);
	text-decoration: underline;
}

.aa-failed {
	color: var(--color-error-text);
}

.aa-more {
	margin-top: calc(var(--default-grid-baseline) * 4);
}
</style>
