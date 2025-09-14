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
				<div class="contactpersonenHeader">
					<h3>{{ t('softwarecatalog', 'Contactpersonen') }}</h3>
					<span class="contactCount">{{ getContactpersonenCount() }} contactpersonen</span>
				</div>

				<!-- Contactpersonen Table -->
				<div class="contactpersonenTable">
					<table class="contactTable">
						<thead>
							<tr>
								<th>{{ t('softwarecatalog', 'Naam') }}</th>
								<th>{{ t('softwarecatalog', 'Functie') }}</th>
								<th>{{ t('softwarecatalog', 'E-mail') }}</th>
								<th>{{ t('softwarecatalog', 'Telefoon') }}</th>
								<th>{{ t('softwarecatalog', 'Aanspreekpunt') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="contact in getContactpersonen()" :key="getContactId(contact)" class="contactRow">
								<td class="contactName">
									<div class="contactNameCell">
										<AccountMultiple :size="16" />
										{{ getContactName(contact) }}
									</div>
								</td>
								<td>{{ getContactFunctie(contact) || '-' }}</td>
								<td>
									<a v-if="getContactEmail(contact)" :href="`mailto:${getContactEmail(contact)}`" class="contactLink">
										{{ getContactEmail(contact) }}
									</a>
									<span v-else>-</span>
								</td>
								<td>{{ getContactPhone(contact) || '-' }}</td>
								<td>
									<span v-if="getContactIsAanspreekpunt(contact)" class="aanspreekpuntBadge">
										{{ t('softwarecatalog', 'Ja') }}
									</span>
									<span v-else>-</span>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<!-- Fallback List View for Small Screens -->
				<div class="contactpersonenList">
					<div v-for="contact in getContactpersonen()" :key="getContactId(contact)" class="contactItem">
						<div class="contactHeader">
							<AccountMultiple :size="16" />
							<span class="contactName">
								{{ getContactName(contact) }}
							</span>
						</div>
						<div class="contactDetails">
							<div v-if="getContactFunctie(contact)" class="contactDetail">
								<span class="contactLabel">{{ t('softwarecatalog', 'Functie') }}:</span>
								{{ getContactFunctie(contact) }}
							</div>
							<div v-if="getContactEmail(contact)" class="contactDetail">
								<Email :size="14" />
								<a :href="`mailto:${getContactEmail(contact)}`">
									{{ getContactEmail(contact) }}
								</a>
							</div>
							<div v-if="getContactPhone(contact)" class="contactDetail">
								<Phone :size="14" />
								{{ getContactPhone(contact) }}
							</div>
						</div>
					</div>
				</div>

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
		 * Get the name for a contact person
		 * @param {object} contact - The contact person object
		 * @return {string} The contact person's name
		 */
		getContactName(contact) {
			// Handle different data structures
			if (contact?.voornaam && contact?.achternaam) {
				return `${contact.voornaam} ${contact.achternaam}`
			}
			if (contact?.naam) {
				return contact.naam
			}
			if (contact?.name) {
				return contact.name
			}
			if (contact?.['@self']?.name) {
				return contact['@self'].name
			}
			if (contact?.['@self']?.voornaam && contact?.['@self']?.achternaam) {
				return `${contact['@self'].voornaam} ${contact['@self'].achternaam}`
			}
			return 'Onbekende contactpersoon'
		},

		/**
		 * Get the contactpersonen count
		 * @return {number} The number of contactpersons
		 */
		getContactpersonenCount() {
			return this.item.contactpersonen?.length || 0
		},

		/**
		 * Get the contactpersonen data
		 * @return {Array} The contactpersonen array
		 */
		getContactpersonen() {
			return this.item.contactpersonen || []
		},

		/**
		 * Get the ID for a contact person
		 * @param {object} contact - The contact person object
		 * @return {string} The contact person's ID
		 */
		getContactId(contact) {
			return contact.id || contact.uuid || 'unknown'
		},

		/**
		 * Get the functie for a contact person
		 * @param {object} contact - The contact person object
		 * @return {string} The contact person's functie
		 */
		getContactFunctie(contact) {
			return contact?.functie || contact?.['@self']?.functie
		},

		/**
		 * Get the email for a contact person
		 * @param {object} contact - The contact person object
		 * @return {string} The contact person's email
		 */
		getContactEmail(contact) {
			return contact?.['e-mailadres'] || contact?.['@self']?.['e-mailadres']
		},

		/**
		 * Get the phone number for a contact person
		 * @param {object} contact - The contact person object
		 * @return {string} The contact person's phone number
		 */
		getContactPhone(contact) {
			return contact?.telefoonnummer || contact?.['@self']?.telefoonnummer
		},

		/**
		 * Check if contact person is aanspreekpunt
		 * @param {object} contact - The contact person object
		 * @return {boolean} Whether the contact person is aanspreekpunt
		 */
		getContactIsAanspreekpunt(contact) {
			return contact?.isAanspreekpunt || contact?.['@self']?.isAanspreekpunt || false
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

.contactpersonenList {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.contactItem {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
	background: var(--color-main-background);
}

.contactHeader {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 8px;
}

.contactName {
	font-size: 14px;
	font-weight: 600;
	color: var(--color-main-text);
}

.contactDetails {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.contactDetail {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 13px;
	color: var(--color-main-text);
}

.contactLabel {
	font-weight: 600;
	color: var(--color-text-lighter);
}

/* Contactpersonen Table Styles */
.contactpersonenTable {
	margin-bottom: 16px;
}

.contactTable {
	width: 100%;
	border-collapse: collapse;
	font-size: 13px;
}

.contactTable th {
	background: var(--color-background-dark);
	color: var(--color-text-lighter);
	font-weight: 600;
	text-align: left;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}

.contactTable td {
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border-light);
	vertical-align: top;
}

.contactRow:hover {
	background: var(--color-background-dark);
}

.contactNameCell {
	display: flex;
	align-items: center;
	gap: 6px;
	font-weight: 600;
}

.contactLink {
	color: var(--color-primary);
	text-decoration: none;
}

.contactLink:hover {
	text-decoration: underline;
}

.aanspreekpuntBadge {
	background: var(--color-primary-light);
	color: var(--color-primary-element-text);
	padding: 2px 6px;
	border-radius: var(--border-radius-pill);
	font-size: 11px;
	font-weight: 600;
}

/* Hide table on small screens, show list */
@media (max-width: 768px) {
	.contactpersonenTable {
		display: none;
	}
}

@media (min-width: 769px) {
	.contactpersonenList {
		display: none;
	}
}

</style>