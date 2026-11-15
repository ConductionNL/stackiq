<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Opens Move to organisation for the record a detail page shows
  (landscape-move-between-organisations D3). Registered as a `modal` in
  registry.js, so a detail page's `open-modal` header action mounts it; it
  takes the record id from the route, hands the dialog its schema and that
  id through the navigation store, where Dialogs.vue renders it, and closes
  itself. It draws nothing.
-->
<template>
	<span hidden />
</template>

<script>
import { openMoveForRecord } from '../../services/moveToOrganisation.js'
import { navigationStore } from '../../store/store.js'

/**
 * @spec openspec/changes/landscape-move-between-organisations
 */
export default {
	name: 'MoveToOrganisationLauncher',

	props: {
		/**
		 * The schema of the record the detail page shows.
		 */
		schema: {
			type: String,
			required: true,
		},
	},

	emits: ['close'],

	mounted() {
		openMoveForRecord(
			(name, properties) => navigationStore.setDialog(name, properties),
			this.schema,
			this.$route?.params?.id,
		)
		this.$emit('close')
	},
}
</script>
