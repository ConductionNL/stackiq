<!--
 - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 - SPDX-License-Identifier: EUPL-1.2
 -->

<!--
 EntryWizardAction — the Add button an index page shows in its actions slot
 (manifest `actionsComponent`, with `showAdd: false` so the generic form does
 not compete). It opens EntryWizardDialog for its kind and, once the record is
 saved, opens the record's page.

 @spec openspec/changes/capability-requirements-from-the-matrix/specs/guided-entry-wizard/spec.md#requirement-req-gew-001-applications-services-and-connections-are-added-through-a-step-by-step-wizard
-->
<template>
	<span class="entry-wizard-action">
		<NcButton variant="primary" data-testid="entry-wizard-add" @click="open = true">
			<template #icon>
				<Plus :size="20" />
			</template>
			{{ t('stackiq', 'Add') }}
		</NcButton>
		<EntryWizardDialog v-model:show="open" :kind="kind" @created="onCreated" />
	</span>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import EntryWizardDialog from '../../dialogs/EntryWizardDialog.vue'
import { wizardKind } from '../../utils/entryWizard.js'

export default {
	name: 'EntryWizardAction',

	components: {
		EntryWizardDialog,
		NcButton,
		Plus,
	},

	props: {
		/** The kind of record it adds, a key of ENTRY_WIZARD_KINDS. */
		kind: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			open: false,
		}
	},

	methods: {
		t,

		/**
		 * Close the wizard and open the saved record's page.
		 *
		 * @param {string|undefined} id The saved record's id.
		 * @return {void}
		 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/guided-entry-wizard/spec.md#requirement-req-gew-001-applications-services-and-connections-are-added-through-a-step-by-step-wizard
		 */
		onCreated(id) {
			this.open = false
			if (id && this.$router) {
				this.$router.push({ name: wizardKind(this.kind).detailRoute, params: { id } }).catch(() => {})
			}
		},
	},
}
</script>
