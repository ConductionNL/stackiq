<script setup>
import { navigationStore, objectStore } from '../../store/store.js'
</script>

<template>
	<NcDialog
		v-if="navigationStore.dialog === 'deleteObject'"
		:name="
			t('stackiq', 'Delete {name}', {
				name:
					objectStore.objectItem?.['@self']?.name
					|| objectStore.objectItem?.name
					|| objectStore.objectItem?.['@self']?.title
					|| objectStore.objectItem?.id
					|| t('stackiq', 'Publication'),
			})
		"
		size="normal"
		:canClose="false">
		<p v-if="success === null">
			{{ t('stackiq', 'Do you want to permanently delete') }}
			<b>{{
				objectStore.objectItem?.['@self']?.name
				|| objectStore.objectItem?.name
				|| objectStore.objectItem?.['@self']?.title
				|| objectStore.objectItem?.id
			}}</b
			>{{ t('stackiq', '? This action cannot be undone.') }}
		</p>

		<NcNoteCard v-if="success" type="success">
			<p>{{ t('stackiq', 'Publication successfully deleted') }}</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<NcButton @click="closeDialog">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{
					success === null ? t('stackiq', 'Cancel') : t('stackiq', 'Close')
				}}
			</NcButton>
			<NcButton
				v-if="success === null"
				:disabled="loading"
				variant="error"
				@click="deleteObject()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<TrashCanOutline v-if="!loading" :size="20" />
				</template>
				{{ t('stackiq', 'Delete') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'

export default {
	name: 'DeleteObject',
	components: {
		NcDialog,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		// Icons
		TrashCanOutline,
		Cancel,
	},

	data() {
		return {
			success: null,
			loading: false,
			error: false,
			closeModalTimeout: null,
		}
	},

	methods: {
		/**
		 * @spec openspec/specs/fe-object-modals/spec.md
		 */
		closeDialog() {
			navigationStore.setDialog(false)
			clearTimeout(this.closeModalTimeout)
			this.success = null
			this.loading = false
			this.error = false
		},

		/**
		 * @spec openspec/specs/fe-object-modals/spec.md
		 */
		async deleteObject() {
			this.loading = true

			try {
				await objectStore.deleteObject(objectStore.objectItem)
				this.success = true
				this.error = false
				this.closeModalTimeout = setTimeout(this.closeDialog, 2000)
			} catch (error) {
				this.success = false
				this.error =
					error.message
					|| this.t(
						'stackiq',
						'An error occurred while deleting the publication',
					)
			} finally {
				this.loading = false
			}
		},
	},
}
</script>
