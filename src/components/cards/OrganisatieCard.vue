/**
 * OrganisatieCard.vue
 * Custom card component for displaying organisatie objects
 * @category Components
 * @package softwarecatalog
 * @author Ruben Linde
 * @copyright 2024
 * @license AGPL-3.0-or-later
 * @version 1.0.0
 * @link https://github.com/opencatalogi/softwarecatalog
 */

<template>
	<div class="organisatieCard">
		<div class="cardHeader">
			<h2 v-tooltip.bottom="getOrganisatieSummary(item)">
				<component :is="cardIcon" :size="20" />
				{{ getOrganisatieTitle(item) }}
			</h2>
			<div class="cardHeaderActions">
				<!-- Object Actions -->
				<NcActions :primary="true" menu-name="Actions">
					<template #icon>
						<DotsHorizontal :size="20" />
					</template>
					<NcActionButton
						v-for="action in objectActions"
						:key="action.id"
						close-after-click
						:disabled="action.condition && !action.condition(item)"
						@click="executeObjectAction(action, item)">
						<template #icon>
							<component :is="action.icon" :size="20" />
						</template>
						{{ action.label }}
					</NcActionButton>
				</NcActions>
			</div>
		</div>

		<!-- Custom Organisation Content -->
		<div class="organisatieContent">
			<!-- Organisation View -->
			<div v-if="currentView === 'organisatie'">
				<!-- Organisation Type Badge -->
				<div class="organisatieBadges">
					<span v-if="item.type" class="typeBadge" :class="`type-${item.type.toLowerCase()}`">
						{{ item.type }}
					</span>
					<span v-if="item.status" class="statusBadge" :class="`status-${item.status.toLowerCase()}`">
						{{ item.status }}
					</span>
				</div>

				<!-- Organisation Description -->
				<div class="organisatieDescription">
					<p v-if="item.beschrijvingKort" class="beschrijvingKort">
						{{ item.beschrijvingKort }}
					</p>
					<p v-else-if="item.beschrijvingLang" class="beschrijvingLang">
						{{ truncateText(item.beschrijvingLang, 150) }}
					</p>
					<p v-else class="noDescription">
						{{ t('softwarecatalog', 'Geen beschrijving beschikbaar') }}
					</p>
				</div>

				<!-- Organisation Details -->
				<div class="organisatieDetails">
					<div v-if="item.website" class="detailItem">
						<Globe :size="16" />
						<a :href="formatWebsiteUrl(item.website)" target="_blank" rel="noopener">
							{{ item.website }}
						</a>
					</div>
					<div v-if="item['e-mailadres']" class="detailItem">
						<Email :size="16" />
						<a :href="`mailto:${item['e-mailadres']}`">
							{{ item['e-mailadres'] }}
						</a>
					</div>
					<div v-if="item.telefoonnummer" class="detailItem">
						<Phone :size="16" />
						<span>{{ item.telefoonnummer }}</span>
					</div>
					<div v-if="item.kvkNummer" class="detailItem">
						<Certificate :size="16" />
						<span>KvK: {{ item.kvkNummer }}</span>
					</div>
				</div>

				<!-- Contactpersonen Count and Toggle Button -->
				<div v-if="getContactpersonenCount() > 0" class="contactCountRow">
					<div class="contactCount">
						<AccountMultiple :size="16" />
						<span>{{ getContactpersonenCount() }} contactpersonen</span>
					</div>
					<div class="viewToggleContainer">
						<NcButton
							:type="currentView === 'contactpersonen' ? 'primary' : 'secondary'"
							size="small"
							@click="toggleView">
							<template #icon>
								<AccountMultiple :size="16" />
							</template>
							{{ currentView === 'contactpersonen' ? 'Bekijk organisatie' : 'Bekijk contactpersonen' }}
						</NcButton>
					</div>
				</div>
			</div>

			<!-- Contactpersonen View -->
			<div v-else-if="currentView === 'contactpersonen'" class="contactpersonenView">
				<ContactpersonenList 
					:organisation-id="item.id || item.uuid" 
					:organisation-data="item" />

				<!-- Toggle Button Row in Contactpersonen View -->
				<div class="contactCountRow">
					<div class="contactCount">
						<component :is="cardIcon" :size="16" />
						<span>{{ getOrganisatieAdres() }}</span>
					</div>
					<div class="viewToggleContainer">
						<NcButton
							:type="currentView === 'organisatie' ? 'primary' : 'secondary'"
							size="small"
							@click="toggleView">
							<template #icon>
								<component :is="cardIcon" :size="16" />
							</template>
							{{ currentView === 'contactpersonen' ? 'Bekijk organisatie' : 'Bekijk contactpersonen' }}
						</NcButton>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { NcActions, NcActionButton, NcButton } from '@nextcloud/vue'
import DotsHorizontal from 'vue-material-design-icons/DotsHorizontal.vue'
import Globe from 'vue-material-design-icons/Web.vue'
import Email from 'vue-material-design-icons/Email.vue'
import Phone from 'vue-material-design-icons/Phone.vue'
import Certificate from 'vue-material-design-icons/Certificate.vue'
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import ContactpersonenList from '../ContactpersonenList.vue'

