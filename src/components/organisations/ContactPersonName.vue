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
				v-if="loading"
				:size="20"
				:name="t('stackiq', 'Loading the name')" />
			<template v-else>
				<p v-if="name" class="contact-person-name__name">
					{{ name }}
				</p>
				<p v-else class="contact-person-name__hint">
					{{
						t(
							'stackiq',
							'The name is kept in Nextcloud Contacts, in an address book you cannot read.',
						)
					}}
				</p>
			</template>
		</div>
	</CnWidgetWrapper>
</template>

<script>
import { CnIcon, CnWidgetWrapper } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { NcLoadingIcon } from '@nextcloud/vue'
import { contactName } from '../../utils/contactNames.js'

/**
 * The name of a contact person, on its detail page.
 *
 * A contact person holds only a `contactsUid`; the name lives in Nextcloud
 * Contacts. It is read through /api/contact-names, which answers only from the
 * signed-in user's own and shared address books, so the name is shown to whoever
 * can already read the contact, and to nobody else.
 *
 * @spec openspec/specs/application-usage-pages/spec.md#requirement-req-uap-003-a-usage-names-a-business-owner-and-a-technical-owner
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
	},

	data() {
		return {
			loading: true,
			name: null,
		}
	},

	watch: {
		objectId: {
			immediate: true,
			/**
			 * Read the name of the contact person.
			 *
			 * @param {string} id The contact person uuid.
			 * @return {Promise<void>}
			 * @spec openspec/specs/application-usage-pages/spec.md#requirement-req-uap-003-a-usage-names-a-business-owner-and-a-technical-owner
			 */
			async handler(id) {
				this.loading = true
				this.name = null
				const name = await contactName(id)
				if (id === this.objectId) {
					this.name = name
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
