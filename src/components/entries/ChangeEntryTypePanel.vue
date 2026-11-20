<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
 ChangeEntryTypePanel.vue (landscape-change-entry-type)

 A body widget on the application page: names the entry's current type and
 opens ChangeEntryTypeDialog. After a change to a service the entry no longer
 lives in the applications list, so the panel opens the Services list; after a
 change between Application and System software the page reloads in place.

 @spec openspec/changes/landscape-change-entry-type/specs/entry-type-change/spec.md#requirement-req-etc-002-the-user-sees-what-carries-over-before-confirming
-->
<template>
	<CnWidgetWrapper
		:title="t('stackiq', 'Entry type')"
		titleIconPosition="left"
		:showRefresh="false"
		:showRequestFeature="false">
		<template #title-icon>
			<CnIcon name="SwapHorizontal" :size="20" />
		</template>

		<div class="change-entry-type-panel">
			<p>{{ currentLabel }}</p>
			<NcButton :disabled="!loaded" @click="open = true">
				{{ t('stackiq', 'Change type') }}
			</NcButton>
		</div>

		<ChangeEntryTypeDialog
			:show="open"
			:uuid="objectId"
			:currentType="currentType"
			@close="open = false"
			@changed="onChanged" />
	</CnWidgetWrapper>
</template>

<script>
import { CnIcon, CnWidgetWrapper } from '@conduction/nextcloud-vue'
import { showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'
import ChangeEntryTypeDialog from '../../dialogs/ChangeEntryTypeDialog.vue'
import { objectStore } from '../../store/store.js'
import { ensureObjectType } from '../../utils/objectTypes.js'
import { moduleEntryType } from './entryTypes.js'

export default {
	name: 'ChangeEntryTypePanel',

	components: { ChangeEntryTypeDialog, CnIcon, CnWidgetWrapper, NcButton },

	props: {
		objectId: { type: String, required: true },
	},

	data() {
		return {
			currentType: 'application',
			loaded: false,
			open: false,
		}
	},

	computed: {
		/**
		 * The sentence naming the entry's current type.
		 *
		 * @spec openspec/changes/landscape-change-entry-type/specs/entry-type-change/spec.md#requirement-req-etc-002-the-user-sees-what-carries-over-before-confirming
		 * @return {string} The label.
		 */
		currentLabel() {
			return this.currentType === 'systemSoftware'
				? t('stackiq', 'This entry is system software.')
				: t('stackiq', 'This entry is an application.')
		},
	},

	/**
	 * Read the module's type.
	 *
	 * @spec exclude framework lifecycle hook; delegates to load()
	 */
	created() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Read the module to learn whether it is an application or system software.
		 *
		 * @spec openspec/changes/landscape-change-entry-type/specs/entry-type-change/spec.md#requirement-req-etc-002-the-user-sees-what-carries-over-before-confirming
		 * @return {Promise<void>}
		 */
		async load() {
			try {
				ensureObjectType('module')
				const module = await objectStore.fetchObject('module', this.objectId)
				this.currentType = moduleEntryType(module)
			} catch {
				this.currentType = 'application'
			} finally {
				this.loaded = true
			}
		},

		/**
		 * Follow the entry to where it lives now.
		 *
		 * @spec openspec/changes/landscape-change-entry-type/specs/entry-type-change/spec.md#requirement-req-etc-001-an-entry-changes-type-and-keeps-its-identity
		 * @param {object} outcome The change outcome ({uuid, targetType, moved}).
		 * @return {void}
		 */
		onChanged(outcome) {
			this.open = false
			showSuccess(t('stackiq', 'The type was changed.'))
			if (outcome?.targetType === 'service') {
				this.$router.push({ name: 'Diensten' })
				return
			}
			window.location.reload()
		},
	},
}
</script>

<style scoped>
.change-entry-type-panel {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 3);
	flex-wrap: wrap;
}
</style>