export default {
	name: 'OrganisatieCard',
	components: {
		NcActions,
		NcActionButton,
		NcButton,
		DotsHorizontal,
		Globe,
		Email,
		Phone,
		Certificate,
		AccountMultiple,
		ContactpersonenList,
	},
	props: {
		/**
		 * The organisation item data
		 */
		item: {
			type: Object,
			required: true,
		},
		/**
		 * Available actions for this organisation
		 */
		objectActions: {
			type: Array,
			default: () => [],
		},
		/**
		 * Icon component for the card
		 */
		cardIcon: {
			type: [String, Object],
			required: true,
		},
	},
	data() {
		return {
			currentView: 'organisatie', // 'organisatie' or 'contactpersonen'
		}
	},
	methods: {
		/**
		 * Get the display title for the organisation
		 * @param {object} item - The organisation object
		 * @return {string} The title to display
		 */
		getOrganisatieTitle(item) {
			return item?.naam || item?.name || item?.['@self']?.name || 'Unknown Organisation'
		},

		/**
		 * Get the summary/tooltip text for the organisation
		 * @param {object} item - The organisation object
		 * @return {string} The summary text
		 */
		getOrganisatieSummary(item) {
			if (item?.beschrijvingKort) return item.beschrijvingKort
			if (item?.beschrijvingLang) return item.beschrijvingLang
			if (item?.type && item?.naam) return `${item.type} organisatie`
			if (item?.type) return item.type
			return ''
		},

		/**
		 * Execute an object action
		 * @param {object} action - The action to execute
		 * @param {object} item - The item to execute the action on
		 */
		executeObjectAction(action, item) {
			if (action.handler) {
				action.handler(item)
			}
		},

		/**
		 * Format website URL to ensure it has protocol
		 * @param {string} url - The website URL
		 * @return {string} Formatted URL with protocol
		 */
		formatWebsiteUrl(url) {
			if (!url) return '#'
			if (url.startsWith('http://') || url.startsWith('https://')) {
				return url
			}
			return `https://${url}`
		},

		/**
		 * Truncate text to specified length
		 * @param {string} text - Text to truncate
		 * @param {number} maxLength - Maximum length
		 * @return {string} Truncated text
		 */
		truncateText(text, maxLength = 150) {
			if (!text || text.length <= maxLength) return text
			return text.substring(0, maxLength).trim() + '...'
		},

		/**
		 * Toggle between organisation and contactpersonen views
		 */
		toggleView() {
			this.currentView = this.currentView === 'organisatie' ? 'contactpersonen' : 'organisatie'
		},

		/**
		 * Get the contactpersonen count
		 * @return {number} The number of contactpersons
		 */
		getContactpersonenCount() {
			return this.item.contactpersonen?.length || 0
		},

		/**
		 * Get the organisation address
		 * @return {string} The organisation's address
		 */
		getOrganisatieAdres() {
			const adres = this.item?.adres
			const postcode = this.item?.postcode
			const plaats = this.item?.plaats
			
			if (adres && postcode && plaats) {
				return `${adres}, ${postcode} ${plaats}`
			} else if (adres && plaats) {
				return `${adres}, ${plaats}`
			} else if (plaats) {
				return plaats
			} else if (adres) {
				return adres
			}
			
			return 'Geen adres beschikbaar'
		},
	},
}
</script>

<style scoped>
.organisatieCard {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px;
	background: var(--color-main-background);
	transition: box-shadow 0.2s ease;
}

.organisatieCard:hover {
	box-shadow: 0 2px 8px var(--color-box-shadow);
}

.cardHeader {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	margin-bottom: 12px;
}

.cardHeader h2 {
	margin: 0;
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 16px;
	font-weight: 600;
	flex: 1;
	min-width: 0;
	color: var(--color-main-text);
}

.cardHeaderActions {
	display: flex;
	gap: 8px;
	align-items: center;
}

.organisatieContent {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.organisatieBadges {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}

.typeBadge, .statusBadge {
	padding: 4px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 12px;
	font-weight: 600;
	text-transform: capitalize;
}

.typeBadge {
	background: var(--color-primary-light);
	color: var(--color-primary-element-text);
}

.typeBadge.type-gemeente {
	background: #e3f2fd;
	color: #1565c0;
}

.typeBadge.type-leverancier {
	background: #f3e5f5;
	color: #7b1fa2;
}

.typeBadge.type-samenwerking {
	background: #e8f5e8;
	color: #2e7d32;
}

.statusBadge {
	background: var(--color-background-dark);
	color: var(--color-text-lighter);
}

.statusBadge.status-actief {
	background: #e8f5e8;
	color: #2e7d32;
}

.statusBadge.status-concept {
	background: #fff3e0;
	color: #f57c00;
}

.statusBadge.status-deactief {
	background: #ffebee;
	color: #c62828;
}

.organisatieDescription {
	line-height: 1.4;
}

.beschrijvingKort, .beschrijvingLang {
	margin: 0;
	color: var(--color-main-text);
	font-size: 14px;
}

.noDescription {
	margin: 0;
	color: var(--color-text-lighter);
	font-style: italic;
	font-size: 14px;
}

.organisatieDetails {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.detailItem {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 13px;
	color: var(--color-main-text);
}

.detailItem a {
	color: var(--color-primary);
	text-decoration: none;
}

.detailItem a:hover {
	text-decoration: underline;
}

.contactCountRow {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding-top: 8px;
	border-top: 1px solid var(--color-border-dark);
}

.contactCount {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 13px;
	color: var(--color-text-lighter);
}

.viewToggleContainer {
	display: flex;
}

.contactpersonenView {
	padding-top: 12px;
	border-top: 1px solid var(--color-border-dark);
}

.contactpersonenHeader {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 12px;
}

.contactpersonenHeader h3 {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
	color: var(--color-main-text);
}


</style>