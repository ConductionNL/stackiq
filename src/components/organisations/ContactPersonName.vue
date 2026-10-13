<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->
<template>
	<CnWidgetWrapper
		:title="t('stackiq', 'Contact person')"
		titleIconPosition="left"
		:showRefresh="false"
		:showRequestFeature="false">
		<template #title-icon>
			<CnIcon name="AccountOutline" :size="20" />
		</template>
		<div class="contact-person-name" data-testid="contact-person-name">
			<NcLoadingIcon
				v-if="waiting"
				:size="20"
				:name="t('stackiq', 'Loading the name')" />
			<template v-else>
				<p v-if="name" class="contact-person-name__name">
					{{ name }}
				</p>
				<p
					v-else
					class="contact-person-name__hint"
					data-testid="contact-person-name-hint">
					{{ hint }}
				</p>
			</template>
		</div>
	</CnWidgetWrapper>
</template>

<script>
import { CnIcon, CnWidgetWrapper } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { NcLoadingIcon } from '@nextcloud/vue'
import { contactNameResult } from '../../utils/contactNames.js'

/**
 * The name of a contact person, on its detail page.
 *
 * A contact person holds only a `contactsUid`; the name lives in Nextcloud
 * Contacts. It is read through /api/contact-names, which answers only from the
 * signed-in user's own and shared address books, so the name is shown to whoever
 * can already read the contact, and to nobody else.
 *
 * @spec openspec/specs/application-usage-pages/spec.md#requirement-req-uap-005-a-contact-persons-name-shows-only-to-who-can-read-the-contact
 */
export default {
	name: 'ContactPersonName',

	components: {
		CnIcon,
		CnWidgetWrapper,
		NcLoadingIcon,
	},

	props: {
		/** The contact person uuid. */
		objectId: {
			type: String,
			required: true,
		},

		/** The Nextcloud contact the person is linked to; empty when not linked. */
		contactsUid: {
			type: String,
			default: '',
		},

		/**
		 * The id of the loaded object (`@object.id`); absent while the page is
		 * still reading it, when `contactsUid` is empty for that reason too.
		 */
		loadedId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			loading: true,
			name: null,
			failed: false,
		}
	},

	computed: {
		/**
		 * Whether to show the spinner: the name is being read, or the object is
		 * not loaded yet, so "not linked" cannot be told from "not known yet".
		 *
		 * @return {boolean} True while waiting.
		 * @spec openspec/specs/application-usage-pages/spec.md#requirement-req-uap-005-a-contact-persons-name-shows-only-to-who-can-read-the-contact
		 */
		waiting() {
			return this.loading || !this.loadedId
		},

		/**
		 * Why there is no name to show.
		 *
		 * @return {string} The hint.
		 * @spec openspec/specs/application-usage-pages/spec.md#requirement-req-uap-005-a-contact-persons-name-shows-only-to-who-can-read-the-contact
		 */
		hint() {
			if (this.failed) {
				return t(
					'stackiq',
					'The name could not be loaded. Reload the page to try again.',
				)
			}
			if (!this.contactsUid) {
				return t(
					'stackiq',
					'This contact person is not linked to a Nextcloud contact.',
				)
			}
			return t(
				'stackiq',
				'The name is kept in Nextcloud Contacts, in an address book you cannot read.',
			)
		},
	},

	watch: {
		objectId: {
			immediate: true,
			/**
			 * Read the name of the contact person.
			 *
			 * @param {string} id The contact person uuid.
			 * @return {Promise<void>}
			 * @spec openspec/specs/application-usage-pages/spec.md#requirement-req-uap-005-a-contact-persons-name-shows-only-to-who-can-read-the-contact
			 */
			async handler(id) {
				this.loading = true
				this.name = null
				this.failed = false
				const result = await contactNameResult(id)
				if (id === this.objectId) {
					this.name = result.name
					this.failed = result.failed
					this.loading = false
				}
			},
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped>
.contact-person-name__name {
	margin: 0;
	font-size: 1.1em;
	font-weight: bold;
}

.contact-person-name__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
