<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->

<!--
 An information button next to a GEMMA term that opens its GEMMA definition
 in place: "<name>, from GEMMA", the element's documentation and a link
 "More in GEMMA Online". A term without documentation shows only its name
 and the link; without a link, only what it has.

 @spec openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-gemma-terms-show-their-definition-where-they-appear-req-gpl-003
-->
<template>
	<NcPopover class="gemma-definition" popupRole="dialog">
		<template #trigger>
			<NcButton
				variant="tertiary-no-background"
				:aria-label="t('stackiq', 'What is {name}?', { name })"
				data-testid="gemma-definition-button">
				<template #icon>
					<InformationOutline :size="18" />
				</template>
			</NcButton>
		</template>
		<div class="gemma-definition__body" data-testid="gemma-definition">
			<p class="gemma-definition__name">
				{{ t('stackiq', '{name}, from GEMMA', { name }) }}
			</p>
			<p v-if="documentation" class="gemma-definition__text">
				{{ documentation }}
			</p>
			<a
				v-if="gemmaUrl"
				class="gemma-definition__link"
				:href="gemmaUrl"
				target="_blank"
				rel="noopener noreferrer">
				{{ t('stackiq', 'More in GEMMA Online') }}
				<OpenInNew :size="16" />
			</a>
		</div>
	</NcPopover>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcPopover } from '@nextcloud/vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'

/**
 * @class GemmaDefinitionPopover
 * @module Components
 * @copyright 2026 Conduction B.V.
 * @license EUPL-1.2
 *
 * @spec openspec/changes/gemma-plate-views-peers-and-definitions/specs/gemma-plate/spec.md#requirement-gemma-terms-show-their-definition-where-they-appear-req-gpl-003
 */
export default {
	name: 'GemmaDefinitionPopover',

	components: {
		InformationOutline,
		NcButton,
		NcPopover,
		OpenInNew,
	},

	props: {
		/** The GEMMA term, for example "Zaaksysteem". */
		name: {
			type: String,
			required: true,
		},

		/** The element's `documentation`. */
		documentation: {
			type: String,
			default: '',
		},

		/** The element's `gemmaUrl`, its page on GEMMA Online. */
		gemmaUrl: {
			type: String,
			default: '',
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped>
.gemma-definition__body {
	max-width: 320px;
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	padding: calc(var(--default-grid-baseline, 4px) * 3);
}

.gemma-definition__name {
	margin: 0;
	font-weight: 600;
}

.gemma-definition__text {
	margin: 0;
	white-space: pre-line;
}

.gemma-definition__link {
	display: inline-flex;
	align-items: center;
	gap: var(--default-grid-baseline, 4px);
	color: var(--color-primary-element);
	text-decoration: underline;
}
</style>
