<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<div class="round-trip-check" data-test="round-trip-check">
		<h4>{{ t('stackiq', 'Check a model file') }}</h4>
		<p class="round-trip-check__hint">
			{{ t('stackiq', 'Checks that a model survives the import and export without losing or changing anything. A large file takes as long as an import.') }}
		</p>
		<input
			id="round-trip-check-file"
			type="file"
			accept=".xml,.archimate"
			:disabled="checking"
			data-test="round-trip-file"
			@change="onFile">
		<label for="round-trip-check-file" class="round-trip-check__file">
			{{ file ? file.name : t('stackiq', 'Choose ArchiMate XML file') }}
		</label>
		<fieldset class="round-trip-check__modes">
			<legend>{{ t('stackiq', 'Check mode') }}</legend>
			<label data-test="round-trip-mode-before">
				<input v-model="mode" type="radio" name="round-trip-mode" value="before-import">
				{{ t('stackiq', 'Before import (nothing is written)') }}
			</label>
			<label data-test="round-trip-mode-against">
				<input v-model="mode" type="radio" name="round-trip-mode" value="against-imported">
				{{ t('stackiq', 'Against the imported model') }}
			</label>
		</fieldset>
		<NcButton
			variant="primary"
			:disabled="!file || checking"
			data-test="round-trip-check-button"
			@click="check">
			<template #icon>
				<NcLoadingIcon v-if="checking" :size="20" />
			</template>
			{{ t('stackiq', 'Check') }}
		</NcButton>

		<NcNoteCard v-if="error" type="error" data-test="round-trip-error">
			{{ error }}
		</NcNoteCard>
		<NcNoteCard v-else-if="result && result.imported === false" type="warning" data-test="round-trip-not-imported">
			{{ t('stackiq', 'The model is not imported') }}
		</NcNoteCard>

		<div v-else-if="result" class="round-trip-check__result" data-test="round-trip-result">
			<p class="round-trip-check__summary" data-test="round-trip-summary">
				{{ summary }}
			</p>
			<table class="round-trip-check__table">
				<thead>
					<tr>
						<th scope="col">
							{{ t('stackiq', 'Category') }}
						</th>
						<th scope="col">
							{{ t('stackiq', 'In the file') }}
						</th>
						<th scope="col">
							{{ t('stackiq', 'After the round trip') }}
						</th>
						<th scope="col">
							{{ t('stackiq', 'Missing') }}
						</th>
						<th scope="col">
							{{ t('stackiq', 'Extra') }}
						</th>
						<th scope="col">
							{{ t('stackiq', 'Changed') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<template v-for="row in rows" :key="row.key">
						<tr :data-test="`round-trip-row-${row.key}`">
							<th scope="row">
								<button
									v-if="row.examples.length > 0"
									type="button"
									class="round-trip-check__toggle"
									:aria-expanded="String(open === row.key)"
									@click="open = open === row.key ? '' : row.key">
									{{ row.label }}
								</button>
								<span v-else>{{ row.label }}</span>
							</th>
							<td>{{ row.source }}</td>
							<td>{{ row.result }}</td>
							<td>{{ row.missing }}</td>
							<td>{{ row.extra }}</td>
							<td>{{ row.changed }}</td>
						</tr>
						<tr v-if="open === row.key" :data-test="`round-trip-examples-${row.key}`">
							<td colspan="6">
								<ul class="round-trip-check__examples">
									<li v-for="example in row.examples" :key="`${example.kind}-${example.identifier}`">
										<strong>{{ kindLabel(example.kind) }}</strong>
										{{ example.identifier }}<template v-if="example.name">
											({{ example.name }})
										</template>
										<template v-if="example.fields && example.fields.length">
											: {{ example.fields.join(', ') }}
										</template>
									</li>
								</ul>
							</td>
						</tr>
					</template>
				</tbody>
			</table>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { settingsStore } from '../../../store/store.js'

/** The categories in report order, with their labels. */
export const CATEGORIES = [
	['elements', 'Elements'],
	['relationships', 'Relationships'],
	['views', 'Views'],
	['viewNodes', 'View nodes'],
	['propertyDefinitions', 'Property definitions'],
	['organizations', 'Organizations'],
]

/**
 * The examples of one category as a flat list of `{kind, identifier, name, fields}`,
 * whatever shape the comparator gave them (a list per kind, or one list with a kind).
 *
 * @param {object} category One category of the report.
 * @return {Array<object>}
 */
export function examplesOf(category) {
	const examples = category?.examples ?? {}
	if (Array.isArray(examples)) {
		return examples
	}
	return ['missing', 'extra', 'changed'].flatMap((kind) => (examples[kind] ?? []).map((example) => ({
		kind,
		...(typeof example === 'object' && example !== null ? example : { identifier: String(example) }),
	})))
}

/**
 * ArchiMateRoundTripCheck — "Check a model file" on the admin settings page
 * (architecture-round-trip-check REQ-ART-004): a file picker, the two modes,
 * a Check button, and the report as a table with a row per category, the
 * examples expandable per category, and a summary that reads "No losses
 * found" or names the categories with losses. Every state is written as
 * text, not shown by colour alone.
 *
 * @spec openspec/changes/architecture-round-trip-check/specs/archimate-round-trip-check/spec.md#requirement-req-art-004-the-admin-settings-page-shall-show-the-round-trip-report
 */
export default {
	name: 'ArchiMateRoundTripCheck',

	components: { NcButton, NcLoadingIcon, NcNoteCard },

	data() {
		return {
			file: null,
			mode: 'before-import',
			checking: false,
			result: null,
			error: '',
			open: '',
		}
	},

	computed: {
		/** @return {Array<object>} One row per category. */
		rows() {
			const categories = this.result?.categories ?? {}
			return CATEGORIES.filter(([key]) => categories[key]).map(([key, label]) => ({
				key,
				label: t('stackiq', label),
				source: categories[key].source ?? 0,
				result: categories[key].result ?? 0,
				missing: categories[key].missing ?? 0,
				extra: categories[key].extra ?? 0,
				changed: categories[key].changed ?? 0,
				examples: examplesOf(categories[key]),
			}))
		},

		/** @return {string} "No losses found", or the categories with losses. */
		summary() {
			const lossy = this.rows.filter((row) => row.missing + row.extra + row.changed > 0).map((row) => row.label)
			return lossy.length === 0
				? t('stackiq', 'No losses found')
				: t('stackiq', 'Losses in: {categories}', { categories: lossy.join(', ') })
		},
	},

	methods: {
		t,

		/**
		 * @param {Event} event The file input change.
		 * @spec openspec/changes/architecture-round-trip-check/tasks.md#task-5
		 */
		onFile(event) {
			this.file = event?.target?.files?.[0] ?? null
			this.result = null
			this.error = ''
		},

		/**
		 * @param {string} kind `missing`, `extra` or `changed`.
		 * @return {string}
		 */
		kindLabel(kind) {
			return { missing: t('stackiq', 'Missing'), extra: t('stackiq', 'Extra'), changed: t('stackiq', 'Changed') }[kind] ?? kind
		},

		/** @spec openspec/changes/architecture-round-trip-check/tasks.md#task-5 */
		async check() {
			if (!this.file) {
				return
			}
			this.checking = true
			this.error = ''
			this.result = null
			this.open = ''
			try {
				this.result = await settingsStore.checkRoundTrip(this.file, this.mode)
			} catch (error) {
				this.error = error?.message || t('stackiq', 'The round-trip check failed')
			} finally {
				this.checking = false
			}
		},
	},
}
</script>

<style scoped>
.round-trip-check {
	display: flex;
	flex-direction: column;
	gap: 12px;
	margin-top: 24px;
}

.round-trip-check__hint {
	color: var(--color-text-maxcontrast);
}

.round-trip-check__modes {
	border: none;
	padding: 0;
	display: flex;
	flex-wrap: wrap;
	gap: 8px 16px;
}

.round-trip-check__table {
	width: 100%;
	border-collapse: collapse;
}

.round-trip-check__table th,
.round-trip-check__table td {
	padding: 4px 8px;
	border-bottom: 1px solid var(--color-border);
	text-align: start;
}

.round-trip-check__toggle {
	background: none;
	border: none;
	padding: 0;
	color: var(--color-primary-element);
	text-decoration: underline;
	cursor: pointer;
}
</style>
