<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -
  - Move to organisation: the dialog a list page's bulk action opens with the
  - selected entries (src/services/moveToOrganisation.js). The entries move
  - from the active organisation to the one picked here. "Preview" asks the
  - plan endpoint what moves, what is skipped and why, and which linked
  - entries stay behind with the source organisation; "Move" carries out the
  - entries the preview moves. The server decides who may move (an
  - administrator of both organisations) and answers anyone else with a
  - refusal, which the dialog shows.
  -
  - @spec openspec/changes/archive/2026-11-25-landscape-move-between-organisations/specs/move-between-organisations/spec.md#requirement-req-mbo-003-the-dry-run-shows-what-moves-and-what-stays-behind
  -->

<template>
	<NcDialog
		:name="t('stackiq', 'Move to organisation')"
		size="normal"
		@closing="close">
		<div class="move-to-organisation">
			<p class="move-to-organisation__intro">
				{{ n('stackiq', 'Move {count} entry from {source} to another organisation.', 'Move {count} entries from {source} to another organisation.', ids.length, { count: ids.length, source: sourceName }) }}
			</p>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcNoteCard v-if="movedCount !== null" type="success">
				{{ n('stackiq', '{count} entry moved.', '{count} entries moved.', movedCount, { count: movedCount }) }}
			</NcNoteCard>

			<NcSelect
				v-model="target"
				class="move-to-organisation__target"
				:inputLabel="t('stackiq', 'Target organisation')"
				:placeholder="t('stackiq', 'Select the organisation to move to')"
				:options="targetOptions"
				:reduce="(option) => option.value"
				:disabled="busy || movedCount !== null"
				@update:modelValue="plan = null" />

			<template v-if="plan">
				<section class="move-to-organisation__group">
					<h3>{{ t('stackiq', 'Moving') }}</h3>
					<ul v-if="split.moving.length > 0">
						<li v-for="item in split.moving" :key="'move-' + item.id">
							{{ entryLabel(item) }}
						</li>
					</ul>
					<p v-else class="move-to-organisation__empty">
						{{ t('stackiq', 'Nothing moves.') }}
					</p>
				</section>

				<section v-if="split.skipped.length > 0" class="move-to-organisation__group">
					<h3>{{ t('stackiq', 'Skipped') }}</h3>
					<ul>
						<li v-for="item in split.skipped" :key="'skip-' + item.id">
							{{ entryLabel(item) }}
							<span class="move-to-organisation__reason">— {{ reasonText(item.reason) }}</span>
						</li>
					</ul>
				</section>

				<section v-if="split.staysBehind.length > 0" class="move-to-organisation__group">
					<h3>{{ t('stackiq', 'Staying with {source}', { source: sourceName }) }}</h3>
					<ul>
						<li v-for="item in split.staysBehind" :key="'stay-' + item.schema + '-' + item.id">
							{{ entryLabel(item) }}
						</li>
					</ul>
				</section>
			</template>
		</div>

		<template #actions>
			<NcButton @click="close">
				{{ movedCount !== null ? t('stackiq', 'Close') : t('stackiq', 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="movedCount === null"
				:disabled="!canPreview"
				@click="preview">
				<template v-if="busy && !plan" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('stackiq', 'Preview') }}
			</NcButton>
			<NcButton
				v-if="movedCount === null"
				variant="primary"
				:disabled="!canMove"
				@click="move">
				<template v-if="busy && plan" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('stackiq', 'Move') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard, NcSelect } from '@nextcloud/vue'
import {
	entryLabel,
	splitPlan,
	targetOptions,
	transferBody,
} from '../services/moveToOrganisation.js'
import { navigationStore } from '../store/store.js'

/**
 * The reasons the plan endpoint gives for a skipped entry, translated.
 *
 * @return {Object<string, string>} Server reason to its translation.
 */
function reasonTexts() {
	return {
		'This kind of entry cannot move between organisations': t('stackiq', 'This kind of entry cannot move between organisations'),
		'The target organisation is unknown or the same as the source': t('stackiq', 'The target organisation is unknown or the same as the source'),
		'Not found': t('stackiq', 'Not found'),
		'Not owned by the source organisation': t('stackiq', 'Not owned by the source organisation'),
	}
}

