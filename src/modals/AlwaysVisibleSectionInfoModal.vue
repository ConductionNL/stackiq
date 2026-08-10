<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -
  - Info modal for AlwaysVisibleSection — the panel behind the (i) button in a
  - settings section header. Extracted from AlwaysVisibleSection.vue per
  - ADR-004/ADR-012: a modal lives in its own file under src/modals/ and is
  - imported by its parent.
  -
  - Deliberately NOT shared with CollapsibleSectionInfoModal. The two render
  - different DOM: this one leans on NcModal's own `title`/`name` chrome and has
  - no footer, while the collapsible variant paints its own <h2> header and a
  - Close button inside the body. Folding them together would mean a variant
  - flag switching between two disjoint templates and two disjoint stylesheets —
  - two components wearing one filename — and would risk changing the rendered
  - output of one of them.
  -
  - Presentation only: the section owns the `info-content` / `info` slot API and
  - forwards the resolved content into this modal's default slot.
  -
  - @spec openspec/specs/fe-shell-navigation/spec.md
  -->

<template>
	<NcModal
		:show="show"
		:title="name + ' Information'"
		:name="name + ' Info'"
		@close="$emit('close')">
		<div class="info-content">
			<slot />
		</div>
	</NcModal>
</template>

<script>
import { defineComponent } from 'vue'
import { NcModal } from '@nextcloud/vue'

export default defineComponent({
	name: 'AlwaysVisibleSectionInfoModal',

	components: {
		NcModal,
	},

	props: {
		/**
		 * Section name — used to build the modal title.
		 */
		name: {
			type: String,
			required: true,
		},

		/**
		 * Whether the modal is open.
		 */
		show: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close'],
})
</script>

<style scoped>
.info-content {
	max-width: 600px;
	line-height: 1.6;
}
</style>
