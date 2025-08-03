<template>
	<div>
		<NcSettingsSection
			name="Software Catalog Configuration"
			description="Configure OpenRegister schema mappings for Software Catalog objects"
			doc-url="https://docs.opencatalogi.nl" />

		<!-- Version Information Section -->
		<VersionInformation />

		<!-- OpenRegister Integration Section -->
		<OpenRegisterIntegration />

		<!-- User Groups Configuration Section -->
		<UserGroupsConfiguration />

		<!-- Organization Synchronization Section -->
		<OrganizationSynchronization />

		<!-- ArchiMate Import/Export Section -->
		<ArchiMateImportExport />

		<!-- Email Configuration Section -->
		<EmailConfiguration />
	</div>
</template>

<script>
import { defineComponent } from 'vue'
import { NcSettingsSection } from '@nextcloud/vue'
import { settingsStore } from '../../store/store.js'
import OpenRegisterIntegration from './sections/OpenRegisterIntegration.vue'
import VersionInformation from './sections/VersionInformation.vue'
import UserGroupsConfiguration from './sections/UserGroupsConfiguration.vue'
import OrganizationSynchronization from './sections/OrganizationSynchronization.vue'
import ArchiMateImportExport from './sections/ArchiMateImportExport.vue'
import EmailConfiguration from './sections/EmailConfiguration.vue'

/**
 * Software Catalog Settings component
 *
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @version  1.0.0
 */
export default defineComponent({
	name: 'SoftwareCatalogSettings',
	components: {
		NcSettingsSection,
		OpenRegisterIntegration,
		VersionInformation,
		UserGroupsConfiguration,
		OrganizationSynchronization,
		ArchiMateImportExport,
		EmailConfiguration,
	},

	setup() {
		// Use the settings store
		return {
			store: settingsStore,
		}
	},

	/**
	 * Load settings data when component is created
	 */
	async created() {
		await this.store.loadSettings()
	},
})
</script>
