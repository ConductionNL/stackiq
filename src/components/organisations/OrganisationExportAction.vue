<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<NcButton
		v-if="mayExport"
		class="organisation-export-action"
		data-testid="organisation-export-archimate"
		variant="secondary"
		:href="exportUrl"
		download>
		<template #icon>
			<Download :size="20" />
		</template>
		{{ t('stackiq', 'Export as ArchiMate') }}
	</NcButton>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import Download from 'vue-material-design-icons/Download.vue'
import {
	mayExportFrom,
	organisationExportAccessUrl,
	organisationExportUrl,
	organisationUuidOf,
} from './organisationExport.js'

/**
 * @class OrganisationExportAction
 * @module Components/Organisations
 * @copyright 2026 Conduction B.V.
 * @license EUPL-1.2
 *
 * OrganisatieDetail `actionsComponent`: Export as ArchiMate for the
 * organisation the page shows. It shows only when the server says the caller
 * may export that organisation (their own active organisation, or admin or
 * `ambtenaar`); the export endpoint applies the same rule again.
 *
 * @spec openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-003-a-member-of-an-organisation-must-be-able-to-export-that-organisation-as-archimate-from-its-detail-page-and-only-that-organisation
 */
export default {
	name: 'OrganisationExportAction',

	components: { NcButton, Download },

	props: {
		/** The organisation the detail page shows. */
		object: { type: Object, default: null },
		/** The route's object id. */
		objectId: { type: String, default: '' },
	},

	data() {
		return { mayExport: false }
	},

	computed: {
		/**
		 * @return {string} The organisation's uuid.
		 */
		organisationUuid() {
			return organisationUuidOf(this.object, this.objectId)
		},

		/**
		 * @return {string} The export's download URL.
		 */
		exportUrl() {
			return organisationExportUrl(generateUrl, this.organisationUuid)
		},
	},

	watch: {
		/**
		 * Ask again when the page moves to another organisation.
		 *
		 * @return {void}
		 */
		organisationUuid() {
			this.checkAccess()
		},
	},

	mounted() {
		this.checkAccess()
	},

	methods: {
		t,

		/**
		 * Ask the server whether the caller may export this organisation; any failure hides the action.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-003-a-member-of-an-organisation-must-be-able-to-export-that-organisation-as-archimate-from-its-detail-page-and-only-that-organisation
		 */
		async checkAccess() {
			const uuid = this.organisationUuid
			this.mayExport = false
			if (uuid === '') {
				return
			}
			try {
				const { data } = await axios.get(organisationExportAccessUrl(generateUrl, uuid))
				this.mayExport = uuid === this.organisationUuid && mayExportFrom(data)
			} catch {
				this.mayExport = false
			}
		},
	},
}
</script>
