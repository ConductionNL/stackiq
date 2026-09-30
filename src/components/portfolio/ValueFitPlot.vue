<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<template>
	<figure class="value-fit" data-testid="pr-value-fit">
		<svg
			class="value-fit__svg"
			:viewBox="`0 0 ${width} ${height}`"
			role="img"
			:aria-label="ariaLabel">
			<rect
				v-for="quadrant in quadrants"
				:key="quadrant.key"
				:x="quadrant.x"
				:y="quadrant.y"
				:width="quadrant.width"
				:height="quadrant.height"
				class="value-fit__quadrant" />
			<text
				v-for="quadrant in quadrants"
				:key="`label-${quadrant.key}`"
				:x="quadrant.x + 8"
				:y="quadrant.y + 18"
				class="value-fit__quadrantLabel">
				{{ quadrantLabel(quadrant.key) }}
			</text>
			<g v-for="tick in ticks" :key="`tick-${tick}`">
				<text
					:x="scaleX(tick)"
					:y="height - pad.bottom + 18"
					text-anchor="middle"
					class="value-fit__tick">
					{{ tick }}
				</text>
				<text
					:x="pad.left - 10"
					:y="scaleY(tick) + 4"
					text-anchor="end"
					class="value-fit__tick">
					{{ tick }}
				</text>
			</g>
			<text
				:x="pad.left + plotWidth / 2"
				:y="height - 6"
				text-anchor="middle"
				class="value-fit__axis">
				{{ t('stackiq', 'Technical fit') }}
			</text>
			<text
				:x="14"
				:y="pad.top + plotHeight / 2"
				text-anchor="middle"
				:transform="`rotate(-90 14 ${pad.top + plotHeight / 2})`"
				class="value-fit__axis">
				{{ t('stackiq', 'Business value') }}
			</text>
			<circle
				v-for="point in plotted.points"
				:key="point.uuid"
				:cx="scaleX(point.fit + point.offset[0])"
				:cy="scaleY(point.value + point.offset[1])"
				:r="point.radius"
				:fill="quadrantColor(point.suggested || 'Unclassified')"
				:stroke="point.recorded && point.recorded !== point.suggested ? 'var(--color-main-text)' : 'none'"
				stroke-width="2"
				fill-opacity="0.75"
				data-testid="pr-value-fit-point">
				<title>{{ pointTitle(point) }}</title>
			</circle>
		</svg>
		<figcaption class="value-fit__caption">
			{{
				t(
					'stackiq',
					'Each circle is an application in use, its size the annualised cost. A dark ring marks a recorded TIME class that differs from the scores.',
				)
			}}
			<span v-if="plotted.notScored > 0" data-testid="pr-value-fit-not-scored">
				{{
					n(
						'stackiq',
						'%n application in use is not scored yet.',
						'%n applications in use are not scored yet.',
						plotted.notScored,
					)
				}}
			</span>
		</figcaption>
	</figure>
</template>

<script>
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { formatCurrency, quadrantColor } from '../../utils/portfolioReport.js'
import { valueFitPoints } from '../../utils/valueAssessment.js'

