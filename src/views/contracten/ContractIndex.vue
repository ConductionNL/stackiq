/**
 * ContractIndex.vue
 * Component for displaying and managing contracten using GenericObjectTable
 * @category Views
 * @package softwarecatalog
 * @author Ruben Linde
 * @copyright 2024
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 * @link https://github.com/opencatalogi/softwarecatalog
 */

<script setup>
import { translate as t } from '@nextcloud/l10n'
import { navigationStore, objectStore } from '../../store/store.js'
</script>

<template>
	<GenericObjectTable
		object-type="contract"
		object-type-plural="contracten"
		:title="t('softwarecatalog', 'Contracten')"
		:description="t('softwarecatalog', 'Manage your contracten and their specifications')"
		:empty-icon="FileDocumentEdit"
		:card-icon="FileDocumentEdit"
		:properties="contractProperties"
		:object-actions="contractObjectActions"
		:mass-actions="contractMassActions"
		:actions="contractActions"
		:add-action="addContractAction"
		:help-url="'https://conduction.gitbook.io/softwarecatalog-nextcloud/beheerders/contracten'"
		card-display-mode="properties"
		@mounted="onMounted" />
</template>

<script>
import GenericObjectTable from '../../components/GenericObjectTable.vue'
import FileDocumentEdit from 'vue-material-design-icons/FileDocumentEdit.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import HelpCircleOutline from 'vue-material-design-icons/HelpCircleOutline.vue'
import Eye from 'vue-material-design-icons/Eye.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import PublishIcon from 'vue-material-design-icons/Publish.vue'
import PublishOffIcon from 'vue-material-design-icons/PublishOff.vue'

export default {
	name: 'ContractIndex',
	components: {
		GenericObjectTable,
	},
	data() {
		return {
			contractProperties: [
				{
					id: 'contractNummer',
					label: t('softwarecatalog', 'Contract number'),
					key: 'contractNummer',
					sortable: true,
					searchable: true,
				},
				{
					id: 'contractType',
					label: t('softwarecatalog', 'Contract type'),
					key: 'contractType',
					sortable: true,
					searchable: true,
				},
				{
					id: 'status',
					label: t('softwarecatalog', 'Status'),
					key: 'status',
					sortable: true,
					searchable: true,
				},
				{
					id: 'startDatum',
					label: t('softwarecatalog', 'Start date'),
					key: 'startDatum',
					sortable: true,
					searchable: false,
				},
				{
					id: 'eindDatum',
					label: t('softwarecatalog', 'End date'),
					key: 'eindDatum',
					sortable: true,
					searchable: false,
				},
				{
					id: 'voorzieningAanbod',
					label: t('softwarecatalog', 'Provision offer'),
					key: 'voorzieningAanbod',
					sortable: false,
					searchable: true,
				},
				{
					id: 'voorzieningGebruik',
					label: t('softwarecatalog', 'Provision usage'),
					key: 'voorzieningGebruik',
					sortable: false,
					searchable: true,
				},
			],
			contractObjectActions: [
				{
					id: 'view',
					label: t('softwarecatalog', 'View'),
					icon: Eye,
					handler: (contract) => {
						objectStore.setActiveObject('contract', contract)
						navigationStore.setModal('viewContract')
					},
				},
				{
					id: 'edit',
					label: t('softwarecatalog', 'Edit'),
					icon: Pencil,
					handler: (contract) => {
						objectStore.setActiveObject('contract', contract)
						navigationStore.setModal('contract')
					},
				},
				{
					id: 'copy',
					label: t('softwarecatalog', 'Copy'),
					icon: ContentCopy,
					handler: (contract) => {
						objectStore.setActiveObject('contract', contract)
						navigationStore.setDialog('copyObject', {
							objectType: 'contract',
							dialogTitle: 'Contract',
						})
					},
				},
				{
					id: 'delete',
					label: t('softwarecatalog', 'Delete'),
					icon: TrashCanOutline,
					handler: (contract) => {
						objectStore.setActiveObject('contract', contract)
						navigationStore.setDialog('deleteObject', {
							objectType: 'contract',
							dialogTitle: 'Contract',
						})
					},
				},
			],
			contractMassActions: [
				{
					id: 'massDelete',
					label: t('softwarecatalog', 'Delete Selected'),
					icon: Delete,
					handler: () => {
						navigationStore.setDialog('massDeleteObjects', {
							objectType: 'contract',
							dialogTitle: 'Contracten',
						})
					},
				},
				{
					id: 'massPublish',
					label: t('softwarecatalog', 'Publish Selected'),
					icon: PublishIcon,
					handler: () => {
						navigationStore.setDialog('massPublishObjects', {
							objectType: 'contract',
							dialogTitle: 'Contracten',
						})
					},
				},
				{
					id: 'massDepublish',
					label: t('softwarecatalog', 'Depublish Selected'),
					icon: PublishOffIcon,
					handler: () => {
						navigationStore.setDialog('massDepublishObjects', {
							objectType: 'contract',
							dialogTitle: 'Contracten',
						})
					},
				},
			],
			contractActions: [
				{
					id: 'add',
					label: t('softwarecatalog', 'Add Contract'),
					icon: Plus,
					primary: true,
					handler: () => {
						objectStore.clearActiveObject('contract')
						navigationStore.setModal('contract')
					},
				},
				{
					id: 'refresh',
					label: t('softwarecatalog', 'Refresh'),
					icon: Refresh,
					handler: () => {
						objectStore.fetchCollection('contract')
					},
					disabled: () => objectStore.isLoading('contract'),
				},
				{
					id: 'help',
					label: t('softwarecatalog', 'Help'),
					icon: HelpCircleOutline,
					handler: () => {
						window.open('https://conduction.gitbook.io/softwarecatalog-nextcloud/beheerders/contracten', '_blank')
					},
				},
			],
			addContractAction: {
				id: 'add',
				label: t('softwarecatalog', 'Add Contract'),
				icon: Plus,
				handler: () => {
					objectStore.clearActiveObject('contract')
					navigationStore.setModal('contract')
				},
			},
		}
	},
	methods: {
		/**
		 * Handle component mount - initialize settings and fetch contracten
		 * @return {Promise<void>}
		 */
		async onMounted() {
			console.info('ContractIndex mounted, initializing...')
			try {
				// Ensure settings are loaded first (this will also register object types)
				if (!objectStore.settings) {
					console.info('Loading settings before fetching contracten...')
					await objectStore.fetchSettings()
				}

				// Fetch contracten collection
				console.info('Fetching contracten...')
				await objectStore.fetchCollection('contract')
			} catch (error) {
				console.error('Error initializing ContractIndex:', error)
				// Show error to user if needed
			}
		},
	},
}
</script>
