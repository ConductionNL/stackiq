<script setup>
import { objectStore, navigationStore } from '../../store/store.js'
</script>

<template>
	<NcDialog
		v-if="navigationStore.modal === 'lockObject'"
		:name="
			t('softwarecatalog', 'Lock {name}', {
				name:
					objectStore.objectItem?.['@self']?.name
					|| objectStore.objectItem?.name
					|| objectStore.objectItem?.['@self']?.title
					|| objectStore.objectItem?.id
					|| t('softwarecatalog', 'Publication'),
			})
		"
		size="normal"
		:can-close="false">
		<p v-if="success === null">
			{{ t('softwarecatalog', 'Do you want to lock') }}
			<b>{{
				objectStore.objectItem?.['@self']?.name
				|| objectStore.objectItem?.name
				|| objectStore.objectItem?.['@self']?.title
				|| objectStore.objectItem?.id
			}}</b
			>{{
				t(
					'softwarecatalog',
					"? Locking an object prevents other users from modifying it until it is unlocked. You can specify an optional process name to indicate why it's locked and a duration after which it will automatically unlock. Only the user who locked the object or an administrator can unlock it before the duration expires.",
				)
			}}
		</p>
		<NcNoteCard v-if="success" type="success">
			<p>{{ t('softwarecatalog', 'Object successfully locked') }}</p>
		</NcNoteCard>
		<NcNoteCard v-if="error" type="error">
			<p>{{ error }}</p>
		</NcNoteCard>

		<template #actions>
			<NcButton @click="closeModal">
				<template #icon>
					<Cancel :size="20" />
				</template>
				{{
					success
						? t('softwarecatalog', 'Close')
						: t('softwarecatalog', 'Cancel')
				}}
			</NcButton>
			<NcButton
				:disabled="loading || success"
				variant="primary"
				@click="lockObject()">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<LockOutline v-else :size="20" />
				</template>
				{{ t('softwarecatalog', 'Lock') }}
			</NcButton>
		</template>

		<div v-if="!success" class="formContainer">
			<NcTextField
				v-model="process"
				:label="t('softwarecatalog', 'Process Name (optional)')"
				:disabled="loading" />
			<NcTextField
				v-model="duration"
				type="number"
				:label="t('softwarecatalog', 'Duration in seconds (optional)')"
				:disabled="loading" />
		</div>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcTextField,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'

import LockOutline from 'vue-material-design-icons/LockOutline.vue'
import Cancel from 'vue-material-design-icons/Cancel.vue'

export default {
	name: 'LockObject',
	components: {
		NcDialog,
		NcTextField,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		LockOutline,
		Cancel,
	},
	data() {
		return {
			process: '',
			duration: 3600,
			success: null,
			loading: false,
			error: null,
			closeModalTimeout: null,
		}
	},
	methods: {
		/**
		 * @spec openspec/specs/fe-object-modals/spec.md
		 */
		closeModal() {
			navigationStore.setModal(false)
			clearTimeout(this.closeModalTimeout)
			this.success = null
			this.loading = false
			this.error = null
			this.process = ''
			this.duration = 3600
		},
		/**
		 * @spec openspec/specs/fe-object-modals/spec.md
		 */
		async lockObject() {
			this.loading = true

			try {
				await objectStore.lockObject(
					objectStore.objectItem,
					this.process || undefined,
					this.duration || undefined,
				)
				this.success = true
				this.error = null
				this.closeModalTimeout = setTimeout(this.closeModal, 2000)
			} catch (error) {
				this.error =
					error.message
					|| this.t('softwarecatalog', 'Failed to lock object')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>
