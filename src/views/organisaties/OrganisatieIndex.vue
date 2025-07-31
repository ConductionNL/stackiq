/**
 * OrganisatieIndex.vue
 * Component for displaying and managing organisaties using GenericObjectTable
 * @category Views
 * @package softwarecatalog
 * @author Ruben Linde
 * @copyright 2024
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 * @link https://github.com/opencatalogi/softwarecatalog
 */

<script setup>
import { navigationStore, objectStore } from '../../store/store.js'
</script>

<template>
	<GenericObjectTable
		object-type="organisatie"
		object-type-plural="organisaties"
		:title="t('softwarecatalog', 'Organisaties')"
		:description="t('softwarecatalog', 'Manage your organisaties and their configurations')"
		:empty-icon="OfficeBuildingOutline"
		:card-icon="OfficeBuildingOutline"
		:properties="organisatieProperties"
		:object-actions="organisatieObjectActions"
		:mass-actions="organisatieMassActions"
		:actions="organisatieActions"
		:add-action="addOrganisatieAction"
		:help-url="'https://conduction.gitbook.io/softwarecatalog-nextcloud/beheerders/organisaties'"
		@mounted="onMounted" />
</template>

<script>
import GenericObjectTable from '../../components/GenericObjectTable.vue'
import OfficeBuildingOutline from 'vue-material-design-icons/OfficeBuildingOutline.vue'
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
	name: 'OrganisatieIndex',
	components: {
		GenericObjectTable,
	},
	data() {
		return {
			organisatieProperties: [
				{
					id: 'name',
					label: 'Name',
					key: 'name',
					sortable: true,
					searchable: true,
				},
				{
					id: 'website',
					label: 'Website',
					key: 'website',
					sortable: true,
					searchable: true,
				},
				{
					id: 'summary',
					label: 'Summary',
					key: 'summary',
					sortable: false,
					searchable: true,
				},
				{
					id: 'oin',
					label: 'OIN',
					key: 'oin',
					sortable: true,
					searchable: true,
				},
				{
					id: 'tooi',
					label: 'TOOI',
					key: 'tooi',
					sortable: true,
					searchable: true,
				},
				{
					id: 'rsin',
					label: 'RSIN',
					key: 'rsin',
					sortable: true,
					searchable: true,
				},
				{
					id: 'updatedAt',
					label: 'Last Updated',
					key: 'updatedAt',
					sortable: true,
					searchable: false,
				},
			],
			organisatieObjectActions: [
				{
					id: 'view',
					label: 'View',
					icon: Eye,
					handler: (organisatie) => {
						objectStore.setActiveObject('organisatie', organisatie)
						navigationStore.setModal('viewOrganisatie')
					},
				},
				{
					id: 'edit',
					label: 'Edit',
					icon: Pencil,
					handler: (organisatie) => {
						objectStore.setActiveObject('organisatie', organisatie)
						navigationStore.setModal('organisatie')
					},
				},
				{
					id: 'copy',
					label: 'Copy',
					icon: ContentCopy,
					handler: (organisatie) => {
						objectStore.setActiveObject('organisatie', organisatie)
						navigationStore.setDialog('copyObject', {
							objectType: 'organisatie',
							dialogTitle: 'Organisatie',
						})
					},
				},
				{
					id: 'delete',
					label: 'Delete',
					icon: TrashCanOutline,
					handler: (organisatie) => {
						objectStore.setActiveObject('organisatie', organisatie)
						navigationStore.setDialog('deleteObject', {
							objectType: 'organisatie',
							dialogTitle: 'Organisatie',
						})
					},
				},
			],
			organisatieMassActions: [
				{
					id: 'massDelete',
					label: 'Delete Selected',
					icon: Delete,
					handler: () => {
						navigationStore.setDialog('massDeleteObjects', {
							objectType: 'organisatie',
							dialogTitle: 'Organisaties',
						})
					},
				},
				{
					id: 'massPublish',
					label: 'Publish Selected',
					icon: PublishIcon,
					handler: () => {
						navigationStore.setDialog('massPublishObjects', {
							objectType: 'organisatie',
							dialogTitle: 'Organisaties',
						})
					},
				},
				{
					id: 'massDepublish',
					label: 'Depublish Selected',
					icon: PublishOffIcon,
					handler: () => {
						navigationStore.setDialog('massDepublishObjects', {
							objectType: 'organisatie',
							dialogTitle: 'Organisaties',
						})
					},
				},
			],
			organisatieActions: [
				{
					id: 'add',
					label: 'Add Organisatie',
					icon: Plus,
					primary: true,
					handler: () => {
						objectStore.clearActiveObject('organisatie')
						navigationStore.setModal('organisatie')
					},
				},
				{
					id: 'refresh',
					label: 'Refresh',
					icon: Refresh,
					handler: () => {
						objectStore.fetchCollection('organisatie')
					},
					disabled: () => objectStore.isLoading('organisatie'),
				},
				{
					id: 'help',
					label: 'Help',
					icon: HelpCircleOutline,
					handler: () => {
						window.open('https://conduction.gitbook.io/softwarecatalog-nextcloud/beheerders/organisaties', '_blank')
					},
				},
			],
			addOrganisatieAction: {
				id: 'add',
				label: 'Add Organisatie',
				icon: Plus,
				handler: () => {
					objectStore.clearActiveObject('organisatie')
					navigationStore.setModal('organisatie')
				},
			},
		}
	},
	methods: {
		onMounted() {
			console.info('OrganisatieIndex mounted, fetching organisaties...')
			objectStore.fetchCollection('organisatie')
		},
	},
}
</script>
