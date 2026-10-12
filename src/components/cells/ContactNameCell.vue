<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

ContactNameCell.vue

Table cell widget `contactName`: a contact person shown by the name of its
Nextcloud contact instead of its uuid. The name comes from the signed-in
user's own address books (see `utils/contactNames.js`); when there is none,
the uuid stays, as before.

@spec openspec/specs/application-usage-pages/spec.md#requirement-req-uap-003-a-usage-names-a-business-owner-and-a-technical-owner
-->

<template>
	<span class="contact-name-cell" :title="name ? undefined : id">{{
		name || id
	}}</span>
</template>

<script>
import { contactName, contactPersonId } from '../../utils/contactNames.js'

export default {
	name: 'ContactNameCell',

	props: {
		/** The cell value: a contact person uuid, or the related object. */
		value: {
			type: [String, Object],
			default: null,
		},
	},

	data() {
		return {
			name: null,
		}
	},

	computed: {
		/**
		 * @return {string} The contact person uuid, or ''.
		 */
		id() {
			return contactPersonId(this.value)
		},
	},

	watch: {
		id: {
			immediate: true,
			/**
			 * Read the name of the contact person in the cell.
			 *
			 * @param {string} id The contact person uuid.
			 * @return {Promise<void>}
			 */
			async handler(id) {
				this.name = null
				const name = await contactName(id)
				if (id === this.id) {
					this.name = name
				}
			},
		},
	},
}
</script>