export default {
	name: 'MoveToOrganisationDialog',

	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
	},

	emits: ['close', 'moved'],

	/**
	 * @spec exclude Pinia store wiring in setup() — bootstrap plumbing
	 */
	setup() {
		return { navigationStore }
	},

	data() {
		return {
			organisations: [],
			source: null,
			sourceName: '',
			target: null,
			plan: null,
			busy: false,
			error: '',
			movedCount: null,
		}
	},

	computed: {
		/**
		 * The schema of the chosen entries, from the dialog properties.
		 *
		 * @return {string} The schema slug.
		 */
		schema() {
			return this.navigationStore.dialogProperties?.schema || ''
		},

		/**
		 * The ids of the chosen entries, from the dialog properties.
		 *
		 * @return {Array<string>} The ids.
		 */
		ids() {
			const ids = this.navigationStore.dialogProperties?.ids
			return Array.isArray(ids) ? ids : []
		},

		/**
		 * The organisations the entries can move to.
		 *
		 * @return {Array<{value: string, label: string}>} The options.
		 */
		targetOptions() {
			return targetOptions(this.organisations, this.source)
		},

		/**
		 * The dry run, split into moving, skipped and staying behind.
		 *
		 * @return {{moving: Array, skipped: Array, staysBehind: Array}} The split.
		 */
		split() {
			return splitPlan(this.plan)
		},

		/**
		 * Whether a preview can be asked for.
		 *
		 * @return {boolean} Whether it can.
		 */
		canPreview() {
			return !this.busy && Boolean(this.source && this.target && this.schema && this.ids.length > 0)
		},

		/**
		 * Whether the previewed move can be carried out.
		 *
		 * @return {boolean} Whether it can.
		 */
		canMove() {
			return this.canPreview && this.plan !== null && this.split.moving.length > 0
		},
	},

	/**
	 * @spec openspec/changes/archive/2026-11-25-landscape-move-between-organisations
	 */
	mounted() {
		this.loadOrganisations()
	},

	methods: {
		t,
		n,
		entryLabel,

		/**
		 * Load the user's organisations and take the active one as the source.
		 *
		 * @return {Promise<void>} Resolves once loaded.
		 * @spec openspec/changes/archive/2026-11-25-landscape-move-between-organisations
		 */
		async loadOrganisations() {
			try {
				const { data } = await axios.get(generateUrl('/apps/stackiq/api/me'))
				this.organisations = data?.organisations?.all ?? []
				const active = data?.organisations?.active ?? null
				this.source = active?.uuid ?? null
				this.sourceName = active?.name || ''
				if (!this.source) {
					this.error = t('stackiq', 'Choose an active organisation first.')
				}
			} catch {
				this.error = t('stackiq', 'Could not load your organisations.')
			}
		},

		/**
		 * Ask the plan endpoint what would move.
		 *
		 * @return {Promise<void>} Resolves once the plan is in.
		 * @spec openspec/changes/archive/2026-11-25-landscape-move-between-organisations/specs/move-between-organisations/spec.md#requirement-req-mbo-003-the-dry-run-shows-what-moves-and-what-stays-behind
		 */
		async preview() {
			const plan = await this.request('plan')
			if (plan) {
				this.plan = plan
			}
		},

		/**
		 * Carry out the move and report how many entries moved.
		 *
		 * @return {Promise<void>} Resolves once moved.
		 * @spec openspec/changes/archive/2026-11-25-landscape-move-between-organisations/specs/move-between-organisations/spec.md#requirement-req-mbo-001-an-administrator-moves-chosen-entries-to-another-organisation-and-they-keep-their-identity
		 */
		async move() {
			const result = await this.request('execute')
			if (result) {
				this.plan = result
				this.movedCount = Number(result.moved ?? 0)
				this.$emit('moved', this.movedCount)
			}
		},

		/**
		 * Post the chosen entries to the plan or execute endpoint.
		 *
		 * @param {string} step plan or execute.
		 * @return {Promise<?object>} The answer, or null when refused or failed.
		 * @spec openspec/changes/archive/2026-11-25-landscape-move-between-organisations/specs/move-between-organisations/spec.md#requirement-req-mbo-002-only-an-administrator-of-both-organisations-moves-entries
		 */
		async request(step) {
			this.busy = true
			this.error = ''
			try {
				const { data } = await axios.post(
					generateUrl('/apps/stackiq/api/ownership-transfers/' + step),
					transferBody(this.schema, this.ids, this.source, this.target),
				)
				return data
			} catch (e) {
				this.error = e?.response?.status === 403
					? t('stackiq', 'Only an administrator of both organisations may move entries.')
					: t('stackiq', 'The move could not be planned or carried out.')
				return null
			} finally {
				this.busy = false
			}
		},

		/**
		 * The translation of a skip reason; an unknown one shows as given.
		 *
		 * @param {?string} reason The server's reason.
		 * @return {string} The text.
		 */
		reasonText(reason) {
			return reasonTexts()[reason] ?? String(reason ?? '')
		},

		/**
		 * Close the dialog.
		 *
		 * @return {void}
		 */
		close() {
			this.navigationStore.setDialog(false)
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.move-to-organisation {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.move-to-organisation__target {
	width: 100%;
}

.move-to-organisation__group h3 {
	margin: 0 0 var(--default-grid-baseline);
	font-size: var(--default-font-size);
	font-weight: bold;
}

.move-to-organisation__group ul {
	margin: 0;
	padding-inline-start: calc(var(--default-grid-baseline) * 5);
	list-style: disc;
}

.move-to-organisation__reason,
.move-to-organisation__empty {
	color: var(--color-text-maxcontrast);
}
</style>
