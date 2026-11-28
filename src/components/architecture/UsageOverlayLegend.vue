<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<template>
	<ul class="usage-legend" :aria-label="t('stackiq', 'Legend')" data-testid="usage-overlay-legend">
		<li v-if="own" class="usage-legend__item">
			<span class="usage-legend__swatch usage-legend__swatch--own" aria-hidden="true" />
			{{ t('stackiq', 'Our application') }}
		</li>
		<li v-if="shared" class="usage-legend__item">
			<span class="usage-legend__swatch usage-legend__swatch--shared" aria-hidden="true" />
			{{ t('stackiq', 'Shared with a partner') }}
			<span class="usage-legend__badge">{{ t('stackiq', 'Shared') }}</span>
		</li>
	</ul>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

/**
 * The legend under a GEMMA view that shows applications: a solid border for
 * an own application, a dashed border and the text "Shared" for one shared
 * with a partner, so the two never differ by colour alone.
 *
 * @spec openspec/changes/architecture-shared-usage-on-views/specs/shared-usage-on-views/spec.md#requirement-req-suv-001-the-gemma-view-page-must-let-a-user-show-own-and-shared-applications-on-the-view
 */
export default {
	name: 'UsageOverlayLegend',

	props: {
		/** Whether own applications are shown. */
		own: {
			type: Boolean,
			default: false,
		},

		/** Whether shared applications are shown. */
		shared: {
			type: Boolean,
			default: false,
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped>
.usage-legend {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 4);
	margin: 0;
	padding: 0;
	list-style: none;
	color: var(--color-main-text);
}

.usage-legend__item {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
}

.usage-legend__swatch {
	display: inline-block;
	width: 24px;
	height: 14px;
	border-radius: var(--border-radius);
	background-color: var(--color-main-background);
}

.usage-legend__swatch--own {
	border: 2px solid var(--color-primary-element);
}

.usage-legend__swatch--shared {
	border: 2px dashed var(--color-text-maxcontrast);
}

.usage-legend__badge {
	padding: 0 var(--default-grid-baseline);
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	font-size: 0.85em;
}
</style>
