<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<!--
  Check a model file: the admin picks an exchange file and a mode, and reads
  what the round trip through stackiq loses, per category.

  @spec openspec/changes/architecture-round-trip-check/specs/archimate-round-trip-check/spec.md#requirement-req-art-004-the-admin-settings-page-shall-show-the-round-trip-report
-->
<template>
	<div class="round-trip-check">
		<h4>{{ t('stackiq', 'Check a model file') }}</h4>
		<p class="round-trip-check__hint">
			{{ t('stackiq', 'See what an exchange file loses on its way through the import and the export.') }}
		</p>

		<input
			type="file"
			accept=".xml,.archimate"
			data-testid="round-trip-file"
			:aria-label="t('stackiq', 'Model file to check')"
			@change="onFileChange">

		<div class="round-trip-check__modes" role="radiogroup" :aria-label="t('stackiq', 'Check mode')">
			<NcCheckboxRadioSwitch
				v-model="mode"
				type="radio"
				name="round-trip-mode"
				:value="modes.before">
				{{ t('stackiq', 'Before import (nothing is written)') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch
				v-model="mode"
				type="radio"
				name="round-trip-mode"
				:value="modes.against">
				{{ t('stackiq', 'Against the imported model') }}
			</NcCheckboxRadioSwitch>
		</div>

		<NcButton
			variant="secondary"
			:disabled="!file || checking"
			@click="runCheck">
			<template #icon>
				<NcLoadingIcon v-if="checking" :size="20" />
			</template>
			{{ checking ? t('stackiq', 'Checking…') : t('stackiq', 'Check') }}
		</NcButton>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<div v-if="report" class="round-trip-check__result" data-testid="round-trip-result">
			<NcNoteCard :type="summaryType">
				{{ summary }}
			</NcNoteCard>

			<table v-if="rows.length > 0" class="round-trip-check__table">
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
					<tr v-for="row in rows" :key="row.key" :data-category="row.key">
						<th scope="row">
							{{ row.label }}
						</th>
						<td>{{ row.source }}</td>
						<td>{{ row.result }}</td>
						<td>{{ row.missing }}</td>
						<td>{{ row.extra }}</td>
						<td>{{ row.changed }}</td>
					</tr>
				</tbody>
			</table>

			<details
				v-for="row in lossyRows"
				:key="'examples-' + row.key"
				class="round-trip-check__examples">
				<summary>{{ t('stackiq', 'Examples: {category}', { category: row.label }) }}</summary>
				<ul>
					<li v-for="example in row.examples" :key="example.kind + example.identifier">
						{{ exampleLine(example) }}
					</li>
				</ul>
			</details>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import {
	checkRoundTrip,
	MODE_AGAINST_IMPORTED,
	MODE_BEFORE_IMPORT,
	roundTripRows,
	roundTripSummary,
} from '../../../utils/archiMateRoundTrip.js'

export default {
	name: 'ArchiMateRoundTripCheck',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
	},

	data() {
		return {
			file: null,
			mode: MODE_BEFORE_IMPORT,
			modes: { before: MODE_BEFORE_IMPORT, against: MODE_AGAINST_IMPORTED },
			checking: false,
			report: null,
			error: null,
		}
	},

	computed: {
		rows() {
			return roundTripRows(this.report)
		},

		lossyRows() {
			return this.rows.filter((row) => row.lossy && row.examples.length > 0)
		},

		summary() {
			return roundTripSummary(this.report)
		},

		summaryType() {
			if (this.report?.imported === false) {
				return 'warning'
			}
			return this.rows.some((row) => row.lossy) ? 'warning' : 'success'
		},
	},

	methods: {
		t,

		/**
		 * Keep the picked file and drop an earlier result.
		 *
		 * @param {Event} event The change event of the file input.
		 * @return {void}
		 */
		onFileChange(event) {
			this.file = event.target.files?.[0] ?? null
			this.report = null
			this.error = null
		},

		/**
		 * Run the check on the picked file in the chosen mode.
		 *
		 * @return {Promise<void>}
		 */
		async runCheck() {
			if (!this.file) {
				return
			}
			this.checking = true
			this.error = null
			this.report = null
			try {
				this.report = await checkRoundTrip(this.file, this.mode)
			} catch (error) {
				this.error = error.message
			} finally {
				this.checking = false
			}
		},

		/**
		 * One example as a line: what happened, the identifier, the name and the changed fields.
		 *
		 * @param {object} example An example from the report.
		 * @return {string}
		 */
		exampleLine(example) {
			const kinds = {
				missing: t('stackiq', 'Missing'),
				extra: t('stackiq', 'Extra'),
				changed: t('stackiq', 'Changed'),
			}
			const name = example.name ? ` (${example.name})` : ''
			const fields = Array.isArray(example.fields) && example.fields.length > 0 ? `: ${example.fields.join(', ')}` : ''
			return `${kinds[example.kind] ?? example.kind}: ${example.identifier}${name}${fields}`
		},
	},
}
</script>

<style scoped>
.round-trip-check {
	margin-top: 24px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.round-trip-check__hint {
	color: var(--color-text-maxcontrast);
}

.round-trip-check__table {
	border-collapse: collapse;
	width: 100%;
}

.round-trip-check__table th,
.round-trip-check__table td {
	padding: 4px 8px;
	border-bottom: 1px solid var(--color-border);
	text-align: start;
}
</style>
