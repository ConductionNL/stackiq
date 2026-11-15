<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<CnFormDialog
		ref="formDialog"
		:item="null"
		:schema="formSchema"
		:dialogTitle="t('stackiq', 'New report over {schema}', { schema: schemaLabel })"
		:successText="t('stackiq', 'Report saved')"
		:recoverDraft="false"
		@create="onCreate"
		@close="$emit('close')" />
</template>

<script>
import { CnFormDialog } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { profileFromForm, profilesUrl, reportFormSchema } from '../../views/reports/customReports.js'

/**
 * @class CustomReportDialog
 * @module Modals/Reports
 * @copyright 2026 Conduction B.V.
 * @license EUPL-1.2
 *
 * Defines a new custom report over one catalogue schema and stores it as the
 * caller's OpenRegister export profile. OpenRegister makes the caller its
 * owner and checks the export right when the report runs.
 *
 * @spec openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-004-a-user-shall-define-run-and-delete-their-own-reports-over-one-catalogue-schema
 */
export default {
	name: 'CustomReportDialog',

	components: { CnFormDialog },

	props: {
		/** The catalogue register's id. */
		registerId: { type: [String, Number], required: true },
		/** The OpenRegister schema the report reads. */
		schema: { type: Object, required: true },
		/** The schema's name as the page shows it. */
		schemaLabel: { type: String, default: '' },
	},

	emits: ['created', 'close'],

	computed: {
		/**
		 * @return {object} The form's JSON schema.
		 */
		formSchema() {
			return reportFormSchema(this.schema, (text) => t('stackiq', text))
		},
	},

	methods: {
		t,

		/**
		 * Store the report as an export profile and report the outcome in the dialog.
		 *
		 * @param {object} form The form's answer.
		 * @return {Promise<void>}
		 * @spec openspec/changes/insight-exports-and-custom-reports/specs/catalogue-exports-and-reports/spec.md#requirement-req-cer-004-a-user-shall-define-run-and-delete-their-own-reports-over-one-catalogue-schema
		 */
		async onCreate(form) {
			const profile = profileFromForm(form, { registerId: this.registerId, schemaId: this.schema.id })
			try {
				const { data } = await axios.post(profilesUrl(generateUrl), profile)
				this.$refs.formDialog?.setResult({ success: true })
				this.$emit('created', data)
			} catch (error) {
				this.$refs.formDialog?.setResult({
					error: error?.response?.data?.error || t('stackiq', 'The report could not be saved.'),
				})
			}
		},
	},
}
</script>
