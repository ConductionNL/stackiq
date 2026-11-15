<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
 The table "What comparable organisations use" of the GEMMA plate: per
 reference component the organisation's own applications and the
 applications comparable organisations use with their counts. A count the
 endpoint holds back reads "fewer than 3"; no organisation is ever named.

 @spec openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-the-plate-shows-what-comparable-organisations-use-req-gpl-002
-->
<template>
	<section class="peer-usage" data-testid="gemma-plate-peers">
		<h3 class="peer-usage__title">
			{{ heading }}
		</h3>
		<p class="peer-usage__hint">
			{{
				t('stackiq', 'Comparable organisations: {count}', {
					count: organisations,
				})
			}}
		</p>
		<table class="peer-usage__table">
			<thead>
				<tr>
					<th scope="col">
						{{ t('stackiq', 'Reference component') }}
					</th>
					<th scope="col">
						{{ t('stackiq', 'Own applications') }}
					</th>
					<th scope="col">
						{{ t('stackiq', 'Comparable organisations use') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="component in components"
					:key="component.id"
					:data-component="component.id">
					<th scope="row">
						{{ component.name }}
					</th>
					<td class="peer-usage__own">
						{{ ownNames(component) }}
					</td>
					<td class="peer-usage__peers">
						<template v-if="peersOf(component).length">
							<span
								v-for="application in peersOf(component)"
								:key="application.moduleId"
								class="peer-usage__peer">
								{{ peerLabel(application) }}
							</span>
						</template>
						<template v-else>
							{{ t('stackiq', 'None') }}
						</template>
					</td>
				</tr>
			</tbody>
		</table>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { peerApplicationsOf, peerIndex } from '../../utils/peerUsage.js'
import { peerLabel } from './peerLabel.js'

/**
 * @class PeerUsageTable
 * @module Components
 * @copyright 2026 Conduction B.V.
 * @license EUPL-1.2
 *
 * @spec openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-the-plate-shows-what-comparable-organisations-use-req-gpl-002
 */
export default {
	name: 'PeerUsageTable',

	props: {
		/**
		 * The coverage rows on the plate, in plate order.
		 */
		components: {
			type: Array,
			required: true,
		},

		/**
		 * The answer of `GET /api/reference-component-coverage/peers`.
		 */
		peers: {
			type: Object,
			required: true,
		},
	},

	computed: {
		/**
		 * The peer applications by reference component.
		 *
		 * @return {Map<string, Array<object>>} The index.
		 * @spec openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-the-plate-shows-what-comparable-organisations-use-req-gpl-002
		 */
		index() {
			return peerIndex(this.peers)
		},

		/**
		 * How many comparable organisations the counts cover.
		 *
		 * @return {number} The organisations.
		 * @spec openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-the-plate-shows-what-comparable-organisations-use-req-gpl-002
		 */
		organisations() {
			return Number(this.peers?.comparable?.organisations || 0)
		},

		/**
		 * The heading, naming the organisation type and inhabitants band compared with.
		 *
		 * @return {string} The heading.
		 * @spec openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-the-plate-shows-what-comparable-organisations-use-req-gpl-002
		 */
		heading() {
			const type = String(this.peers?.comparable?.type || '')
			const band = this.bandLabel(this.peers?.comparable?.band)
			if (type && band) {
				return t('stackiq', 'What comparable organisations use: {type}, {band}', { type, band })
			}
			if (type) {
				return t('stackiq', 'What comparable organisations use: {type}', { type })
			}
			return t('stackiq', 'What comparable organisations use')
		},
	},

	methods: {
		t,
		peerLabel,

		/**
		 * The peer applications of a component.
		 *
		 * @param {object} component A coverage row.
		 * @return {Array<object>} The applications.
		 * @spec openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-the-plate-shows-what-comparable-organisations-use-req-gpl-002
		 */
		peersOf(component) {
			return peerApplicationsOf(this.index, component)
		},

		/**
		 * The organisation's own applications for a component.
		 *
		 * @param {object} component A coverage row.
		 * @return {string} The names.
		 * @spec openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-the-plate-shows-what-comparable-organisations-use-req-gpl-002
		 */
		ownNames(component) {
			const names = (component.applications || [])
				.map((app) => app.moduleName)
				.filter(Boolean)
			return names.length ? names.join(', ') : t('stackiq', 'No application')
		},

		/**
		 * An inhabitants band in words.
		 *
		 * @param {{min: number, max: number|null}|null} band The band.
		 * @return {string} The band, '' without one.
		 * @spec openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-the-plate-shows-what-comparable-organisations-use-req-gpl-002
		 */
		bandLabel(band) {
			if (!band) {
				return ''
			}
			const min = Number(band.min || 0).toLocaleString()
			if (band.max === null || band.max === undefined) {
				return t('stackiq', 'above {min} inhabitants', { min })
			}
			const max = Number(band.max).toLocaleString()
			if (!band.min) {
				return t('stackiq', 'under {max} inhabitants', { max })
			}
			return t('stackiq', '{min} to {max} inhabitants', { min, max })
		},
	},
}
</script>

<style scoped>
.peer-usage {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}

.peer-usage__title {
	margin: 0;
	font-size: 1rem;
}

.peer-usage__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.peer-usage__table {
	width: 100%;
	border-collapse: collapse;
}

.peer-usage__table th,
.peer-usage__table td {
	padding: calc(var(--default-grid-baseline, 4px) * 2);
	text-align: start;
	vertical-align: top;
	border-bottom: 1px solid var(--color-border);
}

.peer-usage__peer {
	display: block;
}
</style>