export default {
	name: 'ValueFitPlot',

	props: {
		rows: {
			type: Array,
			default: () => [],
		},
	},

	data() {
		return {
			width: 520,
			height: 400,
			pad: { top: 16, right: 24, bottom: 44, left: 48 },
			ticks: [1, 2, 3, 4, 5],
		}
	},

	computed: {
		/**
		 * @return {{points: Array<object>, notScored: number}} The plotted points.
		 * @spec openspec/changes/lifecycle-application-value-assessment/specs/application-value-assessment/spec.md#requirement-req-ava-003-the-portfolio-report-plots-value-against-fit-and-flags-classes-the-scores-contradict
		 */
		plotted() {
			return valueFitPoints(this.rows)
		},

		/**
		 * @return {number} The plot width inside the padding.
		 * @spec openspec/changes/lifecycle-application-value-assessment/specs/application-value-assessment/spec.md#requirement-req-ava-003-the-portfolio-report-plots-value-against-fit-and-flags-classes-the-scores-contradict
		 */
		plotWidth() {
			return this.width - this.pad.left - this.pad.right
		},

		/**
		 * @return {number} The plot height inside the padding.
		 * @spec openspec/changes/lifecycle-application-value-assessment/specs/application-value-assessment/spec.md#requirement-req-ava-003-the-portfolio-report-plots-value-against-fit-and-flags-classes-the-scores-contradict
		 */
		plotHeight() {
			return this.height - this.pad.top - this.pad.bottom
		},

		/**
		 * The four TIME areas, split between score 2 and 3.
		 *
		 * @return {Array<object>} The areas.
		 * @spec openspec/changes/lifecycle-application-value-assessment/specs/application-value-assessment/spec.md#requirement-req-ava-003-the-portfolio-report-plots-value-against-fit-and-flags-classes-the-scores-contradict
		 */
		quadrants() {
			const left = this.scaleX(0.5)
			const mid = this.scaleX(2.5)
			const right = this.scaleX(5.5)
			const top = this.scaleY(5.5)
			const middle = this.scaleY(2.5)
			const bottom = this.scaleY(0.5)
			return [
				{ key: 'Migrate', x: left, y: top, width: mid - left, height: middle - top },
				{ key: 'Invest', x: mid, y: top, width: right - mid, height: middle - top },
				{ key: 'Eliminate', x: left, y: middle, width: mid - left, height: bottom - middle },
				{ key: 'Tolerate', x: mid, y: middle, width: right - mid, height: bottom - middle },
			]
		},

		/**
		 * @return {string} A text alternative for the plot.
		 * @spec openspec/changes/lifecycle-application-value-assessment/specs/application-value-assessment/spec.md#requirement-req-ava-003-the-portfolio-report-plots-value-against-fit-and-flags-classes-the-scores-contradict
		 */
		ariaLabel() {
			return n(
				'stackiq',
				'Business value against technical fit for %n scored application in use',
				'Business value against technical fit for %n scored applications in use',
				this.plotted.points.length,
			)
		},
	},

	methods: {
		t,
		n,
		quadrantColor,

		/**
		 * @param {number} fit A technical fit value.
		 * @return {number} The x coordinate.
		 * @spec openspec/changes/lifecycle-application-value-assessment/specs/application-value-assessment/spec.md#requirement-req-ava-003-the-portfolio-report-plots-value-against-fit-and-flags-classes-the-scores-contradict
		 */
		scaleX(fit) {
			return this.pad.left + ((fit - 0.5) / 5) * this.plotWidth
		},

		/**
		 * @param {number} value A business value.
		 * @return {number} The y coordinate.
		 * @spec openspec/changes/lifecycle-application-value-assessment/specs/application-value-assessment/spec.md#requirement-req-ava-003-the-portfolio-report-plots-value-against-fit-and-flags-classes-the-scores-contradict
		 */
		scaleY(value) {
			return this.pad.top + ((5.5 - value) / 5) * this.plotHeight
		},

		/**
		 * @param {string} key A TIME class.
		 * @return {string} Its label.
		 * @spec openspec/changes/lifecycle-application-value-assessment/specs/application-value-assessment/spec.md#requirement-req-ava-003-the-portfolio-report-plots-value-against-fit-and-flags-classes-the-scores-contradict
		 */
		quadrantLabel(key) {
			const map = {
				Tolerate: t('stackiq', 'Tolerate'),
				Invest: t('stackiq', 'Invest'),
				Migrate: t('stackiq', 'Migrate'),
				Eliminate: t('stackiq', 'Eliminate'),
			}
			return map[key] || key
		},

		/**
		 * @param {object} point A plotted point.
		 * @return {string} The tooltip.
		 * @spec openspec/changes/lifecycle-application-value-assessment/specs/application-value-assessment/spec.md#requirement-req-ava-003-the-portfolio-report-plots-value-against-fit-and-flags-classes-the-scores-contradict
		 */
		pointTitle(point) {
			return t(
				'stackiq',
				'{name}: business value {value}, technical fit {fit}, annualised cost {cost}',
				{
					name: point.label,
					value: point.value,
					fit: point.fit,
					cost: formatCurrency(point.cost),
				},
			)
		},
	},
}
</script>

<style scoped>
.value-fit {
	margin: 0;
	max-width: 640px;
}

.value-fit__svg {
	width: 100%;
	height: auto;
}

.value-fit__quadrant {
	fill: var(--color-background-hover);
	stroke: var(--color-border);
}

.value-fit__quadrantLabel {
	fill: var(--color-text-maxcontrast);
	font-size: 12px;
}

.value-fit__tick,
.value-fit__axis {
	fill: var(--color-main-text);
	font-size: 12px;
}

.value-fit__caption {
	color: var(--color-text-maxcontrast);
	margin-top: calc(var(--default-grid-baseline) * 2);
}
</style>
