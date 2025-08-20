<template>
	<div>
		<NcSettingsSection
			name="Software Catalog Configuration"
			description="Configure OpenRegister schema mappings for Software Catalog objects"
			doc-url="https://docs.opencatalogi.nl" />

		<!-- Version Information Section -->
		<VersionInformation />

		<!-- Statistics Overview Section -->
		<StatisticsOverview />

		<!-- General Settings Section -->
		<AlwaysVisibleSection
			name="General Settings"
			description="Configure basic application settings"
			:loading="store.loadingGeneralSettings"
			loading-text="Loading general settings..."
			:show-save-button="true"
			:show-refresh-button="true"
			:can-save="catalogLocationChanged"
			:saving="savingCatalogLocation"
			save-button-text="Save General Settings"
			@save="saveGeneralSettings"
			@refresh="refreshGeneralSettings">
			<!-- Software Catalog Location -->
			<div class="catalog-location-section">
				<h3>Software Catalog Location</h3>
				<p>Set the base URL for your software catalog interface</p>

				<NcTextField
					:value="catalogLocation"
					:label="t('softwarecatalog', 'Software Catalog Location URL')"
					:placeholder="t('softwarecatalog', 'https://catalog.example.com')"
					:disabled="store.loading"
					@update:value="onCatalogLocationChange">
					<template #icon>
						<Web :size="16" />
					</template>
				</NcTextField>

				<div class="catalog-location-help">
					<p class="help-text">
						This URL will be used for external links to your software catalog. The system will append "/beheer" to this URL for management interfaces.
					</p>
				</div>
			</div>
		</AlwaysVisibleSection>

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
import {
	NcSettingsSection,
	NcTextField,
	NcButton,
	NcLoadingIcon,
} from '@nextcloud/vue'
import { settingsStore } from '../../store/store.js'
import Save from 'vue-material-design-icons/ContentSave.vue'
import Web from 'vue-material-design-icons/Web.vue'
import OpenRegisterIntegration from './sections/OpenRegisterIntegration.vue'
import VersionInformation from './sections/VersionInformation.vue'
import StatisticsOverview from './sections/StatisticsOverview.vue'
import UserGroupsConfiguration from './sections/UserGroupsConfiguration.vue'
import OrganizationSynchronization from './sections/OrganizationSynchronization.vue'
import ArchiMateImportExport from './sections/ArchiMateImportExport.vue'
import EmailConfiguration from './sections/EmailConfiguration.vue'
import AlwaysVisibleSection from '../../components/AlwaysVisibleSection.vue'

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
		NcTextField,
		NcButton,
		NcLoadingIcon,
		OpenRegisterIntegration,
		VersionInformation,
		StatisticsOverview,
		UserGroupsConfiguration,
		OrganizationSynchronization,
		ArchiMateImportExport,
		EmailConfiguration,
		AlwaysVisibleSection,
		Save,
		Web,
	},

	setup() {
		// Use the settings store
		return {
			store: settingsStore,
		}
	},

	/**
	 * Component data
	 *
	 * @return {object} Component data
	 */
	data() {
		return {
			savingCatalogLocation: false,
			catalogLocation: '',
		}
	},

	computed: {
		/**
		 * Check if catalog location has changed
		 *
		 * @return {boolean} True if catalog location has changed
		 */
		catalogLocationChanged() {
			return this.catalogLocation !== (this.store.settings.catalogLocation || '')
		},
	},

	/**
	 * Load settings data when component is created
	 */
	async created() {
		await this.store.loadSettings()
		// Initialize catalog location from store
		this.catalogLocation = this.store.settings.catalogLocation || ''
	},

	/**
	 * Watch for changes in the store's catalogLocation
	 */
	watch: {
		'store.settings.catalogLocation': {
			handler(newValue) {
				if (newValue !== undefined && newValue !== null) {
					this.catalogLocation = newValue
				}
			},
			immediate: true
		},
		'store.loadingGeneralSettings': {
			handler(newValue, oldValue) {
				// When loading finishes, update the catalog location
				if (oldValue === true && newValue === false) {
					this.catalogLocation = this.store.settings.catalogLocation || ''
				}
			}
		}
	},

	methods: {
		/**
		 * Handle catalog location input change
		 *
		 * @param {string} value - New catalog location value
		 * @return {void}
		 */
		onCatalogLocationChange(value) {
			this.catalogLocation = value
		},

		/**
		 * Save general settings using the centralized settings store
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		async saveGeneralSettings() {
			this.savingCatalogLocation = true
			try {
				// First update the store with the new catalog location value
				await this.store.updateCatalogLocation(this.catalogLocation)
				// Then use the settings store's centralized save method
				await this.store.saveConfiguration()
				console.info('General settings saved successfully')
			} catch (error) {
				console.error('Failed to save general settings:', error)
			} finally {
				this.savingCatalogLocation = false
			}
		},

		/**
		 * Refresh general settings from the store
		 *
		 * @async
		 * @return {Promise<void>}
		 */
		async refreshGeneralSettings() {
			try {
				await this.store.loadGeneralConfig()
				// Reset the local catalog location to match the store
				this.catalogLocation = this.store.settings.catalogLocation || ''
			} catch (error) {
				console.error('Failed to refresh general settings:', error)
			}
		},
	},
})
</script>

<style scoped>
.catalog-location-section {
	margin-bottom: 2rem;
	max-width: 500px;
}

.catalog-location-help {
	margin-top: 0.5rem;
	margin-bottom: 1rem;
}

.help-text {
	font-size: 12px;
	color: var(--color-text-lighter);
	margin: 0;
}

.loading-text {
	text-align: center;
	color: var(--color-text-lighter);
	margin-top: 1rem;
	font-size: 14px;
}
</style>
