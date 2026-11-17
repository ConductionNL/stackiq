<!--
 - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 - SPDX-License-Identifier: EUPL-1.2
 -->

<!--
 The Connections page's Add: the entry wizard for a connection between two
 applications (manifest `actionsComponent` of page Koppelingen).

 @spec openspec/changes/capability-requirements-from-the-matrix/specs/guided-entry-wizard/spec.md#requirement-req-gew-001-applications-services-and-connections-are-added-through-a-step-by-step-wizard
-->
<template>
	<EntryWizardAction kind="connection" />
</template>

<script>
import EntryWizardAction from './EntryWizardAction.vue'

export default {
	name: 'ConnectionWizardAction',

	components: {
		EntryWizardAction,
	},
}
</script>
