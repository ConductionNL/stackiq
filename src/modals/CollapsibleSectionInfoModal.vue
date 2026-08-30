<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -
  - Info modal for CollapsibleSection — the panel behind the (i) button in a
  - collapsible settings section header. Extracted from CollapsibleSection.vue
  - per ADR-004/ADR-012: a modal lives in its own file under src/modals/ and is
  - imported by its parent.
  -
  - Deliberately NOT shared with AlwaysVisibleSectionInfoModal. This variant
  - paints its own <h2> header and a Close button inside the modal body and
  - carries its own :deep() typography rules for slotted content; the
  - always-visible variant uses NcModal's built-in title chrome and has no
  - footer. See the note in AlwaysVisibleSectionInfoModal.vue.
  -
  - Presentation only: the section owns the `info-content` / `info` slot API and
  - forwards the resolved content (including the empty-state fallback) into this
  - modal's default slot.
  -
  - @spec openspec/specs/fe-shell-navigation/spec.md
  -->

<template>
	<NcModal @close="$emit('close')">
		<div class="info-modal">
			<div class="modal-header">
				<h2>{{ name }} - Information</h2>
			</div>
			<div class="modal-content">
				<slot />
			</div>
			<div class="modal-footer">
				<NcButton @click="$emit('close')"> Close </NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcModal } from '@nextcloud/vue'

export default {
	name: 'CollapsibleSectionInfoModal',

	components: {
		NcButton,
		NcModal,
	},

	props: {
		/**
		 * Section name — used to build the modal heading.
		 */
		name: {
			type: String,
			required: true,
		},
	},

	emits: ['close'],
}
</script>

<style scoped>
/* Info Modal Styles */
.info-modal {
	padding: 20px;
	max-width: 600px;
	max-height: 80vh;
	overflow-y: auto;
}

.modal-header {
	margin-bottom: 16px;
	padding-bottom: 16px;
	border-bottom: 1px solid var(--color-border);
}

.modal-header h2 {
	margin: 0;
	font-size: 20px;
	font-weight: 600;
	color: var(--color-main-text);
}

.modal-content {
	margin-bottom: 20px;
	line-height: 1.6;
}

.modal-content :deep(h3) {
	margin-top: 20px;
	margin-bottom: 12px;
	font-size: 16px;
	font-weight: 600;
}

.modal-content :deep(h4) {
	margin-top: 16px;
	margin-bottom: 8px;
	font-size: 14px;
	font-weight: 600;
}

.modal-content :deep(ul) {
	padding-left: 20px;
	margin-bottom: 16px;
}

.modal-content :deep(li) {
	margin-bottom: 4px;
}

.modal-content :deep(p) {
	margin-bottom: 12px;
}

.modal-content :deep(code) {
	background-color: var(--color-background-dark);
	padding: 2px 6px;
	border-radius: 4px;
	font-family: monospace;
	font-size: 13px;
}

.modal-footer {
	display: flex;
	justify-content: flex-end;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}
</style>
